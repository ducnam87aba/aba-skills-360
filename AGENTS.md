# ABA Skills 360 — Engineering Instructions

## 1. Project goal

ABA Skills 360 is not a traditional WordPress LMS.

It is an EdTech platform focused on:

1. Assessment Engine
2. Skills Profile
3. Parent Report
4. Recommendation Engine
5. Learning Path
6. Gamification

WordPress is used as CMS, administration layer, authentication/user layer, and REST API host.

The student and parent applications are built separately using Next.js and TypeScript.

Do not redesign this architecture unless explicitly instructed.

---

## 2. Fixed architecture

The architecture is:

```text
Next.js Web App
      |
REST API / JSON
      |
ABA Skills Core WordPress Plugin
      |
Domain Services
      |
Repositories
      |
MySQL
```

WordPress must not contain frontend business UI for students or parents except administration screens.

Do not implement the student application using Elementor, Gutenberg pages, shortcodes, or page-builder plugins.

---

## 3. Repository structure

Maintain this structure:

```text
apps/
  web/
  wordpress/

packages/
  ui/
  api-client/
  types/
  validation/

docs/
```

Do not introduce a new top-level structure unless necessary.

---

## 4. Backend architecture

All ABA-specific WordPress logic belongs in:

```text
apps/wordpress/wp-content/plugins/aba-skills-core/
```

Never place application business logic inside:

```text
functions.php
```

The plugin should follow:

```text
REST Controller
      ↓
Service
      ↓
Repository
      ↓
Database
```

REST controllers must not contain raw SQL.

Services contain business logic.

Repositories contain persistence logic.

---

## 5. Domain modules

Maintain the following domain boundaries:

```text
Assessment
Student
Skill
Learning
Gamification
Report
Parent
School
Recommendation
```

Avoid cross-domain coupling.

Shared behavior should live in a shared/core layer.

---

## 6. Database strategy

Use WordPress Custom Post Types only for editorial content.

Examples:

```text
aba_course
aba_module
aba_lesson
aba_question
aba_assessment
aba_mission
aba_badge
```

Use custom database tables for transactional or high-volume data.

Examples:

```text
wp_aba_students
wp_aba_schools
wp_aba_classes
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

Do not store large transactional datasets in wp_postmeta or wp_usermeta.

---

## 7. API rules

All ABA APIs must use:

```text
/wp-json/aba/v1/
```

Examples:

```text
GET  /aba/v1/me

GET  /aba/v1/learning/path

POST /aba/v1/assessments/{id}/start

POST /aba/v1/assessment-sessions/{id}/responses

POST /aba/v1/assessment-sessions/{id}/complete

GET  /aba/v1/students/{id}/skills/current

GET  /aba/v1/students/{id}/skills/history

GET  /aba/v1/parent/children

GET  /aba/v1/students/{id}/reports
```

Every protected endpoint must implement server-side permission checks.

Never rely on frontend role checks for authorization.

---

## 8. Security rules

Student data is sensitive.

Every endpoint must enforce:

- authentication
- authorization
- school scope
- parent-child relationship checks where applicable
- role/capability checks
- input validation
- output sanitization

Never allow access to another school's students by simply changing an ID.

Use WordPress capabilities rather than hardcoded role-name comparisons whenever possible.

---

## 9. Assessment Engine

Assessment is a core domain and must remain independent from presentation.

Required flow:

```text
Assessment Definition
       ↓
Assessment Session
       ↓
Responses
       ↓
Scoring Engine
       ↓
Skill Scores
       ↓
Skill Snapshot
```

Assessment sessions must support:

- start
- resume
- autosave
- completion
- versioning

Do not assume a fixed scoring algorithm.

Use a scoring strategy abstraction.

Possible strategies:

```text
LikertScoringStrategy
ScenarioScoringStrategy
TeacherRatingStrategy
FutureIRTScoringStrategy
```

---

## 10. Assessment versioning

Every assessment session must store the assessment version used.

Never silently mutate historical assessment definitions.

Historical scores must remain reproducible.

---

## 11. Skills Profile

Skills data must support both current score and longitudinal history.

Do not store only the latest score.

Required concepts:

```text
skill_scores
skill_snapshots
```

The platform must support future trend charts and comparisons over time.

---

## 12. XP architecture

Do not store XP only as:

```text
total_xp = 1000
```

Use an XP ledger.

Example:

```text
+20 lesson completion
+10 streak
+30 mission
+50 assessment
```

Use:

```text
wp_aba_xp_transactions
```

A cached total may be added for performance.

The ledger remains the source of truth.

---

## 13. Recommendation Engine

Version 1 should use deterministic rules.

Example:

```text
IF communication_score < 50
AND grade_group = 4_6

THEN recommend:
- lesson A
- lesson B
- mission C
```

Do not introduce LLM-based recommendations until the deterministic system is functional and testable.

---

## 14. Frontend stack

Use:

```text
Next.js
TypeScript
Tailwind CSS
shadcn/ui
TanStack Query
Zustand
React Hook Form
Zod
Recharts
Framer Motion
```

Avoid adding competing libraries unless there is a clear technical reason.

---

## 15. Frontend architecture

Use feature-based architecture:

```text
src/features/
  auth/
  learning-path/
  lesson/
  assessment/
  skills/
  parent-report/
  gamification/
  missions/
  achievements/
  profile/
```

Do not scatter business logic across generic UI components.

---

## 16. API access

Frontend components must not call arbitrary `fetch()` directly.

Use the shared API client:

```text
packages/api-client/
```

Example:

```ts
assessment.start()
assessment.answer()
assessment.complete()

skills.getCurrent()
skills.getHistory()

learning.getPath()
```

---

## 17. Shared types

Shared domain types belong in:

```text
packages/types/
```

Validation schemas belong in:

```text
packages/validation/
```

Frontend and backend API contracts should remain synchronized.

---

## 18. Student navigation

The student application has exactly these primary navigation items:

```text
Học
Khám phá
Nhiệm vụ
Thành tích
Hồ sơ
```

Do not add new primary navigation without explicit approval.

---

## 19. Parent navigation

The parent application has exactly these primary navigation items:

```text
Tổng quan
Năng lực
Báo cáo
Hoạt động
Hồ sơ
```

---

## 20. UX direction

The product may borrow interaction principles from Duolingo:

- clear progression
- short sessions
- nodes
- locked/unlocked states
- progress feedback
- XP
- streak
- rewards
- micro-interactions

Do not copy Duolingo branding, visual assets, characters, or exact screen layouts.

ABA must maintain its own design system.

---

## 21. Priority order

Build in this order:

```text
Assessment Engine
Skills Profile
Parent Report
Recommendation Engine
Learning Path
Gamification
Content Library
AI
```

Do not prioritize a large course library before the measurement platform works.

---

## 22. Coding rules

Before coding:

1. Read AGENTS.md.
2. Read relevant docs in `/docs`.
3. Inspect existing code.
4. Reuse existing abstractions.
5. Do not rewrite unrelated modules.

For every task:

1. Explain the planned changes briefly.
2. Implement only the requested scope.
3. Add or update tests.
4. Run lint/typecheck/tests.
5. Report changed files.
6. Report any unresolved issue.

Do not claim a task is complete if tests are failing.

---

## 23. No speculative rewrites

Do not:

- replace WordPress with another backend
- replace Next.js
- introduce microservices
- introduce GraphQL
- introduce Firebase
- add an LMS platform
- add an AI agent architecture
- rewrite the repository

unless explicitly requested.

Favor the simplest implementation compatible with the documented architecture.

---

## 24. Definition of Done

A task is complete only when:

- implementation matches documented architecture
- TypeScript typecheck passes
- lint passes
- relevant automated tests pass
- migrations are included if schema changed
- API contracts are documented if changed
- no unrelated functionality is broken
- changed files are listed in the final report

If any condition cannot be met, state it explicitly.
