# ABA Skills 360 — REST API Contract

**Document:** `docs/api-contract.md`  
**Version:** 0.1.0  
**Status:** Source of Truth

---

# 1. Purpose

This document defines the public application API contract between:

```text
ABA Skills 360 Next.js clients
and
ABA Skills Core WordPress backend
```

Implementation must not silently diverge from this contract.

Breaking changes require documentation and versioning.

---

# 2. Base URL

All ABA APIs use:

```text
/wp-json/aba/v1
```

Example:

```text
GET /wp-json/aba/v1/me
```

For readability, endpoint definitions below omit:

```text
/wp-json
```

Example documentation:

```text
GET /aba/v1/me
```

---

# 3. Content Type

Requests:

```http
Content-Type: application/json
```

Responses:

```http
Content-Type: application/json
```

unless returning a file such as PDF.

---

# 4. Authentication

Protected endpoints require a valid authenticated user/session.

Exact token/cookie implementation may evolve without changing business endpoint semantics.

Unauthenticated response:

```http
401 Unauthorized
```

```json
{
  "error": {
    "code": "UNAUTHENTICATED",
    "message": "Authentication is required.",
    "details": {}
  }
}
```

---

# 5. Authorization

Authentication does not imply authorization.

Server must enforce:

```text
Student ownership
Parent-child linkage
Teacher class scope
School scope
Administrative capabilities
```

Authorization failure:

```http
403 Forbidden
```

```json
{
  "error": {
    "code": "FORBIDDEN",
    "message": "You do not have permission to access this resource.",
    "details": {}
  }
}
```

---

# 6. Standard Error Shape

All application errors should follow:

```json
{
  "error": {
    "code": "ERROR_CODE",
    "message": "Human readable message.",
    "details": {}
  }
}
```

The client should primarily branch on:

```text
error.code
```

rather than parsing the message string.

---

# 7. Common HTTP Status Codes

```text
200 OK

201 Created

204 No Content

400 Bad Request

401 Unauthorized

403 Forbidden

404 Not Found

409 Conflict

422 Unprocessable Entity

429 Too Many Requests

500 Internal Server Error
```

---

# 8. Pagination

List endpoints should use:

```text
?page=1
&per_page=20
```

Response:

```json
{
  "data": [],
  "pagination": {
    "page": 1,
    "perPage": 20,
    "total": 100,
    "totalPages": 5
  }
}
```

The server must enforce a maximum `per_page`.

Recommended maximum:

```text
100
```

---

# 9. Date Format

Use ISO 8601.

Example:

```text
2026-10-19T07:30:00Z
```

Date-only values:

```text
2026-10-19
```

---

# 10. API Response Naming

JSON uses camelCase.

Example:

```json
{
  "studentId": 123,
  "schoolId": 5,
  "createdAt": "2026-10-19T07:30:00Z"
}
```

Database naming may remain snake_case.

Mapping belongs in the API/application layer.

---

# 11. Authentication Endpoints

## POST `/aba/v1/auth/login`

Authenticate a user.

### Request

```json
{
  "username": "student@example.com",
  "password": "secret"
}
```

### Response 200

```json
{
  "user": {
    "id": 10,
    "displayName": "Nguyễn Minh Anh",
    "roles": [
      "aba_student"
    ]
  }
}
```

Depending on the final auth implementation, secure session credentials may be delivered using HTTP-only cookies rather than JSON tokens.

### Errors

```text
400 INVALID_REQUEST
401 INVALID_CREDENTIALS
403 ACCOUNT_DISABLED
429 RATE_LIMITED
```

---

# 12. POST `/aba/v1/auth/logout`

Log out the current session.

### Response

```http
204 No Content
```

---

# 13. POST `/aba/v1/auth/refresh`

Refresh session credentials when the selected authentication architecture requires it.

### Response 200

```json
{
  "success": true
}
```

Exact token fields are intentionally not frozen until the authentication implementation is finalized.

---

# 14. GET `/aba/v1/me`

Return current authenticated user context.

### Response 200 — Student

```json
{
  "user": {
    "id": 10,
    "displayName": "Nguyễn Minh Anh",
    "roles": [
      "aba_student"
    ]
  },

  "student": {
    "id": 123,
    "studentCode": "ABA-2026-00123",
    "schoolId": 5,
    "classId": 21,
    "grade": 5
  }
}
```

### Response 200 — Parent

```json
{
  "user": {
    "id": 20,
    "displayName": "Nguyễn Văn A",
    "roles": [
      "aba_parent"
    ]
  }
}
```

---

# 15. Student Profile

## GET `/aba/v1/students/me`

Student-only convenience endpoint.

### Response 200

```json
{
  "student": {
    "id": 123,
    "studentCode": "ABA-2026-00123",
    "displayName": "Nguyễn Minh Anh",
    "schoolId": 5,
    "classId": 21,
    "grade": 5
  }
}
```

---

# 16. GET `/aba/v1/students/{studentId}`

Return student information visible to the authenticated actor.

Allowed:

```text
Student → self
Parent → linked child
Teacher → authorized class/student
School admin → same school
ABA admin → according to capabilities
```

### Response 200

```json
{
  "student": {
    "id": 123,
    "studentCode": "ABA-2026-00123",
    "displayName": "Nguyễn Minh Anh",
    "schoolId": 5,
    "classId": 21,
    "grade": 5,
    "status": "active"
  }
}
```

### Errors

```text
403 FORBIDDEN
404 STUDENT_NOT_FOUND
```

---

# 17. Schools

## GET `/aba/v1/schools`

Administrative endpoint.

### Query

```text
?page=1
&per_page=20
&status=active
&search=...
```

### Response

```json
{
  "data": [
    {
      "id": 5,
      "code": "SCHOOL-005",
      "name": "Trường ABC",
      "province": "Hà Nội",
      "district": "Ba Đình",
      "status": "active"
    }
  ],

  "pagination": {
    "page": 1,
    "perPage": 20,
    "total": 1,
    "totalPages": 1
  }
}
```

---

# 18. Classes

## GET `/aba/v1/classes`

### Query

```text
school_id
grade
school_year
page
per_page
```

### Response

```json
{
  "data": [
    {
      "id": 21,
      "schoolId": 5,
      "name": "5A1",
      "grade": 5,
      "schoolYear": "2026-2027"
    }
  ],

  "pagination": {
    "page": 1,
    "perPage": 20,
    "total": 1,
    "totalPages": 1
  }
}
```

---

# 19. Learning Path

## GET `/aba/v1/learning/path`

Return the learning path for the current student.

### Query

Optional:

```text
student_id
```

`student_id` may only be used by an authorized actor.

### Response 200

```json
{
  "studentId": 123,

  "path": {
    "title": "Hành trình kỹ năng",

    "units": [
      {
        "id": "unit-1",
        "title": "Giao tiếp tự tin",

        "progress": 100,

        "status": "completed",

        "nodes": [
          {
            "id": 101,
            "type": "lesson",
            "title": "Lắng nghe tích cực",
            "status": "completed",
            "xpReward": 20
          }
        ]
      },

      {
        "id": "unit-2",
        "title": "Hợp tác",

        "progress": 35,

        "status": "current",

        "nodes": [
          {
            "id": 115,
            "type": "lesson",
            "title": "Làm việc cùng nhau",
            "status": "available",
            "xpReward": 20
          },

          {
            "id": 116,
            "type": "lesson",
            "title": "Vai trò trong nhóm",
            "status": "locked",
            "xpReward": 25
          }
        ]
      }
    ]
  }
}
```

Possible node statuses:

```text
completed
current
available
locked
```

---

# 20. Courses

## GET `/aba/v1/courses`

Filters may include:

```text
grade
skill
difficulty
page
per_page
```

### Response

```json
{
  "data": [
    {
      "id": 50,
      "title": "Kỹ năng giao tiếp",
      "gradeMin": 4,
      "gradeMax": 6,
      "skillDomain": "communication"
    }
  ],

  "pagination": {
    "page": 1,
    "perPage": 20,
    "total": 1,
    "totalPages": 1
  }
}
```

---

# 21. GET `/aba/v1/courses/{courseId}`

### Response

```json
{
  "course": {
    "id": 50,
    "title": "Kỹ năng giao tiếp",
    "description": "..."
  },

  "modules": [
    {
      "id": 501,
      "title": "Lắng nghe"
    }
  ]
}
```

---

# 22. GET `/aba/v1/lessons/{lessonId}`

Return lesson data the user is eligible to access.

### Response

```json
{
  "lesson": {
    "id": 115,
    "title": "Làm việc cùng nhau",

    "content": {
      "type": "interactive",
      "blocks": []
    },

    "xpReward": 20,

    "progress": {
      "status": "available",
      "progressPercent": 0
    }
  }
}
```

---

# 23. POST `/aba/v1/lessons/{lessonId}/start`

### Request

```json
{}
```

### Response 200

```json
{
  "progress": {
    "lessonId": 115,
    "status": "in_progress",
    "progressPercent": 0,
    "startedAt": "2026-10-19T07:30:00Z"
  }
}
```

### Errors

```text
403 LESSON_NOT_AVAILABLE
404 LESSON_NOT_FOUND
```

---

# 24. POST `/aba/v1/lessons/{lessonId}/complete`

### Request

```json
{
  "score": 85
}
```

Score may be omitted for lessons without scored activities.

### Response 200

```json
{
  "progress": {
    "lessonId": 115,
    "status": "completed",
    "progressPercent": 100,
    "score": 85
  },

  "rewards": {
    "xpAwarded": 20
  }
}
```

Completion must be idempotent with regard to XP reward.

Repeated calls must not accidentally award duplicate XP.

---

# 25. Assessments

## GET `/aba/v1/assessments`

Return assessments visible/available to the actor.

### Query

```text
grade
status
page
per_page
```

### Response

```json
{
  "data": [
    {
      "id": 12,
      "title": "Khảo sát năng lực đầu kỳ",

      "version": "1.0",

      "estimatedMinutes": 20,

      "availability": {
        "status": "available"
      }
    }
  ],

  "pagination": {
    "page": 1,
    "perPage": 20,
    "total": 1,
    "totalPages": 1
  }
}
```

---

# 26. GET `/aba/v1/assessments/{assessmentId}`

Return public metadata about an assessment.

Do not necessarily return the entire question bank.

### Response

```json
{
  "assessment": {
    "id": 12,
    "title": "Khảo sát năng lực đầu kỳ",
    "version": "1.0",

    "description": "...",

    "estimatedMinutes": 20,

    "instructions": []
  }
}
```

---

# 27. POST `/aba/v1/assessments/{assessmentId}/start`

Start or resume an eligible assessment.

### Request

```json
{}
```

### Response 200/201

```json
{
  "session": {
    "id": 1001,

    "assessmentId": 12,
    "assessmentVersion": "1.0",

    "studentId": 123,

    "status": "in_progress",

    "startedAt": "2026-10-19T07:30:00Z",

    "resume": false
  },

  "questionBatch": {
    "items": [
      {
        "id": 9001,

        "type": "likert",

        "prompt": "Em cảm thấy tự tin khi trình bày ý kiến trước nhóm.",

        "options": [
          {
            "value": 1,
            "label": "Hoàn toàn không đúng"
          },
          {
            "value": 2,
            "label": "Không đúng"
          },
          {
            "value": 3,
            "label": "Phân vân"
          },
          {
            "value": 4,
            "label": "Đúng"
          },
          {
            "value": 5,
            "label": "Hoàn toàn đúng"
          }
        ]
      }
    ],

    "cursor": "opaque_cursor",

    "hasMore": true
  }
}
```

The server may return an existing active session instead of creating a duplicate.

### Errors

```text
403 ASSESSMENT_NOT_AVAILABLE
404 ASSESSMENT_NOT_FOUND
409 ASSESSMENT_ALREADY_COMPLETED
```

---

# 28. GET `/aba/v1/assessment-sessions/{sessionId}`

Return resumable assessment state.

### Response

```json
{
  "session": {
    "id": 1001,
    "assessmentId": 12,
    "assessmentVersion": "1.0",
    "studentId": 123,
    "status": "in_progress",

    "progress": {
      "answered": 8,
      "total": 30,
      "percent": 26.67
    }
  }
}
```

### Errors

```text
403 FORBIDDEN
404 ASSESSMENT_SESSION_NOT_FOUND
```

---

# 29. POST `/aba/v1/assessment-sessions/{sessionId}/responses`

Autosave one or more responses.

### Request

```json
{
  "responses": [
    {
      "questionId": 9001,
      "answer": 4,
      "responseTimeMs": 5200
    },

    {
      "questionId": 9002,
      "answer": {
        "selectedOption": "B"
      },
      "responseTimeMs": 3100
    }
  ]
}
```

### Response 200

```json
{
  "saved": [
    {
      "questionId": 9001,
      "saved": true
    },
    {
      "questionId": 9002,
      "saved": true
    }
  ],

  "progress": {
    "answered": 10,
    "total": 30,
    "percent": 33.33
  }
}
```

Repeated autosave for the same standard question should update the same response rather than create duplicates.

### Errors

```text
403 FORBIDDEN
404 SESSION_NOT_FOUND
409 SESSION_COMPLETED
422 INVALID_RESPONSE
```

---

# 30. GET `/aba/v1/assessment-sessions/{sessionId}/questions`

Retrieve the next question batch.

### Query

```text
cursor
```

### Response

```json
{
  "items": [],
  "cursor": "next_cursor",
  "hasMore": true
}
```

The cursor is opaque to the client.

This endpoint allows future adaptive assessment.

---

# 31. POST `/aba/v1/assessment-sessions/{sessionId}/complete`

Complete an assessment.

### Request

```json
{}
```

### Response 200

```json
{
  "session": {
    "id": 1001,
    "status": "completed",
    "completedAt": "2026-10-19T07:52:00Z"
  },

  "result": {
    "status": "processing"
  },

  "rewards": {
    "xpAwarded": 50
  }
}
```

Scoring may be synchronous initially.

If synchronous:

```json
{
  "result": {
    "status": "ready",
    "skillsProfileAvailable": true
  }
}
```

The contract allows future asynchronous report/scoring workflows.

### Errors

```text
403 FORBIDDEN
404 SESSION_NOT_FOUND
409 SESSION_ALREADY_COMPLETED
422 ASSESSMENT_INCOMPLETE
```

Completion must be idempotent.

Duplicate calls must not award XP twice.

---

# 32. Current Skills

## GET `/aba/v1/students/{studentId}/skills/current`

### Response

```json
{
  "studentId": 123,

  "measuredAt": "2026-10-19T07:52:00Z",

  "skills": [
    {
      "key": "communication",
      "label": "Giao tiếp",
      "score": 78,
      "scale": {
        "min": 0,
        "max": 100
      },

      "trend": {
        "direction": "up",
        "delta": 6
      }
    },

    {
      "key": "collaboration",
      "label": "Hợp tác",
      "score": 85,
      "scale": {
        "min": 0,
        "max": 100
      },

      "trend": {
        "direction": "up",
        "delta": 4
      }
    }
  ]
}
```

---

# 33. Skill History

## GET `/aba/v1/students/{studentId}/skills/history`

### Query

Optional:

```text
skill
from
to
```

### Response

```json
{
  "studentId": 123,

  "series": [
    {
      "skillKey": "communication",

      "points": [
        {
          "measuredAt": "2026-03-01T00:00:00Z",
          "score": 61
        },
        {
          "measuredAt": "2026-06-01T00:00:00Z",
          "score": 70
        },
        {
          "measuredAt": "2026-10-19T07:52:00Z",
          "score": 78
        }
      ]
    }
  ]
}
```

---

# 34. Single Skill Detail

## GET `/aba/v1/students/{studentId}/skills/{skillKey}`

### Response

```json
{
  "skill": {
    "key": "communication",
    "label": "Giao tiếp",

    "currentScore": 78,

    "interpretation": {
      "levelKey": "developing_well"
    }
  },

  "history": [],

  "recommendations": []
}
```

---

# 35. Recommendations

## GET `/aba/v1/students/{studentId}/recommendations`

### Response

```json
{
  "studentId": 123,

  "generatedAt": "2026-10-19T08:00:00Z",

  "recommendations": [
    {
      "id": "rec-001",

      "type": "lesson",

      "reason": {
        "skillKey": "self_management",
        "ruleKey": "self_management_below_50_grade_4_6"
      },

      "resource": {
        "id": 120,
        "title": "Quản lý cảm xúc khi gặp khó khăn"
      },

      "priority": 1
    }
  ]
}
```

Recommendation reasons must remain explainable.

---

# 36. Parent Children

## GET `/aba/v1/parent/children`

Parent-only endpoint.

### Response

```json
{
  "children": [
    {
      "id": 123,
      "displayName": "Nguyễn Minh Anh",
      "grade": 5,
      "schoolId": 5,
      "classId": 21,

      "relationship": "father",

      "isPrimary": true
    }
  ]
}
```

---

# 37. Parent Child Overview

## GET `/aba/v1/parent/children/{studentId}/overview`

Server must verify parent-child relationship.

### Response

```json
{
  "student": {
    "id": 123,
    "displayName": "Nguyễn Minh Anh",
    "grade": 5
  },

  "summary": {
    "currentStreak": 8,
    "totalXp": 2450,

    "weekly": {
      "lessonsCompleted": 4,
      "missionsCompleted": 2
    }
  },

  "skills": {
    "topStrengths": [
      {
        "key": "collaboration",
        "score": 85
      }
    ],

    "priorityGrowthAreas": [
      {
        "key": "self_management",
        "score": 61
      }
    ]
  },

  "latestReport": {
    "id": 501,
    "type": "assessment"
  }
}
```

---

# 38. Parent Child Skills

## GET `/aba/v1/parent/children/{studentId}/skills`

Equivalent child-specific view of the student's skill data.

The parent-child relationship must be validated before querying/reporting.

### Response

```json
{
  "studentId": 123,
  "skills": []
}
```

---

# 39. Parent Activities

## GET `/aba/v1/parent/children/{studentId}/activities`

### Query

```text
page
per_page
from
to
```

### Response

```json
{
  "data": [
    {
      "type": "lesson_completed",

      "title": "Lắng nghe tích cực",

      "occurredAt": "2026-10-18T09:20:00Z"
    }
  ],

  "pagination": {
    "page": 1,
    "perPage": 20,
    "total": 1,
    "totalPages": 1
  }
}
```

Do not expose unnecessary low-level event telemetry to parents.

---

# 40. Reports List

## GET `/aba/v1/students/{studentId}/reports`

### Query

```text
type
page
per_page
```

### Response

```json
{
  "data": [
    {
      "id": 501,
      "type": "assessment",

      "periodStart": "2026-10-01",
      "periodEnd": "2026-10-19",

      "generatedAt": "2026-10-19T08:02:00Z"
    }
  ],

  "pagination": {
    "page": 1,
    "perPage": 20,
    "total": 1,
    "totalPages": 1
  }
}
```

---

# 41. GET `/aba/v1/reports/{reportId}`

### Response

```json
{
  "report": {
    "id": 501,

    "studentId": 123,

    "type": "assessment",

    "version": "1.0",

    "period": {
      "start": "2026-10-01",
      "end": "2026-10-19"
    },

    "summary": {},

    "skills": [],

    "strengths": [],

    "growthAreas": [],

    "recommendations": [],

    "learningPlan": [],

    "generatedAt": "2026-10-19T08:02:00Z"
  }
}
```

---

# 42. POST `/aba/v1/reports/generate`

Administrative/system endpoint.

Parent/student clients must not freely generate arbitrary reports unless explicitly permitted.

### Request

```json
{
  "studentId": 123,
  "reportType": "assessment",
  "assessmentSessionId": 1001
}
```

### Response 201

```json
{
  "report": {
    "id": 501,
    "status": "generated"
  }
}
```

### Errors

```text
403 FORBIDDEN
404 STUDENT_NOT_FOUND
404 SESSION_NOT_FOUND
409 REPORT_ALREADY_EXISTS
422 INVALID_REPORT_REQUEST
```

---

# 43. GET `/aba/v1/reports/{reportId}/pdf`

Return the PDF version of a report.

The server must validate authorization before generating or returning the document.

### Response

```http
Content-Type: application/pdf
```

PDF is a rendered artifact.

Structured report JSON remains the source of truth.

---

# 44. XP

## GET `/aba/v1/students/{studentId}/xp`

### Response

```json
{
  "studentId": 123,

  "totalXp": 2450,

  "level": {
    "number": 7,
    "progress": 68,
    "nextLevelXp": 2800
  }
}
```

---

# 45. XP Transactions

An administrative/debug endpoint may exist later.

It must not be required for ordinary student UX.

Example future route:

```text
GET /aba/v1/students/{studentId}/xp/transactions
```

Access should be appropriately restricted.

---

# 46. Streak

## GET `/aba/v1/students/{studentId}/streak`

### Response

```json
{
  "studentId": 123,

  "current": 8,
  "longest": 20,

  "lastActivityDate": "2026-10-19",

  "freezeAvailable": 0
}
```

---

# 47. Missions

## GET `/aba/v1/missions`

Return missions available to the current student.

### Response

```json
{
  "data": [
    {
      "id": 301,

      "title": "Hoàn thành 3 bài học tuần này",

      "progress": {
        "current": 2,
        "target": 3
      },

      "status": "in_progress",

      "rewards": {
        "xp": 30
      }
    }
  ]
}
```

---

# 48. Badges

## GET `/aba/v1/students/{studentId}/badges`

### Response

```json
{
  "badges": [
    {
      "id": 800,
      "title": "Khởi đầu tự tin",

      "earnedAt": "2026-10-12T08:00:00Z"
    }
  ]
}
```

---

# 49. Student Progress

## GET `/aba/v1/students/{studentId}/progress`

Aggregate progress endpoint.

### Response

```json
{
  "studentId": 123,

  "learning": {
    "completedLessons": 20,
    "totalAvailableLessons": 50,
    "completionPercent": 40
  },

  "assessment": {
    "completed": 2,
    "available": 3
  },

  "gamification": {
    "totalXp": 2450,
    "currentStreak": 8
  }
}
```

This endpoint may use cached aggregates.

---

# 50. Health Check

## GET `/aba/v1/health`

Public or restricted according to deployment policy.

### Response

```json
{
  "status": "ok",
  "version": "0.1.0"
}
```

Do not expose:

```text
database passwords
filesystem paths
PHP configuration secrets
stack traces
WordPress secret keys
```

---

# 51. Idempotency

Endpoints that create rewards or irreversible state must be designed for safe retries.

Examples:

```text
lesson completion
assessment completion
XP reward generation
report generation
```

The client may retry after network failure.

The server must avoid duplicate side effects.

---

# 52. Validation Errors

Example:

```http
422 Unprocessable Entity
```

```json
{
  "error": {
    "code": "VALIDATION_ERROR",
    "message": "One or more fields are invalid.",

    "details": {
      "fields": {
        "responses[0].answer": [
          "Invalid answer value."
        ]
      }
    }
  }
}
```

---

# 53. Not Found Errors

Use entity-specific codes.

Examples:

```text
STUDENT_NOT_FOUND
ASSESSMENT_NOT_FOUND
SESSION_NOT_FOUND
LESSON_NOT_FOUND
REPORT_NOT_FOUND
MISSION_NOT_FOUND
```

---

# 54. Conflict Errors

Use `409` when the current resource state conflicts with the operation.

Examples:

```text
SESSION_ALREADY_COMPLETED
REPORT_ALREADY_EXISTS
ACTIVE_SESSION_EXISTS
LESSON_ALREADY_COMPLETED
```

When an operation is intentionally idempotent, returning the existing successful state may be preferable to an error.

---

# 55. Rate Limiting

Rate limiting should be applied especially to:

```text
login
password recovery
assessment autosave abuse
report generation
expensive search endpoints
```

Example:

```http
429 Too Many Requests
```

```json
{
  "error": {
    "code": "RATE_LIMITED",
    "message": "Too many requests.",
    "details": {
      "retryAfterSeconds": 30
    }
  }
}
```

---

# 56. Authorization Examples

## Student

Request:

```text
GET /students/999/skills/current
```

If the authenticated student's internal ID is `123`:

```text
403 FORBIDDEN
```

unless a separately authorized role applies.

---

## Parent

Parent linked to:

```text
student 123
```

Allowed:

```text
GET /parent/children/123/overview
```

Forbidden:

```text
GET /parent/children/999/overview
```

unless parent `→ student 999` linkage exists.

---

## Teacher

Teacher access must be checked against class/school assignment.

Do not trust a frontend-provided `schoolId`.

---

# 57. School Scope

Administrative APIs must derive allowed school scope from authenticated permissions.

Unsafe:

```text
client sends schoolId=5
server trusts it
```

Correct:

```text
client requests schoolId=5
server verifies actor may access school 5
```

---

# 58. Sensitive Fields

Do not return these unless explicitly required:

```text
password hashes
authentication secrets
private WordPress metadata
internal security tokens
sensitive system configuration
unnecessary child personal information
```

---

# 59. API Client Requirements

Frontend code should use:

```text
packages/api-client
```

Example:

```ts
api.assessment.start(assessmentId)

api.assessment.saveResponses(sessionId, responses)

api.assessment.complete(sessionId)

api.skills.getCurrent(studentId)

api.parent.getChildren()
```

React components must not independently duplicate request/response parsing logic.

---

# 60. Type Safety

API types belong in:

```text
packages/types
```

Runtime request/response validation may use:

```text
packages/validation
```

with Zod.

---

# 61. API Evolution

Prefer additive changes.

Safe example:

```json
{
  "oldField": "...",
  "newOptionalField": "..."
}
```

Breaking examples:

```text
Renaming existing field
Changing number to string
Removing field
Changing error semantics
Changing resource ownership rules
```

Breaking changes require versioning or a controlled migration.

---

# 62. Future API Areas

Not part of the initial MVP contract:

```text
AI assistant
Realtime chat
Marketplace
Payments
Social feed
Video streaming
Push notification system
Complex teacher analytics
External public API
```

Do not implement these because they appear useful.

They require explicit future specifications.

---

# 63. Contract Testing

Critical endpoints should have automated contract/integration tests.

Priority:

```text
/me

assessment start
assessment resume
assessment response save
assessment complete

skills current
skills history

parent children
parent overview

report access

lesson complete / XP idempotency
```

---

# 64. Assessment Security Tests

Must include:

```text
Student cannot read another student's session.

Student cannot submit responses to another student's session.

Completed sessions reject ordinary response updates.

Assessment version remains unchanged after content edits.

Duplicate completion does not create duplicate XP.

Unauthorized parent cannot view a child's results.

Cross-school access is rejected.
```

---

# 65. API Definition of Done

An endpoint is complete only when:

```text
Route exists.

Authentication is implemented where required.

Authorization is implemented.

Input validation exists.

Success response matches this contract.

Errors use the standard structure.

Repository/service architecture is respected.

Relevant tests pass.

API client/types are updated.

Documentation is updated when behavior changes.
```

---

# 66. Final API Principle

The API must represent ABA Skills 360 domain concepts rather than exposing raw WordPress internals.

Good:

```text
/assessment-sessions/{id}/complete
/students/{id}/skills/current
/parent/children/{id}/overview
```

Avoid application clients depending directly on generic WordPress concepts such as:

```text
/wp/v2/posts
/wp/v2/postmeta
```

for core ABA product behavior.

WordPress is an implementation foundation.

ABA Skills 360 API is the product contract.
