'use client';

import React, { useState } from 'react';
import { useRouter } from 'next/navigation';
import { motion } from 'framer-motion';
import { useAuthStore, homeRouteFor } from '../../features/auth/store';

export default function LoginPage() {
  const router = useRouter();
  const login = useAuthStore((s) => s.login);
  const error = useAuthStore((s) => s.error);
  const status = useAuthStore((s) => s.status);

  const [username, setUsername] = useState('');
  const [password, setPassword] = useState('');

  const submitting = status === 'authenticating';

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!username.trim() || !password) return;
    try {
      const user = await login(username.trim(), password);
      router.push(homeRouteFor(user));
    } catch {
      // The store already captured the normalized message in `error`.
    }
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
          <div className="mx-auto w-14 h-14 rounded-2xl bg-brand-500 shadow-[0_4px_0_0_#036c4a] flex items-center justify-center text-white font-black text-xl mb-4">
            AB
          </div>
          <h1 className="font-black text-2xl tracking-tight text-slate-800">ABA Skills 360</h1>
          <p className="text-sm font-bold uppercase tracking-widest text-brand-500 mt-1">
            Đăng nhập để tiếp tục
          </p>
        </div>

        <form
          onSubmit={handleSubmit}
          className="bg-white rounded-3xl border border-black/5 shadow-[0_12px_40px_-20px_rgba(0,0,0,0.25)] p-6 sm:p-8 space-y-5"
        >
          <div>
            <label htmlFor="username" className="block text-sm font-extrabold text-slate-600 mb-1.5">
              Tên đăng nhập
            </label>
            <input
              id="username"
              name="username"
              type="text"
              autoComplete="username"
              value={username}
              onChange={(e) => setUsername(e.target.value)}
              placeholder="namduong"
              className="w-full px-4 py-3 rounded-2xl bg-slate-50 border-2 border-transparent focus:border-brand-400 focus:bg-white outline-none font-semibold text-slate-700 transition-colors"
            />
          </div>

          <div>
            <label htmlFor="password" className="block text-sm font-extrabold text-slate-600 mb-1.5">
              Mật khẩu
            </label>
            <input
              id="password"
              name="password"
              type="password"
              autoComplete="current-password"
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              placeholder="••••••••"
              className="w-full px-4 py-3 rounded-2xl bg-slate-50 border-2 border-transparent focus:border-brand-400 focus:bg-white outline-none font-semibold text-slate-700 transition-colors"
            />
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
            disabled={submitting || !username.trim() || !password}
            className="w-full py-3.5 rounded-2xl bg-brand-500 text-white font-black text-sm uppercase tracking-wide shadow-[0_4px_0_0_#036c4a] active:translate-y-0.5 active:shadow-none transition-all disabled:opacity-50 disabled:cursor-not-allowed disabled:active:translate-y-0 disabled:shadow-[0_4px_0_0_#036c4a]"
          >
            {submitting ? 'Đang đăng nhập…' : 'Đăng nhập'}
          </button>

          <p className="text-xs text-slate-400 text-center font-semibold pt-1">
            Demo backend thật: <span className="text-slate-500">aba_student_1</span> (học sinh) ·{' '}
            <span className="text-slate-500">aba_parent_1</span> (phụ huynh) ·{' '}
            <span className="text-slate-500">aba_teacher_1</span> (giáo viên) — mật khẩu{' '}
            <span className="text-slate-500">Aba@Demo2026</span>.
          </p>
        </form>
      </motion.div>
    </div>
  );
}
