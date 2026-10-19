'use client';

import React from 'react';
import Link from 'next/link';
import { usePathname } from 'next/navigation';
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
} from 'lucide-react';

export function Navigation() {
  const pathname = usePathname();
  const isParentMode = pathname?.startsWith('/parent');

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

  const currentLinks = isParentMode ? parentLinks : studentLinks;

  return (
    <>
      {/* Top Header */}
      <header className="sticky top-0 z-40 bg-white/80 dark:bg-slate-900/80 backdrop-blur-md border-b border-slate-200 dark:border-slate-800">
        <div className="max-w-6xl mx-auto px-4 h-16 flex items-center justify-between">
          <div className="flex items-center gap-3">
            <Link href={isParentMode ? '/parent' : '/learn'} className="flex items-center gap-2.5">
              <div className="w-10 h-10 rounded-xl bg-gradient-to-tr from-emerald-500 to-teal-400 flex items-center justify-center text-white font-bold text-lg shadow-md shadow-emerald-500/20">
                ABA
              </div>
              <div>
                <span className="font-extrabold text-lg tracking-tight bg-gradient-to-r from-emerald-600 to-teal-600 bg-clip-text text-transparent">
                  ABA Skills 360
                </span>
                <span className="block text-[10px] font-semibold tracking-wider text-slate-400 uppercase">
                  {isParentMode ? 'Cổng Phụ Huynh' : 'Cổng Học Sinh'}
                </span>
              </div>
            </Link>
          </div>

          <div className="flex items-center gap-4">
            {/* Student Stats Pill (if student mode) */}
            {!isParentMode && (
              <div className="hidden sm:flex items-center gap-3 bg-slate-100 dark:bg-slate-800/80 px-3 py-1.5 rounded-full text-xs font-bold">
                <span className="flex items-center gap-1 text-amber-500">
                  <Flame className="w-4 h-4 fill-amber-500" />
                  7 Ngày
                </span>
                <span className="w-1 h-3 bg-slate-300 dark:bg-slate-700 rounded-full" />
                <span className="flex items-center gap-1 text-emerald-600 dark:text-emerald-400">
                  <Zap className="w-4 h-4 fill-emerald-500" />
                  1,450 XP
                </span>
              </div>
            )}

            {/* Role Mode Switcher */}
            <div className="flex items-center bg-slate-100 dark:bg-slate-800 p-1 rounded-xl text-xs font-semibold">
              <Link
                href="/learn"
                className={`px-3 py-1.5 rounded-lg transition-all ${
                  !isParentMode
                    ? 'bg-white dark:bg-slate-700 text-emerald-600 dark:text-emerald-400 shadow-sm'
                    : 'text-slate-500 hover:text-slate-900 dark:hover:text-slate-100'
                }`}
              >
                Học sinh
              </Link>
              <Link
                href="/parent"
                className={`px-3 py-1.5 rounded-lg transition-all ${
                  isParentMode
                    ? 'bg-white dark:bg-slate-700 text-emerald-600 dark:text-emerald-400 shadow-sm'
                    : 'text-slate-500 hover:text-slate-900 dark:hover:text-slate-100'
                }`}
              >
                Phụ huynh
              </Link>
            </div>
          </div>
        </div>
      </header>

      {/* Bottom Floating Navigation Bar */}
      <nav className="fixed bottom-0 left-0 right-0 z-40 bg-white/90 dark:bg-slate-900/90 backdrop-blur-md border-t border-slate-200 dark:border-slate-800 shadow-lg">
        <div className="max-w-md mx-auto px-4 h-16 flex items-center justify-around">
          {currentLinks.map((item) => {
            const Icon = item.icon;
            const isActive = pathname === item.href;
            return (
              <Link
                key={item.href}
                href={item.href}
                className={`flex flex-col items-center justify-center w-14 h-14 rounded-2xl transition-all duration-200 ${
                  isActive
                    ? 'text-emerald-600 dark:text-emerald-400 font-bold scale-105'
                    : 'text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 font-medium'
                }`}
              >
                <Icon className={`w-5 h-5 ${isActive ? 'stroke-[2.5]' : 'stroke-2'}`} />
                <span className="text-[11px] mt-1">{item.label}</span>
              </Link>
            );
          })}
        </div>
      </nav>
    </>
  );
}
