import React from 'react';
import { ParentChildrenProvider } from '../../features/parent/ParentChildrenContext';
import { ChildSwitcher } from '../../features/parent/ChildSwitcher';

/**
 * PA41 — shared layout for the parent portal. Hosts the parent↔children context
 * (one child list fetched + one active-child selection) and the switcher bar so
 * every parent screen (Tổng quan, Năng lực, Báo cáo, Hoạt động, Hồ sơ) reflects
 * the same selected child. Keeps the AGENTS.md §19 parent navigation intact —
 * this adds no new nav item, only a within-portal child focus control.
 */
export default function ParentLayout({ children }: { children: React.ReactNode }) {
  return (
    <ParentChildrenProvider>
      <div className="space-y-4">
        <ChildSwitcher />
        {children}
      </div>
    </ParentChildrenProvider>
  );
}
