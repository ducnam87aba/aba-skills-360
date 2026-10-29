'use client';

/**
 * PA41 — shared parent↔children selection.
 *
 * A parent may be linked to more than one child (feature-gated by the
 * wp_aba_parent_student rows the server enforces per §8). Rather than every
 * parent page independently reaching for `children[0]` — which silently pinned
 * the whole portal to the first child — one provider owns the child list and
 * the "which child am I currently viewing" choice, and the switcher + all five
 * parent screens read it. The list is ordered server-side (primary child first),
 * so the default selection is unchanged for single-child parents.
 *
 * This is a presentation concern only: selecting a child never widens access.
 * Each downstream call still goes through the permission-checked endpoint for
 * that child id, and the server rejects an id that is not this parent's child.
 */

import React, { createContext, useCallback, useContext, useMemo, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { api } from '@aba/api-client';
import type { StudentProfile } from '@aba/types';
import { useAuthStore } from '../auth/store';

interface ParentChildrenValue {
  children: StudentProfile[];
  isLoading: boolean;
  /** The child currently in focus (falls back to the primary/first child). */
  activeChild: StudentProfile | null;
  /** Convenience id for query guards; undefined until children resolve. */
  activeChildId: number | undefined;
  setActiveChildId: (id: number) => void;
}

const ParentChildrenContext = createContext<ParentChildrenValue | null>(null);

/**
 * PA42 follow-up: the selection now survives a remount (hard reload, dev
 * Fast Refresh, a guard re-render) — but in sessionStorage, NOT the persisted
 * auth store: it dies with the tab, can never restore a stale id into another
 * account (the key carries the username), and the list guard below still snaps
 * an unknown id back to the primary child.
 */
const SELECTION_KEY_PREFIX = 'aba-parent-active-child:';

function readStoredSelection(username: string | null): number | null {
  if (typeof window === 'undefined' || !username) return null;
  try {
    const raw = window.sessionStorage.getItem(SELECTION_KEY_PREFIX + username);
    const id = raw ? Number(raw) : NaN;
    return Number.isInteger(id) && id > 0 ? id : null;
  } catch {
    return null;
  }
}

export function ParentChildrenProvider({ children: node }: { children: React.ReactNode }) {
  const parentUsername = useAuthStore((s) => s.username ?? null);
  const { data, isLoading } = useQuery({
    queryKey: ['parent-children'],
    queryFn: () => api.parent.getChildren(),
  });

  const list = useMemo(() => data ?? [], [data]);
  // Selection is per tab + per parent account (sessionStorage): it survives
  // navigation and remounts, resets on a new tab, and the key can never leak
  // the choice across logins. Clearing it falls back to the primary child.
  const [selectedId, setSelectedId] = useState<number | null>(() => readStoredSelection(parentUsername));
  const setActiveChildId = useCallback((id: number) => {
    setSelectedId(id);
    try {
      if (parentUsername) window.sessionStorage.setItem(SELECTION_KEY_PREFIX + parentUsername, String(id));
    } catch {
      // Private mode / storage disabled — the in-memory choice still works.
    }
  }, [parentUsername]);

  // Guard against a selection that is no longer in the (server-scoped) list —
  // e.g. a link revoked mid-session — by snapping back to the first child.
  const activeChild = list.find((c) => c.id === selectedId) ?? list[0] ?? null;

  const value = useMemo<ParentChildrenValue>(
    () => ({
      children: list,
      isLoading,
      activeChild,
      activeChildId: activeChild?.id,
      setActiveChildId,
    }),
    [list, isLoading, activeChild, setActiveChildId],
  );

  return <ParentChildrenContext.Provider value={value}>{node}</ParentChildrenContext.Provider>;
}

export function useParentChildren(): ParentChildrenValue {
  const ctx = useContext(ParentChildrenContext);
  if (!ctx) {
    throw new Error('useParentChildren must be used inside a ParentChildrenProvider');
  }
  return ctx;
}
