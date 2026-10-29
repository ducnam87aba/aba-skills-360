<?php
/**
 * Student roster management service (PA44/PA45, Student + School domains).
 *
 * Owns the create/edit/archive flow and the roster-context read that the staff
 * administration screen drives, keeping every decision server-side:
 *
 *   - A *class-assigned* staff creates inside their own class; a *school-assigned*
 *     staff inside their own school. A staff can never redirect a learner into
 *     another school by editing a request field — the scope is read from their
 *     own assignment (Authorization, PA23/PA24), never from the client (§8).
 *   - Only a platform administrator may name a destination school/class, and
 *     then only after it is validated to exist (and the class to belong to that
 *     school). Least privilege, capability-driven — never a role-name check (§3).
 *   - PA45 extends the same rule to EDIT: an actor may only touch a learner who
 *     lives inside their own scope (class staff → their class, school staff →
 *     their school, platform → anywhere), and only a platform admin (or, for
 *     school staff, a move between classes of the SAME school) can re-classify
 *     a learner. Archiving is soft — scores and history survive (§11).
 *
 * Every learner needs a linked wp_user (students.user_id is UNIQUE), mirroring
 * how the demo seeder creates data-only cohort accounts: a random, unusable
 * password and the plain subscriber role, so a roster entry is a record, not a
 * login, unless a password is later provisioned deliberately.
 *
 * Persistence is delegated to the repositories (§4); this class only orchestrates.
 *
 * @package ABA_Skills_Core
 */

namespace ABA\Core\Services;

use ABA\Core\Repositories\StudentRepository;
use ABA\Core\Repositories\SchoolRepository;
use ABA\Core\Auth\Authorization;
use function ABA\Core\Auth\_aba_is_platform_user;

if (!defined('ABSPATH')) {
    exit;
}

class StudentService {
    /** Human-facing learner code: ABA-<year>-<seq>. */
    private const CODE_PATTERN = '/^[A-Za-z0-9\-_]{4,50}$/';

    // PA50 — a learner credential may not stay in force longer than this without
    // its owner refreshing it. The age is measured from the last time the
    // password was set (a staff issue/reset, or the learner's own change); when
    // it lapses the next sign-in is armed for the PA48 forced-change screen, so
    // a shared handout secret cannot quietly live forever (§8).
    public const CREDENTIAL_MAX_AGE_DAYS = 30;

    private StudentRepository $students;
    private SchoolRepository $schools;
    private CredentialAuditService $audit;
    private CredentialSessionService $sessions;

    public function __construct() {
        $this->students = new StudentRepository();
        $this->schools = new SchoolRepository();
        $this->audit = new CredentialAuditService();
        $this->sessions = new CredentialSessionService();
    }

    /**
     * Resolve the caller's roster context: which scope they see, its names, and
     * the current learners, plus destination options for a platform admin.
     * Powers the staff screen's read + create/edit selectors.
     *
     * PA45: staff rosters now include archived learners (they must stay visible
     * to be reactivated), and a platform admin may point the read at one school
     * via $query['schoolId'] to actually see + edit a roster.
     */
    public function context(int $actorUserId, array $query = []): array {
        $isPlatform = _aba_is_platform_user($actorUserId);
        $classId = Authorization::assignedClassId($actorUserId);
        $schoolId = Authorization::assignedSchoolId($actorUserId);

        if ($isPlatform) {
            // An administrator is not pinned to one school: the screen lists every
            // school/class so they can choose where to create. PA45 — they may also
            // ask to read one school's roster; the school is still validated here,
            // and this is a READ gated by requireRoster, so no §8 leak exists.
            $requestedSchool = (int) ($query['schoolId'] ?? 0);
            $requestedClass = (int) ($query['classId'] ?? 0);
            $students = [];
            if ($requestedSchool > 0 && $this->schools->findSchool($requestedSchool) !== null) {
                $students = $requestedClass > 0 && $this->schools->classInSchool($requestedClass, $requestedSchool)
                    ? $this->students->listByClass($requestedClass, true)
                    : $this->students->listBySchool($requestedSchool, true);
            }
            return [
                'scope' => 'platform',
                'schoolId' => $requestedSchool,
                'classId' => $requestedClass,
                'schoolName' => null,
                'className' => null,
                'schools' => $this->platformSchoolOptions(),
                'students' => $students,
            ];
        }

        // Class-assigned staff are scoped to the class; everyone else with a
        // school assignment sees the whole school (§8: derived, never supplied).
        if ($classId > 0) {
            $students = $this->students->listByClass($classId, true);
            $class = $this->schools->findClass($classId);
            $schoolId = $class ? (int) $class['school_id'] : $schoolId;
            $school = $this->schools->findSchool($schoolId);
            return [
                'scope' => 'class',
                'schoolId' => $schoolId,
                'classId' => $classId,
                'schoolName' => $school['name'] ?? null,
                'className' => $class['name'] ?? null,
                'schools' => [],
                'students' => $students,
            ];
        }

        $students = $this->students->listBySchool($schoolId, true);
        $school = $this->schools->findSchool($schoolId);
        return [
            'scope' => 'school',
            'schoolId' => $schoolId,
            'classId' => 0,
            'schoolName' => $school['name'] ?? null,
            'className' => null,
            // A school-scoped staff may only place learners into their own school.
            'schools' => $schoolId > 0 ? [
                ['id' => $schoolId, 'name' => $school['name'] ?? ('#' . $schoolId),
                 'classes' => array_map(static fn($c) => ['id' => (int) $c['id'], 'name' => $c['name']], $this->schools->listClasses($schoolId))]
            ] : [],
            'students' => $students,
        ];
    }

    /**
     * Create one learner. Returns a status-tagged result the controller maps to a
     * REST response; the acting user's identity decides the scope (§8).
     *
     * @param array $input firstName, lastName, gradeLevel, optional studentCode,
     *                     and (platform admin only) schoolId / classId.
     */
    public function create(int $actorUserId, array $input): array {
        $firstName = trim((string) ($input['firstName'] ?? ''));
        $lastName = trim((string) ($input['lastName'] ?? ''));
        $gradeLevel = (int) ($input['gradeLevel'] ?? 0);

        if ($firstName === '' || $lastName === '') {
            return ['status' => 'invalid', 'field' => 'name', 'error' => 'First and last name are required.'];
        }
        if ($gradeLevel < 1 || $gradeLevel > 12) {
            return ['status' => 'invalid', 'field' => 'gradeLevel', 'error' => 'Grade level must be between 1 and 12.'];
        }

        // ---- Resolve the destination scope. Staff are pinned to their own;
        // only a platform admin may name a school/class, and then only a valid one.
        $isPlatform = _aba_is_platform_user($actorUserId);
        if ($isPlatform) {
            $schoolId = (int) ($input['schoolId'] ?? 0);
            $classId = (int) ($input['classId'] ?? 0);
            if ($schoolId <= 0 || $this->schools->findSchool($schoolId) === null) {
                return ['status' => 'invalid', 'field' => 'schoolId', 'error' => 'A valid destination school is required.'];
            }
            if ($classId > 0 && !$this->schools->classInSchool($classId, $schoolId)) {
                return ['status' => 'invalid', 'field' => 'classId', 'error' => 'That class does not belong to the selected school.'];
            }
        } else {
            $schoolId = Authorization::assignedSchoolId($actorUserId);
            $classId = Authorization::assignedClassId($actorUserId);
            if ($schoolId <= 0) {
                // An unassigned staff holds the roster cap but belongs to no school;
                // refusing is the safe default (§8), never fall back to school 0.
                return ['status' => 'forbidden', 'error' => 'Your account is not assigned to a school.'];
            }
            // A class-assigned staff stays in their class; a school-assigned staff
            // may target a class within their own school, validated below.
            if ($classId > 0) {
                $schoolId = (int) ($this->schools->findClass($classId)['school_id'] ?? $schoolId);
            }
        }

        // ---- Optional explicit code, else generate a unique one.
        $requestedCode = strtoupper(trim((string) ($input['studentCode'] ?? '')));
        if ($requestedCode !== '') {
            if (preg_match(self::CODE_PATTERN, $requestedCode) !== 1) {
                return ['status' => 'invalid', 'field' => 'studentCode', 'error' => 'Student code format is invalid.'];
            }
            if ($this->students->codeExists($requestedCode)) {
                return ['status' => 'conflict', 'field' => 'studentCode', 'error' => 'That student code is already taken.'];
            }
            $code = $requestedCode;
        } else {
            $code = $this->generateUniqueCode();
        }

        // A learner must own a wp_user (students.user_id is UNIQUE). It is a
        // data-only record: random password, no ABA role, cannot sign in.
        $userId = $this->ensureLearnerUser($code, $lastName, $firstName);
        if ($userId === null) {
            return ['status' => 'invalid', 'field' => 'user', 'error' => 'Could not provision the learner account.'];
        }

        $studentId = $this->students->insert([
            'user_id' => $userId,
            'school_id' => $schoolId,
            'class_id' => $classId > 0 ? $classId : null,
            'student_code' => $code,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'grade_level' => $gradeLevel,
            'grade_group' => self::gradeGroupFor($gradeLevel),
        ]);
        if ($studentId === null) {
            return ['status' => 'invalid', 'field' => 'insert', 'error' => 'Could not save the learner.'];
        }

        $row = $this->students->findById($studentId);
        return ['status' => 'created', 'student' => $this->students->toApiShape((array) $row)];
    }

    /**
     * PA45 — edit one learner (names, grade, class placement). Partial update:
     * omitted fields keep their stored values. The scope gate is the same §8
     * rule as create, but read from the *student's* row, not the request: an
     * actor may only touch learners inside their own assignment, and only a
     * platform admin may move a learner across schools.
     */
    public function update(int $actorUserId, int $studentId, array $input): array {
        $row = $this->students->findById($studentId);
        if (!$row) {
            return ['status' => 'notFound', 'error' => 'Student not found.'];
        }
        $gate = $this->editGate($actorUserId, (array) $row);
        if ($gate !== null) {
            return $gate;
        }

        $isPlatform = _aba_is_platform_user($actorUserId);
        $write = [];

        // Merge-then-validate: a partial payload must never blank a name.
        if (array_key_exists('firstName', $input) || array_key_exists('lastName', $input)) {
            $firstName = array_key_exists('firstName', $input)
                ? trim((string) $input['firstName']) : (string) $row['first_name'];
            $lastName = array_key_exists('lastName', $input)
                ? trim((string) $input['lastName']) : (string) $row['last_name'];
            if ($firstName === '' || $lastName === '') {
                return ['status' => 'invalid', 'field' => 'name', 'error' => 'First and last name cannot be empty.'];
            }
            $write['first_name'] = $firstName;
            $write['last_name'] = $lastName;
        }

        if (array_key_exists('gradeLevel', $input)) {
            $gradeLevel = (int) $input['gradeLevel'];
            if ($gradeLevel < 1 || $gradeLevel > 12) {
                return ['status' => 'invalid', 'field' => 'gradeLevel', 'error' => 'Grade level must be between 1 and 12.'];
            }
            $write['grade_level'] = $gradeLevel;
            $write['grade_group'] = self::gradeGroupFor($gradeLevel);
        }

        // ---- Re-classification. Server decides what a mover is allowed to do.
        if (array_key_exists('classId', $input)) {
            $classId = (int) $input['classId'];
            if ($isPlatform) {
                if ($classId > 0) {
                    $class = $this->schools->findClass($classId);
                    if (!$class) {
                        return ['status' => 'invalid', 'field' => 'classId', 'error' => 'Unknown class.'];
                    }
                    $write['class_id'] = $classId;
                    $write['school_id'] = (int) $class['school_id']; // follow the class
                } else {
                    $write['class_id'] = 0; // unassign (stored as NULL)
                }
            } else {
                // School-assigned staff may move learners between classes of
                // THEIR OWN school only; a class-assigned teacher is pinned.
                $teacherClass = Authorization::assignedClassId($actorUserId);
                if ($teacherClass > 0) {
                    // Pinned: silently ignore any classId the client sent (§8).
                } else {
                    $teacherSchool = Authorization::assignedSchoolId($actorUserId);
                    if ($classId > 0 && !$this->schools->classInSchool($classId, $teacherSchool)) {
                        return ['status' => 'invalid', 'field' => 'classId', 'error' => 'That class does not belong to your school.'];
                    }
                    $write['class_id'] = $classId;
                }
            }
        }

        if (empty($write)) {
            return ['status' => 'invalid', 'field' => 'body', 'error' => 'Nothing to update.'];
        }
        // class_id 0 means "no class" — the column is nullable.
        if (isset($write['class_id']) && $write['class_id'] === 0) {
            $write['class_id'] = null;
        }
        if (!$this->students->update($studentId, $write)) {
            return ['status' => 'invalid', 'field' => 'update', 'error' => 'Could not save the learner.'];
        }

        $fresh = $this->students->findById($studentId);
        return ['status' => 'updated', 'student' => $this->students->toApiShape((array) $fresh)];
    }

    /**
     * PA45 — archive (inactive) or reactivate (active) a learner. Soft by
     * design: the row, its scores and its XP ledger all survive (§11/§12);
     * findByCode already refuses inactive learners, so an archived student can
     * no longer be claimed through a new parent link request.
     */
    public function setStatus(int $actorUserId, int $studentId, string $status): array {
        if (!in_array($status, ['active', 'inactive'], true)) {
            return ['status' => 'invalid', 'field' => 'status', 'error' => 'Status must be active or inactive.'];
        }
        $row = $this->students->findById($studentId);
        if (!$row) {
            return ['status' => 'notFound', 'error' => 'Student not found.'];
        }
        $gate = $this->editGate($actorUserId, (array) $row);
        if ($gate !== null) {
            return $gate;
        }
        if (!$this->students->update($studentId, ['status' => $status])) {
            return ['status' => 'invalid', 'field' => 'update', 'error' => 'Could not update the status.'];
        }
        $fresh = $this->students->findById($studentId);
        return ['status' => 'updated', 'student' => $this->students->toApiShape((array) $fresh)];
    }

    /**
     * PA46 — turn a roster record into a signable-in learner. The learner's
     * wp_user starts data-only (random unusable password, §PA44); provisioning
     * sets a real password (staff-supplied or generated) and moves the account
     * to the 'student' role when it exists, so /auth/login + the front-end role
     * guard branch correctly.
     *
     * PA47 — rotation is now EXPLICIT. A first issue is recorded with the
     * aba_credential_issued_at user meta; re-calling WITHOUT $rotate=true
     * returns status 'already' (the username, never a password) instead of
     * silently invalidating credentials the family already holds. Rotating is a
     * deliberate, confirmed action and refreshes the marker.
     *
     * Hardening: same §8 scope gate as edit, an archived learner cannot be
     * provisioned, and a linked user that already holds platform privileges is
     * refused — a roster screen must never become a path to take over an admin
     * account by guessing a student id.
     *
     * The plaintext password is returned ONCE (to be handed to family);
     * WordPress stores only its hash, nothing here is retrievable later.
     */
    public function provisionLogin(int $actorUserId, int $studentId, string $requestedPassword = '', bool $rotate = false): array {
        $row = $this->students->findById($studentId);
        if (!$row) {
            return ['status' => 'notFound', 'error' => 'Student not found.'];
        }
        $gate = $this->editGate($actorUserId, (array) $row);
        if ($gate !== null) {
            return $gate;
        }
        if (($row['status'] ?? 'active') !== 'active') {
            return ['status' => 'invalid', 'field' => 'status', 'error' => 'Reactivate the learner before provisioning an account.'];
        }

        $userId = (int) $row['user_id'];
        $user = $userId > 0 ? get_userdata($userId) : null;
        if (!$user) {
            return ['status' => 'invalid', 'field' => 'user', 'error' => 'The learner has no linked account to provision.'];
        }
        if (user_can($userId, 'manage_options')) {
            // Never overwrite the password of someone who administers the site.
            return ['status' => 'forbidden', 'error' => 'This learner account holds platform privileges.'];
        }

        // PA47 — an already-provisioned login is only re-issued on an explicit
        // rotate, so the roster button can never accidentally invalidate the
        // credentials a family is already using to sign in.
        $issuedAt = get_user_meta($userId, 'aba_credential_issued_at', true);
        $hasMarker = ($issuedAt !== '' && $issuedAt !== false);
        // Defense in depth: also treat a login as issued when it already carries
        // the 'student' role — the role provisioning grants — so a pre-PA47
        // learner (whose marker migration v8 backfills) is never silently
        // rotatable even in the window before its marker is stamped.
        $alreadyIssued = $hasMarker || in_array('student', $user->roles, true);
        if ($alreadyIssued && !$rotate) {
            return ['status' => 'already', 'username' => $user->user_login, 'issuedAt' => $hasMarker ? (string) $issuedAt : ''];
        }
        $wasRotated = $alreadyIssued;

        if ($requestedPassword !== '') {
            if (strlen($requestedPassword) < 8) {
                return ['status' => 'invalid', 'field' => 'password', 'error' => 'Password must be at least 8 characters.'];
            }
            $password = $requestedPassword;
        } else {
            $password = $this->generateLearnerPassword();
        }
        $update = ['ID' => $userId, 'user_pass' => $password];
        // The seeder-created 'student' role is what the front-end guard keys on;
        // where it does not exist yet the subscriber keeps ABA_CAP_STUDENT via
        // the role-capability map, so login still works either way.
        if (get_role('student') !== null && !in_array('student', $user->roles, true)) {
            $update['role'] = 'student';
        }
        // Keep the display name in sync with the roster (Họ + Tên convention).
        $display = trim(($row['last_name'] ?? '') . ' ' . ($row['first_name'] ?? ''));
        if ($display !== '') {
            $update['display_name'] = $display;
        }
        if (is_wp_error(wp_update_user($update))) {
            return ['status' => 'invalid', 'field' => 'user', 'error' => 'Could not update the learner account.'];
        }

        $fresh = get_userdata($userId);
        // PA51 — a rotated credential must actually take effect. WordPress
        // validates an app password against its own hash and neither
        // wp_update_user() nor wp_set_password() clears session tokens, so
        // without this the person holding the OLD handout keeps working — the
        // reset would be cosmetic. Both channels are ended before the new
        // secret is handed over, and the count is recorded so the staff screen
        // can say truthfully that other devices were signed out (§8).
        $ended = $this->sessions->revokeAll($userId);
        // PA47 — stamp (or refresh) the one-time-issue marker.
        update_user_meta($userId, 'aba_credential_issued_at', current_time('mysql', 1));
        // PA50 — a newly (re)issued handout is a fresh shared secret the learner
        // has NOT personally chosen, so clear any prior self-change stamp. The
        // rotation window now measures from this issue; without the clear, an old
        // self-change could keep an already-rotated-away credential looking fresh.
        delete_user_meta($userId, 'aba_password_self_changed_at');
        // PA48 — a GENERATED handout password is a shared secret on paper, so the
        // learner must replace it on first sign-in. A staff-chosen password was
        // typed by a human as the intended credential, so no forced change; and
        // any successful issue clears a stale flag.
        $mustChange = $requestedPassword === '';
        if ($mustChange) {
            update_user_meta($userId, 'aba_must_change_password', '1');
        } else {
            delete_user_meta($userId, 'aba_must_change_password');
        }
        // PA49 — record the sensitive action on the append-only ledger. Only
        // non-secret context is stored (login name, generated vs staff-typed);
        // the password never touches the row. First issue vs explicit rotate are
        // distinct events so the history reads truthfully (§12).
        $login = $fresh ? $fresh->user_login : '';
        $sessionsRevoked = (int) $ended['appPasswords'] + (int) $ended['sessions'];
        if ($wasRotated) {
            $this->audit->recordReset($actorUserId, $studentId, $login, $mustChange, $sessionsRevoked);
        } else {
            $this->audit->recordIssue($actorUserId, $studentId, $login, $mustChange, $sessionsRevoked);
        }
        return [
            'status' => 'provisioned',
            'username' => $login,
            'password' => $password,
            'rotated' => $wasRotated,
            'mustChangePassword' => $mustChange,
            // PA51 — how many live logins this rotation ended, so staff know a
            // device they did not touch has been signed out.
            'sessionsRevoked' => $sessionsRevoked,
            'student' => $this->students->toApiShape((array) $this->students->findById($studentId)),
        ];
    }

    /**
     * PA50 — credential rotation policy, evaluated at sign-in. A learner's
     * login is forced to a change once it has been in force longer than
     * CREDENTIAL_MAX_AGE_DAYS since the password was last set. The anchor is the
     * later of the staff issue/reset marker and the learner's own last change,
     * so a genuine self-service change buys a fresh window rather than being
     * expired by an old staff stamp. On lapse we arm the existing
     * aba_must_change_password flag (the PA48 screen + guard already route on
     * it — no new client flow) and record one credential_expired event, so the
     * reminder is both enforced and auditable (§8, §12). Staff/parent logins
     * carry no issue marker and are therefore never touched here.
     *
     * @return bool true when this call armed a previously-fresh credential.
     */
    public function enforceCredentialExpiry(int $userId): bool {
        if ($userId <= 0) {
            return false;
        }
        $issued = (string) get_user_meta($userId, 'aba_credential_issued_at', true);
        if ($issued === '') {
            return false; // no learner credential issued → nothing to expire
        }
        // Already pending a change (a fresh handout, or an earlier lapse) — do
        // not re-arm or double-record the event.
        if ((string) get_user_meta($userId, 'aba_must_change_password', true) === '1') {
            return false;
        }
        $self = (string) get_user_meta($userId, 'aba_password_self_changed_at', true);
        $lastSet = max($this->gmtTimestamp($issued), $this->gmtTimestamp($self));
        if (time() - $lastSet <= self::CREDENTIAL_MAX_AGE_DAYS * DAY_IN_SECONDS) {
            return false; // still inside the rotation window
        }
        update_user_meta($userId, 'aba_must_change_password', '1');
        $this->audit->recordExpired($userId, Authorization::studentIdForUser($userId));
        return true;
    }

    /**
     * PA52 — how many logins are live on this learner's account right now.
     * Counts only; a token never leaves the server (§8). A learner with no
     * provisioned login has nothing live, which is a fact worth stating rather
     * than an error.
     *
     * @return array{status:string,studentId:int,appPasswords:int,sessions:int,total:int}
     */
    public function sessionInventory(int $studentId): array {
        $row = $this->students->findById($studentId);
        if (!$row) {
            return ['status' => 'notFound', 'studentId' => $studentId, 'appPasswords' => 0, 'sessions' => 0, 'total' => 0];
        }
        $userId = (int) ($row['user_id'] ?? 0);
        $inventory = $userId > 0
            ? $this->sessions->inventory($userId)
            : ['appPasswords' => 0, 'sessions' => 0, 'total' => 0];
        return ['status' => 'ok', 'studentId' => $studentId] + $inventory;
    }

    /**
     * PA52 — end every live login of this learner (both credential channels)
     * because a parent believes the secret has been shared or seen. The caller
     * has already been authorised against this student (§8); revoking is
     * strictly weaker than the reset right the same actors already hold, so it
     * needs no separate capability.
     *
     * An event is written only when something actually ended — a click on an
     * empty account must not litter the family's history with "revoked 0".
     *
     * @return array{status:string,studentId:int,revoked:int,appPasswords:int,sessions:int,total:int}
     */
    public function revokeSessions(int $actorUserId, int $studentId): array {
        $row = $this->students->findById($studentId);
        if (!$row) {
            return ['status' => 'notFound', 'studentId' => $studentId, 'revoked' => 0, 'appPasswords' => 0, 'sessions' => 0, 'total' => 0];
        }
        $userId = (int) ($row['user_id'] ?? 0);
        if ($userId <= 0) {
            return ['status' => 'noAccount', 'studentId' => $studentId, 'revoked' => 0, 'appPasswords' => 0, 'sessions' => 0, 'total' => 0];
        }
        $ended = $this->sessions->revokeAll($userId);
        $revoked = (int) $ended['appPasswords'] + (int) $ended['sessions'];
        if ($revoked > 0) {
            $this->audit->recordSessionsRevoked($actorUserId, $studentId, (int) $ended['appPasswords'], (int) $ended['sessions']);
        }
        return ['status' => 'revoked', 'studentId' => $studentId, 'revoked' => $revoked] + $this->sessions->inventory($userId);
    }

    /**
     * PA56 — the live devices of a learner's account, listed for the adult
     * responsible for them. Reuses PA55's projection, so the payload carries a
     * handle, a label and two timestamps and never a token, uuid or hash (§8).
     *
     * No row is marked "current": the caller is asking about somebody else's
     * account, so none of these devices is the one making the request — a
     * "current" flag here would point at a stranger's phone.
     *
     * @return array{status:string,studentId:int,devices:array<int,array<string,mixed>>,maxDevices:int}
     */
    public function deviceList(int $studentId): array {
        $row = $this->students->findById($studentId);
        if (!$row) {
            return ['status' => 'notFound', 'studentId' => $studentId, 'devices' => [], 'maxDevices' => CredentialSessionService::MAX_DEVICES];
        }
        $userId = (int) ($row['user_id'] ?? 0);
        return [
            'status'     => 'ok',
            'studentId'  => $studentId,
            'devices'    => $userId > 0 ? $this->sessions->devices($userId) : [],
            'maxDevices' => CredentialSessionService::MAX_DEVICES,
        ];
    }

    /**
     * PA56 — end ONE of the learner's devices by its handle, leaving every other
     * device signed in. The caller has already been authorised against this
     * student (§8); a per-device sign-out is strictly weaker than the PA52
     * "end everything" right those same actors hold, so it needs no new
     * capability.
     *
     * The label is read BEFORE the delete, because after it the token is gone
     * and the audit trail could no longer say which device was ended. A handle
     * belonging to another account, or to a device that already signed out, is
     * 'deviceNotFound' — never someone else's token, never a "not exists" leak
     * that distinguishes the two cases.
     *
     * @return array{status:string,studentId:int,label:string,count:int}
     */
    public function revokeStudentDevice(int $actorUserId, int $studentId, string $handle): array {
        $row = $this->students->findById($studentId);
        if (!$row) {
            return ['status' => 'notFound', 'studentId' => $studentId, 'label' => '', 'count' => 0];
        }
        $userId = (int) ($row['user_id'] ?? 0);
        $handle = trim($handle);
        if ($userId <= 0 || $handle === '') {
            return ['status' => 'deviceNotFound', 'studentId' => $studentId, 'label' => '', 'count' => 0];
        }

        $label = '';
        foreach ($this->sessions->devices($userId) as $device) {
            if (hash_equals((string) $device['handle'], $handle)) {
                $label = (string) $device['label'];
                break;
            }
        }
        if ($this->sessions->revokeDevice($userId, $handle) !== 'revoked') {
            return ['status' => 'deviceNotFound', 'studentId' => $studentId, 'label' => '', 'count' => count($this->sessions->devices($userId))];
        }
        // Only a real ending reaches the ledger — a click on a stale list must
        // not litter the family's history with a sign-out that never happened.
        $this->audit->recordDeviceRevoked($actorUserId, $studentId, $label);
        return ['status' => 'revoked', 'studentId' => $studentId, 'label' => $label, 'count' => count($this->sessions->devices($userId))];
    }

    /** A GMT 'Y-m-d H:i:s' user-meta datetime → a UTC epoch (0 when empty/bad). */
    private function gmtTimestamp(string $mysqlGmt): int {
        if ($mysqlGmt === '') {
            return 0;
        }
        $ts = strtotime($mysqlGmt . ' UTC');
        return $ts === false ? 0 : $ts;
    }

    /**
     * A hand-readable learner password: Aba-<4 digits>-<3 letters>. Long enough
     * for the min-8 rule, free of look-alike glyphs, easy to read out to a
     * parent over the phone.
     */
    private function generateLearnerPassword(): string {
        $letters = '';
        for ($i = 0; $i < 3; $i++) {
            $letters .= chr(random_int(ord('c'), ord('x'))); // lowercase, no q/weird
        }
        return sprintf('Aba-%04d-%s', random_int(1000, 9999), $letters);
    }

    /**
     * Shared §8 gate for edit/status: is this learner inside this actor's scope?
     * Platform → always. Class-assigned staff → only their own class.
     * School-assigned staff → only their own school. Returns null when allowed,
     * otherwise a forbidden/notFound-shaped error the controller maps.
     */
    private function editGate(int $actorUserId, array $student): ?array {
        if (_aba_is_platform_user($actorUserId)) {
            return null;
        }
        $classId = Authorization::assignedClassId($actorUserId);
        $schoolId = Authorization::assignedSchoolId($actorUserId);
        if ($classId > 0) {
            return (int) $student['class_id'] === $classId
                ? null
                : ['status' => 'forbidden', 'error' => 'That learner is outside your class.'];
        }
        if ($schoolId > 0) {
            return (int) $student['school_id'] === $schoolId
                ? null
                : ['status' => 'forbidden', 'error' => 'That learner is outside your school.'];
        }
        return ['status' => 'forbidden', 'error' => 'Your account is not assigned to a school.'];
    }

    /** Grade bands mirror the assessment groups so recommendations align (§13). */
    private static function gradeGroupFor(int $grade): string {
        if ($grade <= 3) return '1_3';
        if ($grade <= 6) return '4_6';
        if ($grade <= 9) return '7_9';
        return '10_12';
    }

    private function generateUniqueCode(): string {
        $year = (int) gmdate('Y');
        for ($attempt = 0; $attempt < 25; $attempt++) {
            $candidate = sprintf('ABA-%d-%03d', $year, random_int(100, 999));
            if (!$this->students->codeExists($candidate)) {
                return $candidate;
            }
        }
        // Astronomically unlikely fallback; a timestamp suffix is still unique.
        return sprintf('ABA-%d-%d', $year, time());
    }

    /**
     * Provision (or reuse) the data-only wp_user that anchors a learner row. The
     * login is derived from the code so it stays stable and human-traceable.
     */
    private function ensureLearnerUser(string $code, string $lastName, string $firstName): ?int {
        $login = 'aba_' . strtolower(preg_replace('/[^A-Za-z0-9]/', '', $code));
        $existing = get_user_by('login', $login);
        if ($existing) {
            return (int) $existing->ID;
        }
        $userId = wp_insert_user([
            'user_login' => $login,
            'user_pass' => wp_generate_password(32, true, true),
            'user_email' => $login . '@aba.local',
            'display_name' => trim($lastName . ' ' . $firstName),
            'role' => 'subscriber',
        ]);
        return is_wp_error($userId) ? null : (int) $userId;
    }

    /** School + nested class options for a platform admin's destination picker. */
    private function platformSchoolOptions(): array {
        $schools = [];
        foreach ($this->schools->listSchools() as $school) {
            $schools[] = [
                'id' => (int) $school['id'],
                'name' => $school['name'],
                'classes' => array_map(
                    static fn($c) => ['id' => (int) $c['id'], 'name' => $c['name']],
                    $this->schools->listClasses((int) $school['id'])
                ),
            ];
        }
        return $schools;
    }
}
