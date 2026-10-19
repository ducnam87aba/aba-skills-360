# ABA Skills 360 — Assessment Engine Specification

**Document:** `docs/assessment-spec.md`  
**Version:** 0.1.0  
**Status:** Source of Truth — Measurement & Assessment  
**Product:** ABA Skills 360

---

# 1. Purpose

This document defines the behavioral, measurement, scoring, versioning, and data-processing rules of the ABA Skills 360 Assessment Engine.

It is the source of truth for:

- assessment structure
- competency mapping
- question structure
- respondent types
- age/grade groups
- assessment versioning
- question versioning
- scoring
- reverse scoring
- normalization
- missing data
- skill aggregation
- quality flags
- profile snapshot generation
- comparability rules
- report input data

This document governs implementation behavior.

Codex and human developers must not invent new scoring rules or silently modify measurement logic.

If requested implementation conflicts with this document:

1. Stop the conflicting implementation.
2. Report the conflict.
3. Propose the required specification change.
4. Do not alter historical scoring behavior without an explicit approved version.

---

# 2. Measurement Status

ABA Skills 360 v0.1 is a product measurement framework under development.

The current framework:

```text
is suitable for:
- product prototyping
- internal review
- pilot testing
- UX development
- technical implementation
- preliminary developmental feedback

is NOT yet suitable for:
- clinical diagnosis
- psychological diagnosis
- school admission
- student selection
- high-stakes ranking
- employment/career decisions
- claims of standardized national norms
```

Scores produced by the v0.1 engine must not be described as:

```text
IQ
percentile
standard score
diagnosis
validated norm
mastery percentage
CASEL score
OECD SSES score
```

unless a future validated scoring model explicitly introduces such interpretations.

---

# 3. Core Measurement Principle

ABA Skills 360 must preserve the distinction between:

```text
Observed / reported behavior
        ↓
Item responses
        ↓
Scoring rules
        ↓
Skill indicators
        ↓
Skill score
        ↓
Profile snapshot
        ↓
Interpretation
        ↓
Recommendation
```

A recommendation is not a measurement.

A narrative explanation is not a measurement.

Gamification is not a measurement.

XP must never affect skill scores.

---

# 4. Competency Framework v0.1

The current ABA framework contains six competencies.

| Code | Competency | Operational meaning |
|---|---|---|
| SA | Tự nhận thức | Nhận biết cảm xúc, nhu cầu và cách mình thực hiện nhiệm vụ |
| ER | Quản lý cảm xúc | Lựa chọn cách phản ứng an toàn, phù hợp khi có cảm xúc mạnh |
| SM | Tự quản lý | Tổ chức hành động và duy trì trách nhiệm với nhiệm vụ phù hợp tuổi |
| CO | Giao tiếp | Lắng nghe, diễn đạt và trao đổi bất đồng có tôn trọng |
| CE | Hợp tác và đồng cảm | Nhận biết góc nhìn người khác, phối hợp và quan tâm |
| PD | Giải quyết vấn đề và ra quyết định | Xác định vấn đề, cân nhắc lựa chọn và hệ quả phù hợp bối cảnh |

These codes are persistent identifiers.

Do not rename persisted skill codes merely for UI wording changes.

---

# 5. Skill Indicators

Each competency currently contains three primary indicators.

## SA — Tự nhận thức

```text
SA-1 Gọi tên cảm xúc
SA-2 Nhận biết tác nhân
SA-3 Tự phản ánh
```

## ER — Quản lý cảm xúc

```text
ER-1 Tạm dừng
ER-2 Dùng cách điều chỉnh
ER-3 Tìm hỗ trợ
```

## SM — Tự quản lý

```text
SM-1 Lập kế hoạch
SM-2 Duy trì nỗ lực
SM-3 Theo dõi và điều chỉnh
```

## CO — Giao tiếp

```text
CO-1 Lắng nghe
CO-2 Nói rõ nhu cầu
CO-3 Phản hồi bất đồng
```

## CE — Hợp tác và đồng cảm

```text
CE-1 Hiểu góc nhìn
CE-2 Phối hợp
CE-3 Hỗ trợ và tôn trọng khác biệt
```

## PD — Giải quyết vấn đề và ra quyết định

```text
PD-1 Xác định vấn đề
PD-2 Tạo lựa chọn
PD-3 Cân nhắc và xem lại
```

Indicator identifiers must remain stable after production publication.

---

# 6. Grade / Development Groups

The initial framework uses four grade groups.

```text
A = Grades 1–3
B = Grades 4–6
C = Grades 7–9
D = Grades 10–12
```

Machine-readable values:

```text
A
B
C
D
```

Suggested internal labels:

```text
grade_1_3
grade_4_6
grade_7_9
grade_10_12
```

Grade group is part of assessment comparability.

---

# 7. Developmental Interpretation

The same skill does not imply identical observable behavior at every grade group.

Therefore:

```text
SA-A
SA-B
SA-C
SA-D
```

are related constructs but must not automatically be assumed to form one equivalent numerical scale.

A student moving between grade groups enters a new measurement cohort.

Do not automatically draw one continuous progress line across grade-group boundaries unless a future linking/equating study supports it.

---

# 8. Respondent Types

Supported respondent types:

```text
S = Student
P = Parent / Guardian
T = Teacher
```

Machine-readable values:

```text
student
parent
teacher
```

Respondent type is part of the measurement context.

Scores from different respondent types must remain distinguishable.

---

# 9. Respondent Rules by Group

## Group A — Grades 1–3

Primary scored sources:

```text
Parent
Teacher
```

Children may complete age-appropriate scenario/image activities.

Those child activity results remain separate from adult observation scores unless a future approved measurement model specifies otherwise.

Do not calculate a standard numeric self-report skill profile from Group A children in v0.1.

---

## Group B — Grades 4–6

Possible sources:

```text
Student
Parent
Teacher
Scenario assessment
```

Student self-report may be scored if the form is appropriate and the student can understand the items.

Grade alone must not be treated as proof that every student can independently complete the form.

---

## Group C — Grades 7–9

Possible sources:

```text
Student
Parent
Teacher
Scenario assessment
```

Student self-report is a primary source.

Adult observations may supplement it.

---

## Group D — Grades 10–12

Possible sources:

```text
Student
Parent
Teacher
Scenario assessment
```

Student self-report is a primary source.

Adult observations may supplement it.

---

# 10. Source Separation Rule

The Assessment Engine must preserve measurement source.

Example:

```text
communication.student = 72
communication.parent = 78
communication.teacher = 64
```

Do NOT automatically calculate:

```text
communication = average(72, 78, 64)
```

unless a future approved multi-informant model explicitly defines:

- weights
- eligibility
- missingness
- calibration
- interpretation.

Source disagreement is not automatically an error.

It may reflect different behavior in different contexts.

---

# 11. Assessment Families

The initial engine supports three conceptual assessment activity types.

```text
frequency
scenario
evidence
```

MVP scoring is primarily defined for:

```text
frequency
scenario
```

Evidence/open-text items may be collected but are not automatically converted into skill scores in v0.1.

---

# 12. Question Types

Machine-readable initial types:

```text
frequency_5
scenario_single_choice
evidence_text
```

Future types may include:

```text
multi_choice
ranking
image_choice
teacher_quick_observation
audio_response
performance_task
```

New question types must define a scoring strategy before contributing to skill scores.

---

# 13. Frequency Item

A frequency item represents one specific observable or self-reported behavior.

Example structure:

```json
{
  "questionKey": "B-CO-S01",
  "type": "frequency_5",
  "ageBand": "B",
  "respondentType": "student",
  "skillKey": "CO",
  "indicatorKey": "CO-1",
  "reverse": false,
  "prompt": "...",
  "scoringStrategy": "frequency_5_v1"
}
```

---

# 14. Frequency Response Scale

The current scale is:

```text
1 = Chưa lần nào
2 = Hiếm khi
3 = Thỉnh thoảng
4 = Thường xuyên
5 = Gần như mỗi lần
```

The observation/recall period is:

```text
two weeks
```

unless the published form explicitly specifies otherwise.

---

# 15. Non-Scored Frequency Responses

Supported missing-response reasons include:

```text
not_observed
skip
not_understood
```

UI labels may be:

```text
Chưa có cơ hội quan sát
Muốn bỏ qua
Chưa hiểu câu hỏi
```

These are NOT numerical values.

They must not be transformed into:

```text
0
1
minimum score
negative score
```

They remain missing observations with explicit reason codes.

---

# 16. Missing Response Model

A response should distinguish:

```json
{
  "answered": false,
  "missingReason": "not_observed"
}
```

from:

```json
{
  "answered": true,
  "value": 1
}
```

These are fundamentally different.

---

# 17. Item Writing Constraints

A scorable frequency item should:

```text
measure one behavior
use age-appropriate language
refer to a concrete behavior
avoid double-barreled statements
avoid double negatives
avoid assumptions about resources/access
avoid treating quietness as poor communication
avoid treating obedience as self-management
avoid treating absence of visible anger as good emotion regulation
```

The application cannot fully validate item quality automatically.

Publishing therefore requires human review.

---

# 18. Recall Period

Default:

```text
last 2 weeks
```

The recall period belongs to the assessment/form version.

Changing recall period requires a new assessment version.

---

# 19. Question Identity vs Question Version

Question identity and question content version must be conceptually separate.

Example:

```text
Question identity:
B-CO-S01

Question versions:
B-CO-S01@1
B-CO-S01@2
```

A published version is immutable.

Editing wording after publication creates a new version.

Do not mutate an item already used in submitted sessions.

---

# 20. Immutable Published Questions

Once a question version is used by a published assessment version:

```text
prompt
options
skill mapping
indicator mapping
reverse flag
scoring configuration
respondent type
age band
```

must be treated as immutable historical measurement configuration.

A correction requires a new version.

---

# 21. Assessment Template

An assessment template defines the conceptual assessment.

Example:

```text
ABA-B-S
```

A template is not itself the immutable administered instrument.

Administration uses a version.

---

# 22. Assessment Version

Example:

```text
ABA-B-S-0.1
ABA-B-S-0.2
ABA-B-S-1.0
```

A published assessment version must freeze:

```text
assessment identity
version
respondent type
grade group
question versions
question order rules
question weights
missing-data rules
scoring strategy/version
minimum evidence rules
instructions
recall period
```

---

# 23. Version Lifecycle

Recommended states:

```text
draft
in_review
approved
published
retired
```

Only:

```text
published
```

versions may be assigned to normal production assessment sessions.

---

# 24. Published Version Rule

After publication:

```text
DO NOT mutate assessment structure.
```

To change:

```text
questions
weights
missing threshold
instructions affecting measurement
scoring algorithm
```

create a new version.

---

# 25. Assessment Session Binding

At session creation, permanently bind the session to:

```text
assessment_id
assessment_version
scoring_version
respondent_type
student_id
grade_group
```

Recommended additional reproducibility fields:

```text
question_set_hash
content_version
```

Historical sessions must not follow the latest template automatically.

---

# 26. Assessment Session States

Supported states:

```text
in_progress
submitted
completed
expired
cancelled
```

Interpretation:

```text
in_progress
= responses may be saved

submitted
= respondent has finalized answers

completed
= scoring pipeline successfully finalized

expired
= session is no longer normally editable

cancelled
= invalidated by authorized workflow
```

If MVP combines `submitted` and `completed`, the implementation must still preserve the conceptual difference for future asynchronous scoring.

---

# 27. Submission Immutability

After normal submission:

```text
responses must not be edited in place.
```

If reassessment is required:

```text
create new assignment/session
```

Do not rewrite a historical submitted session.

Exceptional administrative correction must use an auditable workflow and must not silently change prior results.

---

# 28. Frequency Scoring — Raw Value

For a non-reverse item:

```text
raw = response
```

where:

```text
response ∈ {1,2,3,4,5}
```

Example:

```text
response = 4
raw = 4
```

---

# 29. Reverse Scoring

For a five-point frequency item marked:

```text
reverse = true
```

use:

```text
reverse_score = 6 - response
```

Mapping:

| Original | Reversed |
|---:|---:|
| 1 | 5 |
| 2 | 4 |
| 3 | 3 |
| 4 | 2 |
| 5 | 1 |

Example:

```text
response = 2

reverse = true

scored value = 6 - 2 = 4
```

---

# 30. Reverse Flag Location

The reverse flag belongs to the immutable question version or template-item configuration approved for that scoring version.

It must never be inferred from wording at runtime.

Unsafe:

```text
AI detects negative wording → reverse score
```

Correct:

```text
published scoring configuration explicitly states reverse=true
```

---

# 31. Frequency Normalization

The normalized 0–100 value for a frequency item is:

```text
normalized = ((scored_value - 1) / 4) * 100
```

Therefore:

| Scored value | Normalized |
|---:|---:|
| 1 | 0 |
| 2 | 25 |
| 3 | 50 |
| 4 | 75 |
| 5 | 100 |

This is a linear descriptive transformation.

It is NOT a percentile.

---

# 32. Frequency Score Representations

The engine may retain both:

```text
raw 1–5 value
normalized 0–100 value
```

Reports may choose the representation appropriate to the approved report design.

Do not describe `75/100` as:

```text
75% competent
75th percentile
75% mastery
```

without a future validated interpretation framework.

---

# 33. Scenario Item

A scenario item presents a situation and asks the respondent to select or perform a response.

Example:

```json
{
  "questionKey": "B-PD-CASE01",
  "type": "scenario_single_choice",
  "ageBand": "B",
  "skillKey": "PD",
  "indicatorKey": "PD-2",
  "scoringStrategy": "scenario_rubric_v1"
}
```

---

# 34. Scenario Scoring

Scenario responses are scored by an explicit rubric.

Example:

```json
{
  "A": 0,
  "B": 1,
  "C": 2,
  "D": 3
}
```

The scoring key must be server-side.

Do not expose score values to the client before completion.

---

# 35. Scenario Normalization

Given:

```text
s = rubric score
min = minimum possible rubric score
max = maximum possible rubric score
```

normalize:

```text
normalized = ((s - min) / (max - min)) * 100
```

Precondition:

```text
max > min
```

If:

```text
max == min
```

the scoring configuration is invalid and publication must fail.

---

# 36. Scenario Feedback

During an active scored scenario assessment:

```text
do not provide correctness feedback that can influence subsequent answers.
```

Educational feedback may be provided after relevant assessment completion if approved in the experience design.

---

# 37. Evidence / Open Text

Example type:

```text
evidence_text
```

Responses may be stored as evidence.

MVP rule:

```text
do not automatically convert open-text evidence into a skill score.
```

AI analysis of open text, if added later, must be stored separately from validated measured scores unless a future scoring specification approves it.

---

# 38. Client Scoring Prohibition

The client must NEVER be the authority for scoring.

Client sends:

```json
{
  "questionId": 123,
  "answer": 4
}
```

Client must not be trusted if it sends:

```json
{
  "score": 100
}
```

Server scoring must derive results from:

```text
saved answer
+
published question version
+
published scoring configuration
```

---

# 39. Item Weight

Default item weight:

```text
1.0
```

Allowed rule:

```text
weight > 0
```

Negative weights are prohibited.

A zero-weight item should normally be considered non-scoring rather than included in the score formula.

Weights belong to the published assessment configuration.

---

# 40. Skill Mapping

Each scorable item must map to:

```text
one primary skill
```

and normally:

```text
one indicator
```

Example:

```json
{
  "skillKey": "CO",
  "indicatorKey": "CO-2"
}
```

MVP should avoid hidden many-skill weighting from one item unless explicitly defined and reviewed.

---

# 41. Skill Aggregation — Frequency

For eligible normalized item scores:

```text
skill_score =
SUM(item_normalized × item_weight)
/
SUM(item_weight)
```

Only eligible answered items are included.

Missing items do not enter the numerator.

Missing items do not enter the denominator.

---

# 42. Example Skill Calculation

Responses for CO:

```text
Item 1 = 4
Item 2 = 3
Item 3 = missing
Item 4 = 5
```

Normalized:

```text
Item 1 = 75
Item 2 = 50
Item 3 = missing
Item 4 = 100
```

If all weights = 1:

```text
CO =
(75 + 50 + 100) / 3

= 75
```

The missing item is not treated as zero.

---

# 43. Minimum Evidence Rule

A skill score must only be produced when sufficient valid responses exist.

The current v0.1 forms use form-specific minimum evidence rules.

Examples currently used include:

```text
3 valid items out of 4

or

4 valid items out of 5
```

The threshold must belong to the immutable assessment version configuration.

Do not hardcode one global threshold across all forms.

---

# 44. Generic Completeness Configuration

Recommended machine configuration:

```json
{
  "minimumAnswered": 3,
  "eligibleItems": 4
}
```

or, if approved:

```json
{
  "minimumCompletionRate": 0.8,
  "minimumAnswered": 3
}
```

The actual published form configuration is authoritative.

---

# 45. Insufficient Evidence

If the minimum evidence threshold is not met:

```json
{
  "status": "insufficient_evidence",
  "score": null
}
```

Do not output:

```text
0
```

because `0` would imply an observed low score.

---

# 46. Missing Data Principle

This is invalid:

```text
missing = 0
```

This is valid:

```text
missing = excluded from score
```

subject to the minimum evidence rule.

---

# 47. Missing Reason Retention

The engine should preserve reason:

```text
not_observed
skip
not_understood
technical_failure
```

Potential future reason:

```text
not_applicable
```

Do not collapse every missing response into one untraceable null when reason information exists.

---

# 48. Score Result Structure

Recommended structure:

```json
{
  "skillKey": "CO",

  "rawScore": 4.0,

  "normalizedScore": 75.0,

  "answeredCount": 3,
  "eligibleCount": 4,

  "status": "valid",

  "qualityFlags": [],

  "sourceType": "student",

  "scoringVersion": "ABA-FREQ-0.1"
}
```

---

# 49. Scoring Version

Scoring logic must have a stable version identifier.

Examples:

```text
ABA-FREQ-0.1
ABA-CASE-0.1
```

A scoring change capable of changing output for the same responses must create a new scoring version.

---

# 50. Scoring Reproducibility

Given:

```text
same responses
same question versions
same template version
same scoring version
```

the engine must generate the same deterministic structured score output.

This property should have automated tests.

---

# 51. No Automatic Historical Recalculation

When scoring changes from:

```text
ABA-FREQ-0.1
```

to:

```text
ABA-FREQ-0.2
```

do NOT silently overwrite historical results.

If historical recalculation is scientifically/product-approved:

```text
create a new score run
preserve old score run
record the new scoring version
record why recalculation occurred
```

---

# 52. Score Run

Recommended conceptual entity:

```text
ScoreRun
```

A score run represents one execution of scoring logic for one submitted assessment session.

Suggested fields:

```text
id
session_id
scoring_version
status
started_at
completed_at
quality_flags
```

A score run enables reproducibility and later re-scoring without overwriting historical measurements.

---

# 53. Skill Score Provenance

Every skill score must be traceable to:

```text
student
assessment session
assessment version
respondent type
question versions
score run
scoring version
measurement time
```

The UI does not need to expose all of this.

The backend must preserve it.

---

# 54. Multi-Source Rule

The following must remain separate:

```text
SA@student
SA@parent
SA@teacher
```

unless a future scoring model explicitly combines them.

The platform may display sources side by side.

Example:

```text
Tự quản lý

HS     68
PH     74
GV     61
```

This is preferred over an unjustified combined score.

---

# 55. Source Difference Interpretation

Do not implement:

```text
parent_score != teacher_score
→ someone is incorrect
```

Contextual variation may be meaningful.

Report language should describe source/context rather than declaring which respondent is “right”.

---

# 56. Cross-Form Comparability

Scores should only be directly compared over time when measurement conditions are considered compatible.

Compatibility must consider at least:

```text
skill framework
grade group
respondent type
form/version compatibility
scoring version compatibility
measurement method
```

Do not automatically compare any two normalized 0–100 scores simply because they share a skill key.

---

# 57. Compatibility Metadata

Recommended future-proof field:

```text
comparison_group_key
```

Example:

```text
CO:B:student:freq:v0.1-family
```

Two scores may be plotted as a continuous trend only when compatibility rules allow it.

---

# 58. Grade Transition Rule

When a student moves:

```text
Group A → Group B

or

Group B → Group C

or

Group C → Group D
```

the previous profile remains historical.

The new group begins a new comparable series unless a validated linking method has been approved.

UX may visually show history but must not imply precise numeric continuity across incompatible scales.

---

# 59. Profile Snapshot

A profile snapshot is an immutable structured representation of an eligible set of measurement results at a point in time.

Concept:

```text
Measurement results
        ↓
Snapshot rules
        ↓
Profile Snapshot
```

Snapshot is not a replacement for underlying scores.

---

# 60. Snapshot Creation Trigger

A new snapshot may be generated when:

```text
a valid scoring run completes

or

an explicitly approved multi-source/profile update occurs
```

MVP primary trigger:

```text
successful assessment scoring completion
```

---

# 61. Snapshot Contents

Recommended structure:

```json
{
  "studentId": 123,

  "asOf": "2026-10-19T08:00:00Z",

  "gradeGroup": "B",

  "frameworkVersion": "ABA-SEL-0.1",

  "ruleVersion": "PROFILE-0.1",

  "sources": [
    {
      "sourceType": "student",
      "scoreRunId": 100
    }
  ],

  "skills": {
    "SA": {
      "score": 62.5,
      "status": "valid"
    },

    "ER": {
      "score": null,
      "status": "insufficient_evidence"
    },

    "SM": {
      "score": 70,
      "status": "valid"
    }
  }
}
```

---

# 62. Snapshot Immutability

Once generated and referenced by a report:

```text
do not mutate the snapshot in place.
```

If profile rules change:

```text
generate a new snapshot
with new ruleVersion
```

Historical reports remain reproducible.

---

# 63. Snapshot Rule Version

Profile aggregation logic must have a version.

Example:

```text
PROFILE-0.1
```

This is distinct from:

```text
assessmentVersion
scoringVersion
```

---

# 64. Current Profile

“Current Skills Profile” is a projection over the latest eligible compatible measurements.

It is not one mutable database score.

Concept:

```text
historical measurements
        ↓
profile selection rules
        ↓
current projection
```

---

# 65. Source-Specific Current Profile

Recommended current profile structure:

```text
student self-report profile
parent observation profile
teacher observation profile
scenario evidence
```

rather than an unsupported single blended score.

---

# 66. Scenario Results and Frequency Scores

Do not automatically mix:

```text
frequency self-report
+
scenario rubric
```

into one skill number unless a future approved model defines the combination.

Scenario results can be shown as complementary evidence.

---

# 67. Quality Flags

Quality flags must NOT automatically mean dishonesty.

Initial possible flags:

```text
too_fast
high_missingness
same_response_pattern
many_not_understood
accessibility_support_used
interrupted_session
```

These indicate review/context.

They do not automatically invalidate a result.

---

# 68. Too Fast Flag

Timing can be used as:

```text
quality metadata
```

not as a definitive truthfulness judgment.

Do not automatically invalidate a session merely because completion time is short.

---

# 69. Same Response Pattern

Example:

```text
5,5,5,5,5,5,5,5...
```

may create a review flag.

It must not automatically generate:

```text
invalid
dishonest
fake
```

without an approved quality-control rule.

---

# 70. Accessibility Support

The system may record support such as:

```text
item_read_aloud
audio_support
keyboard_navigation
other approved accommodation
```

Support usage must not automatically reduce a score.

Any comparability consequence requires explicit measurement review.

---

# 71. Assessment Instructions

Student-facing instruction should communicate that:

```text
this is not a school exam
self-report items do not have correct/incorrect answers
students may skip items they do not understand or do not wish to answer
responses help select suitable activities
```

For self-report, do not award XP based on choosing a “better” response.

---

# 72. XP and Assessment

Allowed:

```text
+XP for completing an eligible assessment
```

Prohibited:

```text
+XP for selecting high-scoring social-emotional answers
```

This avoids incentivizing socially desirable responses.

Example:

```text
assessment completed = +50 XP
```

regardless of whether the measured score is high or low.

---

# 73. Assessment UX Rule

Use:

```text
one primary question/action per screen
```

where practical.

Student UX should prioritize:

```text
clarity
low cognitive load
progress visibility
resume support
accessibility
```

The UX may feel game-like.

The measurement itself must not be gamed by rewarding desired answers.

---

# 74. Adaptive Assessment

The architecture may support adaptive assessment in the future.

MVP must not assume:

```text
all questions are always downloaded at session start.
```

The backend may serve question batches.

Adaptive selection algorithms are NOT defined in v0.1 and must not be invented by Codex.

---

# 75. Randomization

Question randomization must only occur when allowed by the published assessment version.

Configuration example:

```json
{
  "questionOrder": "fixed"
}
```

or:

```json
{
  "questionOrder": "random_within_skill"
}
```

Codex must not randomize assessment order by default.

---

# 76. Option Randomization

Likewise:

```text
do not shuffle answer options unless the item configuration explicitly permits it.
```

For frequency scales:

```text
1 → 5 ordering should normally remain stable.
```

---

# 77. Question Exposure

The client receives only information needed to administer the assessment.

Do not expose:

```text
reverse flag
rubric scores
skill weights
correct answer
scoring key
```

if such exposure could alter response behavior or compromise assessment integrity.

Scoring configuration remains server-side.

---

# 78. Data Flow

Required assessment flow:

```text
Draft Questions
      ↓
Human Review
      ↓
Publish Question Versions
      ↓
Create Assessment Version
      ↓
Validate Configuration
      ↓
Publish Assessment Version
      ↓
Assign Assessment
      ↓
Start Session
      ↓
Save Responses
      ↓
Submit
      ↓
Create Score Run
      ↓
Score
      ↓
Validate Evidence
      ↓
Create Skill Scores
      ↓
Create Profile Snapshot
      ↓
Generate Report / Recommendations
```

---

# 79. Publish Validation

An assessment version must fail publication when:

```text
a referenced question version does not exist

a scoring question lacks a scoring strategy

a skill key is invalid

an indicator key is invalid

reverse configuration is invalid

weight < 0

scenario max == min

minimum evidence threshold is impossible

respondent type conflicts with form

grade group is missing

scoring version is missing
```

---

# 80. Scoring Transaction Safety

Submission should prevent partial inconsistent state.

Conceptually:

```text
submit session
+
create scoring job / scoring transaction
```

must be recoverable.

If scoring fails:

```text
the submitted responses remain intact
scoring can be retried
```

Do not require the respondent to retake the assessment because of a backend scoring failure.

---

# 81. Idempotency

The following must be idempotent:

```text
session submission
scoring run creation where applicable
snapshot creation for the same score run/rule version
assessment completion reward
```

Retry after network failure must not:

```text
create duplicate skill scores
create duplicate snapshots
grant duplicate XP
```

---

# 82. Scoring Service Boundary

Recommended backend structure:

```text
AssessmentSubmissionService
        ↓
ScoringService
        ↓
ScoringStrategy
        ↓
SkillAggregationService
        ↓
ProfileSnapshotService
```

REST controllers must not calculate scores.

---

# 83. Suggested TypeScript/PHP Conceptual Models

Conceptual item:

```ts
type AssessmentItem = {
  questionVersionId: number
  questionKey: string

  type:
    | 'frequency_5'
    | 'scenario_single_choice'
    | 'evidence_text'

  ageBand: 'A' | 'B' | 'C' | 'D'

  respondentType:
    | 'student'
    | 'parent'
    | 'teacher'

  skillKey: SkillKey | null
  indicatorKey: string | null

  weight: number

  reverse: boolean

  scoringStrategy: string | null
}
```

---

# 84. Skill Key Type

```ts
type SkillKey =
  | 'SA'
  | 'ER'
  | 'SM'
  | 'CO'
  | 'CE'
  | 'PD'
```

---

# 85. Response Type

```ts
type AssessmentResponse = {
  sessionId: number
  questionVersionId: number

  answer: unknown

  missingReason?:
    | 'not_observed'
    | 'skip'
    | 'not_understood'
    | 'technical_failure'

  responseTimeMs?: number

  savedAt: string
}
```

---

# 86. Score Status

Suggested values:

```ts
type ScoreStatus =
  | 'valid'
  | 'insufficient_evidence'
  | 'not_scored'
  | 'scoring_error'
```

Do not use numeric `0` to represent these states.

---

# 87. Skill Score Model

```ts
type SkillScore = {
  skillKey: SkillKey

  sourceType:
    | 'student'
    | 'parent'
    | 'teacher'
    | 'scenario'

  rawScore: number | null
  normalizedScore: number | null

  answeredCount: number
  eligibleCount: number

  status: ScoreStatus

  qualityFlags: string[]

  scoringVersion: string
}
```

---

# 88. Assessment Session Model

```ts
type AssessmentSession = {
  id: number

  studentId: number

  assessmentId: number
  assessmentVersion: string

  scoringVersion: string

  gradeGroup: 'A' | 'B' | 'C' | 'D'

  respondentType:
    | 'student'
    | 'parent'
    | 'teacher'

  status:
    | 'in_progress'
    | 'submitted'
    | 'completed'
    | 'expired'
    | 'cancelled'

  startedAt: string
  submittedAt?: string
  completedAt?: string
}
```

---

# 89. Scoring Strategy Interface

Conceptual interface:

```ts
interface ScoringStrategy {
  supports(itemType: string): boolean

  score(
    item: AssessmentItem,
    response: AssessmentResponse
  ): ItemScore
}
```

Implementations may include:

```text
Frequency5ScoringStrategy
ScenarioRubricScoringStrategy
```

Future:

```text
TeacherRatingScoringStrategy
IRTScoringStrategy
PerformanceTaskScoringStrategy
```

Future strategies require separate specification approval.

---

# 90. Frequency Strategy Pseudocode

```ts
function scoreFrequencyItem(item, response) {
  if (response.missingReason) {
    return {
      status: 'missing'
    }
  }

  let value = response.answer

  assert(value >= 1 && value <= 5)

  if (item.reverse) {
    value = 6 - value
  }

  const normalized =
    ((value - 1) / 4) * 100

  return {
    status: 'valid',
    rawScore: value,
    normalizedScore: normalized
  }
}
```

---

# 91. Skill Aggregation Pseudocode

```ts
function aggregateSkill(items) {
  const valid = items.filter(
    item => item.status === 'valid'
  )

  if (!meetsMinimumEvidence(valid, items)) {
    return {
      status: 'insufficient_evidence',
      score: null
    }
  }

  const weightedSum =
    valid.reduce(
      (sum, item) =>
        sum + item.normalizedScore * item.weight,
      0
    )

  const weightSum =
    valid.reduce(
      (sum, item) =>
        sum + item.weight,
      0
    )

  return {
    status: 'valid',
    score: weightedSum / weightSum
  }
}
```

---

# 92. Precision

Internal calculations should retain reasonable precision.

Recommended:

```text
at least 4 decimal places internally
```

Display may round to:

```text
0 or 1 decimal place
```

according to report design.

Do not repeatedly round intermediate calculations.

---

# 93. No Global Total Score — v0.1

ABA Skills 360 v0.1 does not define a scientifically justified single total competency score across all six competencies.

Therefore do not calculate:

```text
ABA Score = average(SA, ER, SM, CO, CE, PD)
```

as an official measurement.

UI may summarize progress narratively, but must not invent a global psychometric score.

---

# 94. No Student Ranking by SEL Score

Do not implement:

```text
class SEL leaderboard
school SEL leaderboard
top 10 emotional skills students
bottom 10 social skills students
```

Gamification leaderboards, if any, must use appropriate participation/activity metrics rather than sensitive social-emotional competency rankings.

---

# 95. No Threshold Labels Without Approval

Do not invent score bands such as:

```text
0–40 Weak
41–60 Average
61–80 Good
81–100 Excellent
```

unless a future validated interpretation study and approved specification defines them.

Likewise do not label children:

```text
weak
poor
abnormal
low EQ
```

from v0.1 scores.

---

# 96. Recommendation Input

Recommendation rules may consume:

```text
skill score
source
grade group
measurement status
trend when compatible
```

But recommendations must distinguish:

```text
insufficient evidence
```

from:

```text
low observed score
```

No recommendation engine may interpret missing evidence as low ability.

---

# 97. Report Data

Parent reports should receive structured measurement data.

Example:

```json
{
  "skillKey": "SM",

  "source": "student",

  "score": 62.5,

  "status": "valid",

  "answeredCount": 4,
  "eligibleCount": 5,

  "comparison": {
    "available": true,
    "previous": 55,
    "delta": 7.5
  }
}
```

Comparison may only be provided when compatibility rules permit it.

---

# 98. Report Provenance

Every generated report should retain references to:

```text
student
score runs
profile snapshot
assessment versions
report template version
generation time
```

This allows reproduction of historical reports.

---

# 99. Framework Version

The competency framework itself must have a version.

Initial identifier:

```text
ABA-SEL-0.1
```

If definitions/indicator structure materially change:

```text
create new framework version
```

Do not silently reinterpret old scores using new construct definitions.

---

# 100. Version Dimensions

The system must distinguish at least:

```text
frameworkVersion
questionVersion
assessmentVersion
scoringVersion
profileRuleVersion
reportTemplateVersion
```

These are not interchangeable.

---

# 101. Example Complete Provenance

```json
{
  "frameworkVersion": "ABA-SEL-0.1",

  "assessment": {
    "key": "ABA-B-S",
    "version": "0.1"
  },

  "scoringVersion": "ABA-FREQ-0.1",

  "profileRuleVersion": "PROFILE-0.1",

  "reportTemplateVersion": "PARENT-0.1"
}
```

---

# 102. Compatibility Matrix

Assessment versions should eventually declare compatibility.

Example:

```json
{
  "version": "0.2",

  "comparisonCompatibility": {
    "ABA-B-S-0.1": "unknown",
    "ABA-B-S-0.2": "same_form_family"
  }
}
```

Possible states:

```text
compatible
same_form_family
not_comparable
unknown
```

MVP default when compatibility has not been reviewed:

```text
unknown
```

Unknown must not be treated as compatible.

---

# 103. Pilot Status

Assessment versions should support measurement status:

```text
draft
pilot
validated_for_product_use
retired
```

Initial ABA v0.1 forms should be considered:

```text
pilot / draft measurement instruments
```

until formally reviewed and tested.

---

# 104. Pilot Label

Where appropriate, UI/report should be able to display:

```text
Bản thử nghiệm / Pilot
```

The technical architecture must not hardcode all assessments as validated.

---

# 105. Analysis Data

For later psychometric analysis, retain necessary non-identifying measurement metadata including:

```text
question version
response
respondent type
grade group
assessment version
response time
missing reason
score run
```

Do not add sensitive demographic fields merely because they might be analytically useful.

Data collection expansion requires explicit approval.

---

# 106. Data Export

Future measurement exports should be capable of producing de-identified analytical records.

Example columns:

```text
participant_key
grade_group
respondent_type
assessment_version
question_key
question_version
answer
missing_reason
response_time_ms
```

Identifiers exposed for research should follow approved privacy rules.

---

# 107. Auditability

Important measurement actions should be auditable:

```text
assessment published
question version published
assessment version retired
scoring version activated
manual invalidation
administrative rescore
```

Audit logs must not unnecessarily copy sensitive answers.

---

# 108. Security

Scoring keys and unpublished measurement configuration are server-side data.

Students/parents should not receive:

```text
reverse flags
rubric scoring values
correct scenario choices
internal weighting
unpublished question bank
```

unless intentionally required by a post-assessment educational experience.

---

# 109. Automated Tests — Reverse Scoring

Required:

```text
1 → 5
2 → 4
3 → 3
4 → 2
5 → 1
```

All must pass.

---

# 110. Automated Tests — Normalization

Required:

```text
1 → 0
2 → 25
3 → 50
4 → 75
5 → 100
```

---

# 111. Automated Tests — Missing Data

Example:

```text
eligible = 4
minimum = 3
responses = [5, 4, missing, 3]
```

Result:

```text
valid
answeredCount = 3
```

Example:

```text
responses = [5, missing, missing, 3]
```

Result:

```text
insufficient_evidence
score = null
```

---

# 112. Automated Tests — Source Isolation

Given:

```text
Student CO = 70
Parent CO = 80
Teacher CO = 60
```

the engine must NOT automatically create:

```text
CO = 70
```

unless an explicit approved multi-source model exists.

---

# 113. Automated Tests — Version Immutability

After session starts on:

```text
ABA-B-S-0.1
```

publishing:

```text
ABA-B-S-0.2
```

must not alter:

```text
questions
scoring
version
```

of the existing session.

---

# 114. Automated Tests — Rescoring

If the same session is rescored under an authorized new scoring version:

```text
old score run remains
new score run is created
historical result is not overwritten
```

---

# 115. Automated Tests — XP Isolation

Changing:

```text
answer 2 → answer 5
```

must not produce more completion XP.

XP depends on approved participation/completion events, not “good” answers.

---

# 116. Automated Tests — Cross-Student Security

Student A must not:

```text
read
save
submit
complete
```

Student B's assessment session.

Parent A must not access Student B unless a valid parent-student link exists.

---

# 117. Assessment Builder Validation

The admin Assessment Builder should eventually expose:

```text
Age band
Respondent type
Assessment version
Question versions
Skill mapping
Indicator mapping
Weight
Reverse flag
Minimum evidence
Scoring version
Question order
Publication status
```

However, dangerous scoring fields should be restricted to authorized measurement/admin roles.

---

# 118. Human Review Gate

Codex must not build a system where any content editor can automatically publish a measurement instrument without review.

Recommended conceptual workflow:

```text
Draft
  ↓
Measurement Review
  ↓
Approved
  ↓
Published
```

Exact WordPress capabilities are defined separately.

---

# 119. Future Psychometric Methods

Possible future additions include:

```text
reliability analysis
factor analysis
measurement invariance
IRT
DIF analysis
scale linking/equating
normative data
confidence intervals
```

These are NOT implemented by assumption.

They require research and a future specification/version.

---

# 120. Explicit Non-Goals v0.1

Codex must not add:

```text
AI-generated competency scores
emotion detection from face/video
automatic psychological diagnosis
IQ-style scoring
national percentile claims
hidden personality classification
career prediction
single composite SEL score
student skill leaderboard
```

without an explicit future approved specification.

---

# 121. Database Alignment

The database must be able to represent:

```text
immutable question versions
immutable assessment versions
assessment sessions
responses
score runs
skill scores
profile snapshots
```

If the current `database-schema.md` cannot represent these rules exactly, the schema must be extended through an approved migration.

Do not weaken this measurement specification to fit an insufficient database design.

---

# 122. Recommended Schema Evolution

Before implementing Assessment Engine Sprint, strongly consider extending the database model with:

```text
question_versions
assessment_template_versions
assessment_template_items
assessment_assignments
score_runs
profile_snapshots
```

rather than relying only on mutable CPT metadata.

WordPress may still provide editorial management, while immutable measurement versions are persisted explicitly.

---

# 123. API Alignment

The REST API must never accept client-calculated authoritative scores.

Correct:

```text
POST response
POST submit
GET result
```

Incorrect:

```text
POST skillScore=85
```

from a student client.

---

# 124. Definition of Done — Question

A scorable question is implementation-complete when:

```text
identity exists
version exists
type exists
age band exists
respondent type exists
skill mapping exists
indicator mapping exists where required
scoring strategy exists
reverse rule exists where applicable
human review state exists
tests exist
```

---

# 125. Definition of Done — Assessment Version

An assessment version is complete when:

```text
question versions are frozen
respondent type is frozen
grade group is frozen
scoring version is frozen
minimum evidence rules are frozen
instructions are frozen
recall period is frozen
publication validation passes
```

---

# 126. Definition of Done — Scoring Engine

The scoring engine is complete when:

```text
server-side scoring works
reverse scoring works
normalization works
missing data works
minimum evidence works
skill aggregation works
source separation works
scoring versioning works
retries are safe
historical results are not overwritten
tests pass
```

---

# 127. Definition of Done — Profile Snapshot

Snapshot generation is complete when:

```text
snapshot references score runs
snapshot has framework version
snapshot has profile rule version
source is preserved
insufficient evidence remains explicit
snapshot is immutable
historical snapshots remain accessible
duplicate retries do not create unintended duplicates
```

---

# 128. Measurement Invariants

The following rules must be treated as hard invariants in v0.1:

1. Missing is never automatically scored as zero.
2. Published assessment versions are immutable.
3. Historical submitted responses are not silently rewritten.
4. Reverse scoring is explicitly configured.
5. Scoring occurs server-side.
6. XP does not depend on selecting socially desirable answers.
7. Different respondents remain separate sources.
8. Skill score history is preserved.
9. Grade-group transitions are not automatically treated as one equivalent scale.
10. Normalized 0–100 values are not percentiles.
11. No global ABA competency score exists in v0.1.
12. Insufficient evidence produces `score=null`.
13. Assessment/scoring versions are stored with results.
14. Scenario and frequency scores are not automatically blended.
15. AI must not invent or modify measured skill scores.

---

# 129. Codex Implementation Rule

Before implementing any code under:

```text
Assessment
Scoring
Skills
ProfileSnapshot
Report
Recommendation
```

Codex must read:

```text
/AGENTS.md
/docs/architecture.md
/docs/database-schema.md
/docs/api-contract.md
/docs/assessment-spec.md
```

If these documents conflict:

```text
assessment-spec.md
```

is authoritative for measurement/scoring semantics.

Architecture and database implementation should be updated explicitly rather than silently changing measurement rules.

---

# 130. Final Principle

ABA Skills 360 must preserve the chain:

```text
Answer
 ↓
Evidence
 ↓
Scoring rule
 ↓
Skill result
 ↓
Profile
 ↓
Recommendation
```

Every transformation must be:

```text
versioned
traceable
reproducible
explainable
testable
```

The system must prefer:

```text
"No score — insufficient evidence"
```

over producing a misleading number.

The long-term value of ABA Skills 360 depends not only on having assessment questions, but on preserving trustworthy longitudinal measurement data.
