'use client';

import React, { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@aba/api-client';
import { Button } from '@aba/ui';
import { LogOut } from 'lucide-react';
import { formatDeviceSeen, formatDeviceSignedIn } from '../../lib/deviceSession';
import { iconFor, readDeviceError } from './deviceView';

/**
 * PA56 — "which machines is my child still signed in on".
 *
 * PA52 could only report a count, so a parent who suspected a shared password
 * learned that *something* was live but not whether it was the phone left at
 * grandma's. This list names each device, and the per-row sign-out ends that one
 * while the child's other devices keep working. The server gates it per request
 * by the parent-child link (§8) and only ever hands back a derived handle, so a
 * row is safe to render as-is.
 *
 * Rendered inside the existing "Thiết bị & bảo mật" card, which keeps the
 * counts line and the "sign out every device" action below.
 */
export function ChildDevicesPanel({ childId }: { childId: number }) {
  const queryClient = useQueryClient();
  const { data, isLoading, isError } = useQuery({
    queryKey: ['child-devices', childId],
    queryFn: () => api.student.getDevices(childId),
  });

  const [confirmOne, setConfirmOne] = useState<string | null>(null);
  const [feedback, setFeedback] = useState<{ ok: boolean; text: string } | null>(null);

  const revoke = useMutation({
    mutationFn: (handle: string) => api.student.revokeDevice(childId, handle),
    onSuccess: (res) => {
      setConfirmOne(null);
      setFeedback({
        ok: true,
        text:
          res.count > 0
            ? `Đã đăng xuất thiết bị ${res.label.toLowerCase()}. Con vẫn còn ${res.count} thiết bị đang đăng nhập.`
            : `Đã đăng xuất thiết bị ${res.label.toLowerCase()}. Con không còn thiết bị nào đang đăng nhập.`,
      });
      // The device list, the PA52 counts and the audit trail all moved together.
      void queryClient.invalidateQueries({ queryKey: ['child-devices', childId] });
      void queryClient.invalidateQueries({ queryKey: ['child-sessions'] });
      void queryClient.invalidateQueries({ queryKey: ['child-credential-events'] });
    },
    onError: (e: unknown) => {
      setConfirmOne(null);
      setFeedback({ ok: false, text: readDeviceError(e) });
      // A stale row is the usual cause: re-read so the list matches the server.
      void queryClient.invalidateQueries({ queryKey: ['child-devices', childId] });
    },
  });

  const devices = data?.devices ?? [];
  const busy = revoke.isPending;

  return (
    <div className="space-y-1.5">
      {isLoading ? (
        <p className="text-xs font-bold text-slate-400">Đang tải danh sách thiết bị…</p>
      ) : isError ? (
        <p className="text-xs font-bold text-rose-600">Không tải được danh sách thiết bị.</p>
      ) : devices.length === 0 ? (
        <p className="text-xs font-bold text-slate-400">Không có thiết bị ứng dụng nào đang đăng nhập.</p>
      ) : (
        <ul className="divide-y divide-black/5 rounded-xl bg-white/70 px-3">
          {devices.map((device) => {
            const Icon = iconFor(device.label);
            return (
              <li key={device.handle} className="py-2 flex items-center gap-2.5">
                <span className="w-8 h-8 rounded-xl bg-slate-50 flex items-center justify-center text-slate-400 shrink-0">
                  <Icon className="w-4 h-4" />
                </span>
                <div className="min-w-0 flex-1">
                  <span className="text-xs font-black text-slate-700">{device.label}</span>
                  <div className="text-[11px] font-bold text-slate-400 mt-0.5">
                    Dùng {formatDeviceSeen(device.lastUsedAt, device.createdAt)} · Đăng nhập{' '}
                    {formatDeviceSignedIn(device.createdAt)}
                  </div>
                </div>
                {confirmOne === device.handle ? (
                  <div className="flex items-center gap-1 shrink-0">
                    <Button size="sm" variant="rose" disabled={busy} onClick={() => revoke.mutate(device.handle)}>
                      Chắc chắn
                    </Button>
                    <Button size="sm" variant="ghost" onClick={() => setConfirmOne(null)}>
                      Huỷ
                    </Button>
                  </div>
                ) : (
                  <Button
                    size="sm"
                    variant="outline"
                    className="gap-1.5 shrink-0"
                    disabled={busy}
                    onClick={() => {
                      setFeedback(null);
                      setConfirmOne(device.handle);
                    }}
                  >
                    <LogOut className="w-3.5 h-3.5" /> Đăng xuất
                  </Button>
                )}
              </li>
            );
          })}
        </ul>
      )}

      {devices.length > 0 && (
        <p className="text-[11px] font-medium text-slate-400">
          Chỉ thiết bị ứng dụng mới hiện nhãn; phiên trình duyệt vẫn được đăng xuất bằng nút bên dưới.
        </p>
      )}

      {feedback && (
        <p className={'text-xs font-bold ' + (feedback.ok ? 'text-brand-700' : 'text-rose-600')}>
          {feedback.text}
        </p>
      )}
    </div>
  );
}
