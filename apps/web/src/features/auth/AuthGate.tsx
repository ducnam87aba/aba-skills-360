'use client';

import React, { useEffect, useState } from 'react';
import { usePathname, useRouter } from 'next/navigation';
import { useAuthStore } from './store';
import { resolveAuthRedirect } from './guard';

/**
 * PA13 — client-side route/role gate. Applies the pure resolveAuthRedirect
 * decision on navigation. It waits for the persisted session to rehydrate so a
 * hard refresh of a signed-in learner is never bounced to /login. This is a UX
 * convenience only; the real authority is the server-side permission check on
 * every endpoint (AGENTS.md §8).
 */
export function AuthGate({ children }: { children: React.ReactNode }) {
  const router = useRouter();
  const pathname = usePathname();
  const user = useAuthStore((s) => s.user);

  const [hydrated, setHydrated] = useState(() => useAuthStore.persist.hasHydrated());
  useEffect(() => {
    const unsub = useAuthStore.persist.onFinishHydration(() => setHydrated(true));
    if (useAuthStore.persist.hasHydrated()) setHydrated(true);
    return unsub;
  }, []);

  useEffect(() => {
    if (!hydrated) return;
    const target = resolveAuthRedirect(pathname, user);
    if (target && target !== pathname) {
      router.replace(target);
    }
  }, [hydrated, pathname, user, router]);

  return <>{children}</>;
}
