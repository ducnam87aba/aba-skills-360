import type {
  User,
  StudentProfile,
  ParentProfile,
  SkillScore,
  SkillSnapshot,
  SkillHistoryEntry,
  LearningPath,
  Assessment,
  AssessmentSession,
  Mission,
  Badge,
  StudentReport,
  ApiError,
} from '@aba/types';

export interface ApiClientConfig {
  baseUrl?: string;
  getToken?: () => string | null;
}

export class AbaApiClient {
  private baseUrl: string;
  private getToken?: () => string | null;

  constructor(config: ApiClientConfig = {}) {
    // Default base URL aligns with docs/api-contract.md: /wp-json/aba/v1
    this.baseUrl = config.baseUrl || '/wp-json/aba/v1';
    this.getToken = config.getToken;
  }

  private async request<T>(endpoint: string, options: RequestInit = {}): Promise<T> {
    const url = `${this.baseUrl}${endpoint.startsWith('/') ? endpoint : `/${endpoint}`}`;
    const headers: Record<string, string> = {
      'Content-Type': 'application/json',
      Accept: 'application/json',
      ...((options.headers as Record<string, string>) || {}),
    };

    const token = this.getToken ? this.getToken() : null;
    if (token) {
      headers['Authorization'] = `Bearer ${token}`;
    }

    const response = await fetch(url, {
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

    return response.json() as Promise<T>;
  }

  // Domain modules matching AGENTS.md
  readonly auth = {
    login: async (credentials: { username: string; password: string }) => {
      return this.request<{ token: string; user: User }>('/auth/login', {
        method: 'POST',
        body: JSON.stringify(credentials),
      });
    },
    logout: async () => {
      return this.request<{ success: boolean }>('/auth/logout', {
        method: 'POST',
      });
    },
    me: async () => {
      return this.request<User>('/me');
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
    get: async (assessmentId: number) => {
      return this.request<Assessment>(`/assessments/${assessmentId}`);
    },
    start: async (assessmentId: number, studentId: number) => {
      return this.request<AssessmentSession>(`/assessments/${assessmentId}/start`, {
        method: 'POST',
        body: JSON.stringify({ studentId }),
      });
    },
    answer: async (sessionId: number, questionId: number, answer: string | number) => {
      return this.request<{ success: boolean; nextQuestionIndex: number }>(
        `/assessment-sessions/${sessionId}/responses`,
        {
          method: 'POST',
          body: JSON.stringify({ questionId, answer }),
        }
      );
    },
    complete: async (sessionId: number) => {
      return this.request<{ session: AssessmentSession; snapshot: SkillSnapshot }>(
        `/assessment-sessions/${sessionId}/complete`,
        {
          method: 'POST',
        }
      );
    },
  };

  readonly skills = {
    getCurrent: async (studentId?: number) => {
      const endpoint = studentId ? `/students/${studentId}/skills/current` : '/skills/current';
      return this.request<SkillScore[]>(endpoint);
    },
    getHistory: async (studentId?: number) => {
      const endpoint = studentId ? `/students/${studentId}/skills/history` : '/skills/history';
      return this.request<SkillHistoryEntry[]>(endpoint);
    },
  };

  readonly learning = {
    getPath: async (studentId?: number) => {
      const query = studentId ? `?studentId=${studentId}` : '';
      return this.request<LearningPath>(`/learning/path${query}`);
    },
  };

  readonly parent = {
    getChildren: async () => {
      return this.request<StudentProfile[]>('/parent/children');
    },
    getReports: async (studentId: number) => {
      return this.request<StudentReport[]>(`/students/${studentId}/reports`);
    },
  };

  readonly gamification = {
    getMissions: async () => {
      return this.request<Mission[]>('/gamification/missions');
    },
    getBadges: async () => {
      return this.request<Badge[]>('/gamification/badges');
    },
  };
}

export const api = new AbaApiClient();
