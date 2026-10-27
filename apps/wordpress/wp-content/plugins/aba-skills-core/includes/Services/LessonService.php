<?php
/**
 * Lesson content reads for the learner-facing app (PA17, Learning domain).
 *
 * The path binds its deterministic nodes to real lessons; this service is how a
 * client fetches the actual body behind a node's `contentId`, plus the browsable
 * catalog. It stays presentation-agnostic: no progress, no XP writes here —
 * completion still flows through LearningController's ledger path (§12).
 *
 * @package ABA_Skills_Core
 */

namespace ABA\Core\Services;

use ABA\Core\Repositories\ContentRepository;

if (!defined('ABSPATH')) {
    exit;
}

class LessonService {
    private ContentRepository $content;

    public function __construct() {
        $this->content = new ContentRepository();
    }

    /** @return list<array> published lessons, optionally filtered by competency code. */
    public function catalog(?string $competency = null): array {
        return $this->content->listLessons($competency);
    }

    /** A single published lesson, or null when it does not exist. */
    public function get(int $lessonId): ?array {
        return $this->content->getLesson($lessonId);
    }

    /** Which lesson post a path node should open, if content exists for it. */
    public function lessonIdFor(string $competency, string $type, ?string $gradeGroup): ?int {
        return $this->content->findLessonId($competency, $type, $gradeGroup);
    }
}
