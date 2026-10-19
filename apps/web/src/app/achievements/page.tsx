'use client';

import React from 'react';
import { useQuery } from '@tanstack/react-query';
import { api } from '@aba/api-client';
import { Card, Badge as UiBadge } from '@aba/ui';
import { Award, Flame, Zap, ShieldCheck, Lock } from 'lucide-react';

export default function AchievementsPage() {
  const { data: badges, isLoading } = useQuery({
    queryKey: ['gamification-badges'],
    queryFn: () => api.gamification.getBadges(),
  });

  const { data: student } = useQuery({
    queryKey: ['student-me'],
    queryFn: () => api.student.getMe(),
  });

  if (isLoading) {
    return <div className="p-8 text-center text-slate-400">Đang tải thành tích...</div>;
  }

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-black tracking-tight text-slate-800 dark:text-slate-100">
          Huy Hiệu & Thành Tích
        </h1>
        <p className="text-sm text-slate-500 dark:text-slate-400 mt-1">
          Bộ sưu tập các danh hiệu bạn đã mở khóa qua các mốc học tập.
        </p>
      </div>

      {/* Summary Stats */}
      <div className="grid grid-cols-3 gap-3">
        <Card className="p-4 text-center">
          <div className="text-2xl font-black text-amber-500">
            {student?.currentStreak || 7} 🔥
          </div>
          <div className="text-[11px] font-semibold text-slate-400 mt-1">
            Chuỗi ngày học
          </div>
        </Card>
        <Card className="p-4 text-center">
          <div className="text-2xl font-black text-emerald-500">
            {student?.totalXp || 1450} ⚡
          </div>
          <div className="text-[11px] font-semibold text-slate-400 mt-1">
            Tổng XP tích lũy
          </div>
        </Card>
        <Card className="p-4 text-center">
          <div className="text-2xl font-black text-indigo-500">
            {badges?.filter((b) => b.isUnlocked).length || 2} / {badges?.length || 3} 🏆
          </div>
          <div className="text-[11px] font-semibold text-slate-400 mt-1">
            Huy hiệu mở khóa
          </div>
        </Card>
      </div>

      {/* Badges Grid */}
      <div className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-4">
        {badges?.map((badge) => (
          <Card
            key={badge.id}
            className={`p-5 flex flex-col items-center text-center relative overflow-hidden transition-all ${
              badge.isUnlocked
                ? 'border-emerald-200/60 dark:border-emerald-800/40 shadow-sm'
                : 'opacity-60 bg-slate-50 dark:bg-slate-900/40'
            }`}
          >
            <div
              className={`w-16 h-16 rounded-2xl flex items-center justify-center mb-3 shadow-md ${
                badge.isUnlocked
                  ? 'bg-gradient-to-tr from-amber-400 to-yellow-500 text-white'
                  : 'bg-slate-200 dark:bg-slate-800 text-slate-400'
              }`}
            >
              {badge.isUnlocked ? (
                badge.icon === 'flame' ? (
                  <Flame className="w-8 h-8 fill-white" />
                ) : badge.icon === 'zap' ? (
                  <Zap className="w-8 h-8 fill-white" />
                ) : (
                  <Award className="w-8 h-8" />
                )
              ) : (
                <Lock className="w-7 h-7" />
              )}
            </div>

            <h3 className="font-bold text-sm text-slate-800 dark:text-slate-100">
              {badge.name}
            </h3>
            <p className="text-xs text-slate-500 dark:text-slate-400 mt-1">
              {badge.description}
            </p>

            <div className="mt-4 pt-3 border-t border-slate-100 dark:border-slate-800 w-full">
              <UiBadge variant={badge.isUnlocked ? 'success' : 'default'}>
                {badge.isUnlocked ? 'Đã đạt được' : 'Chưa mở khóa'}
              </UiBadge>
            </div>
          </Card>
        ))}
      </div>
    </div>
  );
}
