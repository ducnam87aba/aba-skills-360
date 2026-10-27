<?php
/**
 * Class roster reads (School domain, PA26).
 *
 * The student row only carries a bare `class_id` — the class's real identity
 * (code, name, academic year) lives in `wp_aba_classes` (§6: transactional
 * lookups use custom tables, not postmeta). There is no dedicated class-teacher
 * column: a class's teacher is exactly the staff whose PA24 scope meta
 * (`aba_class_id`) points at it and who holds the roster capability, so the
 * homeroom teacher is *derived* from that existing data rather than a new table.
 *
 * This repository is read-only persistence; it holds no business logic (§4).
 *
 * @package ABA_Skills_Core
 */

namespace ABA\Core\Repositories;

if (!defined('ABSPATH')) {
    exit;
}

class ClassRepository {
    private string $classTable;

    public function __construct() {
        global $wpdb;
        $this->classTable = $wpdb->prefix . 'aba_classes';
    }

    /**
     * One active class row projected for the API, or null when the id is unknown
     * or the class is inactive. Never leaks another school's data: the caller
     * passes a class id already resolved through an authorised student.
     */
    public function find(int $classId): ?array {
        if ($classId <= 0) {
            return null;
        }
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT id, school_id, code, name, grade_group, academic_year
             FROM {$this->classTable} WHERE id = %d AND status = 'active'",
            $classId
        ), ARRAY_A);
        if (!$row) {
            return null;
        }
        return [
            'id' => (int) $row['id'],
            'schoolId' => (int) $row['school_id'],
            'code' => (string) $row['code'],
            'name' => (string) $row['name'],
            'gradeGroup' => (string) $row['grade_group'],
            'academicYear' => (string) $row['academic_year'],
        ];
    }

    /**
     * The class's teacher, derived from the PA24 scope model: a staff user whose
     * `aba_class_id` meta equals this class and who holds the roster capability.
     * Deterministic when several qualify (lowest user id). Null when unassigned —
     * an empty staff meta must not fabricate a teacher (§8 least privilege).
     *
     * @return array{userId:int,displayName:string}|null
     */
    public function findTeacher(int $classId): ?array {
        if ($classId <= 0) {
            return null;
        }
        global $wpdb;
        $um = $wpdb->prefix . 'usermeta';
        $candidates = $wpdb->get_col($wpdb->prepare(
            "SELECT user_id FROM {$um}
             WHERE meta_key = 'aba_class_id' AND meta_value = %d
             ORDER BY user_id ASC",
            $classId
        ));
        foreach ($candidates as $uid) {
            $uid = (int) $uid;
            if ($uid > 0 && user_can($uid, \ABA\Core\Auth\ABA_CAP_ROSTER)) {
                $user = get_userdata($uid);
                if ($user) {
                    return ['userId' => $uid, 'displayName' => (string) $user->display_name];
                }
            }
        }
        return null;
    }

    /**
     * The full class context a parent is allowed to see about their child's
     * class: the class row merged with its derived teacher. Null when the child
     * has no (valid) class, so the caller simply omits the field.
     */
    public function contextFor(int $classId): ?array {
        $class = $this->find($classId);
        if ($class === null) {
            return null;
        }
        $teacher = $this->findTeacher($classId);
        $class['teacherId'] = $teacher['userId'] ?? null;
        $class['teacherName'] = $teacher['displayName'] ?? null;
        return $class;
    }
}
