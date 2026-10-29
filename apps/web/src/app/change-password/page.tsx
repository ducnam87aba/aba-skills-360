'use client';

import React, { useState } from 'react';
import { useRouter } from 'next/navigation';
import { motion } from 'framer-motion';
import { api } from '@aba/api-client';
import { useAuthStore, homeRouteFor } from '../../features/auth/store';

/**
 * PA48 — forced first-login password change. A learner provisioned with a
 * staff-generated handout password lands here before the app (guard.ts) and
 * swaps it for a private secret. The call authenticates as the session itself
 * and re-presents the current password server-side; on success the backend
 * revokes the old password AND every outstanding app token, so the store
 * re-logs in with the new pair before handing over to the normal landing route.
 */
export default function ChangePasswordPage() {
  const router = useRouter();
  const user = useAuthStore((s) => s.user);
  const applyNewPassword = useAuthStore((s) => s.applyNewPassword);
  const logout = useAuthStore((s) => s.logout);

  const [current, setCurrent] = useState('');
  const [next, setNext] = useState('');
  const [confirm, setConfirm] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  const mismatch = confirm.length > 0 && next !== confirm;
  const canSubmit = current.length > 0 && next.length >= 8 && !mismatch && !submitting;

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!canSubmit) return;
    setSubmitting(true);
    setError(null);
    try {
      await api.auth.changePassword({ oldPassword: current, newPassword: next });
      // 204 — the old credential is dead; re-authenticate with the new one.
      await applyNewPassword(next);
      router.replace(homeRouteFor(useAuthStore.getState().user));
    } catch (err) {
      const code = (err as { error?: { code?: string } })?.error?.code;
      if (code === 'INVALID_CREDENTIALS') setError('Mật khẩu hiện tại không đúng. Vui lòng thử lại.');
      else if (code === 'PASSWORD_TOO_SHORT') setError('Mật khẩu mới phải có ít nhất 8 ký tự.');
      else if (code === 'PASSWORD_UNCHANGED') setError('Mật khẩu mới cần khác mật khẩu hiện tại.');
      else setError('Không thể đổi mật khẩu. Vui lòng thử lại.');
      setSubmitting(false);
    }
  };

  const handleStartOver = async () => {
    await logout();
    router.replace('/login');
  };

  return (
    <div className="min-h-[70vh] flex items-center justify-center px-4">
      <motion.div
        initial={{ opacity: 0, y: 12 }}
        animate={{ opacity: 1, y: 0 }}
        transition={{ duration: 0.25 }}
        className="w-full max-w-md"
      >
        <div className="text-center mb-8">
          <div className="mx-auto w-14 h-14 rounded-2xl bg-amber-400 shadow-[0_4px_0_0_#b45309] flex items-center justify-center text-white font-black text-xl mb-4">
            🔑
          </div>
          <h1 className="font-black text-2xl tracking-tight text-slate-800">Đổi mật khẩu lần đầu</h1>
          <p className="text-sm font-bold text-slate-500 mt-2">
            Mật khẩu hiện tại do giáo viên cấp là mật khẩu dùng chung trên giấy. Hãy đặt mật khẩu
            riêng của {user?.displayName ? user.displayName : 'bạn'} — sau bước này mật khẩu cũ sẽ
            không còn dùng được.
          </p>
        </div>

        <form
          onSubmit={handleSubmit}
          className="bg-white rounded-3xl border border-black/5 shadow-[0_12px_40px_-20px_rgba(0,0,0,0.25)] p-6 sm:p-8 space-y-5"
        >
          <div>
            <label htmlFor="current" className="block text-sm font-extrabold text-slate-600 mb-1.5">
              Mật khẩu hiện tại
            </label>
            <input
              id="current"
              name="current"
              type="password"
              autoComplete="current-password"
              value={current}
              onChange={(e) => setCurrent(e.target.value)}
              className="w-full px-4 py-3 rounded-2xl bg-slate-50 border-2 border-transparent focus:border-brand-400 focus:bg-white outline-none font-semibold text-slate-700 transition-colors"
            />
          </div>

          <div>
            <label htmlFor="new" className="block text-sm font-extrabold text-slate-600 mb-1.5">
              Mật khẩu mới (ít nhất 8 ký tự)
            </label>
            <input
              id="new"
              name="new"
              type="password"
              autoComplete="new-password"
              value={next}
              onChange={(e) => setNext(e.target.value)}
              className="w-full px-4 py-3 rounded-2xl bg-slate-50 border-2 border-transparent focus:border-brand-400 focus:bg-white outline-none font-semibold text-slate-700 transition-colors"
            />
          </div>

          <div>
            <label htmlFor="confirm" className="block text-sm font-extrabold text-slate-600 mb-1.5">
              Nhập lại mật khẩu mới
            </label>
            <input
              id="confirm"
              name="confirm"
              type="password"
              autoComplete="new-password"
              value={confirm}
              onChange={(e) => setConfirm(e.target.value)}
              aria-invalid={mismatch}
              className={`w-full px-4 py-3 rounded-2xl bg-slate-50 border-2 outline-none font-semibold text-slate-700 transition-colors focus:bg-white ${
                mismatch ? 'border-rose-300' : 'border-transparent focus:border-brand-400'
              }`}
            />
            {mismatch && (
              <p className="mt-1.5 text-xs font-bold text-rose-600">Hai mật khẩu mới không khớp.</p>
            )}
          </div>

          {error && (
            <div
              role="alert"
              className="px-4 py-3 rounded-2xl bg-rose-50 text-rose-600 text-sm font-bold border border-rose-100"
            >
              {error}
            </div>
          )}

          <button
            type="submit"
            disabled={!canSubmit}
            className="w-full py-3.5 rounded-2xl bg-brand-500 text-white font-black text-sm uppercase tracking-wide shadow-[0_4px_0_0_#036c4a] active:translate-y-0.5 active:shadow-none transition-all disabled:opacity-50 disabled:cursor-not-allowed disabled:active:translate-y-0 disabled:shadow-[0_4px_0_0_#036c4a]"
          >
            {submitting ? 'Đang đổi mật khẩu…' : 'Đổi mật khẩu'}
          </button>

          <button
            type="button"
            onClick={handleStartOver}
            className="w-full text-center text-xs font-bold text-slate-400 hover:text-slate-600"
          >
            Không phải bạn? Đăng xuất để quay lại trang đăng nhập
          </button>
        </form>
      </motion.div>
    </div>
  );
}
