<?php
/**
 * Sign-in throttling (PA53, AGENTS.md §8).
 *
 * PA19 counted failures inside AuthController::login(), which guarded exactly
 * one door. WordPress keeps several others that accept the same account
 * password — wp-login.php, XML-RPC, wp_signon() — so the counter could simply be
 * walked around: POST five bad passwords at the REST route, get locked, then
 * keep guessing at /wp-login.php with no penalty at all.
 *
 * This service moves the decision to the `authenticate` filter (priority 99),
 * the single point every password-based sign-in path passes through, and
 * widens the key from "username" to two buckets:
 *
 *   - credential bucket (username + client address): five failures inside the
 *     window lock that pair. Scoping the lock to the address is deliberate — an
 *     account-wide lock lets anyone freeze a learner out on purpose, which turns
 *     a guess into a denial of service. Guessing from one place is still stopped.
 *   - source bucket (client address alone, much higher cap): one host must not
 *     be able to spray a single password across a whole class list, which the
 *     per-credential bucket would never notice.
 *
 * The application-password channel (Basic auth on every API request) does NOT
 * pass through here: core validates it in `determine_current_user` against the
 * token's own hash, never the account password, and its 24-char token is not
 * guessable — throttling it would instead break a locked learner's access to
 * /me, which PA51 keeps open on purpose.
 *
 * State lives in transients — TTL'd, never written against the user record, and
 * holding no secret: a count, a window start, a lock deadline and an escalation
 * level. Locks escalate (15 → 30 → 60 minutes) while failures keep coming and
 * reset after a quiet window, so a determined guesser is slowed without a
 * recovered family being punished forever.
 *
 * @package ABA_Skills_Core
 */

namespace ABA\Core\Services;

use ABA\Core\Auth\Authorization;

if (!defined('ABSPATH')) {
    exit;
}

class LoginThrottleService {
    /** Failures for one credential (username + address) before that pair locks. */
    public const ACCOUNT_MAX_FAILURES = 5;

    /** Failures from one address across ALL accounts (username spraying). */
    public const IP_MAX_FAILURES = 20;

    /** Sliding window the failures are counted in. */
    public const WINDOW_SECONDS = 900;

    /** First lock, doubled per escalation up to the cap. */
    public const BASE_LOCK_SECONDS = 900;
    public const MAX_LOCK_SECONDS = 3600;

    /** Error code every sign-in channel reports a throttled attempt as. */
    public const ERROR_CODE = 'ACCOUNT_LOCKED';

    /** Uniform text: it never says whether the account exists (§8). */
    public const MESSAGE = 'Too many failed sign-in attempts. Please try again later.';

    public const KEY_PREFIX = 'aba_thr_';

    /** @var array{fails:int,window:int,until:int,level:int} */
    private const EMPTY_STATE = ['fails' => 0, 'window' => 0, 'until' => 0, 'level' => 0];

    /**
     * Hook the guard for every password-based sign-in. Registered at plugin load
     * (NOT on rest_api_init) because wp-login.php and XML-RPC never reach the
     * REST bootstrap — that gap is the whole reason PA19 was insufficient.
     */
    public static function register(): void {
        add_filter('authenticate', [self::class, 'guard'], 99, 3);
    }

    /**
     * The single choke point. Refuses while a bucket is locked, counts the
     * failure otherwise, and clears the credential's counter on success.
     *
     * @param \WP_User|\WP_Error|null $user     Result of the filters before us.
     * @param mixed                   $username Raw login (email allowed).
     * @param mixed                   $password Raw secret — never stored.
     * @return \WP_User|\WP_Error|null
     */
    public static function guard($user, $username = '', $password = '') {
        // Empty input is a validation error, not a guess; counting it would let
        // anyone drive up a stranger's counter with blank requests.
        if (!is_string($username) || trim($username) === '' || !is_string($password) || $password === '') {
            return $user;
        }

        $self = new self();
        $ip = $self->clientAddress();

        $status = $self->lockedStatus($username, $ip);
        if ($status['locked']) {
            return self::error((int) $status['retryAfter']);
        }

        if ($user instanceof \WP_User) {
            $self->clear($username, $ip, (string) $user->user_login);
            return $user;
        }

        if (is_wp_error($user)) {
            $result = $self->registerFailure($username, $ip);
            if ($result['locked']) {
                // Answer the attempt that tripped the cap with the lock itself,
                // so the caller learns it is blocked without a sixth round-trip.
                return self::error((int) $result['retryAfter']);
            }
        }

        return $user;
    }

    /** The shared refusal, shaped like every other aba_error payload. */
    private static function error(int $retryAfter): \WP_Error {
        return new \WP_Error(self::ERROR_CODE, self::MESSAGE, [
            'status'  => 429,
            'code'    => self::ERROR_CODE,
            'details' => ['retryAfter' => max(1, $retryAfter)],
        ]);
    }

    /**
     * The client's address. Deliberately REMOTE_ADDR only: X-Forwarded-For is
     * caller-supplied, so honouring it would let an attacker mint a fresh bucket
     * on every request. Behind a proxy that rewrites REMOTE_ADDR, all clients
     * share one source bucket — the credential bucket still isolates them,
     * because it includes the username.
     */
    public function clientAddress(): string {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
        return (string) apply_filters('aba_login_throttle_ip', $ip);
    }

    /** Transient key for the (username + address) bucket. Hashed, not raw. */
    public function accountKey(string $username, string $ip): string {
        return self::KEY_PREFIX . 'acct_' . substr(md5(strtolower(trim($username)) . '|' . $ip), 0, 24);
    }

    /** Transient key for the (address only) bucket. */
    public function ipKey(string $ip): string {
        return self::KEY_PREFIX . 'ip_' . substr(md5($ip), 0, 24);
    }

    /**
     * Is this attempt locked right now? Read-only: a refused attempt never
     * extends the lock, so an attacker cannot keep a learner out indefinitely.
     *
     * @return array{locked:bool,retryAfter:int,scope:string}
     */
    public function lockedStatus(string $username, string $ip): array {
        $now = time();
        foreach ($this->bucketKeys($username, $ip) as $scope => $key) {
            $state = $this->read($key);
            if ((int) $state['until'] > $now) {
                return ['locked' => true, 'retryAfter' => (int) $state['until'] - $now, 'scope' => $scope];
            }
        }
        return ['locked' => false, 'retryAfter' => 0, 'scope' => ''];
    }

    /**
     * Count one failure and report whether this attempt engaged a lock.
     *
     * @return array{locked:bool,retryAfter:int,scope:string,failures:int}
     */
    public function registerFailure(string $username, string $ip): array {
        $now = time();
        $out = ['locked' => false, 'retryAfter' => 0, 'scope' => '', 'failures' => 0];

        foreach ($this->bucketKeys($username, $ip) as $scope => $key) {
            $cap = $scope === 'source' ? self::IP_MAX_FAILURES : self::ACCOUNT_MAX_FAILURES;
            $bumped = $this->bump($key, $cap, $now);
            if (!$bumped['locked']) {
                continue;
            }
            // One ledger row per attempt that triggers a lock, not per bucket:
            // both buckets locking at once is still a single suspicious event.
            if (!$out['locked']) {
                $out = [
                    'locked'     => true,
                    'retryAfter' => $bumped['retryAfter'],
                    'scope'      => $scope,
                    'failures'   => $bumped['failures'],
                ];
                $this->recordLock($username, $scope, $bumped);
            }
        }

        return $out;
    }

    /**
     * A successful sign-in clears the credential bucket only. The source bucket
     * is deliberately NOT cleared: an attacker who finally guesses one password
     * must not thereby reset the spraying counter for their whole host.
     */
    public function clear(string $username, string $ip, string $resolvedLogin = ''): void {
        delete_transient($this->accountKey($username, $ip));
        if ($resolvedLogin !== '' && strtolower($resolvedLogin) !== strtolower(trim($username))) {
            // Signed in with an email but the counter was keyed by the login.
            delete_transient($this->accountKey($resolvedLogin, $ip));
        }
    }

    /**
     * Bucket keys for this attempt, in the order they are checked: cheapest
     * refusal first. An unknown address yields no source bucket at all —
     * without a client address there is nothing to attribute spraying to.
     *
     * @return array<string,string>
     */
    private function bucketKeys(string $username, string $ip): array {
        $keys = ['credential' => $this->accountKey($username, $ip)];
        if ($ip !== '') {
            $keys['source'] = $this->ipKey($ip);
        }
        return $keys;
    }

    /**
     * Increment one bucket, engaging (or escalating) its lock at the cap.
     *
     * @return array{locked:bool,retryAfter:int,failures:int}
     */
    private function bump(string $key, int $cap, int $now): array {
        $state = $this->read($key);

        // Start a fresh counting window; keep the escalation level only while
        // the previous lock is recent (a quiet period forgives it).
        if ((int) $state['window'] === 0 || ($now - (int) $state['window']) > self::WINDOW_SECONDS) {
            $quietSince = (int) $state['until'] > 0 ? $now - (int) $state['until'] : PHP_INT_MAX;
            $state['fails'] = 0;
            $state['window'] = $now;
            $state['until'] = 0;
            if ($quietSince > self::WINDOW_SECONDS) {
                $state['level'] = 0;
            }
        }

        $state['fails'] = (int) $state['fails'] + 1;

        $locked = false;
        $retryAfter = 0;
        if ((int) $state['fails'] >= $cap) {
            $state['level'] = max(1, (int) $state['level'] + 1);
            $retryAfter = (int) min(
                self::BASE_LOCK_SECONDS * (2 ** ((int) $state['level'] - 1)),
                self::MAX_LOCK_SECONDS
            );
            $state['until'] = $now + $retryAfter;
            $locked = true;
        }

        $this->write($key, $state, $now);
        return ['locked' => $locked, 'retryAfter' => $retryAfter, 'failures' => (int) $state['fails']];
    }

    /** @return array{fails:int,window:int,until:int,level:int} */
    private function read(string $key): array {
        $raw = get_transient($key);
        return is_array($raw) ? ($raw + self::EMPTY_STATE) : self::EMPTY_STATE;
    }

    /**
     * Persist a bucket. The TTL always outlives the lock plus one window, so the
     * escalation level survives to the next burst instead of resetting immediately.
     *
     * @param array{fails:int,window:int,until:int,level:int} $state
     */
    private function write(string $key, array $state, int $now): void {
        $ttl = max(self::WINDOW_SECONDS, (int) $state['until'] - $now) + self::WINDOW_SECONDS;
        set_transient($key, $state, $ttl);
    }

    /**
     * Record the lock on the credential ledger, once per engagement (§12).
     * Only non-secret facts are written — scope, the wait, the failure count.
     * The offending address is deliberately omitted: it identifies a household
     * as much as the account does, and the family needs the warning, not the IP.
     *
     * @param array{locked:bool,retryAfter:int,failures:int} $bumped
     */
    private function recordLock(string $username, string $scope, array $bumped): void {
        $user = get_user_by('login', $username);
        if (!$user) {
            $user = get_user_by('email', $username);
        }
        $userId = $user ? (int) $user->ID : null;
        $studentId = $userId ? Authorization::studentIdForUser($userId) : 0;

        (new CredentialAuditService())->recordThrottled(
            $userId,
            $studentId > 0 ? $studentId : null,
            $scope,
            (int) $bumped['retryAfter'],
            (int) $bumped['failures']
        );
    }
}
