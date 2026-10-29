/**
 * ABA Skills 360 - Shared Domain Types
 * Fully aligned with docs/assessment-spec.md, docs/api-contract.md, and docs/architecture.md
 */

export * from './scoring';

export type UserRole =
  | 'student'
  | 'parent'
  | 'teacher'
  | 'school_admin'
  | 'super_admin';

export interface User {
  id: number;
  username: string;
  email: string;
  displayName: string;
  roles: UserRole[];
  studentId?: number;
  parentId?: number;
  schoolId?: number;
  /**
   * PA48 — the account still signs with a staff-generated handout password and
   * must set a private one before entering the app (forced first-login change).
   */
  mustChangePassword?: boolean;
}

export type GradeGroup = '1_3' | '4_6' | '7_9' | '10_12';

export interface ClassContext {
  id: number;
  schoolId: number;
  code: string;
  name: string;
  gradeGroup: GradeGroup;
  academicYear: string;
  teacherId: number | null;
  teacherName: string | null;
}

export interface StudentProfile {
  id: number;
  userId: number;
  studentCode: string;
  firstName: string;
  lastName: string;
  fullName: string;
  gradeLevel: number;
  gradeGroup: GradeGroup;
  schoolId: number;
  schoolName: string;
  classId: number;
  className: string;
  // PA26: the class the learner sits in, resolved from wp_aba_classes with its
  // derived homeroom teacher. Present on the parent-facing views; null when the
  // learner has no assigned class. Optional so roster endpoints stay valid.
  class?: ClassContext | null;
  avatarUrl: string;
  totalXp: number;
  currentStreak: number;
  longestStreak: number;
  level: number;
  lastActiveAt: string;
  // PA45: staff roster views carry the soft-archive state; absent on older
  // endpoints so every existing consumer stays valid.
  status?: 'active' | 'inactive';
  // PA46: the WordPress login the learner signs the app in with. Present once
  // staff provision the account (the password itself is never readable here —
  // only issued once to staff); null when no login exists yet.
  username?: string | null;
  // PA47: set once a password has been issued to staff. Drives the roster's
  // "Cấp tài khoản" vs "Đặt lại mật khẩu" label so a re-click never silently
  // rotates a live credential. Optional so older endpoints stay valid.
  credentialIssuedAt?: string | null;
}

/**
 * PA61 — the four dimensions a credential row can belong to. The security
 * history screen filters on these; the server groups, it never uses them to
 * decide who may read (§8 still gates every row).
 */
export type CredentialEventKind = 'account' | 'device' | 'session' | 'lockout';

/**
 * PA49 — one append-only entry on a learner's credential audit trail: who
 * issued / reset / changed their login, and when. Never carries a password,
 * hash or app token — only non-secret context (§8).
 *
 * PA61 — the trail now covers all three doors (a new device, a password
 * sign-in, a lockout after failed guesses), so each row says which KIND it is
 * and carries the non-secret detail that was already in the ledger: the
 * friendly device label, which door the password came through, and how much a
 * lockout cost. Absent detail is `null`, never `''` or `0`, so the screen can
 * tell "nothing was named" from "something named nothing".
 */
export interface CredentialAuditEvent {
  id: number;
  action:
    | 'credential_issued'
    | 'credential_reset'
    | 'credential_changed'
    | 'credential_expired'
    | 'credential_sessions_revoked'
    | 'credential_device_added'
    | 'credential_device_revoked'
    | 'credential_session_opened'
    | 'login_throttled';
  /** Which filter chip this row answers to. */
  kind: CredentialEventKind;
  /** Localized verb for `action`, e.g. "Cấp tài khoản đăng nhập". */
  actionLabel: string;
  /** Display name of the acting staff, or "Chính bạn" / "Nhân viên nhà trường". */
  actorLabel: string;
  /** 'generated' (forced change) | 'staff' (staff-typed) | '' for a self-change. */
  method: string;
  mustChange: boolean;
  /** ISO-8601 (Z) instant the event was recorded. */
  createdAt: string;
  /** Device label the server derived from the user agent, e.g. "Máy tính". */
  label: string | null;
  /** Which door the account password came through: a page, or a script. */
  door: 'cookie' | 'xmlrpc' | null;
  /** Failed guesses a lockout cost, when the row is a lockout. */
  failures: number | null;
  /** Seconds of the lockout, when the row is a lockout. */
  retryAfter: number | null;
}

export interface CredentialAuditResult {
  studentId: number;
  events: CredentialAuditEvent[];
}

/**
 * PA52 — a learner's live logins, as counts only. The server never returns a
 * token, uuid or hash, so this can be shown to a parent as-is (§8).
 */
export interface StudentSessions {
  studentId: number;
  /** sessions + appPasswords: every login still usable right now. */
  total: number;
  /** Cookie sessions (the browser-mode fallback). */
  sessions: number;
  /** Basic-auth tokens the headless app mints one of per sign-in. */
  appPasswords: number;
  /** How many logins the revoke call just ended (revoke response only). */
  revoked?: number;
}

/**
 * PA55 — one live device of the caller's OWN account. `handle` is a derived,
 * one-way id: the token and its uuid never leave the server, so a row is safe
 * to render as-is (§8).
 */
export interface DeviceSession {
  handle: string;
  /** Device label: 'Điện thoại' | 'Máy tính bảng' | 'Máy tính' | 'Thiết bị'. */
  label: string;
  /** When this device signed in (ISO 8601, UTC). */
  createdAt: string;
  /** Last authenticated request from it, or null while it has never been used. */
  lastUsedAt: string | null;
  /** True for the device making this request. */
  current: boolean;
}

export interface DeviceSessionList {
  devices: DeviceSession[];
  count: number;
  /** How many devices one account may keep signed in (server policy). */
  maxDevices: number;
}

export interface DeviceRevokeResult {
  revoked: number;
}

/**
 * PA56 — a child's live devices, as the adult responsible for them sees it.
 * Same non-secret projection as the self-service list, so every row is safe to
 * render (§8). No row is `current`: none of these devices is the caller's.
 */
export interface StudentDeviceList {
  studentId: number;
  devices: DeviceSession[];
  count: number;
  maxDevices: number;
}

export interface StudentDeviceRevokeResult {
  studentId: number;
  revoked: number;
  /** Which device was ended, so the panel can name it after the fact. */
  label: string;
  /** How many devices remain signed in after the call. */
  count: number;
}

export interface ParentProfile {
  id: number;
  userId: number;
  fullName: string;
  phone: string;
  email: string;
  children: StudentProfile[];
}

/**
 * 6 Core Competencies defined in docs/assessment-spec.md Section 4
 * SA: Tự nhận thức
 * ER: Quản lý cảm xúc
 * SM: Tự quản lý
 * CO: Giao tiếp
 * CE: Hợp tác và đồng cảm
 * PD: Giải quyết vấn đề và ra quyết định
 */
export type CompetencyCode = 'SA' | 'ER' | 'SM' | 'CO' | 'CE' | 'PD';

export interface CompetencyMeta {
  code: CompetencyCode;
  name: string;
  operationalMeaning: string;
  indicators: Array<{
    code: string;
    name: string;
  }>;
}

export const COMPETENCY_DEFINITIONS: Record<CompetencyCode, CompetencyMeta> = {
  SA: {
    code: 'SA',
    name: 'Tự nhận thức',
    operationalMeaning:
      'Nhận biết cảm xúc, nhu cầu và cách mình thực hiện nhiệm vụ',
    indicators: [
      { code: 'SA-1', name: 'Gọi tên cảm xúc' },
      { code: 'SA-2', name: 'Nhận biết tác nhân' },
      { code: 'SA-3', name: 'Tự phản ánh' },
    ],
  },
  ER: {
    code: 'ER',
    name: 'Quản lý cảm xúc',
    operationalMeaning:
      'Lựa chọn cách phản ứng an toàn, phù hợp khi có cảm xúc mạnh',
    indicators: [
      { code: 'ER-1', name: 'Hạ nhiệt cảm xúc' },
      { code: 'ER-2', name: 'Kiềm chế phản ứng xung động' },
      { code: 'ER-3', name: 'Phục hồi sau sự cố' },
    ],
  },
  SM: {
    code: 'SM',
    name: 'Tự quản lý',
    operationalMeaning:
      'Tổ chức hành động và duy trì trách nhiệm với nhiệm vụ phù hợp tuổi',
    indicators: [
      { code: 'SM-1', name: 'Bắt đầu và hoàn thành nhiệm vụ' },
      { code: 'SM-2', name: 'Quản lý đồ dùng và thời gian cơ bản' },
      { code: 'SM-3', name: 'Kiên trì trước bài tập khó' },
    ],
  },
  CO: {
    code: 'CO',
    name: 'Giao tiếp',
    operationalMeaning:
      'Lắng nghe, diễn đạt và trao đổi bất đồng có tôn trọng',
    indicators: [
      { code: 'CO-1', name: 'Lắng nghe chủ động' },
      { code: 'CO-2', name: 'Diễn đạt nhu cầu rõ ràng' },
      { code: 'CO-3', name: 'Hóa giải hiểu lầm' },
    ],
  },
  CE: {
    code: 'CE',
    name: 'Hợp tác và đồng cảm',
    operationalMeaning:
      'Nhận biết góc nhìn người khác, phối hợp và quan tâm',
    indicators: [
      { code: 'CE-1', name: 'Thấu hiểu cảm xúc bạn bè' },
      { code: 'CE-2', name: 'Phối hợp nhóm' },
      { code: 'CE-3', name: 'Chia sẻ và hỗ trợ' },
    ],
  },
  PD: {
    code: 'PD',
    name: 'Giải quyết vấn đề và ra quyết định',
    operationalMeaning:
      'Xác định vấn đề, cân nhắc lựa chọn và hệ quả phù hợp bối cảnh',
    indicators: [
      { code: 'PD-1', name: 'Nhận diện vấn đề' },
      { code: 'PD-2', name: 'Đề xuất giải pháp thay thế' },
      { code: 'PD-3', name: 'Cân nhắc hệ quả trước khi làm' },
    ],
  },
};

export type SkillLevel = 'beginning' | 'developing' | 'proficient' | 'advanced';

export interface SkillScore {
  skillId?: number;
  skillCode: CompetencyCode;
  skillName: string;
  category: string;
  // Insufficient evidence must surface as null, never a fabricated 0 (§45).
  score: number | null;
  maxScore: number;
  level: SkillLevel;
  status?: 'valid' | 'insufficient_evidence';
  sourceType?: RespondentType;
  scoringVersion?: string | null;
  updatedAt: string;
}

export interface SkillHistoryEntry {
  snapshotId: number;
  date: string;
  scores: Record<CompetencyCode, number>;
}

// One competency's learner-vs-class comparison (PA31). classAverage is an
// anonymized aggregate; samples is how many classmates contributed to it.
export interface CompetencyBenchmark {
  code: CompetencyCode;
  studentScore: number | null;
  classAverage: number | null;
  samples: number;
}

// The learner's class benchmark. classId is null for a class-less learner, in
// which case competencies is empty (never a fabricated comparison, §11). A cell's
// classAverage is null when fewer than minSamples peers contributed to it — the
// k-anonymity floor that keeps a thin mean from isolating one learner (§8).
// reliable is false for classes smaller than minSamples.
export interface ClassBenchmark {
  classId: number | null;
  memberCount: number;
  sourceType: RespondentType;
  reliable: boolean;
  minSamples: number;
  competencies: CompetencyBenchmark[];
}

// One assessment date's anonymized class mean per competency (PA32, §11 over
// time). Only competencies measured that date appear; individual peers stay
// hidden behind the aggregate (§8).
export interface ClassTrendEntry {
  date: string;
  scores: Partial<Record<CompetencyCode, number>>;
}

export interface QualityFlags {
  completionRate: number; // percentage
  straightlining: boolean;
  speedingFlag: boolean;
  averageLatencyMs: number;
}

export interface SkillSnapshot {
  id: number;
  studentId: number;
  assessmentSessionId: number;
  snapshotDate: string;
  overallScore: number;
  scores: SkillScore[];
  qualityFlags?: QualityFlags;
}

export type RespondentType = 'student' | 'parent' | 'teacher';

export type QuestionType = 'frequency' | 'scenario';

export interface AssessmentQuestionOption {
  id: string;
  text: string;
  scoreValue?: number; // 0..100 for scenario, or 1..4 for frequency
}

export interface AssessmentQuestion {
  id: number;
  type: QuestionType;
  competencyCode: CompetencyCode;
  indicatorCode: string;
  prompt: string;
  recallPeriod?: string; // "Trong 30 ngày qua"
  isReverse?: boolean; // For reverse scoring (5 - raw)
  options: AssessmentQuestionOption[];
  required: boolean;
  order: number;
}

export interface Assessment {
  id: number;
  title: string;
  description: string;
  version: string;
  gradeGroup: GradeGroup;
  durationMinutes: number;
  totalQuestions: number;
  competencies: CompetencyCode[];
  questions?: AssessmentQuestion[];
}

export interface AssessmentResponse {
  questionId: number;
  responseValue: string | number;
  latencyMs?: number;
  submittedAt: string;
}

export interface AssessmentSession {
  id: number;
  assessmentId: number;
  studentId: number;
  version: string;
  status: 'in_progress' | 'completed' | 'abandoned';
  currentQuestionIndex: number;
  totalQuestions: number;
  startedAt: string;
  completedAt?: string;
  responses: AssessmentResponse[];
}

export type NodeStatus = 'locked' | 'unlocked' | 'completed';
export type NodeType = 'lesson' | 'quiz' | 'boss_challenge';

export interface LearningNode {
  id: string;
  title: string;
  description: string;
  type: NodeType;
  status: NodeStatus;
  score?: number;
  xpReward: number;
  order: number;
  // PA17: the Content Library lesson this node opens, when content is bound.
  contentId?: number | null;
}

export interface LearningUnit {
  id: string;
  title: string;
  description: string;
  competencyTarget: CompetencyCode;
  // PA34 rationale (§20): the gap signal that ordered this unit. Optional so older
  // path payloads stay valid; competencyScore is null when the measure is missing.
  competencyScore?: number | null;
  isPriority?: boolean;
  reason?: string;
  order: number;
  status: 'locked' | 'active' | 'completed';
  nodes: LearningNode[];
}

export interface LearningPath {
  studentId: number;
  currentUnitId: string;
  currentNodeId: string;
  units: LearningUnit[];
}

// PA17 Content Library: an editorial lesson (aba_lesson CPT) that a path node's
// contentId points to. Completion still flows through the XP ledger; this is the
// readable body behind the node.
export interface Lesson {
  id: number;
  title: string;
  excerpt: string;
  content: string;
  competencyTarget: CompetencyCode;
  gradeGroup: string;
  type: NodeType;
  order: number;
  xpReward: number;
  moduleId: number;
}

export interface LessonCatalog {
  count: number;
  lessons: Lesson[];
}

export interface Mission {
  id: number;
  title: string;
  description: string;
  xpReward: number;
  progress: number;
  target: number;
  isCompleted: boolean;
  type: 'daily' | 'weekly';
  expiresAt: string;
}

export interface Badge {
  id: number;
  code: string;
  name: string;
  description: string;
  category: string;
  icon: string;
  isUnlocked: boolean;
  unlockedAt?: string;
}

// PA22: the learner's derived gamification state (GET /gamification/me). XP and
// the streak are read from ledgers; streakFreezes is the banked freeze balance.
export interface GamificationMe {
  studentId: number;
  totalXp: number;
  xpBySource: Record<string, { total: number; count: number }>;
  currentStreak: number;
  longestStreak: number;
  assessmentsCompleted: number;
  level: number;
  streakFreezes: number;
}

// PA22: the outcome of spending one freeze (POST /gamification/streak/freeze).
export interface StreakFreezeResult {
  applied: boolean;
  status: 'applied' | 'no_gap' | 'no_freeze';
  gapDate: string | null;
  currentStreak: number;
  freezes: number;
}

export interface RecommendationItem {
  id: string;
  title: string;
  description: string;
  type: 'lesson' | 'activity' | 'home_exercise';
  targetCompetency: CompetencyCode;
  // Added by the deterministic engine (PA4); optional so older renders ignore them.
  priority?: number;
  targetScore?: number;
  reason?: string;
  gradeGroup?: GradeGroup;
}

export interface StudentReport {
  id: number;
  studentId: number;
  generatedAt: string;
  title: string;
  summary: string;
  overallScore: number;
  strengths: string[];
  areasForGrowth: string[];
  recommendations: RecommendationItem[];
  // PA21: event-derived delivery/read status (only present on the detail view).
  delivery?: ReportDelivery;
}

// PA21: derived from the append-only event ledger, never a stored counter.
export interface ReportDelivery {
  delivered: boolean;
  read: boolean;
  views: number;
  firstReadAt: string | null;
  lastReadAt: string | null;
}

export interface ApiError {
  error: {
    code: string;
    message: string;
    details?: Record<string, unknown>;
  };
}

/**
 * Client-facing shapes mirroring the ABA WordPress REST responses, kept in sync
 * so the shared contract matches the backend (AGENTS.md §17).
 */
export interface LoginResult {
  user: User;
  /**
   * PA37 — WordPress Application Password issued for the headless client.
   * The app replays it as HTTP Basic (username + this token) on later calls;
   * null when the server environment keeps app passwords disabled (then only
   * the cookie/session flow applies).
   */
  token?: string | null;
}

export interface AssessmentMeta {
  id: number;
  code: string;
  title: string;
  version: string;
  questionCount: number;
  respondentType: RespondentType;
}

// Server strips scoring secrets (is_reverse / score_value / weight, §77); the
// learner only ever sees the answer affordances.
export interface SessionQuestionOption {
  value?: number; // likert scale point
  id?: string; // scenario option id
  label: string;
}

export interface SessionQuestionItem {
  id: number;
  type: 'likert' | 'scenario';
  recallPeriod?: string;
  prompt?: string;
  options: SessionQuestionOption[];
}

export interface ParentOverviewSkillRef {
  key: string;
  name: string;
  score: number;
  level: SkillLevel;
}

export interface ParentOverview {
  student: { id: number; displayName: string; grade: number; class?: ClassContext | null };
  summary: {
    currentStreak: number;
    totalXp: number;
    weekly: { lessonsCompleted: number; missionsCompleted: number };
  };
  skills: {
    topStrengths: ParentOverviewSkillRef[];
    priorityGrowthAreas: ParentOverviewSkillRef[];
    insufficientEvidence: string[];
  };
  latestReport: { id: number; type: string } | null;
}

// PA40 — the child's recent activity, as surfaced on the parent "Hoạt động"
// screen. Every item is derived from the progress/XP ledgers and the achievement
// timeline on the server (AGENTS.md §12); the client renders, it never fabricates.
// PA57 adds `device`: the one item about the ACCOUNT rather than about learning —
// a sign-in that minted a new device token (label only, never a token or uuid).
// PA58 adds `security`: a lock engagement from repeated failed sign-ins, carried
// as facts (how many tries, how long the wait) and never the address behind it.
export type ParentActivityType = 'lesson' | 'mission' | 'badge' | 'device' | 'security';

export interface ParentActivity {
  id: string;
  type: ParentActivityType;
  title: string;
  occurredAt: string | null;
  xpEarned: number | null;
  score: number | null;
}

export interface ParentActivityFeed {
  studentId: number;
  weeklyXp: number;
  items: ParentActivity[];
}

// PA42 — parent link-request queue. A submission is only a REQUEST: the
// parent_student access row appears after an administrator approves it (§8).
// Rows deliberately carry no learner name/id before approval — just the code
// the parent typed.
export type ParentLinkRequestStatus = 'pending' | 'approved' | 'rejected';

export interface ParentLinkRequest {
  id: number;
  studentCode: string;
  relationship: string;
  status: ParentLinkRequestStatus;
  createdAt: string;
  updatedAt: string;
}

export interface ParentLinkRequestResult {
  status: 'created' | 'exists' | 'already_linked';
  request: ParentLinkRequest | null;
}

export interface QuestionBatch {
  items: SessionQuestionItem[];
  cursor: string | null;
  hasMore: boolean;
}

export interface AssessmentStartResult {
  session: {
    id: number;
    assessmentId: number;
    assessmentVersion: string;
    studentId: number;
    status: string;
    startedAt: string;
    resume: boolean;
  };
  questionBatch: QuestionBatch;
}

export interface SessionState {
  id: number;
  assessmentId: number;
  assessmentVersion: string;
  studentId: number;
  status: 'in_progress' | 'completed' | 'abandoned';
  startedAt: string;
  progress: { answered: number; total: number; percent: number };
}

export interface AssessmentMetaResult {
  assessment: AssessmentMeta;
}

export interface CompleteResultScore {
  skillCode: CompetencyCode;
  category: string;
  score: number | null;
  level: SkillLevel;
  status?: 'valid' | 'insufficient_evidence';
}

export interface AssessmentCompleteResult {
  session: { id: number; status: string; completedAt: string };
  result: {
    status: string;
    skillsProfileAvailable: boolean;
    overallScore: number | null;
    scoringVersion: string;
    scores: CompleteResultScore[];
    qualityFlags: QualityFlags;
    idempotent: boolean;
  };
  rewards: { xpAwarded: number };
}

/**
 * Recommendation Engine envelope (GET /aba/v1/recommendations). Mirrors the
 * deterministic backend response: provenance plus an ordered, capped item list.
 */
export interface RecommendationsResult {
  studentId: number;
  source: RespondentType;
  strategyVersion: string;
  threshold: number;
  gradeGroup: GradeGroup;
  items: RecommendationItem[];
}
