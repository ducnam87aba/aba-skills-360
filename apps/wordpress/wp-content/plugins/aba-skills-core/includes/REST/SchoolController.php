<?php
/**
 * School scope endpoints (PA23).
 *
 * Gives school staff (a teacher carrying the aba_manage_students capability and
 * assigned to a school) a roster view scoped strictly to their own school, and a
 * single-student read that reuses the now school-aware requireViewStudent gate.
 *
 * This is a thin controller (§4): permission is decided in Authorization, the
 * persistence query lives in StudentRepository. No raw SQL here. Cross-school
 * access is impossible by construction — the school id comes from the caller's
 * own assignment, never a client-supplied id (§8).
 *
 * @package ABA_Skills_Core
 */

namespace ABA\Core\REST;

use ABA\Core\Repositories\StudentRepository;
use ABA\Core\Services\StudentService;
use ABA\Core\Services\CredentialAuditService;
use ABA\Core\Auth\Authorization;
use WP_REST_Request;
use WP_REST_Response;
use function ABA\Core\Auth\aba_error;

class SchoolController {
    private StudentRepository $studentRepo;
    private StudentService $studentService;
    private CredentialAuditService $audit;

    public function __construct() {
        $this->studentRepo = new StudentRepository();
        $this->studentService = new StudentService();
        $this->audit = new CredentialAuditService();
    }

    public function registerRoutes(): void {
        $namespace = 'aba/v1';

        // GET /aba/v1/school/roster — the caller's own-school roster.
        register_rest_route($namespace, '/school/roster', [
            'methods' => 'GET',
            'callback' => [$this, 'getRoster'],
            'permission_callback' => [Authorization::class, 'requireRoster'],
        ]);

        // GET /aba/v1/school/students/{id} — one student, gated by school scope.
        register_rest_route($namespace, '/school/students/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => [$this, 'getStudent'],
            'permission_callback' => 'is_user_logged_in',
        ]);

        // PA24 — GET /aba/v1/class/roster — the caller's own-class roster only.
        register_rest_route($namespace, '/class/roster', [
            'methods' => 'GET',
            'callback' => [$this, 'getClassRoster'],
            'permission_callback' => [Authorization::class, 'requireRoster'],
        ]);

        // PA44 — GET /aba/v1/school/context — the caller's scope, its names and
        // current learners, plus destination options for a platform admin. Powers
        // the staff administration screen; scope is derived server-side (§8).
        // PA45 — a platform admin may point the roster read at one school.
        register_rest_route($namespace, '/school/context', [
            'methods' => 'GET',
            'callback' => [$this, 'getRosterContext'],
            'permission_callback' => [Authorization::class, 'requireRoster'],
            'args' => [
                'schoolId' => ['required' => false, 'default' => 0, 'sanitize_callback' => 'absint'],
                'classId' => ['required' => false, 'default' => 0, 'sanitize_callback' => 'absint'],
            ],
        ]);

        // PA44 — POST /aba/v1/school/students — create a learner. requireRoster
        // only gates the *entry point*; who may create *where* is decided in the
        // service from the caller's own assignment, never from these args (§8).
        register_rest_route($namespace, '/school/students', [
            'methods' => 'POST',
            'callback' => [$this, 'createStudent'],
            'permission_callback' => [Authorization::class, 'requireRoster'],
            'args' => [
                'firstName' => ['required' => true, 'sanitize_callback' => 'sanitize_text_field'],
                'lastName' => ['required' => true, 'sanitize_callback' => 'sanitize_text_field'],
                'gradeLevel' => ['required' => true, 'sanitize_callback' => 'absint'],
                'studentCode' => ['required' => false, 'sanitize_callback' => 'sanitize_text_field'],
                'schoolId' => ['required' => false, 'default' => 0, 'sanitize_callback' => 'absint'],
                'classId' => ['required' => false, 'default' => 0, 'sanitize_callback' => 'absint'],
            ],
        ]);

        // PA45 — PUT /aba/v1/school/students/{id} — edit a learner. Every arg is
        // optional (partial update); requireRoster only gates the entry point,
        // and the service re-checks that THIS learner lives in the caller's
        // scope, so an id from another school never leaks or mutates (§8).
        register_rest_route($namespace, '/school/students/(?P<id>\d+)', [
            'methods' => 'PUT',
            'callback' => [$this, 'updateStudent'],
            'permission_callback' => [Authorization::class, 'requireRoster'],
            'args' => [
                'firstName' => ['required' => false, 'sanitize_callback' => 'sanitize_text_field'],
                'lastName' => ['required' => false, 'sanitize_callback' => 'sanitize_text_field'],
                'gradeLevel' => ['required' => false, 'sanitize_callback' => 'absint'],
                'classId' => ['required' => false, 'sanitize_callback' => 'absint'],
            ],
        ]);

        // PA45 — POST /aba/v1/school/students/{id}/status — soft archive /
        // reactivate. Deleting a learner would destroy measurement history
        // (§10/§11), so the API exposes a status transition, not a DELETE.
        register_rest_route($namespace, '/school/students/(?P<id>\d+)/status', [
            'methods' => 'POST',
            'callback' => [$this, 'setStudentStatus'],
            'permission_callback' => [Authorization::class, 'requireRoster'],
            'args' => [
                'status' => ['required' => true, 'enum' => ['active', 'inactive'], 'sanitize_callback' => 'sanitize_key'],
            ],
        ]);

        // PA46 — POST /aba/v1/school/students/{id}/credentials — issue the
        // learner's sign-in password. The plaintext rides back in this ONE
        // response only — WP keeps the hash — so staff hand it to the family in
        // person; nobody can later READ a password out of the API.
        // PA47 — re-issuing (rotating) is now an EXPLICIT ?rotate=true call; a
        // plain issue on an already-provisioned login answers 409 so the roster
        // button can never silently invalidate credentials in use.
        register_rest_route($namespace, '/school/students/(?P<id>\d+)/credentials', [
            'methods' => 'POST',
            'callback' => [$this, 'provisionCredentials'],
            'permission_callback' => [Authorization::class, 'requireRoster'],
            'args' => [
                'password' => ['required' => false, 'sanitize_callback' => 'sanitize_text_field'],
                'rotate' => ['required' => false, 'default' => false, 'sanitize_callback' => 'rest_sanitize_boolean'],
            ],
        ]);

        // PA49 — GET /aba/v1/students/{id}/credential-events — the learner's
        // append-only account audit (who issued / reset / changed the login).
        // Gated by requireViewStudent so staff (school/class scope), platform,
        // the linked parent and the learner themselves can all read it — never
        // an unrelated account, and never by trusting the id in the URL (§8).
        // PA61 — ?kind= narrows the same trail to one dimension (account action,
        // device, sign-in, lockout). Validated HERE so an unknown value is a 400
        // the client can act on, instead of an empty list that looks like
        // "nothing ever happened to this account".
        register_rest_route($namespace, '/students/(?P<id>\d+)/credential-events', [
            'methods' => 'GET',
            'callback' => [$this, 'getCredentialEvents'],
            'permission_callback' => 'is_user_logged_in',
            'args' => [
                'kind' => [
                    'required' => false,
                    'default' => '',
                    'sanitize_callback' => 'sanitize_key',
                    'validate_callback' => static function ($value): bool {
                        return $value === '' || in_array($value, CredentialAuditService::KINDS, true);
                    },
                ],
            ],
        ]);

        // PA52 — the learner's LIVE logins (counts only, never a token) and the
        // parent's "sign out every device". Same gate as the audit trail: whoever
        // may read the account may see and end its sessions, because revoking is
        // strictly weaker than the password-reset right those same actors hold.
        register_rest_route($namespace, '/students/(?P<id>\d+)/sessions', [
            'methods' => 'GET',
            'callback' => [$this, 'getSessions'],
            'permission_callback' => 'is_user_logged_in',
        ]);

        register_rest_route($namespace, '/students/(?P<id>\d+)/sessions/revoke', [
            'methods' => 'POST',
            'callback' => [$this, 'revokeSessions'],
            'permission_callback' => 'is_user_logged_in',
        ]);

        // PA56 — the learner's live devices, named. Same gate as the PA52 counts
        // (whoever may read the account may see which devices hold it) and the
        // same non-secret projection as PA55's self-service list: handle, label
        // and timestamps, never a token, uuid or hash (§8).
        register_rest_route($namespace, '/students/(?P<id>\d+)/devices', [
            'methods' => 'GET',
            'callback' => [$this, 'getDevices'],
            'permission_callback' => 'is_user_logged_in',
        ]);

        register_rest_route($namespace, '/students/(?P<id>\d+)/devices/(?P<handle>[A-Za-z0-9]{16,64})', [
            'methods' => 'DELETE',
            'callback' => [$this, 'revokeDevice'],
            'permission_callback' => 'is_user_logged_in',
        ]);
    }

    public function getRoster(WP_REST_Request $request): WP_REST_Response|\WP_Error {
        // A class-assigned staff is scoped to their class, not the whole school;
        // refuse the broader view rather than over-expose it (§8, PA24).
        if (Authorization::assignedClassId(\get_current_user_id()) > 0) {
            return aba_error('ABA_CLASS_SCOPED', 'Class-scoped staff must use /class/roster.', 403);
        }
        // The school is derived from the acting user, never from the request.
        $schoolId = Authorization::assignedSchoolId(\get_current_user_id());
        $students = $this->studentRepo->listBySchool($schoolId);
        return rest_ensure_response([
            'schoolId' => $schoolId,
            'count' => count($students),
            'students' => $students,
        ]);
    }

    public function getClassRoster(WP_REST_Request $request): WP_REST_Response|\WP_Error {
        // The class is derived from the acting user's own assignment (§8).
        $classId = Authorization::assignedClassId(\get_current_user_id());
        $students = $this->studentRepo->listByClass($classId);
        return rest_ensure_response([
            'classId' => $classId,
            'count' => count($students),
            'students' => $students,
        ]);
    }

    public function getStudent(WP_REST_Request $request): WP_REST_Response|\WP_Error {
        $studentId = (int) $request->get_param('id');
        $auth = Authorization::requireViewStudent($studentId);
        if (is_wp_error($auth)) {
            return $auth;
        }
        $student = $this->studentRepo->findById($studentId);
        if (!$student) {
            return aba_error('ABA_STUDENT_NOT_FOUND', 'Student not found.', 404);
        }
        return rest_ensure_response($this->studentRepo->toApiShape($student));
    }

    /**
     * PA44 — staff roster context. The acting user id is read from the session
     * (never a client value), so the returned scope cannot be forged (§8).
     */
    public function getRosterContext(WP_REST_Request $request): WP_REST_Response|\WP_Error {
        return rest_ensure_response($this->studentService->context(\get_current_user_id(), [
            'schoolId' => (int) $request->get_param('schoolId'),
            'classId' => (int) $request->get_param('classId'),
        ]));
    }

    /**
     * PA44 — create a learner. The service returns a status-tagged result; this
     * controller only maps it to the contract's error/response shape (§4).
     */
    public function createStudent(WP_REST_Request $request): WP_REST_Response|\WP_Error {
        $result = $this->studentService->create(\get_current_user_id(), [
            'firstName' => (string) $request->get_param('firstName'),
            'lastName' => (string) $request->get_param('lastName'),
            'gradeLevel' => (int) $request->get_param('gradeLevel'),
            'studentCode' => (string) ($request->get_param('studentCode') ?? ''),
            'schoolId' => (int) $request->get_param('schoolId'),
            'classId' => (int) $request->get_param('classId'),
        ]);
        switch ($result['status']) {
            case 'created':
                return new \WP_REST_Response($result['student'], 201);
            case 'conflict':
                return aba_error('ABA_STUDENT_CODE_TAKEN', (string) ($result['error'] ?? 'Student code already taken.'), 409, ['field' => $result['field'] ?? null]);
            case 'forbidden':
                return aba_error('ABA_FORBIDDEN', (string) ($result['error'] ?? 'Not allowed.'), 403);
            default:
                return aba_error('ABA_VALIDATION_ERROR', (string) ($result['error'] ?? 'Invalid input.'), 400, ['field' => $result['field'] ?? null]);
        }
    }

    /**
     * PA45 — edit a learner. Only keys actually present in the request go to the
     * service, so "absent" keeps the stored value instead of clearing it.
     */
    public function updateStudent(WP_REST_Request $request): WP_REST_Response|\WP_Error {
        $input = [];
        foreach (['firstName', 'lastName', 'gradeLevel', 'classId'] as $key) {
            if ($request->has_param($key)) {
                $input[$key] = $request->get_param($key);
            }
        }
        return $this->mapEditResult($this->studentService->update(
            \get_current_user_id(),
            (int) $request->get_param('id'),
            $input
        ));
    }

    /** PA45 — archive/reactivate; same result vocabulary as the edit route. */
    public function setStudentStatus(WP_REST_Request $request): WP_REST_Response|\WP_Error {
        return $this->mapEditResult($this->studentService->setStatus(
            \get_current_user_id(),
            (int) $request->get_param('id'),
            (string) $request->get_param('status')
        ));
    }

    /**
     * PA46 — provision/rotate a learner login. Success answers 201 with the
     * one-time credentials; everything else maps like the edit vocabulary.
     */
    public function provisionCredentials(WP_REST_Request $request): WP_REST_Response|\WP_Error {
        $result = $this->studentService->provisionLogin(
            \get_current_user_id(),
            (int) $request->get_param('id'),
            (string) ($request->get_param('password') ?? ''),
            (bool) $request->get_param('rotate')
        );
        switch ($result['status']) {
            case 'provisioned':
                return new \WP_REST_Response([
                    'username' => $result['username'],
                    'password' => $result['password'],
                    'rotated' => !empty($result['rotated']),
                    // PA48 — tells the roster UI this handout password is forced
                    // to change on the learner's first sign-in.
                    'mustChangePassword' => !empty($result['mustChangePassword']),
                    'student' => $result['student'],
                ], 201);
            case 'already':
                // PA47 — first-issue guard: the login already exists. Return the
                // (safe) username so the UI can switch to the reset action, but
                // never a password.
                return aba_error('ABA_CREDENTIAL_EXISTS', 'Account already provisioned. Use reset to rotate the password.', 409, ['username' => $result['username']]);
            case 'notFound':
                return aba_error('ABA_STUDENT_NOT_FOUND', (string) ($result['error'] ?? 'Student not found.'), 404);
            case 'forbidden':
                return aba_error('ABA_FORBIDDEN', (string) ($result['error'] ?? 'Not allowed.'), 403);
            default:
                return aba_error('ABA_VALIDATION_ERROR', (string) ($result['error'] ?? 'Invalid input.'), 400, ['field' => $result['field'] ?? null]);
        }
    }

    /**
     * PA49 — the learner's credential audit history, newest first. Re-checks
     * view permission here too (the route callback only asserted login) before
     * returning any data, then reads through the service so this controller
     * holds no query logic (§4). The projection never contains a secret.
     *
     * PA61 — `?kind=` filters the same trail (validated by the route's args), so
     * the screen can show "only sign-ins" or "only lockouts" without a second
     * endpoint that would need its own permission gate.
     */
    public function getCredentialEvents(WP_REST_Request $request): WP_REST_Response|\WP_Error {
        $studentId = (int) $request->get_param('id');
        $auth = Authorization::requireViewStudent($studentId);
        if (is_wp_error($auth)) {
            return $auth;
        }
        if (!$this->studentRepo->findById($studentId)) {
            return aba_error('ABA_STUDENT_NOT_FOUND', 'Student not found.', 404);
        }
        return rest_ensure_response([
            'studentId' => $studentId,
            'events' => $this->audit->listFor(
                $studentId,
                \get_current_user_id() ?: null,
                (string) $request->get_param('kind')
            ),
        ]);
    }

    /**
     * PA52 — GET /students/{id}/sessions. How many logins are live on this
     * learner's account, as counts only: a parent can see that a device is
     * signed in without the response ever carrying a token, uuid or hash (§8).
     */
    public function getSessions(WP_REST_Request $request): WP_REST_Response|\WP_Error {
        $studentId = (int) $request->get_param('id');
        $auth = Authorization::requireViewStudent($studentId);
        if (is_wp_error($auth)) {
            return $auth;
        }
        $result = $this->studentService->sessionInventory($studentId);
        if ($result['status'] === 'notFound') {
            return aba_error('ABA_STUDENT_NOT_FOUND', 'Student not found.', 404);
        }
        return rest_ensure_response($this->sessionShape($result));
    }

    /**
     * PA52 — POST /students/{id}/sessions/revoke. Ends every live login of the
     * account: the app tokens the headless client mints and the cookie sessions
     * on any device. The family then has to sign in again with the password
     * they know, which is exactly what a suspected leak requires. Idempotent —
     * running it on an account with nothing live is a successful no-op.
     */
    public function revokeSessions(WP_REST_Request $request): WP_REST_Response|\WP_Error {
        $studentId = (int) $request->get_param('id');
        $auth = Authorization::requireViewStudent($studentId);
        if (is_wp_error($auth)) {
            return $auth;
        }
        $result = $this->studentService->revokeSessions(\get_current_user_id(), $studentId);
        if ($result['status'] === 'notFound') {
            return aba_error('ABA_STUDENT_NOT_FOUND', 'Student not found.', 404);
        }
        return rest_ensure_response($this->sessionShape($result) + ['revoked' => (int) $result['revoked']]);
    }

    /** Shared projection for both session endpoints — counts plus identity, never a token. */
    private function sessionShape(array $result): array {
        return [
            'studentId'    => (int) $result['studentId'],
            'total'        => (int) $result['total'],
            'sessions'     => (int) $result['sessions'],
            'appPasswords' => (int) $result['appPasswords'],
        ];
    }

    /**
     * PA56 — GET /students/{id}/devices. Which devices hold this learner's
     * account, named the way the family can act on ("Điện thoại", not a raw
     * user agent). Authorisation is the same per-request check every student
     * projection makes, so a parent sees only their own child and staff only
     * their class/school (§8). The handle is a one-way token of the device, so
     * the UI can end one row without ever learning the credential.
     */
    public function getDevices(WP_REST_Request $request): WP_REST_Response|\WP_Error {
        $studentId = (int) $request->get_param('id');
        $auth = Authorization::requireViewStudent($studentId);
        if (is_wp_error($auth)) {
            return $auth;
        }
        $result = $this->studentService->deviceList($studentId);
        if ($result['status'] === 'notFound') {
            return aba_error('ABA_STUDENT_NOT_FOUND', 'Student not found.', 404);
        }
        return rest_ensure_response([
            'studentId'  => (int) $result['studentId'],
            'devices'    => $result['devices'],
            'count'      => count($result['devices']),
            'maxDevices' => (int) $result['maxDevices'],
        ]);
    }

    /**
     * PA56 — DELETE /students/{id}/devices/{handle}. Ends one device and leaves
     * the family's others signed in. `label` is echoed back so the panel can say
     * *which* device it ended, and `count` is the remaining total, so a stale
     * list corrects itself without a second round-trip.
     */
    public function revokeDevice(WP_REST_Request $request): WP_REST_Response|\WP_Error {
        $studentId = (int) $request->get_param('id');
        $auth = Authorization::requireViewStudent($studentId);
        if (is_wp_error($auth)) {
            return $auth;
        }
        $result = $this->studentService->revokeStudentDevice(
            \get_current_user_id(),
            $studentId,
            (string) ($request->get_param('handle') ?? '')
        );
        if ($result['status'] === 'notFound') {
            return aba_error('ABA_STUDENT_NOT_FOUND', 'Student not found.', 404);
        }
        if ($result['status'] !== 'revoked') {
            return aba_error('ABA_DEVICE_NOT_FOUND', 'This device is not signed in.', 404);
        }
        return rest_ensure_response([
            'studentId' => (int) $result['studentId'],
            'revoked'   => 1,
            'label'     => (string) $result['label'],
            'count'     => (int) $result['count'],
        ]);
    }

    /** Shared status→HTTP mapping for edit/update results (§4: no logic here). */
    private function mapEditResult(array $result): WP_REST_Response|\WP_Error {
        switch ($result['status']) {
            case 'updated':
                return new \WP_REST_Response($result['student'], 200);
            case 'notFound':
                return aba_error('ABA_STUDENT_NOT_FOUND', (string) ($result['error'] ?? 'Student not found.'), 404);
            case 'forbidden':
                return aba_error('ABA_FORBIDDEN', (string) ($result['error'] ?? 'Not allowed.'), 403);
            default:
                return aba_error('ABA_VALIDATION_ERROR', (string) ($result['error'] ?? 'Invalid input.'), 400, ['field' => $result['field'] ?? null]);
        }
    }
}
