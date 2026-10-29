<?php
/**
 * School / Class lookup repository (PA44, School domain — AGENTS.md §5).
 *
 * wp_aba_schools and wp_aba_classes are the editorial-ish reference tables the
 * roster is scoped against (PA23/PA24). This class owns ONLY those reads so the
 * student-creation flow can (a) validate a class belongs to a school before
 * persisting, and (b) let a platform administrator pick a destination scope from
 * a real list instead of a hand-typed id. Persistence logic lives here, never in
 * a controller or service (§4).
 *
 * @package ABA_Skills_Core
 */

namespace ABA\Core\Repositories;

if (!defined('ABSPATH')) {
    exit;
}

class SchoolRepository {
    private string $schools;
    private string $classes;

    public function __construct() {
        global $wpdb;
        $this->schools = $wpdb->prefix . 'aba_schools';
        $this->classes = $wpdb->prefix . 'aba_classes';
    }

    public function findSchool(int $id): ?array {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->schools} WHERE id = %d", $id), ARRAY_A);
        return $row ?: null;
    }

    /** Every school (id + name), for a platform administrator's destination picker. */
    public function listSchools(): array {
        global $wpdb;
        return $wpdb->get_results("SELECT id, name FROM {$this->schools} ORDER BY id ASC", ARRAY_A) ?: [];
    }

    /**
     * Classes in one school (schoolId <= 0 => every class). Used both to scope a
     * class selector for the admin and to prove a class belongs to a school.
     */
    public function listClasses(int $schoolId = 0): array {
        global $wpdb;
        if ($schoolId > 0) {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$this->classes} WHERE school_id = %d ORDER BY id ASC",
                $schoolId
            ), ARRAY_A) ?: [];
            return $rows;
        }
        return $wpdb->get_results("SELECT * FROM {$this->classes} ORDER BY id ASC", ARRAY_A) ?: [];
    }

    public function findClass(int $id): ?array {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->classes} WHERE id = %d", $id), ARRAY_A);
        return $row ?: null;
    }

    /** True when the class exists AND is a member of the given school. */
    public function classInSchool(int $classId, int $schoolId): bool {
        $class = $this->findClass($classId);
        return $class !== null && (int) $class['school_id'] === $schoolId;
    }
}
