<?php
/**
 * ABA schema migrations (docs/database-schema.md §41: every custom table
 * change requires a migration; migration metadata must record what ran).
 *
 * Additive-only evolution per §42: new tables/columns, never casual renames.
 *
 * @package ABA_Skills_Core
 */

namespace ABA\Core\Database;

if (!defined('ABSPATH')) {
    exit;
}

class MigrationRunner {
    private const OPTION_KEY = 'aba_schema_version';
    private const LATEST = 7;

    public static function run(): void {
        $current = (int)get_option(self::OPTION_KEY, 0);
        if ($current >= self::LATEST) {
            return;
        }

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        for ($version = $current + 1; $version <= self::LATEST; $version++) {
            self::migrate($version);
            update_option(self::OPTION_KEY, $version, false);
        }
    }

    private static function migrate(int $version): void {
        switch ($version) {
            case 1:
                self::migration1AssessmentVersioning();
                break;
            case 2:
                self::migration2ScoringProvenance();
                break;
            case 3:
                self::migration3XpIdempotency();
                break;
            case 4:
                self::migration4NullableScore();
                break;
            case 5:
                self::migration5MultiSourceSkillScores();
                break;
            case 6:
                self::migration6PowerUps();
                break;
            case 7:
                self::migration7ParentLinkRequests();
                break;
        }
    }

    /**
     * v1 — Immutable published assessment versions + score run ledger
     * (assessment-spec.md §22, §52, §122).
     */
    private static function migration1AssessmentVersioning(): void {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        $p = $wpdb->prefix;

        $sql = "CREATE TABLE {$p}aba_assessment_versions (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            assessment_id BIGINT UNSIGNED NOT NULL,
            assessment_code VARCHAR(64) NOT NULL,
            version VARCHAR(20) NOT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'draft',
            grade_group VARCHAR(20) NOT NULL,
            respondent_type VARCHAR(20) NOT NULL DEFAULT 'student',
            scoring_version VARCHAR(50) NOT NULL DEFAULT 'ABA-FREQ-0.1',
            recall_period VARCHAR(50) NOT NULL DEFAULT 'last_2_weeks',
            question_order VARCHAR(30) NOT NULL DEFAULT 'fixed',
            definition_json LONGTEXT NOT NULL,
            published_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uk_assessment_version (assessment_id, version),
            KEY idx_av_status (status),
            KEY idx_av_group (grade_group, respondent_type)
        ) {$charset};";
        dbDelta($sql);

        $sql = "CREATE TABLE {$p}aba_score_runs (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            session_id BIGINT UNSIGNED NOT NULL,
            student_id BIGINT UNSIGNED NOT NULL,
            assessment_version VARCHAR(20) NOT NULL,
            scoring_version VARCHAR(50) NOT NULL,
            respondent_type VARCHAR(20) NOT NULL DEFAULT 'student',
            status VARCHAR(30) NOT NULL DEFAULT 'processing',
            scores_json LONGTEXT NULL,
            quality_flags_json LONGTEXT NULL,
            started_at DATETIME NOT NULL,
            completed_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uk_session_scoring (session_id, scoring_version),
            KEY idx_sr_student (student_id),
            KEY idx_sr_status (status)
        ) {$charset};";
        dbDelta($sql);
    }

    /**
     * v2 — Measurement provenance columns: missing-reason model (§15-16, §47),
     * score status (§45, §86), source separation (§54), session binding (§25).
     */
    private static function migration2ScoringProvenance(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        // Responses: explicit missing reason + per-item score status.
        self::addColumn("{$p}aba_assessment_responses", 'missing_reason', "VARCHAR(30) NULL AFTER response_value");
        self::addColumn("{$p}aba_assessment_responses", 'score_status', "VARCHAR(30) NOT NULL DEFAULT 'valid' AFTER score_value");

        // Sessions: freeze respondent type / grade group at creation (§25).
        self::addColumn("{$p}aba_assessment_sessions", 'respondent_type', "VARCHAR(20) NOT NULL DEFAULT 'student' AFTER assessment_version");
        self::addColumn("{$p}aba_assessment_sessions", 'grade_group', "VARCHAR(20) NOT NULL DEFAULT '' AFTER respondent_type");

        // Skill scores: provenance for the current projection (§53).
        self::addColumn("{$p}aba_skill_scores", 'source_type', "VARCHAR(20) NOT NULL DEFAULT 'student' AFTER skill_category");
        self::addColumn("{$p}aba_skill_scores", 'scoring_version', "VARCHAR(50) NULL AFTER source_type");
        self::addColumn("{$p}aba_skill_scores", 'score_status', "VARCHAR(30) NOT NULL DEFAULT 'valid' AFTER score");
    }

    /**
     * v3 — XP ledger idempotency (database-schema.md §21-22, assessment-spec §81).
     */
    private static function migration3XpIdempotency(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        self::addColumn("{$p}aba_xp_transactions", 'idempotency_key', "VARCHAR(120) NULL AFTER description");
        self::ensureUniqueKey("{$p}aba_xp_transactions", 'uk_xp_idempotency', 'idempotency_key');
    }

    /**
     * v4 — Insufficient evidence must store a null score, never 0
     * (assessment-spec.md §45, invariant #1 "missing != 0"). The initial schema
     * declared skill_scores.score NOT NULL DEFAULT 0, which silently coerces the
     * engine's null to 0; relax it to NULL so the projection stays truthful.
     */
    private static function migration4NullableScore(): void {
        global $wpdb;
        $p = $wpdb->prefix;
        $table = "{$p}aba_skill_scores";

        $col = $wpdb->get_row($wpdb->prepare(
            'SHOW COLUMNS FROM ' . $table . ' LIKE %s',
            'score'
        ));
        // 'Null' column is 'YES' once relaxed; skip when already nullable.
        if ($col !== null && strcasecmp($col->Null, 'YES') === 0) {
            return;
        }
        $wpdb->query("ALTER TABLE {$table} MODIFY score DECIMAL(5,2) NULL");
    }

    /**
     * v5 — Per-source isolation (assessment-spec.md §54: student / teacher / parent
     * measurements must live in separate, non-blended rows). The original
     * uk_student_skill(student_id, skill_code) let a teacher or parent submission
     * overwrite the student projection via ON DUPLICATE KEY UPDATE. Widen the key
     * to include source_type so each source keeps its own current row.
     */
    private static function migration5MultiSourceSkillScores(): void {
        global $wpdb;
        $table = "{$wpdb->prefix}aba_skill_scores";

        // source_type is added by v2; nothing to migrate if it is absent.
        if (!self::columnExists($table, 'source_type')) {
            return;
        }

        // The old key is stricter, so any existing rows are already unique across
        // (student_id, skill_code, source_type); swapping the key is data-safe.
        self::dropIndex($table, 'uk_student_skill');
        self::ensureUniqueKey($table, 'uk_student_skill_source', 'student_id, skill_code, source_type');
    }

    /**
     * v6 — Streak-freeze power-ups (PA22). An append-only ledger, mirroring the
     * XP ledger (§12): grants are +delta, spends are -delta, and the spendable
     * balance is always SUM(delta) rather than a mutable counter. The unique
     * (student_id, kind, reason) key makes every grant/spend idempotent, so a
     * repeated read or a double-tap can never mint or burn the same credit twice.
     */
    private static function migration6PowerUps(): void {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        $p = $wpdb->prefix;

        $sql = "CREATE TABLE {$p}aba_power_ups (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            student_id BIGINT UNSIGNED NOT NULL,
            kind VARCHAR(50) NOT NULL,
            delta INT NOT NULL DEFAULT 0,
            reason VARCHAR(190) NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uk_powerup_idem (student_id, kind, reason),
            KEY idx_powerup_student (student_id, kind)
        ) {$charset};";
        dbDelta($sql);
    }

    /**
     * v7 — Parent link-request queue (PA42). A parent submits a student code;
     * the request waits here until a platform administrator approves it, which
     * is what writes the wp_aba_parent_student row (§8: a link is never
     * self-service). One request per (parent, student) pair via unique key, so
     * double submits stay idempotent; status is the only mutable state.
     */
    private static function migration7ParentLinkRequests(): void {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        $p = $wpdb->prefix;

        $sql = "CREATE TABLE {$p}aba_parent_link_requests (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            parent_user_id BIGINT UNSIGNED NOT NULL,
            student_id BIGINT UNSIGNED NOT NULL,
            student_code VARCHAR(64) NOT NULL,
            relationship VARCHAR(20) NOT NULL DEFAULT 'guardian',
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            reviewed_by BIGINT UNSIGNED NULL,
            reviewed_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uk_plr_parent_student (parent_user_id, student_id),
            KEY idx_plr_status (status)
        ) {$charset};";
        dbDelta($sql);
    }

    /** Idempotent named index removal. */
    private static function dropIndex(string $table, string $key): void {
        global $wpdb;
        $exists = $wpdb->get_row($wpdb->prepare(
            'SHOW INDEX FROM ' . $table . ' WHERE Key_name = %s',
            $key
        ));
        if ($exists === null) {
            return;
        }
        $wpdb->query("ALTER TABLE {$table} DROP INDEX {$key}");
    }

    /** Idempotent named index creation. */
    private static function ensureUniqueKey(string $table, string $key, string $column): void {
        global $wpdb;
        $exists = $wpdb->get_row($wpdb->prepare(
            'SHOW INDEX FROM ' . $table . ' WHERE Key_name = %s',
            $key
        ));
        if ($exists !== null) {
            return;
        }
        $wpdb->query("ALTER TABLE {$table} ADD UNIQUE KEY {$key} ({$column})");
    }

    private static function columnExists(string $table, string $column): bool {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            'SHOW COLUMNS FROM ' . $table . ' LIKE %s',
            $column
        ));
        return $row !== null;
    }

    /** Idempotent additive column change (§42). */
    private static function addColumn(string $table, string $column, string $definition): void {
        global $wpdb;
        if (self::columnExists($table, $column)) {
            return;
        }
        $wpdb->query("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
    }
}
