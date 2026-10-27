import type {
  User,
  StudentProfile,
  SkillScore,
  SkillHistoryEntry,
  ClassBenchmark,
  ClassTrendEntry,
  LearningPath,
  Lesson,
  LessonCatalog,
  Mission,
  Badge,
  GamificationMe,
  StreakFreezeResult,
  StudentReport,
  ReportDelivery,
  ApiError,
  LoginResult,
  RespondentType,
  AssessmentMetaResult,
  AssessmentStartResult,
  AssessmentCompleteResult,
  QuestionBatch,
  SessionState,
  ParentOverview,
  ParentActivityFeed,
  ParentLinkRequest,
  ParentLinkRequestResult,
  RecommendationsResult,
} from '@aba/types';

export interface ApiClientConfig {
  baseUrl?: string;
  getToken?: () => string | null;
  getNonce?: () => string | null;
}

export interface AnswerResult {
  saved: Array<{ questionId: number; saved: boolean }>; 
  progress: { answered: number; total: number; percent: number } | null;
}

// PA18 — longitudinal achievement record. Client envelope (like AnswerResult),
// composed from persisted badge-earned dates + completed-mission history.
export interface AchievementTimelineEntry {
  kind: 'badge' | 'mission';
  earnedAt: string | null;
  code?: string;
  title?: string;
  missionId?: number;
  periodDate?: string;
}

export interface AchievementsResult {
  studentId: number;
  level: number;
  totalXp: number;
  currentStreak: number;
  unlockedCount: number;
  totalCount: number;
  badges: Badge[];
  timeline: AchievementTimelineEntry[];
}

const AUTH_STORAGE_KEY = 'aba-auth';

/**
 * PA37 — read the persisted auth session without importing the app store
 * (packages must not depend on apps/*). The store writes { username, token }
 * into its zustand persist blob after login; we mirror it onto the Authorization
 * header for every call. Returns null in mock/cookie mode (no token stored).
 */
function readStoredAuth(): { username: string; token: string } | null {
  if (typeof window === 'undefined') return null;
  try {
    const raw = window.localStorage.getItem(AUTH_STORAGE_KEY);
    if (!raw) return null;
    const parsed = JSON.parse(raw) as { state?: { username?: string; token?: string } };
    const { username, token } = parsed.state ?? {};
    return username && token ? { username, token } : null;
  } catch {
    return null;
  }
}

export class AbaApiClient {
  private baseUrl: string;
  private getToken?: () => string | null;
  private getNonce?: () => string | null;
  // PA37 — the login name paired with the app password (Basic needs both).
  private basicUser: string | null = null;
  // PA14: the wp_rest CSRF nonce for the cookie session, pushed by the app after
  // login/refresh. State-changing requests echo it back via the X-WP-Nonce header.
  private sessionNonce: string | null = null;

  constructor(config: ApiClientConfig = {}) {
    // Default is a same-origin relative path: docs/api-contract.md (/wp-json/aba/v1).
    // The Next dev server rewrites /wp-json/* to the WordPress origin (see
    // apps/web/next.config.mjs), which keeps the cookie session same-origin and
    // CORS-free. Callers can override explicitly for server-to-server use.
    this.baseUrl = config.baseUrl || '/wp-json/aba/v1';
    this.getToken = config.getToken ?? (() => readStoredAuth()?.token ?? null);
    this.getNonce = config.getNonce;
  }

  /** PA37 — register the Basic credential pair issued at login (null clears). */
  setAuth(username: string | null, token: string | null): void {
    this.basicUser = username && token ? username : null;
  }

  /** Register the session nonce (empty/null clears it). */
  setNonce(nonce: string | null): void {
    this.sessionNonce = nonce && nonce.length > 0 ? nonce : null;
  }

  getNonceValue(): string | null {
    return this.sessionNonce ?? (this.getNonce ? this.getNonce() : null);
  }

  private async request<T>(endpoint: string, options: RequestInit = {}): Promise<T> {
    const url = `${this.baseUrl}${endpoint.startsWith('/') ? endpoint : `/${endpoint}`}`;
    const headers: Record<string, string> = {
      'Content-Type': 'application/json',
      Accept: 'application/json',
      ...((options.headers as Record<string, string>) || {}),
    };

    // Cookie session auth stays supported for same-origin deployments, but the
    // headless Next client authenticates with the app password issued at login
    // (PA37): HTTP Basic username + app-token, the scheme WP 6.5+ retains for
    // Application Passwords. The pair must resolve atomically — reading only the
    // token from storage and keeping the username in memory sent Bearer (which
    // WP rejects) on requests fired before the store re-armed setAuth() after a
    // reload. An explicit bearer token remains for server-side callers.
    const stored = readStoredAuth();
    const token = this.getToken ? this.getToken() : null;
    const basicName = this.basicUser ?? stored?.username ?? null;
    if (token && basicName) {
      // UTF-8-safe base64 (logins are ASCII today, but never rely on that).
      const bytes = new TextEncoder().encode(`${basicName}:${token}`);
      let binary = '';
      bytes.forEach((b) => { binary += String.fromCharCode(b); });
      headers['Authorization'] = `Basic ${btoa(binary)}`;
    } else if (token) {
      headers['Authorization'] = `Bearer ${token}`;
    }

    // Same-origin cookie callers must satisfy WP's CSRF check (PA14).
    const nonce = this.getNonceValue();
    if (nonce) {
      headers['X-WP-Nonce'] = nonce;
    }

    const response = await fetch(url, {
      credentials: 'include',
      ...options,
      headers,
    });

    if (!response.ok) {
      let errorData: ApiError;
      try {
        errorData = await response.json();
      } catch {
        errorData = {
          error: {
            code: `HTTP_${response.status}`,
            message: response.statusText || 'Unknown server error',
          },
        };
      }
      throw errorData;
    }

    // 204 No Content (logout) has no JSON body.
    if (response.status === 204) {
      return undefined as T;
    }

    return response.json() as Promise<T>;
  }

  // Domain modules matching AGENTS.md
  readonly auth = {
    login: async (credentials: { username: string; password: string }) => {
      return this.request<LoginResult>('/auth/login', {
        method: 'POST',
        body: JSON.stringify(credentials),
      });
    },
    logout: async () => {
      return this.request<void>('/auth/logout', {
        method: 'POST',
      });
    },
    refresh: async () => {
      return this.request<{ success: boolean }>('/auth/refresh', {
        method: 'POST',
      });
    },
    me: async () => {
      return this.request<User>('/me');
    },
    // Bootstrap the wp_rest nonce for the current cookie session (PA14). The app
    // stores it via setNonce() so later writes carry X-WP-Nonce.
    nonce: async () => {
      return this.request<{ nonce: string; authenticated: boolean }>('/auth/nonce');
    },
  };

  readonly student = {
    getMe: async () => {
      return this.request<StudentProfile>('/students/me');
    },
    getById: async (studentId: number) => {
      return this.request<StudentProfile>(`/students/${studentId}`);
    },
  };

  readonly assessment = {
    // Public metadata only; never the question bank or scoring key (§26, §77).
    getMeta: async (assessmentId: number) => {
      return this.request<AssessmentMetaResult>(`/assessments/${assessmentId}`);
    },
    // Kept for backward compatibility with callers expecting the legacy shape.
    get: async (assessmentId: number) => {
      const { assessment } = await this.request<AssessmentMetaResult>(`/assessments/${assessmentId}`);
      return {
        id: assessment.id,
        title: assessment.title,
        description: '',
        version: assessment.version,
        gradeGroup: '4_6' as const,
        durationMinutes: 0,
        totalQuestions: assessment.questionCount,
        competencies: [],
      };
    },
    // Identity comes from the authenticated login; the optional studentId is
    // ignored by the server and retained only for legacy call sites.
    start: async (assessmentId: number, _studentId?: number) => {
      return this.request<AssessmentStartResult>(`/assessments/${assessmentId}/start`, {
        method: 'POST',
        body: JSON.stringify({}),
      });
    },
    resume: async (sessionId: number) => {
      return this.request<{ session: SessionState }>(`/assessment-sessions/${sessionId}`);
    },
    getQuestions: async (sessionId: number) => {
      return this.request<QuestionBatch>(`/assessment-sessions/${sessionId}/questions`);
    },
    answer: async (
      sessionId: number,
      questionId: number,
      answer: string | number,
      latencyMs?: number
    ) => {
      return this.request<AnswerResult>(`/assessment-sessions/${sessionId}/responses`, {
        method: 'POST',
        body: JSON.stringify({ questionId, answer, latencyMs }),
      });
    },
    complete: async (sessionId: number) => {
      return this.request<AssessmentCompleteResult>(
        `/assessment-sessions/${sessionId}/complete`,
        {
          method: 'POST',
        }
      );
    },
  };

  readonly skills = {
    getCurrent: async (studentId?: number, source?: RespondentType) => {
      const base = studentId ? `/students/${studentId}/skills/current` : '/skills/current';
      const query = source ? `?source=${encodeURIComponent(source)}` : '';
      return this.request<SkillScore[]>(`${base}${query}`);
    },
    getHistory: async (studentId?: number) => {
      const endpoint = studentId ? `/students/${studentId}/skills/history` : '/skills/history';
      return this.request<SkillHistoryEntry[]>(endpoint);
    },
    // Anonymized class average vs the learner's own score (§11, PA31).
    getBenchmark: async (studentId?: number, source?: RespondentType) => {
      const base = studentId ? `/students/${studentId}/skills/benchmark` : '/skills/benchmark';
      const query = source ? `?source=${encodeURIComponent(source)}` : '';
      return this.request<ClassBenchmark>(`${base}${query}`);
    },
    // Longitudinal class average series (§11 over time, PA32).
    getClassTrend: async (studentId?: number) => {
      const endpoint = studentId ? `/students/${studentId}/skills/class-trend` : '/skills/class-trend';
      return this.request<ClassTrendEntry[]>(endpoint);
    },
  };

  readonly recommendations = {
    // Deterministic next-best content for a student's measured growth gaps.
    getCurrent: async (studentId?: number, source?: RespondentType) => {
      const base = studentId ? `/students/${studentId}/recommendations` : '/recommendations';
      const query = source ? `?source=${encodeURIComponent(source)}` : '';
      return this.request<RecommendationsResult>(`${base}${query}`);
    },
  };

  readonly learning = {
    getPath: async (studentId?: number) => {
      const query = studentId ? `?studentId=${studentId}` : '';
      return this.request<LearningPath>(`/learning/path${query}`);
    },
    // PA17: browsable Content Library catalog, optionally scoped to a competency.
    getLessons: async (competency?: string) => {
      const query = competency ? `?competency=${encodeURIComponent(competency)}` : '';
      return this.request<LessonCatalog>(`/learning/lessons${query}`);
    },
    // PA17: the real lesson body behind a learning-path node's contentId.
    getLesson: async (lessonId: number) => {
      return this.request<Lesson>(`/lessons/${lessonId}`);
    },
    completeNode: async (nodeId: string, xpReward: number = 40) => {
      return this.request<{ success: boolean; nodeId: string; xpAwarded: number; status: string }>(
        `/lessons/${nodeId}/complete`,
        {
          method: 'POST',
          body: JSON.stringify({ xpReward }),
        }
      );
    },
  };

  readonly parent = {
    getChildren: async () => {
      return this.request<StudentProfile[]>('/parent/children');
    },
    getChildOverview: async (childId: number) => {
      return this.request<ParentOverview>(`/parent/children/${childId}/overview`);
    },
    getReports: async (studentId: number) => {
      return this.request<StudentReport[]>(`/students/${studentId}/reports`);
    },
    // PA40: the child's recent activity feed (lessons + missions + badges),
    // derived server-side from the ledgers; parent-scoped via §8 permission check.
    getActivities: async (childId: number) => {
      return this.request<ParentActivityFeed>(`/parent/children/${childId}/activities`);
    },
    // PA20: persist an immutable report snapshot, returned with its durable id.
    generateReport: async (studentId: number) => {
      return this.request<StudentReport>(`/students/${studentId}/reports/generate`, {
        method: 'POST',
        body: JSON.stringify({}),
      });
    },
    // PA21: one snapshot with its event-derived delivery/read status.
    getReport: async (studentId: number, reportId: number) => {
      return this.request<StudentReport>(`/students/${studentId}/reports/${reportId}`);
    },
    // PA21: acknowledge a read; appends a receipt and returns the derived status.
    markReportRead: async (studentId: number, reportId: number) => {
      return this.request<{ reportId: number; delivery: ReportDelivery }>(
        `/students/${studentId}/reports/${reportId}/read`,
        { method: 'POST', body: JSON.stringify({}) }
      );
    },
    // PA42: "add a child" is request-then-approve — submitting a student code
    // only queues a request; an administrator approval grants access (§8).
    createLinkRequest: async (studentCode: string, relationship: 'father' | 'mother' | 'guardian') => {
      return this.request<ParentLinkRequestResult>('/parent/link-requests', {
        method: 'POST',
        body: JSON.stringify({ studentCode, relationship }),
      });
    },
    getLinkRequests: async () => {
      return this.request<ParentLinkRequest[]>('/parent/link-requests');
    },
  };

  // PA23: school staff scope. The roster is always the caller's own school —
  // the server derives the school from the login, never from a client id (§8).
  readonly school = {
    getRoster: async () => {
      return this.request<{ schoolId: number; count: number; students: StudentProfile[] }>(
        '/school/roster'
      );
    },
    getStudent: async (studentId: number) => {
      return this.request<StudentProfile>(`/school/students/${studentId}`);
    },
    // PA24: the caller's own-class roster (class-scoped staff only).
    getClassRoster: async () => {
      return this.request<{ classId: number; count: number; students: StudentProfile[] }>(
        '/class/roster'
      );
    },
  };

  readonly gamification = {
    getMissions: async () => {
      return this.request<Mission[]>('/gamification/missions');
    },
    claimMission: async (missionId: number) => {
      return this.request<{ success: boolean; missionId: number; xpAwarded: number }>(
        `/gamification/missions/${missionId}/claim`,
        {
          method: 'POST',
        }
      );
    },
    getBadges: async () => {
      return this.request<Badge[]>('/gamification/badges');
    },
    // PA18: dated achievement timeline (unlocked badges + completed missions).
    getAchievements: async () => {
      return this.request<AchievementsResult>('/gamification/achievements');
    },
    // PA22: the derived header state, now including the banked freeze balance.
    getMe: async () => {
      return this.request<GamificationMe>('/gamification/me');
    },
    // PA22: spend one banked freeze to bridge a single missed day.
    useStreakFreeze: async () => {
      return this.request<StreakFreezeResult>('/gamification/streak/freeze', {
        method: 'POST',
      });
    },
  };
}

export const api = new AbaApiClient();
