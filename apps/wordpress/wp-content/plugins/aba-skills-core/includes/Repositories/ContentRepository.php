<?php
/**
 * Content Library persistence (PA17).
 *
 * Lessons are EDITORIAL content, so they live in the `aba_lesson` custom post
 * type (AGENTS.md §6) — never in a transactional table. This repository only
 * reads/projects CPT data; it holds no business logic. The learning path and
 * the learner's progress remain keyed on the deterministic synthetic node id;
 * a lesson here is what a node *points to* for its real body content.
 *
 * @package ABA_Skills_Core
 */

namespace ABA\Core\Repositories;

if (!defined('ABSPATH')) {
    exit;
}

class ContentRepository {
    public const LESSON_CPT = 'aba_lesson';
    public const MODULE_CPT = 'aba_module';
    public const COURSE_CPT = 'aba_course';

    /** Meta keys are the single source of truth shared with ContentSeeder. */
    public const META_COMPETENCY = 'aba_competency';
    public const META_GRADE_GROUP = 'aba_grade_group';
    public const META_LESSON_TYPE = 'aba_lesson_type';
    public const META_ORDER = 'aba_order';
    public const META_XP = 'aba_xp';
    public const META_MODULE = 'aba_module_id';

    /**
     * Resolve the best lesson post id for a (competency, type, grade group)
     * triple. Falls back to the same competency+type in any grade band so a
     * starter library seeded for one band still serves every learner. Determin
     * by (order asc, id asc). Returns null when no content has been seeded yet
     * — the caller then keeps the node content-less (graceful degradation).
     */
    public function findLessonId(string $competency, string $lessonType, ?string $gradeGroup = null): ?int {
        $id = $gradeGroup === null
            ? null
            : $this->queryLessonId($competency, $lessonType, $gradeGroup);
        if ($id === null) {
            $id = $this->queryLessonId($competency, $lessonType, null);
        }
        return $id;
    }

    private function queryLessonId(string $competency, string $lessonType, ?string $gradeGroup): ?int {
        $metaQuery = [
            'relation' => 'AND',
            [ 'key' => self::META_COMPETENCY, 'value' => $competency ],
            [ 'key' => self::META_LESSON_TYPE, 'value' => $lessonType ],
        ];
        if ($gradeGroup !== null) {
            $metaQuery[] = [ 'key' => self::META_GRADE_GROUP, 'value' => $gradeGroup ];
        }

        $query = new \WP_Query([
            'post_type' => self::LESSON_CPT,
            'post_status' => 'publish',
            'posts_per_page' => 1,
            'fields' => 'ids',
            'no_found_rows' => true,
            'meta_key' => self::META_ORDER,
            'orderby' => ['meta_value_num' => 'ASC', 'ID' => 'ASC'],
            'meta_query' => $metaQuery,
        ]);

        $ids = $query->posts;
        return $ids ? (int) $ids[0] : null;
    }

    /** A single lesson projected for the API, or null when it does not exist. */
    public function getLesson(int $postId): ?array {
        $post = get_post($postId);
        if (!$post || $post->post_type !== self::LESSON_CPT || $post->post_status !== 'publish') {
            return null;
        }
        return $this->toLesson($post);
    }

    /** Catalog of published lessons, optionally filtered by competency. */
    public function listLessons(?string $competency = null): array {
        $metaQuery = null;
        if ($competency !== null && $competency !== '') {
            $metaQuery = [ [ 'key' => self::META_COMPETENCY, 'value' => $competency ] ];
        }

        $query = new \WP_Query([
            'post_type' => self::LESSON_CPT,
            'post_status' => 'publish',
            'posts_per_page' => 100,
            'no_found_rows' => true,
            'meta_key' => self::META_ORDER,
            'orderby' => ['meta_value_num' => 'ASC', 'ID' => 'ASC'],
            'meta_query' => $metaQuery,
        ]);

        $lessons = [];
        foreach ($query->posts as $post) {
            $lessons[] = $this->toLesson($post);
        }
        return $lessons;
    }

    public function countLessons(): int {
        $counts = wp_count_posts(self::LESSON_CPT);
        return is_object($counts) ? (int) $counts->publish : 0;
    }

    private function toLesson(\WP_Post $post): array {
        return [
            'id' => (int) $post->ID,
            'title' => get_the_title($post),
            'excerpt' => (string) $post->post_excerpt,
            'content' => wp_kses_post((string) $post->post_content),
            'competencyTarget' => (string) get_post_meta($post->ID, self::META_COMPETENCY, true),
            'gradeGroup' => (string) get_post_meta($post->ID, self::META_GRADE_GROUP, true),
            'type' => (string) get_post_meta($post->ID, self::META_LESSON_TYPE, true),
            'order' => (int) get_post_meta($post->ID, self::META_ORDER, true),
            'xpReward' => (int) get_post_meta($post->ID, self::META_XP, true),
            'moduleId' => (int) get_post_meta($post->ID, self::META_MODULE, true),
        ];
    }
}
