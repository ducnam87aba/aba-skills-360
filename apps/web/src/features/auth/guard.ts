import type { User } from '@aba/types';

/**
 * PA13 — route/role protection, expressed as a pure decision so it is unit
 * tested under plain node vitest and reused by the client gate. This is a UX
 * guard only; every protected endpoint still enforces server-side permission
 * checks (AGENTS.md §8). The client never trusts this to be the authority.
 */

/** Routes reachable without a session. */
const PUBLIC_PATHS = new Set(['/', '/login']);

/** PA48 — the forced first-login password screen, reachable by any session. */
export const CHANGE_PASSWORD_PATH = '/change-password';

function isChangePassword(path: string): boolean {
  return normalize(path) === CHANGE_PASSWORD_PATH;
}

export function isPublicPath(pathname: string): boolean {
  return PUBLIC_PATHS.has(normalize(pathname));
}

function normalize(pathname: string): string {
  if (!pathname) return '/';
  const noTrailing = pathname.length > 1 ? pathname.replace(/\/+$/, '') : pathname;
  return noTrailing || '/';
}

function isParentArea(pathname: string): boolean {
  const p = normalize(pathname);
  return p === '/parent' || p.startsWith('/parent/');
}

/**
 * Where the auth state should send the learner, or null when access is allowed.
 * - Unauthenticated on a protected route => '/login'.
 * - Parent-only role inside the student app => '/parent' (and vice-versa).
 * - A session holding both roles, or a staff role with neither, is unrestricted
 *   here (real gating is server-side).
 */
export function resolveAuthRedirect(pathname: string, user: User | null): string | null {
  const path = normalize(pathname);
  if (isPublicPath(path)) return null;
  if (!user) return '/login';

  // PA48 — a handout-credential session may only sit on the change-password
  // screen until it owns a private secret. PA51 made this a real constraint
  // rather than a courtesy: the server refuses every other aba/v1 route with
  // 403 CREDENTIAL_CHANGE_REQUIRED while the flag is set, so this guard exists
  // to route the user before they hit that wall, not to authorize them.
  if (user.mustChangePassword && !isChangePassword(path)) return CHANGE_PASSWORD_PATH;

  const roles = user.roles ?? [];
  const canParent = roles.includes('parent');
  const canStudent = roles.includes('student');

  // Staff/admin roles are not gated by this client guard.
  if (!canParent && !canStudent) return null;

  const parentArea = isParentArea(path);
  if (parentArea && !canParent) return '/learn';
  if (!parentArea && !canStudent) return '/parent';
  return null;
}
