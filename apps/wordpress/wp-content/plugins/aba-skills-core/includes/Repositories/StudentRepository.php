<?php

namespace ABA\Core\Repositories;

class StudentRepository {
    private string $table;

    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'aba_students';
    }

    public function findById(int $id): ?array {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table} WHERE id = %d", $id), ARRAY_A);
        return $row ?: null;
    }

    public function findByUserId(int $userId): ?array {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table} WHERE user_id = %d", $userId), ARRAY_A);
        return $row ?: null;
    }

    public function getChildrenForParent(int $parentUserId): array {
        global $wpdb;
        $parentStudentTable = $wpdb->prefix . 'aba_parent_student';

        $sql = "SELECT s.* 
                FROM {$this->table} s
                INNER JOIN {$parentStudentTable} ps ON s.id = ps.student_id
                WHERE ps.parent_user_id = %d AND ps.status = 'active'";

        return $wpdb->get_results($wpdb->prepare($sql, $parentUserId), ARRAY_A) ?: [];
    }
}
