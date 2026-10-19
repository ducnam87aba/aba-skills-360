/**
 * ABA Skills 360 - Shared Domain Types
 * Matching docs/api-contract.md & AGENTS.md
 */

export type UserRole = 'student' | 'parent' | 'teacher' | 'school_admin' | 'super_admin';

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

export interface StudentProfile {
  id: number;
  userId: number;
  studentCode: string;
  firstName: string;
  lastName: string;
  fullName: string;
  gradeLevel: number;
  gradeGroup: '1_3' | '4_6' | '7_9' | '10_12';
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

export type SkillCategory = 
  | 'cognitive'
  | 'social_emotional'
  | 'communication'
  | 'adaptive'
  | 'motor';

export interface SkillScore {
  skillId: number;
  skillCode: string;
  skillName: string;
  category: SkillCategory;
  score: number; // 0 - 100
  maxScore: number;
  level: 'beginning' | 'developing' | 'proficient' | 'advanced';
  updatedAt: string;
}

export interface SkillHistoryEntry {
  snapshotId: number;
  date: string;
  scores: Record<string, number>; // code -> score
}

export interface SkillSnapshot {
  id: number;
  studentId: number;
  assessmentSessionId: number;
  snapshotDate: string;
  scores: SkillScore[];
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

export interface AssessmentQuestion {
  id: number;
  prompt: string;
  type: 'multiple_choice' | 'likert' | 'scenario';
  options: Array<{
    id: string;
    text: string;
    scoreValue?: number;
  }>;
  skillTarget: string;
  required: boolean;
}

export interface Assessment {
  id: number;
  title: string;
  description: string;
  version: string;
  gradeGroup: string;
  durationMinutes: number;
  totalQuestions: number;
  skillCategories: SkillCategory[];
  questions?: AssessmentQuestion[];
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
  responses: Array<{
    questionId: number;
    answer: string | number;
    submittedAt: string;
  }>;
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

export interface StudentReport {
  id: number;
  studentId: number;
  generatedAt: string;
  title: string;
  summary: string;
  strengths: string[];
  areasForGrowth: string[];
  recommendations: Array<{
    title: string;
    description: string;
    type: 'lesson' | 'activity' | 'home_exercise';
  }>;
  overallScore: number;
}

export interface ApiError {
  error: {
    code: string;
    message: string;
    details?: Record<string, unknown>;
  };
}
