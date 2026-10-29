'use client';

import React from 'react';
import Link from 'next/link';
import { usePathname, useRouter } from 'next/navigation';
import { useQuery } from '@tanstack/react-query';
import { api } from '@aba/api-client';
import { useAuthStore } from '../features/auth/store';
import {
  BookOpen,
  Compass,
  CheckSquare,
  Award,
  User as UserIcon,
  LayoutDashboard,
  TrendingUp,
  FileText,
  Activity,
  Flame,
  Zap,
  Gem,
  LogOut,
} from 'lucide-react';

const studentLinks = [
  { href: '/learn', label: 'Học', icon: BookOpen },
  { href: '/explore', label: 'Khám phá', icon: Compass },
  { href: '/missions', label: 'Nhiệm vụ', icon: CheckSquare },
  { href: '/achievements', label: 'Thành tích', icon: Award },
  { href: '/profile', label: 'Hồ sơ', icon: UserIcon },
];

const parentLinks = [
  { href: '/parent', label: 'Tổng quan', icon: LayoutDashboard },
  { href: '/parent/skills', label: 'Năng lực', icon: TrendingUp },
  { href: '/parent/reports', label: 'Báo cáo', icon: FileText },
  { href: '/parent/activities', label: 'Hoạt động', icon: Activity },
  { href: '/parent/profile', label: 'Hồ sơ', icon: UserIcon },
];

function Logo() {
  return (
    <div className="flex items-center gap-2.5">
      <div className="w-10 h-10 rounded-2xl bg-brand-500 shadow-[0_4px_0_0_#036c4a] flex items-center justify-center text-white font-black text-base">
        AB
      </div>
      <div className="leading-tight">
        <span className="block font-black text-[17px] tracking-tight text-slate-800">ABA Skills 360</span>
        <span className="block text-[10px] font-extrabold tracking-widest text-brand-500 uppercase">Năng lực · Cá nhân hóa</span>
      </div>
    </div>
  );
}

export function Navigation() {
  const pathname = usePathname();
  // Auth screens are full-bleed; the app chrome (and its session-derived stats)
  // only belongs once the learner/parent is inside the product (PA9).
  const isAuthScreen = pathname?.startsWith('/login') ?? false;
  const isParentMode = pathname?.startsWith('/parent');

  const { data: student } = useQuery({
    queryKey: ['student-me'],
    queryFn: () => api.student.getMe(),
    enabled: !isParentMode && !isAuthScreen,
  });

  if (isAuthScreen) return null;
  const currentLinks = isParentMode ? parentLinks : studentLinks;

  const stats = {
    streak: student?.currentStreak ?? 0,
    xp: student?.totalXp ?? 0,
    level: student?.level ?? 1,
  };

  return (
    <>
      {/* ============ Desktop left sidebar ============ */}
      <aside className="hidden lg:flex fixed inset-y-0 left-0 w-72 flex-col border-r border-black/5 bg-white z-40">
        <div className="h-20 px-6 flex items-center">
          <Link href={isParentMode ? '/parent' : '/learn'}>
            <Logo />
          </Link>
        </div>

        <nav className="flex-1 px-4 space-y-1.5 overflow-y-auto no-scrollbar">
          {currentLinks.map((item) => {
            const Icon = item.icon;
            const isActive = pathname === item.href;
            return (
              <Link
                key={item.href}
                href={item.href}
                className={`group flex items-center gap-3 px-4 py-3 rounded-2xl font-extrabold text-sm uppercase tracking-wide transition-all ${
                  isActive
                    ? 'bg-brand-50 text-brand-700 shadow-[inset_0_0_0_2px_#9dead0]'
                    : 'text-slate-500 hover:bg-slate-50 hover:text-slate-700'
                }`}
              >
                <Icon
                  className={`w-6 h-6 transition-transform group-hover:scale-110 ${isActive ? 'text-brand-500' : 'text-slate-400'}`}
                  strokeWidth={2.4}
                />
                {item.label}
              </Link>
            );
          })}
        </nav>

        <div className="p-4 space-y-3">
          <SessionControls />
          <RoleSwitcher isParentMode={isParentMode} className="w-full" />
        </div>
      </aside>

      {/* ============ Top bar (sticky) ============ */}
      <header className="fixed top-0 inset-x-0 z-30 h-16 bg-white/85 backdrop-blur-md border-b border-black/5 lg:pl-72">
        <div className="h-full px-4 sm:px-6 flex items-center justify-between gap-4">
          {/* Mobile logo */}
          <Link href={isParentMode ? '/parent' : '/learn'} className="lg:hidden">
            <Logo />
          </Link>

          {/* Stats strip */}
          {!isParentMode ? (
            <div className="flex items-center gap-2 sm:gap-3">
              <StatChip icon={<Flame className="w-5 h-5 fill-sm" />} value={stats.streak} label="Chuỗi" color="#ffae00" />
              <StatChip icon={<Zap className="w-5 h-5 fill-co" />} value={stats.xp.toLocaleString('vi-VN')} label="XP" color="#1cb0f6" />
              <StatChip icon={<Gem className="w-5 h-5 fill-sa" />} value={stats.level} label="Cấp" color="#7c5cff" />
            </div>
          ) : (
            <div className="text-sm font-black uppercase tracking-wide text-slate-400 lg:hidden">Cổng phụ huynh</div>
          )}

          {/* Mobile role switch */}
          <div className="lg:hidden">
            <RoleSwitcher isParentMode={isParentMode} compact />
          </div>
        </div>
      </header>

      {/* ============ Mobile bottom navigation ============ */}
      <nav className="lg:hidden fixed bottom-0 inset-x-0 z-40 bg-white/95 backdrop-blur-md border-t border-black/5 shadow-[0_-4px_20px_-8px_rgba(0,0,0,0.15)]">
        <div className="px-2 h-16 flex items-stretch justify-around">
          {currentLinks.map((item) => {
            const Icon = item.icon;
            const isActive = pathname === item.href;
            return (
              <Link
                key={item.href}
                href={item.href}
                className={`flex flex-col items-center justify-center gap-0.5 flex-1 rounded-xl my-1.5 transition-colors ${
                  isActive ? 'text-brand-600' : 'text-slate-400'
                }`}
              >
                <Icon className={`w-6 h-6 ${isActive ? 'stroke-[2.6]' : 'stroke-2'}`} />
                <span className="text-[10px] font-bold">{item.label}</span>
              </Link>
            );
          })}
        </div>
      </nav>
    </>
  );
}

function StatChip({ icon, value, label, color }: { icon: React.ReactNode; value: React.ReactNode; label: string; color: string }) {
  return (
    <div
      className="flex items-center gap-1.5 px-2.5 sm:px-3 py-1.5 rounded-xl font-black text-sm"
      style={{ color, backgroundColor: `${color}14` }}
      title={label}
    >
      {icon}
      <span className="tabular-nums">{value}</span>
      <span className="hidden sm:inline text-[11px] uppercase tracking-wide opacity-70">{label}</span>
    </div>
  );
}

function SessionControls() {
  const router = useRouter();
  const user = useAuthStore((s) => s.user);
  const logout = useAuthStore((s) => s.logout);

  if (!user) {
    return (
      <Link
        href="/login"
        className="flex items-center justify-center gap-2 px-4 py-2.5 rounded-2xl bg-brand-500 text-white font-extrabold text-sm uppercase tracking-wide shadow-[0_4px_0_0_#036c4a] active:translate-y-0.5 active:shadow-none transition-all"
      >
        Đăng nhập
      </Link>
    );
  }

  const handleLogout = async () => {
    await logout();
    router.push('/login');
  };

  return (
    <div className="flex items-center gap-3 px-2 py-2 rounded-2xl bg-slate-50">
      <div className="w-9 h-9 rounded-full bg-brand-100 text-brand-700 flex items-center justify-center font-black text-sm shrink-0">
        {user.displayName?.trim()?.charAt(0)?.toUpperCase() ?? '?'}
      </div>
      <div className="min-w-0 flex-1 leading-tight">
        <span className="block truncate font-extrabold text-sm text-slate-700">{user.displayName}</span>
        <span className="block truncate text-[11px] font-bold uppercase tracking-wide text-slate-400">
          {user.roles?.includes('parent') ? 'Phụ huynh' : 'Học sinh'}
        </span>
      </div>
      <button
        type="button"
        onClick={handleLogout}
        title="Đăng xuất"
        aria-label="Đăng xuất"
        className="p-2 rounded-xl text-slate-400 hover:text-rose-500 hover:bg-white transition-colors"
      >
        <LogOut className="w-5 h-5" />
      </button>
    </div>
  );
}

function RoleSwitcher({ isParentMode, className = '', compact = false }: { isParentMode: boolean; className?: string; compact?: boolean }) {
  const pad = compact ? 'px-2.5 py-1.5 text-[11px]' : 'px-3 py-2 text-xs';
  return (
    <div className={`inline-flex items-center bg-slate-100 p-1 rounded-2xl font-extrabold ${className}`}>
      <Link
        href="/learn"
        className={`flex-1 text-center rounded-xl transition-all ${pad} ${
          !isParentMode ? 'bg-white text-brand-600 shadow-sm' : 'text-slate-500'
        }`}
      >
        Học sinh
      </Link>
      <Link
        href="/parent"
        className={`flex-1 text-center rounded-xl transition-all ${pad} ${
          isParentMode ? 'bg-white text-brand-600 shadow-sm' : 'text-slate-500'
        }`}
      >
        Phụ huynh
      </Link>
    </div>
  );
}
