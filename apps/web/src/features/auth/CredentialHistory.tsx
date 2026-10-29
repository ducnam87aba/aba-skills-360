'use client';

import React, { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { api } from '@aba/api-client';
import { Card } from '@aba/ui';
import { History, MonitorSmartphone, LogIn, ShieldAlert } from 'lucide-react';
import type { ComponentType } from 'react';
import type { CredentialAuditEvent, CredentialEventKind } from '@aba/types';

/**
 * PA61 — the full sign-in & security history of one child.
 *
 * PA49 started this list with account actions only, and PA57/PA59/PA60 kept
 * appending to the same ledger (a new device, a password sign-in, a script
 * replaying it) — but the screen still showed the first slice as if nothing
 * else existed. This surfaces the whole trail, newest first, and filters it by
 * kind so "was anyone guessing my child's password?" is one tap away.
 *
 * The server does the filtering (`?kind=`), so a chip never shows a row the
 * §8 gate would otherwise withhold; the client only chooses which projection
 * to ask for. Nothing here is replayable: labels, doors, counts and instants.
 */

/** Mirrors CredentialAuditService::HISTORY_LIMIT — only used to say when the
 *  list may be cut off, so a parent never reads a full page as the whole story. */
const HISTORY_LIMIT = 200;

const FILTERS: Array<{ value: CredentialEventKind | ''; text: string }> = [
  { value: '', text: 'Tất cả' },
  { value: 'account', text: 'Tài khoản' },
  { value: 'device', text: 'Thiết bị' },
  { value: 'session', text: 'Đăng nhập' },
  { value: 'lockout', text: 'Cảnh báo' },
];

const KIND_ICON: Record<CredentialEventKind, { icon: ComponentType<{ className?: string }>; tone: string }> = {
  account: { icon: History, tone: 'text-slate-400' },
  device: { icon: MonitorSmartphone, tone: 'text-amber-500' },
  session: { icon: LogIn, tone: 'text-brand-500' },
  lockout: { icon: ShieldAlert, tone: 'text-rose-500' },
};

export function CredentialHistory({ childId }: { childId: number }) {
  const [kind, setKind] = useState<CredentialEventKind | ''>('');
  // Same query-key prefix the revoke mutation invalidates, so a "sign out every
  // device" appends to this list without a manual refresh.
  const { data, isLoading } = useQuery({
    queryKey: ['child-credential-events', childId, kind],
    queryFn: () => api.student.getCredentialEvents(childId, kind || undefined),
  });

  const events = data?.events ?? [];
  // A filtered empty list is not "this account is clean": say which it is.
  const emptyText = kind
    ? 'Chưa có sự kiện nào thuộc nhóm này.'
    : 'Chưa có sự kiện nào được ghi nhận.';

  return (
    <Card className="mt-1.5 p-3 bg-slate-50/60 space-y-2">
      <div className="flex items-center gap-1 flex-wrap">
        {FILTERS.map((f) => (
          <button
            key={f.value || 'all'}
            type="button"
            onClick={() => setKind(f.value)}
            className={
              'rounded-full px-2.5 py-1 text-[11px] font-black transition-colors ' +
              (kind === f.value
                ? 'bg-slate-800 text-white'
                : 'bg-white text-slate-500 hover:bg-slate-100 border border-slate-200')
            }
          >
            {f.text}
          </button>
        ))}
      </div>

      {isLoading ? (
        <p className="text-xs font-bold text-slate-400">Đang tải…</p>
      ) : !events.length ? (
        <p className="text-xs font-bold text-slate-400">{emptyText}</p>
      ) : (
        <ul className="space-y-1.5">
          {events.map((ev) => (
            <Row key={ev.id} event={ev} />
          ))}
        </ul>
      )}

      {events.length >= HISTORY_LIMIT && (
        <p className="text-[11px] font-medium text-slate-400">
          Chỉ hiển thị {HISTORY_LIMIT} sự kiện gần nhất.
        </p>
      )}
    </Card>
  );
}

function Row({ event }: { event: CredentialAuditEvent }) {
  const meta = KIND_ICON[event.kind] ?? KIND_ICON.account;
  const Icon = meta.icon;
  const detail = detailFor(event);
  return (
    <li className="flex items-start gap-2 text-xs text-slate-600">
      <Icon className={'w-3.5 h-3.5 mt-0.5 shrink-0 ' + meta.tone} />
      <span className="min-w-0">
        <span className="font-black text-slate-700">{event.actionLabel}</span>
        {/* The device the row is about — the whole reason a parent opens this. */}
        {detail && <span className="text-slate-500"> · {detail}</span>}
        <span className="block text-[11px] font-medium text-slate-400">
          {event.actorLabel} · {new Date(event.createdAt).toLocaleString('vi-VN', { hour12: false })}
        </span>
      </span>
    </li>
  );
}

/** The non-secret detail a row carries, in the family's words. */
function detailFor(ev: CredentialAuditEvent): string {
  if (ev.kind === 'lockout') {
    const parts: string[] = [];
    if (ev.failures) parts.push(`${ev.failures} lần thử sai`);
    if (ev.retryAfter) parts.push(`tạm khoá ${Math.round(ev.retryAfter / 60)} phút`);
    return parts.join(' · ');
  }
  if (ev.kind === 'device' || ev.kind === 'session') {
    const parts: string[] = [];
    // A door is only meaningful for a sign-in, not for "đăng xuất mọi thiết bị".
    if (ev.door === 'xmlrpc') parts.push('ứng dụng khác');
    if (ev.label) parts.push(ev.label);
    return parts.join(' · ');
  }
  return '';
}
