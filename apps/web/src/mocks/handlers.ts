import { http, HttpResponse } from 'msw';
import type {
  User,
  StudentProfile,
  ClassContext,
  ClassBenchmark,
  ClassTrendEntry,
  SkillScore,
  SkillHistoryEntry,
  LearningPath,
  LearningUnit,
  LearningNode,
  Lesson,
  NodeStatus,
  NodeType,
  CompetencyCode,
  Mission,
  Badge,
  StudentReport,
  SessionQuestionItem,
  ParentOverview,
  ParentActivityFeed,
  ParentActivityType,
  ParentLinkRequest,
  RecommendationItem,
  RecommendationsResult,
  GradeGroup,
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

// PA9: the mock mirrors a real cookie session — login sets it, logout clears it,
// /me reads it. Identity is no longer faked per-request via a `?as=` query param.
let mockAuthSession: User | null = null;

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

// PA26: the class + homeroom teacher a parent sees for their child. Mirrors
// ClassRepository::contextFor — real class row + teacher derived from scope.
export const mockClassContext: ClassContext = {
  id: 402,
  schoolId: 10,
  code: '4A2',
  name: 'Lớp 4A2',
  gradeGroup: '4_6',
  academicYear: '2026-2027',
  teacherId: 501,
  teacherName: 'Cô giáo Lan',
};

// PA41: a second linked child (different class + school) so the parent child
// switcher renders in mock mode too. Mirrors the demo seeder linking learner 2.
const mockClassContext2: ClassContext = {
  id: 501,
  schoolId: 20,
  code: '5B1',
  name: 'Lớp 5B1',
  gradeGroup: '4_6',
  academicYear: '2026-2027',
  teacherId: 0,
  teacherName: '',
};

export const mockSecondChild: StudentProfile = {
  ...mockStudentProfile,
  id: 2,
  userId: 102,
  studentCode: 'ABA-2026-002',
  firstName: 'Mai',
  lastName: 'Trần Nữ',
  fullName: 'Trần Nữ Mai Lan',
  gradeLevel: 5,
  classId: 501,
  className: '5B1',
  schoolId: 20,
  schoolName: 'Trường Tiểu Học Sao Mai',
  totalXp: 300,
  currentStreak: 0,
  longestStreak: 0,
  level: 6,
};

// PA42: mock link-request queue, mirroring the demo seeder — one genuine
// pending request the parent can see, unlinked cohort codes are requestable,
// and rows only ever carry the CODE (a pending request must not leak a learner
// identity before approval, §8 — same as the real backend's toApiShape).
const MOCK_LINKED_CODES = [mockStudentProfile.studentCode, mockSecondChild.studentCode];
const MOCK_UNLINKED_CODES = ['ABA-2026-101', 'ABA-2026-102', 'ABA-2026-103', 'ABA-2026-104', 'ABA-2026-105', 'ABA-2026-106'];
let mockLinkRequestId = 1;
let mockLinkRequests: ParentLinkRequest[] = [
  {
    id: mockLinkRequestId,
    studentCode: 'ABA-2026-101',
    relationship: 'mother',
    status: 'pending',
    createdAt: new Date().toISOString(),
    updatedAt: new Date().toISOString(),
  },
];

/**
 * 6 Core Competencies defined in docs/assessment-spec.md Section 4
 */
export const mockSkillScores: SkillScore[] = [
  { skillId: 1, skillCode: 'SA', skillName: 'Tự nhận thức', category: 'core_competency', score: 82, maxScore: 100, level: 'proficient', status: 'valid', sourceType: 'student', updatedAt: new Date().toISOString() },
  { skillId: 2, skillCode: 'ER', skillName: 'Quản lý cảm xúc', category: 'core_competency', score: 45, maxScore: 100, level: 'beginning', status: 'valid', sourceType: 'student', updatedAt: new Date().toISOString() },
  { skillId: 3, skillCode: 'SM', skillName: 'Tự quản lý', category: 'core_competency', score: 85, maxScore: 100, level: 'advanced', status: 'valid', sourceType: 'student', updatedAt: new Date().toISOString() },
  { skillId: 4, skillCode: 'CO', skillName: 'Giao tiếp', category: 'core_competency', score: 74, maxScore: 100, level: 'proficient', status: 'valid', sourceType: 'student', updatedAt: new Date().toISOString() },
  { skillId: 5, skillCode: 'CE', skillName: 'Hợp tác và đồng cảm', category: 'core_competency', score: 88, maxScore: 100, level: 'advanced', status: 'valid', sourceType: 'student', updatedAt: new Date().toISOString() },
  { skillId: 6, skillCode: 'PD', skillName: 'Giải quyết vấn đề và ra quyết định', category: 'core_competency', score: 90, maxScore: 100, level: 'advanced', status: 'valid', sourceType: 'student', updatedAt: new Date().toISOString() },
];

// Learning path is DERIVED from the recommendation engine (PA7), mirroring
// LearningPathService: units are ordered by the learner's biggest measured gaps,
// then the strong/unmeasured tail in canonical order; a single frontier unlocks
// nodes and real progress is tracked in a small in-memory set so the mock demo
// advances when a lesson is completed.
const LP_ALL_CODES: CompetencyCode[] = ['SA', 'ER', 'SM', 'CO', 'CE', 'PD'];

const LP_TITLES: Record<CompetencyCode, string> = {
  SA: 'Nhận diện bản thân',
  ER: 'Điều hòa cảm xúc',
  SM: 'Tự quản lý và kỷ luật',
  CO: 'Giao tiếp chủ động',
  CE: 'Hợp tác và thấu cảm',
  PD: 'Giải quyết vấn đề',
};

const LP_DESCS: Record<CompetencyCode, string> = {
  SA: 'Gọi tên cảm xúc, nhận biết điểm mạnh và điểm cần hoàn thiện',
  ER: 'Hạ nhiệt và chọn phản ứng an toàn khi cảm xúc dâng cao',
  SM: 'Bắt đầu, kiên trì và hoàn thành nhiệm vụ phù hợp tuổi',
  CO: 'Lắng nghe chủ động và diễn đạt ý kiến rõ ràng',
  CE: 'Phối hợp nhóm, chia sẻ và hỗ trợ bạn bè',
  PD: 'Nhận diện vấn đề, cân nhắc lựa chọn và hệ quả',
};

const LP_TEMPLATE: Array<{ type: NodeType; xp: number; label: string }> = [
  { type: 'lesson', xp: 30, label: 'Bài học nền tảng' },
  { type: 'quiz', xp: 60, label: 'Kiểm tra nhanh' },
  { type: 'boss_challenge', xp: 100, label: 'Thử thách cuối chương' },
];

const completedNodeIds = new Set<string>();

// PA17 Content Library (mock parity): the same (competency x node-type) lessons
// the backend seeds, so a path node's contentId resolves to a real body offline.
const mockLessons: Lesson[] = [];
const lessonByCodeType: Record<string, number> = {};
LP_ALL_CODES.forEach((code, ci) => {
  LP_TEMPLATE.forEach((t, ti) => {
    const id = 1000 + ci * LP_TEMPLATE.length + ti + 1;
    lessonByCodeType[`${code}:${t.type}`] = id;
    mockLessons.push({
      id,
      title: `${t.label}: ${LP_TITLES[code]}`,
      excerpt: LP_DESCS[code],
      content: `Trong bài này em luyện “${LP_TITLES[code]}”. ${LP_DESCS[code]}. Hãy áp dụng vào một tình huống thật trong hôm nay.`,
      competencyTarget: code,
      gradeGroup: '4_6',
      type: t.type,
      order: ti + 1,
      xpReward: t.xp,
      moduleId: ci + 1,
    });
  });
});

function buildLearningPath(scores: SkillScore[], studentId: number): LearningPath {
  const gapOrder: CompetencyCode[] = [];
  scores
    .filter((s) => s.status !== 'insufficient_evidence' && s.score !== null)
    .map((s) => ({ code: s.skillCode, score: s.score as number }))
    .filter((g) => g.score < 60 && LP_TITLES[g.code])
    .sort((a, b) => a.score - b.score || a.code.localeCompare(b.code))
    .forEach((g) => {
      if (!gapOrder.includes(g.code)) gapOrder.push(g.code);
    });
  const ordered = [...gapOrder, ...LP_ALL_CODES.filter((c) => !gapOrder.includes(c))];

  // PA34 rationale parity: current score + status per competency for the reason text.
  const scoreByCode = new Map(scores.map((s) => [s.skillCode, s.score]));
  const statusByCode = new Map(scores.map((s) => [s.skillCode, s.status]));

  const units: LearningUnit[] = [];
  let currentUnitId = '';
  let currentNodeId = '';
  let activeAssigned = false;

  ordered.forEach((code, idx) => {
    const unitId = `unit-${code.toLowerCase()}`;
    const nodeIds = LP_TEMPLATE.map((_, k) => `${unitId}-node-${k + 1}`);
    const allDone = nodeIds.every((id) => completedNodeIds.has(id));

    let unitStatus: LearningUnit['status'];
    if (allDone) {
      unitStatus = 'completed';
    } else if (!activeAssigned) {
      unitStatus = 'active';
      activeAssigned = true;
    } else {
      unitStatus = 'locked';
    }

    let frontierFound = false;
    let firstUnlocked = '';
    const nodes: LearningNode[] = LP_TEMPLATE.map((t, k) => {
      const nodeId = nodeIds[k];
      const isDone = completedNodeIds.has(nodeId);
      let status: NodeStatus;
      if (isDone) {
        status = 'completed';
      } else if (unitStatus !== 'active') {
        status = 'locked';
      } else if (!frontierFound) {
        status = 'unlocked';
        frontierFound = true;
        firstUnlocked = nodeId;
      } else {
        status = 'locked';
      }
      const node: LearningNode = {
        id: nodeId,
        title: `${t.label}: ${LP_TITLES[code]}`,
        description: LP_DESCS[code],
        type: t.type,
        status,
        xpReward: t.xp,
        order: k + 1,
        contentId: lessonByCodeType[`${code}:${t.type}`] ?? null,
      };
      if (isDone) node.score = 100;
      return node;
    });

    if (unitStatus === 'active' && currentUnitId === '') {
      currentUnitId = unitId;
      currentNodeId = firstUnlocked;
    }

    const uScore = scoreByCode.get(code) ?? null;
    const uStatus = statusByCode.get(code) ?? 'valid';
    const isPriority = gapOrder.includes(code);
    const reason =
      uStatus === 'insufficient_evidence' || uScore === null
        ? 'Chưa đủ dữ liệu đo — học chương này để cập nhật năng lực.'
        : isPriority
          ? `Ưu tiên rèn luyện: ${LP_TITLES[code]} đang ở mức ${uScore} (< 60).`
          : `Nền tảng đã vững (${uScore}) — học để củng cố và mở rộng.`;

    units.push({
      id: unitId,
      title: `Chương ${idx + 1}: ${LP_TITLES[code]}`,
      description: LP_DESCS[code],
      competencyTarget: code,
      competencyScore: uScore,
      isPriority,
      reason,
      order: idx + 1,
      status: unitStatus,
      nodes,
    });
  });

  if (currentUnitId === '' && units.length) {
    const last = units[units.length - 1];
    currentUnitId = last.id;
    currentNodeId = last.nodes[last.nodes.length - 1].id;
  }

  return { studentId, currentUnitId, currentNodeId, units };
}

export const mockMissions: Mission[] = [
  { id: 1, title: 'Hoàn thành 1 bài học hôm nay', description: 'Thực hiện bất kỳ bài học nào trên lộ trình', xpReward: 25, progress: 1, target: 1, isCompleted: true, type: 'daily', expiresAt: new Date(Date.now() + 86400000).toISOString() },
  { id: 2, title: 'Đạt điểm tuyệt đối trong 1 Quiz', description: 'Đạt 100% điểm trong bài kiểm tra bất kỳ', xpReward: 50, progress: 0, target: 1, isCompleted: false, type: 'daily', expiresAt: new Date(Date.now() + 86400000).toISOString() },
  { id: 3, title: 'Duy trì chuỗi học tập 7 ngày liên tiếp', description: 'Không bỏ lỡ ngày nào trong tuần', xpReward: 120, progress: 5, target: 7, isCompleted: false, type: 'weekly', expiresAt: new Date(Date.now() + 86400000 * 3).toISOString() },
];

export const mockBadges: Badge[] = [
  { id: 1, code: 'STREAK_7', name: 'Chiến Binh Bền Bỉ', description: 'Đạt chuỗi học tập liên tục 7 ngày', category: 'streak', icon: 'flame', isUnlocked: true, unlockedAt: '2026-10-18T10:00:00Z' },
  { id: 2, code: 'XP_1000', name: 'Bậc Thầy Tích Lũy', description: 'Vượt mốc 1000 điểm kinh nghiệm XP', category: 'xp', icon: 'zap', isUnlocked: true, unlockedAt: '2026-10-15T14:30:00Z' },
  { id: 3, code: 'PERFECT_COMM', name: 'Nhà Giao Tiếp Xuất Sắc', description: 'Hoàn thành trọn vẹn Chương 1 Giao Tiếp', category: 'skill', icon: 'award', isUnlocked: false },
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
      { id: 'rec-1', title: 'Thực hành thở sâu 3 phút', description: 'Bài tập điều hòa nhịp thở trước khi bắt đầu bài thi', type: 'home_exercise', targetCompetency: 'ER' },
      { id: 'rec-2', title: 'Chương 1: Nền tảng Giao tiếp Chủ động', description: 'Khóa học bổ trợ kỹ năng lắng nghe và giải thích bất đồng', type: 'lesson', targetCompetency: 'CO' },
    ],
  },
];

// PA20: the mock mirrors report persistence — generating a report APPENDS an
// immutable snapshot (newest first) instead of overwriting, so the parent demo
// shows a longitudinal history just like wp_aba_reports.
const reportsStore: StudentReport[] = [...mockReports];
let reportSeq = 1000;

// PA21 mock read receipts: reportId => accumulated views + first-read moment.
const reportReads: Record<number, { views: number; firstReadAt: string | null }> = {};
function reportDelivery(rid: number) {
  const rec = reportReads[rid] ?? { views: 0, firstReadAt: null };
  return {
    delivered: true,
    read: rec.views > 0,
    views: rec.views,
    firstReadAt: rec.firstReadAt,
    lastReadAt: rec.firstReadAt,
  };
}

// Mirrors the server projection (AssessmentController::projectQuestion): likert
// items expose {value,label}; scenario items expose {id,label}; never the
// scoring key (assessment-spec.md §77).
export const mockQuestionBatch: SessionQuestionItem[] = [
  {
    id: 1,
    type: 'scenario',
    recallPeriod: 'Trong các hoạt động nhóm gần đây',
    prompt: 'Khi làm việc nhóm và có hai bạn bất đồng ý kiến về cách giải một bài toán, em sẽ làm gì đầu tiên?',
    options: [
      { id: 'A', label: 'Lắng nghe lập luận của cả hai bạn và cùng viết ra các bước để đối chiếu' },
      { id: 'B', label: 'Bỏ qua và tự làm theo cách riêng của mình để tiết kiệm thời gian' },
      { id: 'C', label: 'Bầu chọn theo số đông mà không cần giải thích lý do' },
      { id: 'D', label: 'Nhờ thầy cô can thiệp ngay lập tức mà chưa tự thảo luận' },
    ],
  },
  {
    id: 2,
    type: 'scenario',
    recallPeriod: 'Trong các buổi thảo luận lớp học',
    prompt: 'Khi em muốn chia sẻ một ý tưởng mới nhưng các bạn đang nói chuyện rất sôi nổi, em sẽ:',
    options: [
      { id: 'A', label: 'Nói thật to át tiếng các bạn để được chú ý ngay' },
      { id: 'B', label: 'Giơ tay xin phát biểu hoặc đợi bạn dứt câu rồi lịch sự đưa ra ý kiến' },
      { id: 'C', label: 'Giữ im lặng và không chia sẻ ý kiến đó nữa' },
      { id: 'D', label: 'Viết ra giấy và ném cho bạn ngồi đối diện' },
    ],
  },
  {
    id: 3,
    type: 'scenario',
    recallPeriod: 'Trong 30 ngày qua',
    prompt: 'Khi kết quả kiểm tra không như mong đợi dù em đã ôn bài rất kỹ, phản ứng phù hợp nhất là:',
    options: [
      { id: 'A', label: 'Tức giận và cho rằng đề thi không công bằng' },
      { id: 'B', label: 'Bình tĩnh xem lại những câu làm sai để rút kinh nghiệm cho lần sau' },
      { id: 'C', label: 'Cảm thấy chán nản và không muốn học môn đó nữa' },
      { id: 'D', label: 'Tìm bạn bị điểm thấp hơn mình để cảm thấy đỡ buồn' },
    ],
  },
  {
    id: 4,
    type: 'likert',
    recallPeriod: 'Trong 30 ngày qua',
    prompt: 'Em có thể nhận biết và gọi tên chính xác cảm xúc của mình (vui, buồn, lo lắng, bực bội) khi nó xuất hiện:',
    options: [
      { value: 1, label: '1 — Hiếm khi / Chưa bao giờ' },
      { value: 2, label: '2 — Thỉnh thoảng' },
      { value: 3, label: '3 — Thường xuyên' },
      { value: 4, label: '4 — Hầu như luôn luôn' },
    ],
  },
  {
    id: 5,
    type: 'likert',
    recallPeriod: 'Trong 30 ngày qua',
    prompt: 'Khi gặp một bài tập khó, em kiên trì suy nghĩ và thử các cách khác nhau trước khi bỏ cuộc:',
    options: [
      { value: 1, label: '1 — Hiếm khi / Chưa bao giờ' },
      { value: 2, label: '2 — Thỉnh thoảng' },
      { value: 3, label: '3 — Thường xuyên' },
      { value: 4, label: '4 — Hầu như luôn luôn' },
    ],
  },
  {
    id: 6,
    type: 'likert',
    recallPeriod: 'Trong 30 ngày qua',
    prompt: 'Em chú ý lắng nghe và quan sát khi bạn cùng lớp gặp khó khăn hoặc buồn phiền để an ủi, giúp đỡ:',
    options: [
      { value: 1, label: '1 — Hiếm khi / Chưa bao giờ' },
      { value: 2, label: '2 — Thỉnh thoảng' },
      { value: 3, label: '3 — Thường xuyên' },
      { value: 4, label: '4 — Hầu như luôn luôn' },
    ],
  },
];

function buildParentOverview(childId: number): ParentOverview {
  // PA41: reflect the requested child so switching changes the overview, matching
  // the server which returns a per-child overview (only for linked children).
  const child = childId === mockSecondChild.id ? mockSecondChild : mockStudentProfile;
  const ctx = child === mockSecondChild ? mockClassContext2 : mockClassContext;
  return {
    student: { id: child.id, displayName: child.fullName, grade: child.gradeLevel, class: ctx },
    summary: {
      currentStreak: child.currentStreak,
      totalXp: child.totalXp,
      weekly: { lessonsCompleted: child === mockSecondChild ? 1 : 4, missionsCompleted: child === mockSecondChild ? 0 : 2 },
    },
    skills: {
      topStrengths: [
        { key: 'PD', name: 'Giải quyết vấn đề và ra quyết định', score: 90, level: 'advanced' },
        { key: 'CE', name: 'Hợp tác và đồng cảm', score: 88, level: 'advanced' },
        { key: 'SM', name: 'Tự quản lý', score: 85, level: 'advanced' },
      ],
      priorityGrowthAreas: [],
      insufficientEvidence: [],
    },
    latestReport: { id: 101, type: 'assessment' },
  };
}

// Mirrors RecommendationService::getRecommendations (deterministic, PA4) so the
// mock and the WordPress backend stay in contract parity (AGENTS.md §17).
const REC_BAND_STYLE: Record<string, string> = {
  '4_6': 'Trải nghiệm qua trò chơi và tình huống',
  '7_9': 'Luyện tập qua thảo luận và dự án nhóm',
  '10_12': 'Phát triển qua dự án thực tế',
};

const REC_FOCUS: Record<string, string> = {
  SA: 'nhận diện điểm mạnh và điểm cần hoàn thiện của bản thân',
  ER: 'điều hòa cảm xúc khi gặp áp lực',
  SM: 'tự giác lập kế hoạch và hoàn thành nhiệm vụ',
  CO: 'trình bày ý kiến rõ ràng và lắng nghe chủ động',
  CE: 'hợp tác và thấu cảm với người khác',
  PD: 'phân tích vấn đề và ra quyết định có cân nhắc',
};

function buildRecommendations(
  scores: SkillScore[],
  gradeGroup: string,
  studentId: number,
  source: string
): RecommendationsResult {
  const band = REC_BAND_STYLE[gradeGroup] ? gradeGroup : '4_6';
  const style = REC_BAND_STYLE[band];

  // Only measured competencies below the threshold drive content (§45).
  const gaps = scores
    .filter((s) => s.status !== 'insufficient_evidence' && s.score !== null)
    .map((s) => ({ code: s.skillCode, score: s.score as number }))
    .filter((g) => g.score < 60 && REC_FOCUS[g.code])
    .sort((a, b) => a.score - b.score || a.code.localeCompare(b.code));

  const items: RecommendationItem[] = [];
  gaps.forEach((gap, rank) => {
    if (items.length >= 6) return;
    const focus = REC_FOCUS[gap.code];
    items.push({
      id: `rec-${gap.code}-lesson`,
      title: `${style}: ${focus}`,
      description: `Bài học cho nhóm lớp ${band}, giúp học sinh rèn luyện ${focus}.`,
      type: 'lesson',
      targetCompetency: gap.code,
      priority: items.length + 1,
      targetScore: gap.score,
      reason: `Năng lực ở mức cần cải thiện (${gap.score} < 60)`,
      gradeGroup: band as GradeGroup,
    });
    if (items.length >= 6) return;
    items.push({
      id: `rec-${gap.code}-home`,
      title: `Bài tập cùng gia đình: ${focus}`,
      description: `Gợi ý phụ huynh đồng hành 10-15 phút mỗi ngày để con luyện ${focus} (nhóm lớp ${band}).`,
      type: 'home_exercise',
      targetCompetency: gap.code,
      priority: items.length + 1,
      targetScore: gap.score,
      reason: `Củng cố tại nhà cho ưu tiên #${rank + 1}`,
      gradeGroup: band as GradeGroup,
    });
  });

  return {
    studentId,
    source: (source === 'teacher' || source === 'parent' ? source : 'student') as RecommendationsResult['source'],
    strategyVersion: 'ABA-REC-0.2',
    threshold: 60,
    gradeGroup: band as GradeGroup,
    items,
  };
}

// Longitudinal snapshots (Skill Snapshots over time, §11). Ordered oldest ->
// newest so both the learner profile and the parent trend chart render a rise.
export const mockSkillHistory: SkillHistoryEntry[] = [
  { snapshotId: 1, date: '2026-08-01', scores: { SA: 70, ER: 55, SM: 72, CO: 60, CE: 78, PD: 80 } },
  { snapshotId: 2, date: '2026-09-01', scores: { SA: 76, ER: 62, SM: 80, CO: 68, CE: 82, PD: 85 } },
  { snapshotId: 3, date: '2026-10-01', scores: { SA: 82, ER: 68, SM: 85, CO: 74, CE: 88, PD: 90 } },
];

// PA41 mock parity: the second linked child carries its own measured profile
// (same shapes, different numbers), mirroring the real seeder so switching
// children visibly changes the parent screens.
const SECOND_CHILD_SCORES: Record<CompetencyCode, number> = {
  SA: 74, ER: 62, SM: 55, CO: 68, CE: 80, PD: 58,
};

function levelForDemo(score: number): SkillScore['level'] {
  if (score >= 90) return 'advanced';
  if (score >= 70) return 'proficient';
  if (score >= 50) return 'developing';
  return 'beginning';
}

function skillsForStudent(id: number): SkillScore[] {
  if (id !== mockSecondChild.id) return mockSkillScores;
  return mockSkillScores.map((s) => ({
    ...s,
    score: SECOND_CHILD_SCORES[s.skillCode],
    level: levelForDemo(SECOND_CHILD_SCORES[s.skillCode]),
  }));
}

function historyForStudent(id: number): SkillHistoryEntry[] {
  if (id !== mockSecondChild.id) return mockSkillHistory;
  const scale = (factor: number) =>
    Object.fromEntries(
      (Object.keys(SECOND_CHILD_SCORES) as CompetencyCode[]).map((code) => [
        code,
        Math.round(SECOND_CHILD_SCORES[code] * factor),
      ]),
    ) as Record<CompetencyCode, number>;
  return [
    { snapshotId: 11, date: '2026-08-01', scores: scale(0.86) },
    { snapshotId: 12, date: '2026-10-01', scores: scale(1) },
  ];
}

// Class benchmark (PA31, §11): the learner's current score (matches
// mockSkillScores) beside an anonymized class mean. ER sits below the class
// average so the parent demo shows a real growth gap to target. CE demonstrates
// the k-anonymity floor (PA33, §8): only 4 peers contributed, below minSamples 5,
// so the class mean is suppressed (null) even though the class is reliable overall.
export const mockClassBenchmark: ClassBenchmark = {
  classId: 402,
  memberCount: 24,
  sourceType: 'student',
  reliable: true,
  minSamples: 5,
  competencies: [
    { code: 'SA', studentScore: 82, classAverage: 70, samples: 24 },
    { code: 'ER', studentScore: 45, classAverage: 61, samples: 23 },
    { code: 'SM', studentScore: 85, classAverage: 72, samples: 24 },
    { code: 'CO', studentScore: 74, classAverage: 68, samples: 24 },
    { code: 'CE', studentScore: 88, classAverage: null, samples: 4 },
    { code: 'PD', studentScore: 90, classAverage: 73, samples: 24 },
  ],
};

// Longitudinal class average (PA32, §11 over time) on the same snapshot dates as
// mockSkillHistory so the parent trend overlays align. The class line sits a bit
// lower everywhere except ER, where it stays above the learner early — the gap
// the report should surface as a growth target.
export const mockClassTrend: ClassTrendEntry[] = [
  { date: '2026-08-01', scores: { SA: 64, ER: 60, SM: 66, CO: 62, CE: 70, PD: 68 } },
  { date: '2026-09-01', scores: { SA: 67, ER: 62, SM: 70, CO: 65, CE: 72, PD: 71 } },
  { date: '2026-10-01', scores: { SA: 69, ER: 64, SM: 72, CO: 67, CE: 74, PD: 73 } },
];

// Gamification derived from an in-memory XP ledger (PA11, §12): XP totals the
// ledger, badges unlock from milestones, missions reflect live demo progress, and
// claims are idempotent — mirroring the deterministic WordPress backend.
type XpTx = { amount: number; source: string };
const xpLedger: XpTx[] = [
  { amount: 1200, source: 'lesson' },
  { amount: 200, source: 'mission' },
  { amount: 50, source: 'assessment' },
];
const claimedMissions = new Set<number>();
let lessonsToday = 1;
let xpEarnedToday = 30;
let assessmentsDone = 1;
// PA22: banked streak-freeze credits the mock learner can spend.
let mockFreezeBank = 2;
let mockStreakDays = mockStudentProfile.currentStreak;
const mockLongestStreak = mockStudentProfile.longestStreak;

function ledgerTotal(): number {
  return xpLedger.reduce((sum, t) => sum + t.amount, 0);
}

function endOfDay(): string {
  const d = new Date();
  d.setUTCHours(24, 0, 0, 0);
  return d.toISOString();
}

function endOfWeek(): string {
  const d = new Date();
  const days = ((8 - d.getUTCDay()) % 7) || 7;
  d.setUTCDate(d.getUTCDate() + days);
  d.setUTCHours(0, 0, 0, 0);
  return d.toISOString();
}

function makeMission(
  id: number,
  title: string,
  description: string,
  xpReward: number,
  type: 'daily' | 'weekly',
  target: number,
  progressRaw: number
): Mission {
  const progress = Math.min(progressRaw, target);
  return { id, title, description, xpReward, progress, target, isCompleted: progress >= target, type, expiresAt: type === 'daily' ? endOfDay() : endOfWeek() };
}

function buildMissions(): Mission[] {
  return [
    makeMission(1, 'Hoàn thành 1 bài học hôm nay', 'Bất kỳ bài học nào trên lộ trình học tập', 25, 'daily', 1, lessonsToday),
    makeMission(2, 'Kiếm 50 XP trong ngày', 'Tổng XP tích lũy từ bài học, nhiệm vụ và đánh giá', 30, 'daily', 50, xpEarnedToday),
    makeMission(3, 'Hoàn thành 3 bài học trong tuần', 'Duy trì nhịp học đều đặn cả tuần', 40, 'weekly', 3, lessonsToday),
    makeMission(4, 'Hoàn thành 1 bài đánh giá năng lực', 'Đánh giá 360° để cập nhật hồ sơ năng lực', 50, 'weekly', 1, assessmentsDone),
  ];
}

function buildBadges(): Badge[] {
  const total = ledgerTotal();
  const now = new Date().toISOString();
  const defs: Array<Omit<Badge, 'isUnlocked' | 'unlockedAt'> & { on: boolean }> = [
    { id: 1, code: 'FIRST_ASSESSMENT', name: 'Người Dám Thử', description: 'Hoàn thành bài đánh giá năng lực đầu tiên', category: 'assessment', icon: 'award', on: assessmentsDone >= 1 },
    { id: 2, code: 'STREAK_3', name: 'Kỷ Luật Nhỏ', description: 'Duy trì chuỗi học tập 3 ngày liên tiếp', category: 'streak', icon: 'flame', on: mockStreakDays >= 3 },
    { id: 3, code: 'STREAK_7', name: 'Chiến Binh Bền Bỉ', description: 'Duy trì chuỗi học tập 7 ngày liên tiếp', category: 'streak', icon: 'flame', on: mockStreakDays >= 7 },
    { id: 4, code: 'XP_1000', name: 'Bậc Thầy Tích Lũy', description: 'Vượt mốc 1000 điểm kinh nghiệm XP', category: 'xp', icon: 'zap', on: total >= 1000 },
  ];
  return defs.map(({ on, ...b }) => (on ? { ...b, isUnlocked: true, unlockedAt: now } : { ...b, isUnlocked: false }));
}

function buildGamificationMe() {
  const total = ledgerTotal();
  return {
    studentId: mockStudentProfile.id,
    totalXp: total,
    xpBySource: xpLedger.reduce<Record<string, { total: number; count: number }>>((acc, t) => {
      acc[t.source] = acc[t.source] || { total: 0, count: 0 };
      acc[t.source].total += t.amount;
      acc[t.source].count += 1;
      return acc;
    }, {}),
    currentStreak: mockStreakDays,
    longestStreak: mockLongestStreak,
    assessmentsCompleted: assessmentsDone,
    level: Math.floor(total / 200) + 1,
    streakFreezes: mockFreezeBank,
  };
}

// PA18: mirror GamificationService::buildAchievements — a dated timeline merging
// unlocked badges (first-earned date) with completed/claimed missions.
function buildAchievements() {
  const badges = buildBadges();
  const unlocked = badges.filter((b) => b.isUnlocked);
  const me = buildGamificationMe();
  const timeline = [
    ...unlocked.map((b) => ({ kind: 'badge' as const, code: b.code, title: b.name, earnedAt: b.unlockedAt ?? null })),
    ...Array.from(claimedMissions).map((id) => ({
      kind: 'mission' as const,
      missionId: id,
      periodDate: new Date().toISOString().slice(0, 10),
      earnedAt: new Date().toISOString(),
    })),
  ].sort((a, b) => String(b.earnedAt ?? '').localeCompare(String(a.earnedAt ?? '')));
  return {
    studentId: mockStudentProfile.id,
    level: me.level,
    totalXp: me.totalXp,
    currentStreak: me.currentStreak,
    unlockedCount: unlocked.length,
    totalCount: badges.length,
    badges,
    timeline,
  };
}

// PA40: mirror ParentOverviewService::buildActivityFeed — the parent "Hoạt động"
// feed merges recent lesson completions with the achievement timeline (unlocked
// badges + claimed missions) and the weekly XP summed from the ledger (§12).
function daysAgo(days: number, hours = 9): string {
  const d = new Date();
  d.setUTCDate(d.getUTCDate() - days);
  d.setUTCHours(hours, 0, 0, 0);
  return d.toISOString();
}

function buildParentActivityFeed(childId: number): ParentActivityFeed {
  // PA41 parity: the seeder gives learner 2 no activity ledger rows, so the
  // real backend derives an honest empty feed for that child — the mock mirrors
  // it instead of inventing history (§12).
  if (childId === mockSecondChild.id) {
    return { studentId: childId, weeklyXp: 0, items: [] };
  }
  const items = [
    // Recent completed lessons, titled like LearningPathService::titleForNode.
    ...mockLessons.slice(0, 3).map((l, i): ParentActivityFeed['items'][number] => ({
      id: `lesson:unit-${l.competencyTarget.toLowerCase()}-node-${i + 1}`,
      type: 'lesson' as ParentActivityType,
      title: `Hoàn thành bài học: ${l.title}`,
      occurredAt: daysAgo(i, 14),
      xpEarned: l.xpReward,
      score: 100 - i * 5,
    })),
    // Badge and mission entries straight from the derived timeline.
    ...buildAchievements().timeline.map((e): ParentActivityFeed['items'][number] =>
      e.kind === 'badge'
        ? {
            id: `badge:${e.code}`,
            type: 'badge' as ParentActivityType,
            title: `Đạt danh hiệu: ${e.title}`,
            occurredAt: e.earnedAt,
            xpEarned: null,
            score: null,
          }
        : {
            id: `mission:${e.missionId}:${e.periodDate}`,
            type: 'mission' as ParentActivityType,
            title: `Hoàn thành nhiệm vụ: ${buildMissions().find((m) => m.id === e.missionId)?.title ?? `#${e.missionId}`}`,
            occurredAt: e.earnedAt,
            xpEarned: null,
            score: null,
          }
    ),
  ]
    .sort((a, b) => String(b.occurredAt ?? '').localeCompare(String(a.occurredAt ?? '')))
    .slice(0, 15);
  return { studentId: childId, weeklyXp: 115, items };
}

// MSW REST Handlers for /wp-json/aba/v1/*
export const handlers = [
  // ---- Auth (api-contract §11-13) ----
  http.post('*/wp-json/aba/v1/auth/login', async ({ request }) => {
    const body = (await request.json().catch(() => ({}))) as { username?: string; password?: string };
    const username = (body.username ?? '').trim();
    const password = body.password ?? '';
    // Mirror the backend: empty credentials => 400, and the adult demo accounts
    // (parent/teacher/admin) resolve to the parent DTO while the learner stays
    // the learner — enough for the /login hint to behave in both modes.
    if (!username || !password) {
      return HttpResponse.json(
        { error: { code: 'INVALID_REQUEST', message: 'Username and password are required.', details: [] } },
        { status: 400 }
      );
    }
    const user = username !== 'aba_student_1' && (username.startsWith('parent') || username.startsWith('aba_'))
      ? mockParentUser
      : mockStudentUser;
    mockAuthSession = user;
    // PA37 parity: the real backend also returns the app-password fields; mocks
    // stay on the cookie-style session, so the token is explicitly null.
    return HttpResponse.json({ user, username, token: null });
  }),
  http.post('*/wp-json/aba/v1/auth/logout', () => {
    mockAuthSession = null;
    return new HttpResponse(null, { status: 204 });
  }),
  http.post('*/wp-json/aba/v1/auth/refresh', () => {
    return HttpResponse.json({ success: true });
  }),

  // Session nonce bootstrap (PA14) — mirrors the backend: a live session gets a
  // nonce, an anonymous one gets an empty string.
  http.get('*/wp-json/aba/v1/auth/nonce', () =>
    HttpResponse.json({
      nonce: mockAuthSession ? 'mocknonce10' : '',
      authenticated: !!mockAuthSession,
    })
  ),

  // Current user info — the live mock session, defaulting to the learner so an
  // unauthenticated demo load still renders (PA9: no `?as=` role hack).
  http.get('*/wp-json/aba/v1/me', () => HttpResponse.json(mockAuthSession ?? mockStudentUser)),

  // Student me profile
  http.get('*/wp-json/aba/v1/students/me', () => HttpResponse.json(mockStudentProfile)),

  // Student profile by id
  http.get('*/wp-json/aba/v1/students/:id', ({ params }) =>
    HttpResponse.json({ ...mockStudentProfile, id: Number(params.id) })
  ),

  // Learning path (derived from the recommendation engine, PA7)
  http.get('*/wp-json/aba/v1/learning/path', () =>
    HttpResponse.json(buildLearningPath(mockSkillScores, mockStudentProfile.id))
  ),

  // PA17 Content Library — catalog (optionally by competency) + single lesson.
  http.get('*/wp-json/aba/v1/learning/lessons', ({ request }) => {
    const url = new URL(request.url);
    const comp = url.searchParams.get('competency')?.toUpperCase();
    const lessons = comp ? mockLessons.filter((l) => l.competencyTarget === comp) : mockLessons;
    return HttpResponse.json({ count: lessons.length, lessons });
  }),
  http.get('*/wp-json/aba/v1/lessons/:id', ({ params }) => {
    const lesson = mockLessons.find((l) => l.id === Number(params.id));
    if (!lesson) {
      return HttpResponse.json({ code: 'LESSON_NOT_FOUND', message: 'Lesson not found.' }, { status: 404 });
    }
    return HttpResponse.json(lesson);
  }),

  // PA23 School scope (mock parity): roster is the caller's own school, and a
  // student outside it is not readable — mirrors the server's §8 isolation.
  http.get('*/wp-json/aba/v1/school/roster', () =>
    HttpResponse.json({
      schoolId: mockStudentProfile.schoolId ?? 1,
      count: 1,
      students: [mockStudentProfile],
    })
  ),
  http.get('*/wp-json/aba/v1/school/students/:id', ({ params }) =>
    Number(params.id) === mockStudentProfile.id
      ? HttpResponse.json(mockStudentProfile)
      : HttpResponse.json({ code: 'ABA_FORBIDDEN', message: 'No access to this student.' }, { status: 403 })
  ),

  // PA24 Class scope (mock parity): a class-scoped staff sees only their own class.
  http.get('*/wp-json/aba/v1/class/roster', () =>
    HttpResponse.json({
      classId: mockStudentProfile.classId ?? 0,
      count: 1,
      students: [mockStudentProfile],
    })
  ),

  // Current Skills
  http.get('*/wp-json/aba/v1/skills/current', () => HttpResponse.json(mockSkillScores)),
  http.get('*/wp-json/aba/v1/students/:id/skills/current', ({ params }) =>
    HttpResponse.json(skillsForStudent(Number(params.id)))
  ),

  // Recommendations (deterministic engine parity, PA4)
  http.get('*/wp-json/aba/v1/recommendations', ({ request }) => {
    const url = new URL(request.url);
    const source = url.searchParams.get('source') || 'student';
    return HttpResponse.json(
      buildRecommendations(mockSkillScores, mockStudentProfile.gradeGroup, mockStudentProfile.id, source)
    );
  }),
  http.get('*/wp-json/aba/v1/students/:id/recommendations', ({ request, params }) => {
    const url = new URL(request.url);
    const source = url.searchParams.get('source') || 'student';
    return HttpResponse.json(
      buildRecommendations(mockSkillScores, mockStudentProfile.gradeGroup, Number(params.id), source)
    );
  }),

  // Skill History (longitudinal, §11) — self and by-id share one source so the
  // learner profile and parent view stay consistent (mirror of the backend).
  http.get('*/wp-json/aba/v1/skills/history', () => HttpResponse.json(mockSkillHistory)),
  http.get('*/wp-json/aba/v1/students/:id/skills/history', ({ params }) =>
    HttpResponse.json(historyForStudent(Number(params.id)))
  ),

  // Class benchmark (anonymized class average, PA31) — self and by-id share one source.
  http.get('*/wp-json/aba/v1/skills/benchmark', () => HttpResponse.json(mockClassBenchmark)),
  http.get('*/wp-json/aba/v1/students/:id/skills/benchmark', () => HttpResponse.json(mockClassBenchmark)),

  // Class trend (longitudinal class average, PA32) — self and by-id share one source.
  http.get('*/wp-json/aba/v1/skills/class-trend', () => HttpResponse.json(mockClassTrend)),
  http.get('*/wp-json/aba/v1/students/:id/skills/class-trend', () => HttpResponse.json(mockClassTrend)),

  // Assessment metadata (public, §26)
  http.get('*/wp-json/aba/v1/assessments/:id', ({ params }) => {
    return HttpResponse.json({
      assessment: {
        id: Number(params.id),
        code: 'ABA-B-S',
        title: 'Đánh Giá Kỹ Năng Toàn Diện 360°',
        version: '0.1',
        questionCount: mockQuestionBatch.length,
        respondentType: 'student',
      },
    });
  }),

  // Assessment start
  http.post('*/wp-json/aba/v1/assessments/:id/start', ({ params }) => {
    return HttpResponse.json(
      {
        session: {
          id: 999,
          assessmentId: Number(params.id),
          assessmentVersion: '0.1',
          studentId: 1,
          status: 'in_progress',
          startedAt: new Date().toISOString(),
          resume: false,
        },
        questionBatch: { items: mockQuestionBatch, cursor: null, hasMore: false },
      },
      { status: 201 }
    );
  }),

  // Resume session state (§28)
  http.get('*/wp-json/aba/v1/assessment-sessions/:id', ({ params }) => {
    return HttpResponse.json({
      session: {
        id: Number(params.id),
        assessmentId: 1,
        assessmentVersion: '0.1',
        studentId: 1,
        status: 'in_progress',
        startedAt: new Date().toISOString(),
        progress: { answered: 0, total: mockQuestionBatch.length, percent: 0 },
      },
    });
  }),

  // Frozen question batch for the session (§30)
  http.get('*/wp-json/aba/v1/assessment-sessions/:id/questions', () => {
    return HttpResponse.json({ items: mockQuestionBatch, cursor: null, hasMore: false });
  }),

  // Assessment answer (autosave upsert)
  http.post('*/wp-json/aba/v1/assessment-sessions/:id/responses', async ({ request }) => {
    const body = (await request.json().catch(() => ({}))) as { questionId?: number };
    return HttpResponse.json({
      saved: [{ questionId: Number(body.questionId ?? 0), saved: true }],
      progress: { answered: 1, total: mockQuestionBatch.length, percent: 100 / mockQuestionBatch.length },
    });
  }),

  // Assessment complete
  http.post('*/wp-json/aba/v1/assessment-sessions/:id/complete', ({ params }) => {
    // Reflect the +50 completion XP + a finished assessment in the demo ledger.
    xpLedger.push({ amount: 50, source: 'assessment' });
    xpEarnedToday += 50;
    assessmentsDone += 1;
    return HttpResponse.json({
      session: { id: Number(params.id), status: 'completed', completedAt: new Date().toISOString() },
      result: {
        status: 'ready',
        skillsProfileAvailable: true,
        overallScore: 81.17,
        scoringVersion: 'ABA-FREQ-0.1',
        scores: mockSkillScores.map((s) => ({
          skillCode: s.skillCode,
          category: s.category,
          score: s.score,
          level: s.level,
          status: 'valid',
        })),
        qualityFlags: { completionRate: 100, speedingFlag: false, straightlining: false, averageLatencyMs: 4200 },
        idempotent: false,
      },
      rewards: { xpAwarded: 50 },
    });
  }),

  // Parent Children (PA41: two linked children so the switcher demo renders)
  http.get('*/wp-json/aba/v1/parent/children', () => HttpResponse.json([
    { ...mockStudentProfile, class: mockClassContext },
    { ...mockSecondChild, class: mockClassContext2 },
  ])),

  // PA42: parent link-request queue, mirroring the WP flow — submitting only
  // joins a queue (admin approval grants access in the real backend), the pair
  // is idempotent, unknown codes 404, and rows never leak a learner identity.
  http.post('*/wp-json/aba/v1/parent/link-requests', async ({ request }) => {
    const body = (await request.json()) as { studentCode?: string; relationship?: string };
    const code = (body.studentCode ?? '').trim().toUpperCase();
    if (MOCK_LINKED_CODES.includes(code)) {
      return HttpResponse.json({ status: 'already_linked', request: null });
    }
    if (!MOCK_UNLINKED_CODES.includes(code)) {
      return HttpResponse.json(
        { error: { code: 'ABA_STUDENT_NOT_FOUND', message: 'Student code not found.' } },
        { status: 404 },
      );
    }
    const pending = mockLinkRequests.find((r) => r.studentCode === code && r.status === 'pending');
    if (pending) return HttpResponse.json({ status: 'exists', request: pending });
    const now = new Date().toISOString();
    const created: ParentLinkRequest = {
      id: ++mockLinkRequestId,
      studentCode: code,
      relationship: body.relationship ?? 'guardian',
      status: 'pending',
      createdAt: now,
      updatedAt: now,
    };
    mockLinkRequests = [created, ...mockLinkRequests];
    return HttpResponse.json({ status: 'created', request: created }, { status: 201 });
  }),

  http.get('*/wp-json/aba/v1/parent/link-requests', () => HttpResponse.json(mockLinkRequests)),

  // Parent child overview (§37)
  http.get('*/wp-json/aba/v1/parent/children/:id/overview', ({ params }) => {
    return HttpResponse.json(buildParentOverview(Number(params.id)));
  }),

  // PA40: parent child activity feed — the child must be linked to the caller,
  // mirroring the server's requireParent + requireViewStudent checks (§8).
  http.get('*/wp-json/aba/v1/parent/children/:id/activities', ({ params }) => {
    const childId = Number(params.id);
    if (childId !== mockStudentProfile.id && childId !== mockSecondChild.id) {
      return HttpResponse.json({ error: { code: 'ABA_FORBIDDEN', message: 'This student is not your linked child.' } }, { status: 403 });
    }
    return HttpResponse.json(buildParentActivityFeed(childId));
  }),

  // Student Reports (PA20: stored history, newest first)
  http.get('*/wp-json/aba/v1/students/:id/reports', () => HttpResponse.json(reportsStore)),

  // Generate a report snapshot — appends an immutable row (mirrors the backend).
  http.post('*/wp-json/aba/v1/students/:id/reports/generate', ({ params }) => {
    const base = reportsStore[0] ?? mockReports[0];
    const snapshot: StudentReport = {
      ...base,
      id: ++reportSeq,
      studentId: Number(params.id),
      generatedAt: new Date().toISOString(),
      title: `Báo cáo Định kỳ Năng lực Kỹ năng Tháng ${String(new Date().getUTCMonth() + 1).padStart(2, '0')}/${new Date().getUTCFullYear()}`,
    };
    reportsStore.unshift(snapshot);
    return HttpResponse.json(snapshot);
  }),

  // PA21: report detail (with derived delivery/read status) + read acknowledgement.
  http.get('*/wp-json/aba/v1/students/:id/reports/:reportId', ({ params }) => {
    const rid = Number(params.reportId);
    const report = reportsStore.find((r) => r.id === rid);
    if (!report) {
      return HttpResponse.json({ code: 'ABA_REPORT_NOT_FOUND', message: 'Report not found.' }, { status: 404 });
    }
    return HttpResponse.json({ ...report, delivery: reportDelivery(rid) });
  }),
  http.post('*/wp-json/aba/v1/students/:id/reports/:reportId/read', ({ params }) => {
    const rid = Number(params.reportId);
    const now = new Date().toISOString();
    const rec = reportReads[rid] ?? { views: 0, firstReadAt: null };
    rec.views += 1;
    if (!rec.firstReadAt) rec.firstReadAt = now;
    reportReads[rid] = rec;
    return HttpResponse.json({ reportId: rid, delivery: reportDelivery(rid) });
  }),

  // Complete lesson node (records progress so the derived path advances)
  http.post('*/wp-json/aba/v1/lessons/:id/complete', async ({ params, request }) => {
    const body = (await request.json().catch(() => ({}))) as { xpReward?: number };
    const nodeId = String(params.id);
    const already = completedNodeIds.has(nodeId);
    completedNodeIds.add(nodeId);
    const reward = Number(body.xpReward ?? 40);
    // A first completion of the day credits the ledger once (idempotent, §12).
    if (!already) {
      xpLedger.push({ amount: reward, source: 'lesson' });
      xpEarnedToday += reward;
      lessonsToday += 1;
    }
    return HttpResponse.json({ success: true, nodeId, xpAwarded: reward, status: 'completed' });
  }),

  // Gamification derived from the XP ledger (PA11)
  http.get('*/wp-json/aba/v1/gamification/me', () => HttpResponse.json(buildGamificationMe())),
  http.get('*/wp-json/aba/v1/gamification/missions', () => HttpResponse.json(buildMissions())),
  http.get('*/wp-json/aba/v1/gamification/badges', () => HttpResponse.json(buildBadges())),

  // PA18: longitudinal achievement timeline (unlocked badges + claimed missions)
  http.get('*/wp-json/aba/v1/gamification/achievements', () => HttpResponse.json(buildAchievements())),

  // Claim mission (idempotent per mission, pays through the ledger)
  http.post('*/wp-json/aba/v1/gamification/missions/:id/claim', ({ params }) => {
    const missionId = Number(params.id);
    const mission = buildMissions().find((m) => m.id === missionId);
    if (!mission) {
      return HttpResponse.json({ error: { code: 'MISSION_NOT_FOUND', message: 'Mission not found.' } }, { status: 404 });
    }
    if (!mission.isCompleted) {
      return HttpResponse.json({ error: { code: 'MISSION_NOT_COMPLETE', message: 'This mission is not claimable yet.' } }, { status: 400 });
    }
    if (claimedMissions.has(missionId)) {
      return HttpResponse.json({ success: true, missionId, xpAwarded: 0, status: 'claimed' });
    }
    claimedMissions.add(missionId);
    xpLedger.push({ amount: mission.xpReward, source: 'mission' });
    xpEarnedToday += mission.xpReward;
    return HttpResponse.json({ success: true, missionId, xpAwarded: mission.xpReward, status: 'claimed' });
  }),

  // PA22: spend a banked freeze to bridge one missed day (mirrors useStreakFreeze).
  http.post('*/wp-json/aba/v1/gamification/streak/freeze', () => {
    if (mockFreezeBank < 1) {
      return HttpResponse.json({ applied: false, status: 'no_freeze', gapDate: null, currentStreak: mockStreakDays, freezes: mockFreezeBank });
    }
    mockFreezeBank -= 1;
    mockStreakDays += 1; // the repaired day is folded back into the run
    return HttpResponse.json({ applied: true, status: 'applied', gapDate: new Date(Date.now() - 86400000).toISOString().slice(0, 10), currentStreak: mockStreakDays, freezes: mockFreezeBank });
  }),
];
