'use client';

/**
 * PA9 — client-side auth session.
 *
 * Identity is owned by the server (WordPress cookie session); this store only
 * mirrors the returned user DTO and drives the login/logout flow through the
 * shared api-client (AGENTS.md §16 — no ad-hoc fetch). Nothing here is an
 * authorization decision: every protected call still relies on server-side
 * permission checks (AGENTS.md §8). The persisted copy just spares a reload
 * from re-prompting; refreshMe() re-reads /me to stay honest about the cookie.
 */

import { create } from 'zustand';
import { persist, createJSONStorage } from 'zustand/middleware';
import { api } from '@aba/api-client';
import type { User } from '@aba/types';

export type AuthStatus = 'unauthenticated' | 'authenticating' | 'authenticated';

interface AuthState {
  user: User | null;
  status: AuthStatus;
  error: string | null;
  // PA37 — the app-password pair issued by the real backend. The api-client
  // reads these from the persisted blob to send Basic auth; mocks never set
  // them, so the MSW flow is untouched.
  username: string | null;
  token: string | null;
  login: (username: string, password: string) => Promise<User>;
  logout: () => Promise<void>;
  refreshMe: () => Promise<void>;
  clearError: () => void;
}

/** Normalize the two error shapes the backend can surface into one message. */
function readAuthError(e: unknown): string {
  if (e && typeof e === 'object') {
    const err = e as { error?: { code?: string; message?: string }; message?: string; code?: string };
    if (err.error?.message) return err.error.message;
    if (err.message) return err.message;
    const code = err.error?.code ?? err.code;
    if (code === 'INVALID_CREDENTIALS') return 'Sai tên đăng nhập hoặc mật khẩu.';
    if (code === 'ACCOUNT_DISABLED') return 'Tài khoản này đã bị vô hiệu hóa.';
    if (code) return `Đăng nhập thất bại (${code}).`;
  }
  return 'Không thể đăng nhập. Vui lòng thử lại.';
}

/** Best-effort nonce bootstrap; a failure must never block the auth transition. */
async function refreshNonce(): Promise<void> {
  try {
    const { nonce } = await api.auth.nonce();
    api.setNonce(nonce);
  } catch {
    api.setNonce(null);
  }
}

export const useAuthStore = create<AuthState>()(
  persist(
    (set) => ({
      user: null,
      status: 'unauthenticated',
      error: null,
      username: null,
      token: null,

      login: async (username, password) => {
        set({ status: 'authenticating', error: null });
        try {
          const result = await api.auth.login({ username, password });
          const token = result.token ?? null;
          if (token) {
            // PA37 real-backend mode: every later call rides Basic auth with the
            // freshly issued app password. Writes still carry X-WP-Nonce (the
            // smoke test showed a nonce-less POST /streak/freeze => 403), and
            // /auth/nonce authenticates fine over Basic, so fetch it too.
            api.setAuth(username, token);
            await refreshNonce();
            set({ user: result.user, status: 'authenticated', error: null, username, token });
          } else {
            // Cookie/mock mode: keep the CSRF nonce bootstrap (PA14) as before.
            await refreshNonce();
            set({ user: result.user, status: 'authenticated', error: null, username: null, token: null });
          }
          return result.user;
        } catch (e) {
          set({ user: null, status: 'unauthenticated', error: readAuthError(e), username: null, token: null });
          throw e;
        }
      },

      logout: async () => {
        try {
          await api.auth.logout();
        } catch {
          // The cookie is already gone client-side; a failed call must not strand the UI.
        }
        api.setNonce(null);
        api.setAuth(null, null);
        set({ user: null, status: 'unauthenticated', error: null, username: null, token: null });
      },

      refreshMe: async () => {
        try {
          // Re-arm Basic auth from the persisted pair before probing /me — the
          // in-memory client starts empty on every page load.
          const { username, token } = useAuthStore.getState();
          if (username && token) api.setAuth(username, token);
          const user = await api.auth.me();
          await refreshNonce();
          set({ user, status: 'authenticated' });
        } catch {
          api.setNonce(null);
          set({ user: null, status: 'unauthenticated' });
        }
      },

      clearError: () => set({ error: null }),
    }),
    {
      name: 'aba-auth',
      storage: createJSONStorage(() => (typeof window !== 'undefined' ? window.localStorage : undefined as unknown as Storage)),
      partialize: (s) => ({ user: s.user, status: s.status, username: s.username, token: s.token }),
    }
  )
);

export const useAuthUser = (): User | null => useAuthStore((s) => s.user);
export const useIsAuthenticated = (): boolean => useAuthStore((s) => s.status === 'authenticated');

/** Landing route by role: parents land on the parent overview, learners on their path. */
export function homeRouteFor(user: User | null): string {
  return user?.roles?.includes('parent') ? '/parent' : '/learn';
}
