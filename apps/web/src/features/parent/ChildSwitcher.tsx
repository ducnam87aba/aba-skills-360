'use client';

/**
 * PA41 — parent child switcher.
 *
 * Rendered once in the parent layout so a parent with several linked children
 * can move the whole portal's focus between them. It is intentionally hidden
 * for a single-child parent (nothing to switch, AGENTS.md §19 keeps the parent
 * surface uncluttered) and it only offers children the server already returned
 * for this parent — it never accepts an arbitrary id (§8).
 */

import React from 'react';
import { useParentChildren } from './ParentChildrenContext';
import { Avatar } from '../../components/Avatar';
import { formatClassLabel } from '../../lib/classLabel';

export function ChildSwitcher() {
  const { children, activeChildId, setActiveChildId } = useParentChildren();

  if (children.length <= 1) return null;

  return (
    <div
      className="flex items-center gap-2 overflow-x-auto pb-1"
      role="tablist"
      aria-label="Chọn con đang xem"
    >
      <span className="text-[11px] font-black uppercase tracking-widest text-slate-400 shrink-0">
        Đang xem
      </span>
      {children.map((child) => {
        const isActive = child.id === activeChildId;
        return (
          <button
            key={child.id}
            type="button"
            role="tab"
            aria-selected={isActive}
            onClick={() => setActiveChildId(child.id)}
            className={
              'inline-flex items-center gap-2 rounded-2xl px-3 py-1.5 text-sm font-black transition-colors shadow-[0_2px_0_0_rgba(0,0,0,0.06)] '
              + (isActive
                ? 'bg-brand-500 text-white'
                : 'bg-white text-slate-500 hover:bg-brand-50 hover:text-brand-700')
            }
          >
            <Avatar
              src={child.avatarUrl}
              name={child.fullName}
              className={
                'w-6 h-6 rounded-full object-cover shrink-0 text-[9px] '
                + (isActive ? 'ring-2 ring-white/70' : 'ring-1 ring-black/5')
              }
            />
            <span className="whitespace-nowrap">{child.fullName}</span>
            <span className={'whitespace-nowrap text-[11px] font-bold ' + (isActive ? 'text-brand-100' : 'text-slate-400')}>
              {formatClassLabel(child.className)}
            </span>
          </button>
        );
      })}
    </div>
  );
}
