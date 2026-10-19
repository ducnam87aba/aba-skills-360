# ABA Skills 360 — System Architecture

**Document:** `docs/architecture.md`  
**Version:** 0.1.0  
**Status:** Source of Truth  
**Product:** ABA Skills 360

---

# 1. Purpose

This document defines the technical architecture of ABA Skills 360.

All implementation decisions must comply with this document unless a later approved Architecture Decision Record explicitly replaces a decision.

Codex and human developers must read this document before implementing architecture-level changes.

If an implementation request conflicts with this document:

1. Do not silently change the architecture.
2. Report the conflict.
3. Propose options.
4. Wait for an explicit architecture decision before changing the affected design.

---

# 2. Product Definition

ABA Skills 360 is a student competency assessment and development platform for grades 1–12.

The platform is not primarily a traditional LMS.

The core value chain is:

```text
Assessment
    ↓
Skills Profile
    ↓
Recommendations
    ↓
Learning Path
    ↓
Student Activities
    ↓
Re-assessment
    ↓
Longitudinal Skills Profile
    ↓
Parent / School Reports
```

The first product moat is the measurement and longitudinal student competency system.

The current priority order is:

```text
1. Assessment Engine
2. Skills Profile
3. Parent Report
4. Recommendation Engine
5. Learning Path
6. Gamification
7. Content Library
8. AI features
```

Do not reverse this priority by building a large LMS/content library first.

---

# 3. User Types

The system must support at least:

```text
Administrator
ABA Manager
Content Editor
School Administrator
Teacher
Student
Parent / Guardian
```

Future roles may be added through WordPress capabilities.

Authorization must use capabilities and resource-level permission rules rather than hardcoded role names wherever possible.

---

# 4. Grade Groups

The product covers grades 1–12.

For assessment design, reporting, UX, and content targeting, grades may be grouped as:

```text
Grades 1–3
Grades 4–6
Grades 7–9
Grades 10–12
```

Grade grouping must be configurable rather than deeply hardcoded into business logic.

---

# 5. High-Level Architecture

ABA Skills 360 uses a hybrid/headless WordPress architecture.

```text
┌─────────────────────────────────────────────┐
│                  CLIENTS                    │
│                                             │
│ Student Web │ Parent Web │ Future Mobile    │
│             Next.js / TypeScript            │
└──────────────────────┬──────────────────────┘
                       │
                  HTTPS / JSON
                       │
┌──────────────────────▼──────────────────────┐
│              ABA REST API                   │
│          /wp-json/aba/v1/...                │
└──────────────────────┬──────────────────────┘
                       │
┌──────────────────────▼──────────────────────┐
│             ABA SKILLS CORE                 │
│        WordPress Custom Plugin              │
│                                             │
│ Assessment │ Skills │ Learning │ Reports    │
│ Schools    │ Parent │ Gamification          │
│ Recommendation                             │
└──────────────────────┬──────────────────────┘
                       │
┌──────────────────────▼──────────────────────┐
│                    DATA                     │
│                                             │
│ WordPress │ Custom Tables │ Redis │ Media   │
└─────────────────────────────────────────────┘
```

---

# 6. Core Architectural Decision

WordPress is used for:

```text
CMS
Administration
Authentication/User integration
Content management
Media management
REST API host
Back-office operations
```

WordPress is NOT the primary student or parent UI.

Student and parent experiences are implemented as a dedicated Next.js application.

Do not implement the main student application using:

```text
Elementor
Gutenberg pages
WordPress shortcodes
Traditional WordPress themes
Page-builder-driven LMS screens
```

---

# 7. Technology Stack

## 7.1 Backend

```text
WordPress
PHP 8.2+
MySQL 8.x or compatible MariaDB
ABA custom plugin
WordPress REST API
Redis where available
```

---

## 7.2 Frontend

```text
Next.js
TypeScript
App Router
Tailwind CSS
shadcn/ui
TanStack Query
Zustand
React Hook Form
Zod
Recharts
Framer Motion
```

Avoid introducing competing frameworks unless an Architecture Decision Record approves the change.

---

# 8. Repository Architecture

Use a monorepo.

```text
aba-skills-360/
│
├── AGENTS.md
├── README.md
├── package.json
├── pnpm-workspace.yaml
│
├── apps/
│   ├── web/
│   └── wordpress/
│
├── packages/
│   ├── ui/
│   ├── api-client/
│   ├── types/
│   ├── validation/
│   └── config/
│
├── docs/
│   ├── architecture.md
│   ├── database-schema.md
│   ├── api-contract.md
│   ├── coding-rules.md
│   ├── decisions/
│   └── sprints/
│
└── scripts/
```

---

# 9. WordPress Plugin Location

All ABA backend business logic belongs in:

```text
apps/wordpress/wp-content/plugins/aba-skills-core/
```

Suggested structure:

```text
aba-skills-core/
│
├── aba-skills-core.php
├── composer.json
│
├── src/
│   ├── Core/
│   ├── Database/
│   ├── REST/
│   ├── Services/
│   ├── Repositories/
│   ├── Domain/
│   ├── Admin/
│   ├── Auth/
│   ├── Events/
│   └── Cron/
│
├── migrations/
├── tests/
└── assets/
```

Application business logic must never be implemented in the active theme's `functions.php`.

---

# 10. Backend Layering

The normal request flow must be:

```text
REST Controller
      ↓
Application / Domain Service
      ↓
Repository
      ↓
Database
```

Responsibilities:

## REST Controller

Responsible for:

```text
HTTP request parsing
Authentication checks
Permission checks
Input validation
Calling services
Mapping domain results to HTTP responses
```

REST controllers must not contain complex business logic.

REST controllers must not contain raw SQL.

---

## Service

Responsible for:

```text
Business rules
Transaction coordination
Domain operations
Workflow orchestration
```

Example:

```text
AssessmentService
AssessmentScoringService
SkillProfileService
RecommendationService
ReportService
XPService
StreakService
```

---

## Repository

Responsible for:

```text
Database reads
Database writes
Query construction
Persistence mapping
```

Repositories should not make authorization decisions.

---

# 11. Domain Modules

The codebase must maintain clear domain boundaries.

```text
Domain/
├── Assessment/
├── Student/
├── Skill/
├── Learning/
├── Recommendation/
├── Report/
├── Gamification/
├── Parent/
└── School/
```

---

# 12. Assessment Domain

Assessment is a core platform domain.

Required conceptual flow:

```text
Assessment Definition
        ↓
Assessment Version
        ↓
Assessment Session
        ↓
Assessment Responses
        ↓
Scoring Engine
        ↓
Dimension / Skill Scores
        ↓
Skills Snapshot
```

An assessment must be versioned.

Historical assessment sessions must preserve the version used at the time the assessment was taken.

Changing future assessment questions must not invalidate historical results.

---

# 13. Assessment Session Lifecycle

Supported states:

```text
not_started
in_progress
completed
expired
cancelled
```

Core operations:

```text
Start
Resume
Autosave response
Complete
Read result
```

Future adaptive assessment must be possible without replacing the current architecture.

Therefore, the client must not assume that the complete assessment question set will always be downloaded at once.

---

# 14. Scoring Architecture

Scoring must be implemented behind a strategy abstraction.

Concept:

```text
ScoringStrategy
├── LikertScoringStrategy
├── ScenarioScoringStrategy
├── TeacherRatingStrategy
└── FutureIRTScoringStrategy
```

Do not put scoring formulas directly in REST controllers.

The scoring pipeline is:

```text
Responses
   ↓
Scoring Strategy
   ↓
Raw Score
   ↓
Normalization
   ↓
Skill Aggregation
   ↓
Skill Score
   ↓
Skills Snapshot
```

---

# 15. Skills Profile

The platform must support both:

```text
Current skills state
Historical skills state
```

The system must not overwrite prior measurements.

Conceptually:

```text
Student
   │
   ├── Current Skill Scores
   │
   └── Historical Skill Snapshots
```

This supports:

```text
Trend charts
Pre/post comparison
Development reports
Longitudinal analytics
Recommendation changes
```

---

# 16. Recommendation Engine

Version 1 uses deterministic rules.

Example:

```text
IF
communication_score < 50
AND grade_group = "4_6"

THEN
recommend lesson A
recommend lesson B
recommend mission C
```

The first recommendation engine must be:

```text
Explainable
Deterministic
Testable
Auditable
```

Do not introduce an LLM dependency into the core recommendation decision path for MVP.

AI may later assist with narrative explanation, but structured recommendation rules remain available as a fallback/source of truth.

---

# 17. Learning Domain

Learning content is organized conceptually as:

```text
Course
  ↓
Module
  ↓
Lesson
  ↓
Activities
```

Student-facing progression can use a Duolingo-inspired learning path.

The UX may use:

```text
Nodes
Units
Progress states
Locked/unlocked lessons
Short sessions
Rewards
Micro-interactions
```

Do not copy Duolingo branding, visual assets, character designs, or exact layouts.

ABA must maintain its own design language.

---

# 18. Student Navigation

Primary navigation must remain:

```text
Học
Khám phá
Nhiệm vụ
Thành tích
Hồ sơ
```

Do not add a new primary tab without an explicit product decision.

---

# 19. Parent Navigation

Primary parent navigation:

```text
Tổng quan
Năng lực
Báo cáo
Hoạt động
Hồ sơ
```

---

# 20. Parent Report Architecture

Reports must be based on structured data.

Do not treat generated HTML/PDF as the source of truth.

The source report model should contain structured sections such as:

```json
{
  "summary": {},
  "skills": [],
  "strengths": [],
  "growthAreas": [],
  "recommendations": [],
  "learningPlan": []
}
```

Structured report data can then be rendered to:

```text
Web
PDF
Email
Future mobile view
```

---

# 21. Gamification Architecture

Gamification includes:

```text
XP
Level
Streak
Badges
Missions
Achievements
```

XP must use a transaction ledger.

Do not use only:

```text
student.total_xp
```

as the source of truth.

Instead:

```text
XP Transaction Ledger
        ↓
Cached Total XP
```

Example transactions:

```text
+20 lesson completed
+10 daily streak
+30 mission completed
+50 assessment completed
```

---

# 22. WordPress Content Model

Use Custom Post Types for editorial/content objects.

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

Possible taxonomies:

```text
aba_grade
aba_skill_domain
aba_topic
aba_difficulty
aba_content_type
```

Use CPTs where WordPress editorial workflows are useful.

Do not use CPT/postmeta for high-volume transactional records.

---

# 23. Custom Database Tables

Use custom tables for:

```text
Schools
Classes
Student profile data
Parent/student relations
Assessment sessions
Assessment responses
Skill scores
Skill snapshots
Lesson progress
XP transactions
Streaks
Mission progress
Student badges
Reports
Analytics/events
```

Exact definitions are maintained in:

```text
docs/database-schema.md
```

---

# 24. API Architecture

All custom API routes use:

```text
/wp-json/aba/v1/
```

Example:

```text
/wp-json/aba/v1/me
/wp-json/aba/v1/learning/path
/wp-json/aba/v1/assessments/{id}/start
```

The API contract is defined in:

```text
docs/api-contract.md
```

Frontend components must consume the shared API client.

Do not scatter arbitrary `fetch()` calls across React components.

---

# 25. Frontend Architecture

Main application:

```text
apps/web/
```

Suggested structure:

```text
src/
├── app/
├── components/
├── features/
├── hooks/
├── lib/
├── services/
├── stores/
├── config/
└── types/
```

Use feature-based architecture:

```text
features/
├── auth/
├── learning-path/
├── lesson/
├── assessment/
├── skills/
├── recommendation/
├── parent-report/
├── gamification/
├── missions/
├── achievements/
└── profile/
```

---

# 26. App Router Groups

Recommended structure:

```text
app/
│
├── (auth)/
│   ├── login/
│   └── forgot-password/
│
├── (student)/
│   ├── learn/
│   ├── explore/
│   ├── missions/
│   ├── achievements/
│   ├── profile/
│   └── assessment/
│
└── (parent)/
    ├── overview/
    ├── skills/
    ├── reports/
    ├── activities/
    └── profile/
```

---

# 27. Shared Packages

## `packages/ui`

Reusable visual components.

Must not contain application-specific business rules.

---

## `packages/api-client`

Typed API communication.

Example interface:

```ts
assessment.start()
assessment.saveResponses()
assessment.complete()

skills.getCurrent()
skills.getHistory()

learning.getPath()

parent.getChildren()
reports.getReport()
```

---

## `packages/types`

Shared TypeScript domain and API types.

---

## `packages/validation`

Shared Zod schemas where appropriate.

---

# 28. State Management

Use:

```text
TanStack Query → remote/server state
Zustand        → limited local application state
React state    → component state
```

Do not mirror all server state into Zustand.

---

# 29. Authentication

WordPress remains the main user identity system for the initial architecture.

The frontend authentication mechanism must support secure web application access without exposing WordPress admin functionality to students.

Authentication implementation may use:

```text
Secure HTTP-only cookies
Short-lived access tokens
Refresh/session mechanisms
```

Final implementation must be documented before production.

Never store long-lived sensitive tokens in browser localStorage without an explicit security review.

---

# 30. Authorization

Authorization must be enforced server-side.

Examples:

```text
Student → own data only

Parent → linked children only

Teacher → assigned students/classes only

School Admin → users/resources in own school

ABA Manager → permitted organization scope

Administrator → platform-level access
```

Never rely solely on UI visibility.

Changing a resource ID must never allow access to another user's protected resource.

---

# 31. Multi-School Design

All school-related architecture should assume multi-school operation.

Conceptual hierarchy:

```text
Platform
   ↓
School
   ↓
Class
   ↓
Student
```

Where relevant, database queries must be scoped by school.

Do not design MVP tables as if only one school will ever exist.

---

# 32. Security Principles

Student data requires stricter handling than ordinary CMS content.

Mandatory principles:

```text
Least privilege
Data minimization
Server-side authorization
Input validation
Output escaping/sanitization
Auditability
Secure session management
Rate limiting where appropriate
No sensitive data in client logs
No secrets in repository
```

---

# 33. Student Privacy

Do not collect unnecessary personal data.

Fields must have a clear operational purpose.

Sensitive data must not be included in analytics events unless required.

Analytics metadata should prefer internal identifiers over personally identifying strings.

---

# 34. Event Tracking

The system should track meaningful product events such as:

```text
app_opened
assessment_started
question_answered
assessment_completed
lesson_started
lesson_completed
mission_completed
report_viewed
```

Analytics/event architecture must remain separate from core transactional correctness.

Failure to record an analytics event must not normally invalidate completion of a lesson or assessment transaction.

---

# 35. Database Migrations

All custom schema changes require version-controlled migrations.

Example:

```text
migrations/
├── 001_create_schools.php
├── 002_create_classes.php
├── 003_create_students.php
└── 004_create_assessment_sessions.php
```

Do not modify production database structure manually.

Migrations should be:

```text
Repeatable where safe
Versioned
Reviewable
Tested
```

---

# 36. API Versioning

Initial namespace:

```text
aba/v1
```

Breaking API changes must not silently change existing contracts.

A future breaking API may require:

```text
aba/v2
```

Prefer backward-compatible additive changes where practical.

---

# 37. Error Format

Use a consistent error format.

Example:

```json
{
  "error": {
    "code": "ASSESSMENT_NOT_FOUND",
    "message": "Assessment not found.",
    "details": {}
  }
}
```

Do not expose stack traces or sensitive server information in production responses.

---

# 38. Performance Principles

Do not prematurely micro-optimize the entire system.

However, design high-volume paths correctly from the beginning.

High-volume data includes:

```text
Assessment responses
Progress events
XP transactions
Skill measurements
Analytics events
```

These must use custom tables and suitable indexes.

Potential future infrastructure:

```text
Redis cache
Object storage
CDN
Queue/background workers
Read replicas
Analytics warehouse
```

Do not introduce this infrastructure before there is a demonstrated need unless explicitly approved.

---

# 39. Caching

Cache only data that can be reconstructed from the system of record.

Examples suitable for caching:

```text
Total XP
Student dashboard summary
Learning path
Aggregated skills summary
Public/content metadata
```

Transactional records remain the source of truth.

---

# 40. Testing Strategy

Critical domains require automated tests.

Highest priority:

```text
Assessment lifecycle
Authorization
Scoring
Skill aggregation
Parent-child permissions
School boundaries
XP transactions
Recommendation rules
Report data generation
```

Frontend visual components should also be tested where behavior is meaningful.

---

# 41. Assessment Critical Tests

At minimum:

```text
Student can start an eligible assessment.

Student cannot access another student's session.

Only one active session is created when expected.

Responses can be resumed.

Completed sessions preserve assessment version.

Completed sessions cannot be accidentally rewritten.

Scoring uses the configured strategy.

Historical scores remain available.

Permission checks prevent cross-school access.
```

---

# 42. Development Workflow

For each Sprint:

```text
Read specification
      ↓
Inspect repository
      ↓
Plan change
      ↓
Implement
      ↓
Test
      ↓
Architecture audit
      ↓
Commit
```

Do not begin the next Sprint automatically.

---

# 43. Deployment Architecture — Initial

A practical initial deployment may be:

```text
Cloudflare
    │
    ├── aba.edu.vn/app/ → Next.js
    │
    └── API/Admin → WordPress
```

Possible logical separation:

```text
app.aba.edu.vn
admin.aba.edu.vn
api.aba.edu.vn
```

Exact production domains may change without changing application architecture.

---

# 44. Future Mobile

The backend must remain client-independent.

Future clients may include:

```text
React Native mobile app
Electron desktop app
School dashboard
Teacher application
```

They should reuse the ABA REST API rather than duplicate business logic.

---

# 45. AI Architecture — Future

AI is not part of the MVP decision path.

Potential future use:

```text
Narrative parent report explanations
Personalized coaching suggestions
Content assistance
Teacher assistance
Student learning assistant
```

AI output must not overwrite structured assessment results.

The platform should distinguish:

```text
Measured score
Rule-derived recommendation
AI-generated explanation
```

These are separate concepts.

---

# 46. Explicit Non-Goals for Early MVP

Do not prioritize:

```text
Marketplace
Large social network
Real-time chat
Large course marketplace
AI tutor
Complex microservices
GraphQL
Blockchain
Custom video streaming platform
```

unless separately approved.

---

# 47. Architecture Invariants

The following are considered architecture invariants for the current version:

1. WordPress is the CMS/backend foundation.
2. ABA business logic lives in a custom plugin.
3. Student/parent app uses Next.js + TypeScript.
4. REST is the primary client/backend API.
5. High-volume transactional data uses custom tables.
6. Editorial learning content may use CPTs.
7. Assessment sessions are versioned.
8. Skills history is never represented only by one mutable score.
9. XP uses a transaction ledger.
10. Authorization is enforced server-side.
11. Multi-school scope is considered from the beginning.
12. Assessment Engine, Skills Profile, and Parent Report remain core domains.
13. Core recommendation v1 is deterministic.
14. AI is not the source of truth for measured competency scores.

---

# 48. Change Control

If a developer believes an invariant should change:

Create an ADR in:

```text
docs/decisions/
```

The ADR should state:

```text
Context
Decision
Alternatives considered
Consequences
Migration impact
Status
```

Do not silently introduce architectural drift through implementation.

---

# 49. Definition of Architecture Compliance

A feature is architecture-compliant when:

```text
It respects domain boundaries.
It follows controller → service → repository layering.
It uses correct persistence strategy.
It implements authorization server-side.
It follows the published API contract.
It preserves historical assessment data.
It does not introduce unnecessary platform dependencies.
It includes migrations where schema changes occur.
It includes relevant tests.
```

---

# 50. Final Principle

ABA Skills 360 should be treated as an EdTech platform that happens to use WordPress as part of its backend architecture.

It must not evolve into a collection of unrelated WordPress plugins.

The product architecture should optimize for:

```text
Measurement quality
Longitudinal student data
Explainable recommendations
Parent value
School reporting
Extensibility
Security
Maintainability
```
