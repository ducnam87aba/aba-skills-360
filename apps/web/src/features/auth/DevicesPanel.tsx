'use client';

import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@aba/api-client';
import { Card, Badge, Button } from '@aba/ui';
import { MonitorSmartphone, LogOut } from 'lucide-react';
import { formatDeviceSeen, formatDeviceSignedIn } from '../../lib/deviceSession';
import { iconFor, readDeviceError } from './deviceView';

/**
 * PA55 — "which machines am I still signed in on", for the caller's OWN account.
 *
 * Since PA54 every sign-in mints its own device token, but until now nobody
 * could see them: the family learned about a stray device only by asking the
 * school. The server scopes this list to the session (§8) and hands back a
 * derived handle, never a token, so this panel may render it as-is — revoking a
 * row ends that one device and leaves the rest of the family signed in.
 */

const DEVICES_KEY = ['me-devices'];

export function DevicesPanel() {
  const queryClient = useQueryClient();
  const { data, isLoading, isError } = useQuery({
    queryKey: DEVICES_KEY,
    queryFn: () => api.devices.list(),
  });

  const [confirmOne, setConfirmOne] = useState<string | null>(null);
  const [confirmOthers, setConfirmOthers] = useState(false);
  const [feedback, setFeedback] = useState<{ ok: boolean; text: string } | null>(null);

  const refresh = () => queryClient.invalidateQueries({ queryKey: DEVICES_KEY });

  const revokeOne = useMutation({
    mutationFn: (handle: string) => api.devices.revoke(handle),
    onSuccess: () => {
      setConfirmOne(null);
      setFeedback({ ok: true, text: 'Đã đăng xuất thiết bị đó.' });
      void refresh();
    },
    onError: (e: unknown) => {
      setConfirmOne(null);
      setFeedback({ ok: false, text: readDeviceError(e) });
      void refresh();
    },
  });

  const revokeOthers = useMutation({
    mutationFn: () => api.devices.revokeOthers(),
    onSuccess: (res) => {
      setConfirmOthers(false);
      setFeedback({
        ok: true,
        text:
          res.revoked > 0
            ? `Đã đăng xuất ${res.revoked} thiết bị khác. Chúng sẽ phải đăng nhập lại.`
            : 'Không có thiết bị nào khác đang đăng nhập.',
      });
      void refresh();
    },
    onError: (e: unknown) => {
      setConfirmOthers(false);
      setFeedback({ ok: false, text: readDeviceError(e) });
    },
  });

  const devices = data?.devices ?? [];
  const others = devices.filter((d) => !d.current).length;
  const busy = revokeOne.isPending || revokeOthers.isPending;

  return (
    <Card className="p-5 space-y-3">
      <div className="flex items-center justify-between gap-2 flex-wrap">
        <h3 className="text-sm font-black text-slate-700 flex items-center gap-2">
          <MonitorSmartphone className="w-5 h-5 text-brand-500" /> Thiết bị đang đăng nhập
        </h3>
        {data && (
          <Badge variant={devices.length >= data.maxDevices ? 'warning' : 'success'}>
            {data.count}/{data.maxDevices} thiết bị
          </Badge>
        )}
      </div>

      <p className="text-[11px] font-medium text-slate-400">
        Đây là những thiết bị đang dùng tài khoản của bạn. Máy nào bạn không nhận ra thì nên đăng xuất ngay —
        mật khẩu của bạn vẫn giữ nguyên, chỉ phiên trên máy đó bị kết thúc.
      </p>

      {isLoading ? (
        <p className="text-xs font-bold text-slate-400">Đang tải…</p>
      ) : isError ? (
        <p className="text-xs font-bold text-rose-600">Không tải được danh sách thiết bị.</p>
      ) : devices.length === 0 ? (
        <p className="text-xs font-bold text-slate-400">Chưa có thiết bị nào đăng nhập.</p>
      ) : (
        <ul className="divide-y divide-black/5">
          {devices.map((device) => {
            const Icon = iconFor(device.label);
            return (
              <li key={device.handle} className="py-2.5 flex items-center gap-3">
                <span className="w-9 h-9 rounded-xl bg-slate-50 flex items-center justify-center text-slate-400 shrink-0">
                  <Icon className="w-4 h-4" />
                </span>
                <div className="min-w-0 flex-1">
                  <div className="flex items-center gap-2 flex-wrap">
                    <span className="text-sm font-black text-slate-700">{device.label}</span>
                    {device.current && <Badge variant="success">Thiết bị này</Badge>}
                  </div>
                  <div className="text-[11px] font-bold text-slate-400 mt-0.5">
                    Dùng {formatDeviceSeen(device.lastUsedAt, device.createdAt)} · Đăng nhập{' '}
                    {formatDeviceSignedIn(device.createdAt)}
                  </div>
                </div>
                {!device.current && (
                  <div className="flex items-center gap-1.5 shrink-0">
                    {confirmOne === device.handle ? (
                      <>
                        <Button
                          size="sm"
                          variant="rose"
                          disabled={busy}
                          onClick={() => revokeOne.mutate(device.handle)}
                        >
                          Chắc chắn
                        </Button>
                        <Button size="sm" variant="ghost" onClick={() => setConfirmOne(null)}>
                          Huỷ
                        </Button>
                      </>
                    ) : (
                      <Button
                        size="sm"
                        variant="outline"
                        className="gap-1.5"
                        disabled={busy}
                        onClick={() => {
                          setFeedback(null);
                          setConfirmOne(device.handle);
                        }}
                      >
                        <LogOut className="w-3.5 h-3.5" /> Đăng xuất
                      </Button>
                    )}
                  </div>
                )}
              </li>
            );
          })}
        </ul>
      )}

      {others > 0 && (
        <div className="pt-1">
          {confirmOthers ? (
            <div className="flex items-center gap-2 flex-wrap">
              <Button size="sm" variant="rose" disabled={busy} onClick={() => revokeOthers.mutate()}>
                {revokeOthers.isPending ? 'Đang đăng xuất…' : 'Chắc chắn đăng xuất tất cả'}
              </Button>
              <Button size="sm" variant="ghost" onClick={() => setConfirmOthers(false)}>
                Huỷ
              </Button>
            </div>
          ) : (
            <Button
              size="sm"
              variant="ghost"
              className="gap-1.5 text-xs"
              disabled={busy}
              onClick={() => {
                setFeedback(null);
                setConfirmOthers(true);
              }}
            >
              <LogOut className="w-3.5 h-3.5" /> Đăng xuất mọi thiết bị khác
            </Button>
          )}
        </div>
      )}

      {feedback && (
        <p className={'text-xs font-bold ' + (feedback.ok ? 'text-brand-700' : 'text-rose-600')}>
          {feedback.text}
        </p>
      )}
    </Card>
  );
}
