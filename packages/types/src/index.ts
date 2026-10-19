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
}

export type GradeGroup = '1_3' | '4_6' | '7_9' | '10_12';

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
  avatarUrl: string;
  totalXp: number;
  currentStreak: number;
  longestStreak: number;
  level: number;
  lastActiveAt: string;
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
  score: number; // 0 - 100 standardized
  maxScore: number;
  level: SkillLevel;
  updatedAt: string;
}

export interface SkillHistoryEntry {
  snapshotId: number;
  date: string;
  scores: Record<CompetencyCode, number>;
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
}

export interface LearningUnit {
  id: string;
  title: string;
  description: string;
  competencyTarget: CompetencyCode;
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

export interface RecommendationItem {
  id: string;
  title: string;
  description: string;
  type: 'lesson' | 'activity' | 'home_exercise';
  targetCompetency: CompetencyCode;
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
}

export interface ApiError {
  error: {
    code: string;
    message: string;
    details?: Record<string, unknown>;
  };
}
