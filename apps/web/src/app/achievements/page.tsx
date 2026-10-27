'use client';

import React from 'react';
import { useQuery } from '@tanstack/react-query';
import { api } from '@aba/api-client';
import { Card, Badge } from '@aba/ui';
import { Award, Flame, Zap, Lock, Trophy, Medal } from 'lucide-react';

const ICONS: Record<string, any> = { flame: Flame, zap: Zap, award: Award };

export default function AchievementsPage() {
  const { data: achievements, isLoading } = useQuery({
    queryKey: ['gamification-achievements'],
    queryFn: () => api.gamification.getAchievements(),
  });
  const { data: student } = useQuery({
    queryKey: ['student-me'],
    queryFn: () => api.student.getMe(),
  });

  if (isLoading) {
    return <div className="grid grid-cols-2 sm:grid-cols-3 gap-4">{[0, 1, 2].map((i) => <div key={i} className="h-52 rounded-2xl bg-slate-100 animate-pulse" />)}</div>;
  }

  const badges = achievements?.badges ?? [];
  const unlocked = achievements?.unlockedCount ?? badges.filter((b) => b.isUnlocked).length;
  const total = achievements?.totalCount ?? badges.length;

  return (
    <div className="space-y-8">
      <div>
        <div className="flex items-center gap-2 text-[11px] font-black uppercase tracking-widest text-brand-500 mb-1">
          <Trophy className="w-4 h-4" /> Thành tích
        </div>
        <h1 className="text-2xl sm:text-3xl font-black tracking-tight text-slate-800">Huy hiệu & Danh hiệu</h1>
        <p className="text-sm text-slate-500 mt-1">Bộ sưu tập mở khóa qua các cột mốc học tập của em.</p>
      </div>

      {/* Stat tiles */}
      <div className="grid grid-cols-2 sm:grid-cols-4 gap-4">
        <StatTile icon={<Trophy className="w-6 h-6" />} value={student?.level ?? 0} label="Cấp độ" color="#4b6bff" />
        <StatTile icon={<Flame className="w-6 h-6" />} value={student?.currentStreak ?? 0} label="Chuỗi ngày" color="#ffae00" />
        <StatTile icon={<Zap className="w-6 h-6" />} value={(student?.totalXp ?? 0).toLocaleString('vi-VN')} label="Tổng XP" color="#1cb0f6" />
        <StatTile icon={<Medal className="w-6 h-6" />} value={`${unlocked}/${total}`} label="Huy hiệu" color="#0aa96f" />
      </div>

      {/* Badge grid */}
      <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-4">
        {badges.map((badge) => {
          const Icon = ICONS[badge.icon] ?? Award;
          const on = badge.isUnlocked;
          return (
            <Card
              key={badge.id}
              hover={on}
              className={`p-5 flex flex-col items-center text-center ${on ? '' : 'opacity-70 bg-slate-50'}`}
            >
              <div
                className={`w-20 h-20 rounded-full flex items-center justify-center mb-3 ${on ? 'text-white' : 'bg-slate-100 text-slate-300'}`}
                style={on ? { backgroundColor: '#ffae00', boxShadow: '0 5px 0 0 #d98c00' } : undefined}
              >
                {on ? <Icon className="w-9 h-9" /> : <Lock className="w-8 h-8" />}
              </div>
              <h3 className="font-black text-sm text-slate-800 leading-tight">{badge.name}</h3>
              <p className="text-xs text-slate-400 mt-1">{badge.description}</p>
              <div className="mt-3">
                <Badge variant={on ? 'success' : 'default'}>{on ? 'Đã đạt' : 'Chưa mở khóa'}</Badge>
              </div>
              {on && badge.unlockedAt && (
                <div className="text-[10px] text-slate-400 mt-2 font-bold">
                  đạt ngày {new Date(badge.unlockedAt).toLocaleDateString('vi-VN')}
                </div>
              )}
            </Card>
          );
        })}
      </div>

      {/* PA18: chronological achievement timeline */}
      {achievements && achievements.timeline.length > 0 && (
        <div>
          <h2 className="text-lg font-black text-slate-800 mb-3 flex items-center gap-2">
            <Medal className="w-5 h-5 text-brand-500" /> Dòng thời gian thành tích
          </h2>
          <Card className="p-2">
            <ol className="divide-y divide-black/5">
              {achievements.timeline.map((ev, i) => (
                <li key={i} className="flex items-center justify-between gap-3 px-4 py-3">
                  <span className="flex items-center gap-2 text-sm font-bold text-slate-700">
                    {ev.kind === 'badge' ? <Award className="w-4 h-4 text-amber-500" /> : <Trophy className="w-4 h-4 text-brand-500" />}
                    {ev.kind === 'badge' ? ev.title ?? ev.code : `Hoàn thành nhiệm vụ #${ev.missionId}`}
                  </span>
                  <span className="text-xs text-slate-400 font-bold shrink-0">
                    {ev.earnedAt ? new Date(ev.earnedAt).toLocaleDateString('vi-VN') : ''}
                  </span>
                </li>
              ))}
            </ol>
          </Card>
        </div>
      )}
    </div>
  );
}

function StatTile({ icon, value, label, color }: { icon: React.ReactNode; value: React.ReactNode; label: string; color: string }) {
  return (
    <Card className="p-4 flex items-center gap-3">
      <div className="w-12 h-12 rounded-2xl flex items-center justify-center text-white shrink-0" style={{ backgroundColor: color }}>
        {icon}
      </div>
      <div className="min-w-0">
        <div className="text-xl font-black text-slate-800 leading-none tabular-nums">{value}</div>
        <div className="text-[11px] font-bold text-slate-400 mt-1 truncate">{label}</div>
      </div>
    </Card>
  );
}
