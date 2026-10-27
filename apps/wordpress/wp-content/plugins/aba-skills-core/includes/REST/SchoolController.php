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
use ABA\Core\Auth\Authorization;
use WP_REST_Request;
use WP_REST_Response;
use function ABA\Core\Auth\aba_error;

class SchoolController {
    private StudentRepository $studentRepo;

    public function __construct() {
        $this->studentRepo = new StudentRepository();
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
}
