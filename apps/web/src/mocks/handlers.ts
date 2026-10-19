import { http, HttpResponse } from 'msw';
import type {
  User,
  StudentProfile,
  SkillScore,
  SkillHistoryEntry,
  LearningPath,
  AssessmentSession,
  Mission,
  Badge,
  StudentReport,
  SkillSnapshot,
} from '@aba/types';

export const mockStudentUser: User = {
  id: 101,
  username: 'namduong',
  email: 'nam.duong@example.edu.vn',
  displayName: 'Dương Đức Nam',
  roles: ['student'],
  studentId: 1,
  schoolId: 10,
};

export const mockParentUser: User = {
  id: 201,
  username: 'parent_lan',
  email: 'lan.nguyen@example.com',
  displayName: 'Nguyễn Thị Lan',
  roles: ['parent'],
  parentId: 5,
};

export const mockStudentProfile: StudentProfile = {
  id: 1,
  userId: 101,
  studentCode: 'ABA-2026-001',
  firstName: 'Nam',
  lastName: 'Dương Đức',
  fullName: 'Dương Đức Nam',
  gradeLevel: 4,
  gradeGroup: '4_6',
  schoolId: 10,
  schoolName: 'Trường Tiểu Học Quốc Tế Á Châu',
  classId: 402,
  className: '4A2',
  avatarUrl:
    'https://images.unsplash.com/photo-1534528741775-53994a69daeb?w=150&auto=format&fit=crop&q=80',
  totalXp: 1450,
  currentStreak: 7,
  longestStreak: 14,
  level: 8,
  lastActiveAt: new Date().toISOString(),
};

/**
 * 6 Core Competencies defined in docs/assessment-spec.md Section 4
 */
export const mockSkillScores: SkillScore[] = [
  {
    skillId: 1,
    skillCode: 'SA',
    skillName: 'Tự nhận thức',
    category: 'core_competency',
    score: 82,
    maxScore: 100,
    level: 'proficient',
    updatedAt: new Date().toISOString(),
  },
  {
    skillId: 2,
    skillCode: 'ER',
    skillName: 'Quản lý cảm xúc',
    category: 'core_competency',
    score: 68,
    maxScore: 100,
    level: 'developing',
    updatedAt: new Date().toISOString(),
  },
  {
    skillId: 3,
    skillCode: 'SM',
    skillName: 'Tự quản lý',
    category: 'core_competency',
    score: 85,
    maxScore: 100,
    level: 'advanced',
    updatedAt: new Date().toISOString(),
  },
  {
    skillId: 4,
    skillCode: 'CO',
    skillName: 'Giao tiếp',
    category: 'core_competency',
    score: 74,
    maxScore: 100,
    level: 'proficient',
    updatedAt: new Date().toISOString(),
  },
  {
    skillId: 5,
    skillCode: 'CE',
    skillName: 'Hợp tác và đồng cảm',
    category: 'core_competency',
    score: 88,
    maxScore: 100,
    level: 'advanced',
    updatedAt: new Date().toISOString(),
  },
  {
    skillId: 6,
    skillCode: 'PD',
    skillName: 'Giải quyết vấn đề và ra quyết định',
    category: 'core_competency',
    score: 90,
    maxScore: 100,
    level: 'advanced',
    updatedAt: new Date().toISOString(),
  },
];

export const mockLearningPath: LearningPath = {
  studentId: 1,
  currentUnitId: 'unit-1',
  currentNodeId: 'node-2',
  units: [
    {
      id: 'unit-1',
      title: 'Chương 1: Nền tảng Giao tiếp Chủ động',
      description: 'Làm quen với việc lắng nghe tích cực và phản hồi hòa nhã',
      competencyTarget: 'CO',
      order: 1,
      status: 'active',
      nodes: [
        {
          id: 'node-1',
          title: 'Nhận biết tín hiệu cảm xúc bạn bè',
          description: 'Học cách quan sát nét mặt và cử chỉ',
          type: 'lesson',
          status: 'completed',
          score: 100,
          xpReward: 30,
          order: 1,
        },
        {
          id: 'node-2',
          title: 'Thực hành Lắng nghe không phán xét',
          description: 'Bài tập nhập vai tình huống làm việc nhóm',
          type: 'lesson',
          status: 'unlocked',
          xpReward: 40,
          order: 2,
        },
        {
          id: 'node-3',
          title: 'Thử thách: Hóa giải hiểu lầm nhỏ',
          description: 'Trắc nghiệm lựa chọn cách diễn đạt phù hợp',
          type: 'quiz',
          status: 'locked',
          xpReward: 60,
          order: 3,
        },
        {
          id: 'node-4',
          title: 'Boss Challenge: Buổi thuyết trình tự tin',
          description: 'Vượt qua thử thách chương để mở khóa',
          type: 'boss_challenge',
          status: 'locked',
          xpReward: 100,
          order: 4,
        },
      ],
    },
    {
      id: 'unit-2',
      title: 'Chương 2: Quản lý Cảm xúc & Làm dịu căng thẳng',
      description: 'Phương pháp nhận biết tác nhân gây cáu giận và tự điều chỉnh',
      competencyTarget: 'ER',
      order: 2,
      status: 'locked',
      nodes: [
        {
          id: 'node-2-1',
          title: 'Kỹ thuật thở sâu 3 phút',
          description: 'Bài tập điều hòa nhịp thở tức thời',
          type: 'lesson',
          status: 'locked',
          xpReward: 50,
          order: 1,
        },
      ],
    },
  ],
};

export const mockMissions: Mission[] = [
  {
    id: 1,
    title: 'Hoàn thành 1 bài học hôm nay',
    description: 'Thực hiện bất kỳ bài học nào trên lộ trình',
    xpReward: 25,
    progress: 1,
    target: 1,
    isCompleted: true,
    type: 'daily',
    expiresAt: new Date(Date.now() + 86400000).toISOString(),
  },
  {
    id: 2,
    title: 'Đạt điểm tuyệt đối trong 1 Quiz',
    description: 'Đạt 100% điểm trong bài kiểm tra bất kỳ',
    xpReward: 50,
    progress: 0,
    target: 1,
    isCompleted: false,
    type: 'daily',
    expiresAt: new Date(Date.now() + 86400000).toISOString(),
  },
  {
    id: 3,
    title: 'Duy trì chuỗi học tập 7 ngày liên tiếp',
    description: 'Không bỏ lỡ ngày nào trong tuần',
    xpReward: 120,
    progress: 5,
    target: 7,
    isCompleted: false,
    type: 'weekly',
    expiresAt: new Date(Date.now() + 86400000 * 3).toISOString(),
  },
];

export const mockBadges: Badge[] = [
  {
    id: 1,
    code: 'STREAK_7',
    name: 'Chiến Binh Bền Bỉ',
    description: 'Đạt chuỗi học tập liên tục 7 ngày',
    category: 'streak',
    icon: 'flame',
    isUnlocked: true,
    unlockedAt: '2026-10-18T10:00:00Z',
  },
  {
    id: 2,
    code: 'XP_1000',
    name: 'Bậc Thầy Tích Lũy',
    description: 'Vượt mốc 1000 điểm kinh nghiệm XP',
    category: 'xp',
    icon: 'zap',
    isUnlocked: true,
    unlockedAt: '2026-10-15T14:30:00Z',
  },
  {
    id: 3,
    code: 'PERFECT_COMM',
    name: 'Nhà Giao Tiếp Xuất Sắc',
    description: 'Hoàn thành trọn vẹn Chương 1 Giao Tiếp',
    category: 'skill',
    icon: 'award',
    isUnlocked: false,
  },
];

export const mockReports: StudentReport[] = [
  {
    id: 101,
    studentId: 1,
    generatedAt: '2026-10-19T08:00:00Z',
    title: 'Báo cáo Định kỳ Năng lực Kỹ năng Tháng 10/2026',
    summary:
      'Học sinh Dương Đức Nam thể hiện sự tiến bộ vượt trội trong năng lực Hợp tác và Giải quyết vấn đề. Năng lực Quản lý cảm xúc có thể đẩy mạnh thêm thông qua các tình huống thực hành gia đình.',
    overallScore: 81,
    strengths: [
      'Khả năng tập trung và nhận diện vấn đề logic mạch lạc',
      'Chủ động phối hợp nhóm và hỗ trợ bạn bè cùng tiến bộ',
    ],
    areasForGrowth: [
      'Cần thêm bài tập thực hành kiềm chế cảm xúc khi gặp áp lực thời gian',
    ],
    recommendations: [
      {
        id: 'rec-1',
        title: 'Thực hành thở sâu 3 phút',
        description: 'Bài tập điều hòa nhịp thở trước khi bắt đầu bài thi',
        type: 'home_exercise',
        targetCompetency: 'ER',
      },
      {
        id: 'rec-2',
        title: 'Chương 1: Nền tảng Giao tiếp Chủ động',
        description: 'Khóa học bổ trợ kỹ năng lắng nghe và giải thích bất đồng',
        type: 'lesson',
        targetCompetency: 'CO',
      },
    ],
  },
];

// MSW REST Handlers for /wp-json/aba/v1/*
export const handlers = [
  // Current user info
  http.get('*/wp-json/aba/v1/me', ({ request }) => {
    const url = new URL(request.url);
    const roleParam = url.searchParams.get('as') || 'student';
    return HttpResponse.json(
      roleParam === 'parent' ? mockParentUser : mockStudentUser
    );
  }),

  // Student me profile
  http.get('*/wp-json/aba/v1/students/me', () => {
    return HttpResponse.json(mockStudentProfile);
  }),

  // Student profile by id
  http.get('*/wp-json/aba/v1/students/:id', ({ params }) => {
    return HttpResponse.json({
      ...mockStudentProfile,
      id: Number(params.id),
    });
  }),

  // Learning path
  http.get('*/wp-json/aba/v1/learning/path', () => {
    return HttpResponse.json(mockLearningPath);
  }),

  // Current Skills
  http.get('*/wp-json/aba/v1/skills/current', () => {
    return HttpResponse.json(mockSkillScores);
  }),
  http.get('*/wp-json/aba/v1/students/:id/skills/current', () => {
    return HttpResponse.json(mockSkillScores);
  }),

  // Skill History
  http.get('*/wp-json/aba/v1/students/:id/skills/history', () => {
    const history: SkillHistoryEntry[] = [
      {
        snapshotId: 1,
        date: '2026-08-01',
        scores: { SA: 70, ER: 55, SM: 72, CO: 60, CE: 78, PD: 80 },
      },
      {
        snapshotId: 2,
        date: '2026-09-01',
        scores: { SA: 76, ER: 62, SM: 80, CO: 68, CE: 82, PD: 85 },
      },
      {
        snapshotId: 3,
        date: '2026-10-01',
        scores: { SA: 82, ER: 68, SM: 85, CO: 74, CE: 88, PD: 90 },
      },
    ];
    return HttpResponse.json(history);
  }),

  // Assessment start
  http.post('*/wp-json/aba/v1/assessments/:id/start', async ({ params }) => {
    const newSession: AssessmentSession = {
      id: 999,
      assessmentId: Number(params.id),
      studentId: 1,
      version: '1.0.0',
      status: 'in_progress',
      currentQuestionIndex: 0,
      totalQuestions: 6,
      startedAt: new Date().toISOString(),
      responses: [],
    };
    return HttpResponse.json(newSession, { status: 201 });
  }),

  // Assessment answer
  http.post('*/wp-json/aba/v1/assessment-sessions/:id/responses', async () => {
    return HttpResponse.json({
      success: true,
      nextQuestionIndex: 1,
    });
  }),

  // Assessment complete
  http.post(
    '*/wp-json/aba/v1/assessment-sessions/:id/complete',
    async ({ params }) => {
      const completedSession: AssessmentSession = {
        id: Number(params.id),
        assessmentId: 1,
        studentId: 1,
        version: '1.0.0',
        status: 'completed',
        currentQuestionIndex: 6,
        totalQuestions: 6,
        startedAt: new Date(Date.now() - 600000).toISOString(),
        completedAt: new Date().toISOString(),
        responses: [],
      };

      const snapshot: SkillSnapshot = {
        id: 55,
        studentId: 1,
        assessmentSessionId: Number(params.id),
        snapshotDate: new Date().toISOString(),
        overallScore: 81.17,
        scores: mockSkillScores,
        qualityFlags: {
          completionRate: 100,
          speedingFlag: false,
          straightlining: false,
          averageLatencyMs: 4200,
        },
      };

      return HttpResponse.json({
        session: completedSession,
        snapshot,
      });
    }
  ),

  // Parent Children
  http.get('*/wp-json/aba/v1/parent/children', () => {
    return HttpResponse.json([mockStudentProfile]);
  }),

  // Student Reports
  http.get('*/wp-json/aba/v1/students/:id/reports', () => {
    return HttpResponse.json(mockReports);
  }),

  // Gamification Missions & Badges
  http.get('*/wp-json/aba/v1/gamification/missions', () => {
    return HttpResponse.json(mockMissions);
  }),
  http.get('*/wp-json/aba/v1/gamification/badges', () => {
    return HttpResponse.json(mockBadges);
  }),
];
