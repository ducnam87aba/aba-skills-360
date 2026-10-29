import { describe, it, expect } from 'vitest';
import type { User } from '@aba/types';
import { resolveAuthRedirect, isPublicPath, CHANGE_PASSWORD_PATH } from './guard';

const student: User = { id: 1, username: 's', email: '', displayName: 'S', roles: ['student'], studentId: 1 };
const parent: User = { id: 2, username: 'p', email: '', displayName: 'P', roles: ['parent'], parentId: 2 };
const dual: User = { id: 3, username: 'd', email: '', displayName: 'D', roles: ['parent', 'student'] };
const admin: User = { id: 4, username: 'a', email: '', displayName: 'A', roles: ['super_admin'] };
const handout: User = { ...student, mustChangePassword: true };

describe('auth guard — public paths (PA13)', () => {
  it('treats the landing and login routes as public', () => {
    expect(isPublicPath('/')).toBe(true);
    expect(isPublicPath('/login')).toBe(true);
    expect(isPublicPath('/learn')).toBe(false);
  });

  it('never redirects away from a public route, even unauthenticated', () => {
    expect(resolveAuthRedirect('/login', null)).toBeNull();
    expect(resolveAuthRedirect('/', null)).toBeNull();
  });
});

describe('auth guard — unauthenticated', () => {
  it('sends any protected route to /login', () => {
    expect(resolveAuthRedirect('/learn', null)).toBe('/login');
    expect(resolveAuthRedirect('/parent', null)).toBe('/login');
    expect(resolveAuthRedirect('/parent/reports', null)).toBe('/login');
    expect(resolveAuthRedirect('/assessment/9', null)).toBe('/login');
  });
});

describe('auth guard — role gating', () => {
  it('keeps a student out of the parent app', () => {
    expect(resolveAuthRedirect('/parent', student)).toBe('/learn');
    expect(resolveAuthRedirect('/parent/reports', student)).toBe('/learn');
  });

  it('keeps a parent out of the student app', () => {
    expect(resolveAuthRedirect('/learn', parent)).toBe('/parent');
    expect(resolveAuthRedirect('/missions', parent)).toBe('/parent');
  });

  it('allows each role inside its own area', () => {
    expect(resolveAuthRedirect('/learn', student)).toBeNull();
    expect(resolveAuthRedirect('/profile', student)).toBeNull();
    expect(resolveAuthRedirect('/parent', parent)).toBeNull();
    expect(resolveAuthRedirect('/parent/activities', parent)).toBeNull();
  });

  it('lets a dual-role session move freely', () => {
    expect(resolveAuthRedirect('/learn', dual)).toBeNull();
    expect(resolveAuthRedirect('/parent', dual)).toBeNull();
  });

  it('does not gate staff roles from the client', () => {
    expect(resolveAuthRedirect('/learn', admin)).toBeNull();
    expect(resolveAuthRedirect('/parent', admin)).toBeNull();
  });
});

describe('auth guard — path normalization', () => {
  it('ignores trailing slashes', () => {
    expect(resolveAuthRedirect('/learn/', student)).toBeNull();
    expect(resolveAuthRedirect('/parent/', student)).toBe('/learn');
  });
});

describe('auth guard — forced first-login password change (PA48)', () => {
  it('pins a handout-credential session to the change screen', () => {
    expect(resolveAuthRedirect('/learn', handout)).toBe(CHANGE_PASSWORD_PATH);
    expect(resolveAuthRedirect('/parent', handout)).toBe(CHANGE_PASSWORD_PATH);
    expect(resolveAuthRedirect('/assessment/9', handout)).toBe(CHANGE_PASSWORD_PATH);
  });

  it('lets the session sit on the change screen itself', () => {
    expect(resolveAuthRedirect('/change-password', handout)).toBeNull();
    expect(resolveAuthRedirect('/change-password/', handout)).toBeNull();
  });

  it('preempts role routing — the flag is checked first', () => {
    // A parent with an unset handout password still sees the change screen,
    // not the /learn bounce they would otherwise get.
    expect(resolveAuthRedirect('/learn', { ...parent, mustChangePassword: true })).toBe(CHANGE_PASSWORD_PATH);
  });

  it('leaves cleared sessions untouched', () => {
    expect(resolveAuthRedirect('/learn', student)).toBeNull();
    expect(resolveAuthRedirect('/learn', { ...student, mustChangePassword: false })).toBeNull();
  });
});
