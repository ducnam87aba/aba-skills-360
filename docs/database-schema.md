# ABA Skills 360 — Database Schema

**Document:** `docs/database-schema.md`  
**Version:** 0.1.0  
**Status:** Source of Truth

---

# 1. Purpose

This document defines the initial persistent data model for ABA Skills 360.

The schema follows two storage strategies:

```text
Editorial content → WordPress Custom Post Types
Transactional data → Custom MySQL tables
```

Do not store high-volume transactional data in `wp_postmeta` or `wp_usermeta`.

---

# 2. Naming Convention

All ABA custom tables use:

```text
{wp_prefix}aba_
```

Examples with default prefix:

```text
wp_aba_students
wp_aba_assessment_sessions
```

Code must use the configured WordPress table prefix rather than assuming `wp_`.

---

# 3. ID Convention

Unless otherwise specified:

```text
BIGINT UNSIGNED
AUTO_INCREMENT
PRIMARY KEY
```

Foreign identifiers should use compatible numeric types.

Application code must not assume IDs are globally meaningful outside their entity type.

---

# 4. Timestamp Convention

Use UTC internally where practical.

Common timestamps:

```text
created_at
updated_at
started_at
completed_at
measured_at
```

Database/documentation should clearly distinguish timestamps from local display times.

---

# 5. Core WordPress Tables

The platform reuses:

```text
wp_users
wp_usermeta
wp_posts
wp_postmeta
wp_terms
wp_term_taxonomy
wp_term_relationships
```

WordPress tables remain managed by WordPress.

ABA migrations must not alter WordPress core schema.

---

# 6. Custom Post Types

Initial CPTs:

```text
aba_course
aba_module
aba_lesson
aba_question
aba_assessment
aba_mission
aba_badge
```

---

# 7. Taxonomies

Initial taxonomies:

```text
aba_grade
aba_skill_domain
aba_topic
aba_difficulty
aba_content_type
```

---

# 8. Table Overview

Custom tables:

```text
wp_aba_schools
wp_aba_classes
wp_aba_students
wp_aba_parent_student

wp_aba_assessment_sessions
wp_aba_assessment_responses

wp_aba_skill_scores
wp_aba_skill_snapshots

wp_aba_lesson_progress

wp_aba_xp_transactions
wp_aba_streaks
wp_aba_mission_progress
wp_aba_student_badges

wp_aba_reports

wp_aba_events
```

---

# 9. `wp_aba_schools`

Purpose:

Store schools/organizational learning units.

```sql
CREATE TABLE wp_aba_schools (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

    code VARCHAR(50) NOT NULL,
    name VARCHAR(255) NOT NULL,

    province VARCHAR(120) NULL,
    district VARCHAR(120) NULL,

    status VARCHAR(30) NOT NULL DEFAULT 'active',

    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,

    PRIMARY KEY (id),
    UNIQUE KEY uk_school_code (code),
    KEY idx_school_status (status),
    KEY idx_school_province (province)
);
```

Possible statuses:

```text
active
inactive
archived
```

---

# 10. `wp_aba_classes`

Purpose:

Store classes belonging to schools.

```sql
CREATE TABLE wp_aba_classes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

    school_id BIGINT UNSIGNED NOT NULL,

    code VARCHAR(50) NULL,
    name VARCHAR(120) NOT NULL,

    grade TINYINT UNSIGNED NOT NULL,
    school_year VARCHAR(20) NOT NULL,

    teacher_user_id BIGINT UNSIGNED NULL,

    status VARCHAR(30) NOT NULL DEFAULT 'active',

    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,

    PRIMARY KEY (id),

    KEY idx_class_school (school_id),
    KEY idx_class_school_grade (school_id, grade),
    KEY idx_class_teacher (teacher_user_id),
    KEY idx_class_year (school_year),
    KEY idx_class_status (status)
);
```

Application-level validation:

```text
grade >= 1
grade <= 12
```

---

# 11. `wp_aba_students`

Purpose:

Store student-specific profile information.

Authentication identity may remain in `wp_users`.

```sql
CREATE TABLE wp_aba_students (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

    user_id BIGINT UNSIGNED NULL,

    student_code VARCHAR(50) NOT NULL,

    school_id BIGINT UNSIGNED NOT NULL,
    class_id BIGINT UNSIGNED NULL,

    grade TINYINT UNSIGNED NOT NULL,

    date_of_birth DATE NULL,

    status VARCHAR(30) NOT NULL DEFAULT 'active',

    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,

    PRIMARY KEY (id),

    UNIQUE KEY uk_student_code (student_code),

    KEY idx_student_user (user_id),
    KEY idx_student_school (school_id),
    KEY idx_student_class (class_id),
    KEY idx_student_school_grade (school_id, grade),
    KEY idx_student_status (status)
);
```

Do not add unnecessary sensitive personal information without a defined product requirement.

---

# 12. `wp_aba_parent_student`

Purpose:

Many-to-many parent/student relationships.

```sql
CREATE TABLE wp_aba_parent_student (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

    parent_user_id BIGINT UNSIGNED NOT NULL,
    student_id BIGINT UNSIGNED NOT NULL,

    relationship VARCHAR(30) NULL,

    is_primary TINYINT(1) NOT NULL DEFAULT 0,

    created_at DATETIME NOT NULL,

    PRIMARY KEY (id),

    UNIQUE KEY uk_parent_student (
        parent_user_id,
        student_id
    ),

    KEY idx_parent (parent_user_id),
    KEY idx_parent_student_student (student_id)
);
```

Suggested relationship values:

```text
father
mother
guardian
other
```

Do not rely on relationship label for authorization.

The existence of an active relationship record establishes linkage.

---

# 13. Assessment Definition Storage

Assessment definitions use:

```text
CPT: aba_assessment
```

Questions use:

```text
CPT: aba_question
```

Assessment/question mapping may initially use WordPress metadata or a dedicated mapping table if ordered/versioned mappings become too complex.

Assessment version must be materialized into each session.

---

# 14. `wp_aba_assessment_sessions`

Purpose:

One student's attempt/session for one assessment version.

```sql
CREATE TABLE wp_aba_assessment_sessions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

    assessment_id BIGINT UNSIGNED NOT NULL,
    student_id BIGINT UNSIGNED NOT NULL,

    assessment_version VARCHAR(50) NOT NULL,

    status VARCHAR(30) NOT NULL DEFAULT 'in_progress',

    started_at DATETIME NOT NULL,
    last_activity_at DATETIME NULL,
    completed_at DATETIME NULL,
    expires_at DATETIME NULL,

    duration_seconds INT UNSIGNED NULL,

    metadata_json LONGTEXT NULL,

    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,

    PRIMARY KEY (id),

    KEY idx_assessment_session_student (student_id),
    KEY idx_assessment_session_assessment (assessment_id),
    KEY idx_assessment_session_status (status),

    KEY idx_assessment_student_status (
        assessment_id,
        student_id,
        status
    )
);
```

Statuses:

```text
in_progress
completed
expired
cancelled
```

A completed session must never lose its assessment version.

---

# 15. `wp_aba_assessment_responses`

Purpose:

Store individual responses.

```sql
CREATE TABLE wp_aba_assessment_responses (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

    session_id BIGINT UNSIGNED NOT NULL,
    question_id BIGINT UNSIGNED NOT NULL,

    answer_value TEXT NULL,
    answer_json LONGTEXT NULL,

    score_raw DECIMAL(10,4) NULL,
    score_normalized DECIMAL(10,4) NULL,

    response_time_ms INT UNSIGNED NULL,

    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,

    PRIMARY KEY (id),

    UNIQUE KEY uk_session_question (
        session_id,
        question_id
    ),

    KEY idx_response_session (session_id),
    KEY idx_response_question (question_id)
);
```

The unique `(session_id, question_id)` key supports idempotent autosave/update behavior for standard questions.

If future assessment types allow multiple responses to the same question, schema evolution must be explicitly versioned.

---

# 16. Response Value Strategy

Simple responses:

```text
answer_value
```

Complex responses:

```text
answer_json
```

Examples:

```json
{
  "selectedOption": "B"
}
```

```json
{
  "ranking": ["A", "C", "B", "D"]
}
```

```json
{
  "values": {
    "statement_1": 3,
    "statement_2": 5
  }
}
```

Validate JSON structure at application level.

---

# 17. `wp_aba_skill_scores`

Purpose:

Store skill measurements derived from an assessment or another recognized measurement source.

```sql
CREATE TABLE wp_aba_skill_scores (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

    student_id BIGINT UNSIGNED NOT NULL,

    skill_key VARCHAR(100) NOT NULL,

    assessment_session_id BIGINT UNSIGNED NULL,

    source_type VARCHAR(50) NOT NULL DEFAULT 'assessment',

    raw_score DECIMAL(10,4) NULL,
    normalized_score DECIMAL(10,4) NOT NULL,

    percentile DECIMAL(6,3) NULL,
    confidence_score DECIMAL(6,4) NULL,

    scoring_version VARCHAR(50) NULL,

    measured_at DATETIME NOT NULL,

    created_at DATETIME NOT NULL,

    PRIMARY KEY (id),

    KEY idx_skill_student (student_id),
    KEY idx_skill_key (skill_key),
    KEY idx_skill_student_key (
        student_id,
        skill_key
    ),

    KEY idx_skill_session (
        assessment_session_id
    ),

    KEY idx_skill_measured (
        student_id,
        measured_at
    )
);
```

Do not overwrite historical score rows.

---

# 18. Skill Keys

Initial skill keys must be mapped to the approved ABA competency framework.

Example placeholders:

```text
self_awareness
self_management
communication
collaboration
problem_solving
responsibility
```

Do not rename persisted skill keys casually.

If the framework changes, define an explicit migration/mapping strategy.

---

# 19. `wp_aba_skill_snapshots`

Purpose:

Store aggregate student skill state at meaningful points in time.

```sql
CREATE TABLE wp_aba_skill_snapshots (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

    student_id BIGINT UNSIGNED NOT NULL,

    snapshot_type VARCHAR(50) NOT NULL,

    snapshot_date DATE NOT NULL,

    data_json LONGTEXT NOT NULL,

    source_session_id BIGINT UNSIGNED NULL,

    scoring_version VARCHAR(50) NULL,

    created_at DATETIME NOT NULL,

    PRIMARY KEY (id),

    KEY idx_snapshot_student (student_id),

    KEY idx_snapshot_student_date (
        student_id,
        snapshot_date
    ),

    KEY idx_snapshot_source (
        source_session_id
    )
);
```

Example `data_json`:

```json
{
  "communication": 78,
  "collaboration": 85,
  "self_management": 61,
  "problem_solving": 72
}
```

Snapshots supplement individual skill score rows.

They do not replace the measurement ledger.

---

# 20. `wp_aba_lesson_progress`

Purpose:

Track progress for student learning content.

```sql
CREATE TABLE wp_aba_lesson_progress (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

    student_id BIGINT UNSIGNED NOT NULL,
    lesson_id BIGINT UNSIGNED NOT NULL,

    status VARCHAR(30) NOT NULL DEFAULT 'not_started',

    progress_percent DECIMAL(5,2) NOT NULL DEFAULT 0,

    score DECIMAL(10,4) NULL,
    attempts INT UNSIGNED NOT NULL DEFAULT 0,

    started_at DATETIME NULL,
    completed_at DATETIME NULL,

    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,

    PRIMARY KEY (id),

    UNIQUE KEY uk_student_lesson (
        student_id,
        lesson_id
    ),

    KEY idx_lesson_progress_student (student_id),
    KEY idx_lesson_progress_lesson (lesson_id),
    KEY idx_lesson_progress_status (status)
);
```

Statuses:

```text
not_started
in_progress
completed
locked
```

`locked` may also be computed rather than persisted depending on Learning Path implementation.

---

# 21. `wp_aba_xp_transactions`

Purpose:

Source-of-truth XP ledger.

```sql
CREATE TABLE wp_aba_xp_transactions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

    student_id BIGINT UNSIGNED NOT NULL,

    source_type VARCHAR(50) NOT NULL,
    source_id BIGINT UNSIGNED NULL,

    xp INT NOT NULL,

    reason VARCHAR(255) NULL,

    idempotency_key VARCHAR(120) NULL,

    created_at DATETIME NOT NULL,

    PRIMARY KEY (id),

    UNIQUE KEY uk_xp_idempotency (
        idempotency_key
    ),

    KEY idx_xp_student (student_id),

    KEY idx_xp_student_created (
        student_id,
        created_at
    ),

    KEY idx_xp_source (
        source_type,
        source_id
    )
);
```

Possible source types:

```text
lesson
assessment
mission
streak
achievement
admin_adjustment
```

A cached XP total may be stored separately later.

The ledger remains authoritative.

---

# 22. XP Idempotency

Reward-producing operations must avoid accidental duplicate XP.

Example:

```text
lesson_completed:123:student:456
```

The application should generate deterministic idempotency keys where appropriate.

---

# 23. `wp_aba_streaks`

Purpose:

Maintain current and historical streak summary.

```sql
CREATE TABLE wp_aba_streaks (
    student_id BIGINT UNSIGNED NOT NULL,

    current_streak INT UNSIGNED NOT NULL DEFAULT 0,
    longest_streak INT UNSIGNED NOT NULL DEFAULT 0,

    last_activity_date DATE NULL,

    freeze_available INT UNSIGNED NOT NULL DEFAULT 0,

    updated_at DATETIME NOT NULL,

    PRIMARY KEY (student_id)
);
```

Streak update rules belong in `StreakService`.

---

# 24. `wp_aba_mission_progress`

Purpose:

Track progress toward missions.

```sql
CREATE TABLE wp_aba_mission_progress (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

    student_id BIGINT UNSIGNED NOT NULL,
    mission_id BIGINT UNSIGNED NOT NULL,

    status VARCHAR(30) NOT NULL DEFAULT 'in_progress',

    progress DECIMAL(10,2) NOT NULL DEFAULT 0,
    target DECIMAL(10,2) NOT NULL DEFAULT 1,

    started_at DATETIME NULL,
    completed_at DATETIME NULL,

    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,

    PRIMARY KEY (id),

    UNIQUE KEY uk_student_mission (
        student_id,
        mission_id
    ),

    KEY idx_mission_student (student_id),
    KEY idx_mission_status (status)
);
```

---

# 25. `wp_aba_student_badges`

Purpose:

Record earned badges.

```sql
CREATE TABLE wp_aba_student_badges (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

    student_id BIGINT UNSIGNED NOT NULL,
    badge_id BIGINT UNSIGNED NOT NULL,

    source_type VARCHAR(50) NULL,
    source_id BIGINT UNSIGNED NULL,

    earned_at DATETIME NOT NULL,

    PRIMARY KEY (id),

    UNIQUE KEY uk_student_badge (
        student_id,
        badge_id
    ),

    KEY idx_badge_student (student_id),
    KEY idx_badge_badge (badge_id)
);
```

---

# 26. `wp_aba_reports`

Purpose:

Structured generated reports.

```sql
CREATE TABLE wp_aba_reports (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

    student_id BIGINT UNSIGNED NOT NULL,

    report_type VARCHAR(50) NOT NULL,

    period_start DATE NULL,
    period_end DATE NULL,

    assessment_session_id BIGINT UNSIGNED NULL,

    report_version VARCHAR(50) NOT NULL,

    data_json LONGTEXT NOT NULL,

    status VARCHAR(30) NOT NULL DEFAULT 'generated',

    generated_at DATETIME NOT NULL,

    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,

    PRIMARY KEY (id),

    KEY idx_report_student (student_id),

    KEY idx_report_student_type (
        student_id,
        report_type
    ),

    KEY idx_report_session (
        assessment_session_id
    ),

    KEY idx_report_period (
        period_start,
        period_end
    )
);
```

Possible report types:

```text
assessment
monthly
term
annual
development
```

---

# 27. Report JSON Example

```json
{
  "summary": {
    "title": "Báo cáo năng lực",
    "studentId": 123
  },

  "skills": [
    {
      "key": "communication",
      "score": 78,
      "trend": 6
    }
  ],

  "strengths": [
    {
      "skill": "collaboration",
      "messageKey": "strong_collaboration"
    }
  ],

  "growthAreas": [],

  "recommendations": [],

  "learningPlan": []
}
```

Narrative copy should ideally be reproducible from structured report data.

---

# 28. `wp_aba_events`

Purpose:

Product analytics/event stream.

```sql
CREATE TABLE wp_aba_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

    user_id BIGINT UNSIGNED NULL,
    student_id BIGINT UNSIGNED NULL,

    event_name VARCHAR(100) NOT NULL,

    entity_type VARCHAR(50) NULL,
    entity_id BIGINT UNSIGNED NULL,

    session_key VARCHAR(120) NULL,

    metadata_json LONGTEXT NULL,

    created_at DATETIME NOT NULL,

    PRIMARY KEY (id),

    KEY idx_event_name (event_name),

    KEY idx_event_student (
        student_id,
        created_at
    ),

    KEY idx_event_user (
        user_id,
        created_at
    ),

    KEY idx_event_entity (
        entity_type,
        entity_id
    ),

    KEY idx_event_created (
        created_at
    )
);
```

Do not store unnecessary personally identifying information in analytics metadata.

---

# 29. Event Names

Initial convention:

```text
app_opened

assessment_started
assessment_resumed
question_answered
assessment_completed

lesson_started
lesson_completed

mission_started
mission_completed

report_viewed
skills_viewed
```

Use snake_case.

Do not dynamically create thousands of event names.

---

# 30. Relationship Overview

```text
wp_users
   │
   ├──────────── Parent
   │                │
   │                ▼
   │        wp_aba_parent_student
   │                │
   │                ▼
   │         wp_aba_students
   │                │
   │                ├──────────► wp_aba_classes
   │                │                  │
   │                │                  ▼
   │                │            wp_aba_schools
   │                │
   │                ├──────────► assessment_sessions
   │                │                  │
   │                │                  ▼
   │                │          assessment_responses
   │                │
   │                ├──────────► skill_scores
   │                ├──────────► skill_snapshots
   │                ├──────────► lesson_progress
   │                ├──────────► xp_transactions
   │                ├──────────► streaks
   │                ├──────────► mission_progress
   │                ├──────────► student_badges
   │                └──────────► reports
```

---

# 31. Referential Integrity Strategy

WordPress installations commonly do not use database foreign keys across all plugin tables.

ABA may initially enforce relations at the application/repository level.

However:

```text
Every referenced ID must be validated.
Deletion must be explicit.
Historical data must not be accidentally orphaned.
```

Never hard-delete assessment history as a side effect of ordinary content editing.

---

# 32. Delete Strategy

Prefer soft delete/archive for entities with historical significance.

Examples:

```text
School → inactive/archive
Student → inactive
Assessment → draft/archive
```

Transactional records such as completed assessments should generally be retained according to product/data-retention policy.

Hard deletion requires explicit privacy/data-retention workflows.

---

# 33. Data Ownership

Resource ownership/scope:

```text
Student data
    ↓
belongs to student identity
and operational school scope

Parent access
    ↓
through wp_aba_parent_student

Teacher access
    ↓
through assigned school/class permissions

School admin
    ↓
school scope

ABA admin
    ↓
platform scope according to capabilities
```

---

# 34. Recommended Indexing Principles

Always index common filters and joins.

High-priority access patterns:

```text
student_id
school_id
class_id
assessment_id
assessment_session_id
status
skill_key
created_at
measured_at
```

Avoid adding indexes blindly.

Review actual query patterns as the application evolves.

---

# 35. Pagination

All list APIs backed by custom tables must use bounded pagination.

Do not implement endpoints that return an unlimited number of:

```text
responses
students
events
reports
transactions
```

---

# 36. JSON Columns

To maximize WordPress/MySQL/MariaDB compatibility, initial schema uses `LONGTEXT` for JSON payloads.

Application code must:

```text
Encode valid JSON
Decode safely
Validate schema
Handle malformed legacy data
```

A future migration to native JSON types may be considered if deployment compatibility is known.

---

# 37. Money / Financial Data

This schema does not currently include payment/e-commerce functionality.

Do not add financial/payment fields to student tables unless a future approved module requires them.

---

# 38. Assessment Versioning Rule

A session stores:

```text
assessment_id
assessment_version
```

Future implementations may additionally store:

```text
definition_snapshot_json
question_set_hash
scoring_version
```

if stronger historical reproducibility is required.

---

# 39. Scoring Versioning

Where scoring algorithms may change, store:

```text
scoring_version
```

with derived scores.

Do not silently recompute historical scores using a new scoring model without an explicit migration/recalculation process.

---

# 40. Future Tables — Not MVP

Possible future tables:

```text
wp_aba_recommendation_rules
wp_aba_recommendations
wp_aba_teacher_student
wp_aba_school_admin
wp_aba_notifications
wp_aba_consents
wp_aba_audit_logs
wp_aba_import_jobs
wp_aba_background_jobs
```

Do not create these until the corresponding Sprint requires them.

---

# 41. Migration Rules

Every custom table change requires a migration.

Example:

```text
001_create_schools.php
002_create_classes.php
003_create_students.php
004_create_parent_student.php
005_create_assessment_sessions.php
006_create_assessment_responses.php
```

Migration metadata must allow the plugin to know which versions have already run.

---

# 42. Schema Change Rules

Do not:

```text
rename a column casually
remove a column immediately
change persisted enum semantics silently
reuse an old field for a new meaning
```

Preferred evolution:

```text
Add new field
Deploy compatible code
Migrate data
Switch reads
Remove old field only in later controlled release
```

---

# 43. Data Retention

Final retention policy is a product/legal decision and is not defined in this version.

Implementation must not assume that historical child assessment data may be retained forever.

Architecture should allow future retention/deletion policies.

---

# 44. Privacy Principle

Collect the minimum student data required for:

```text
Assessment
Progress
Reporting
School operations
Parent access
```

Do not add sensitive profile attributes merely because they may be useful later.

---

# 45. Schema Acceptance Criteria

Database implementation is compliant when:

```text
All schema changes are migrated.
No business-critical transaction data is hidden in postmeta.
Assessment history is versioned.
Skill history is append-oriented.
XP has a ledger.
Parent/student linkage is explicit.
School scope can be efficiently queried.
Critical paths have appropriate indexes.
Data access goes through repositories/services.
```
