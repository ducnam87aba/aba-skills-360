'use client';

import React from 'react';
import { useQuery } from '@tanstack/react-query';
import { api } from '@aba/api-client';
import { Card, Badge, Button } from '@aba/ui';
import { CheckCircle2, Lock, Play, Star, Sparkles, Trophy } from 'lucide-react';
import Link from 'next/link';

export default function LearnPage() {
  const { data: path, isLoading } = useQuery({
    queryKey: ['learning-path'],
    queryFn: () => api.learning.getPath(1),
  });

  const { data: student } = useQuery({
    queryKey: ['student-me'],
    queryFn: () => api.student.getMe(),
  });

  if (isLoading) {
    return (
      <div className="space-y-6 animate-pulse">
        <div className="h-28 bg-slate-200 dark:bg-slate-800 rounded-3xl" />
        <div className="h-64 bg-slate-200 dark:bg-slate-800 rounded-3xl" />
      </div>
    );
  }

  return (
    <div className="space-y-8">
      {/* Hero Banner with Progression */}
      <div className="relative overflow-hidden rounded-3xl bg-gradient-to-r from-emerald-600 to-teal-700 text-white p-6 sm:p-8 shadow-xl shadow-emerald-900/10">
        <div className="relative z-10 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
          <div>
            <div className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-white/20 backdrop-blur-md text-xs font-semibold mb-2">
              <Sparkles className="w-3.5 h-3.5" /> Lộ trình cá nhân hoá
            </div>
            <h1 className="text-2xl sm:text-3xl font-black tracking-tight">
              Xin chào, {student?.firstName || 'Bạn'}! 🚀
            </h1>
            <p className="text-emerald-100 text-sm mt-1 max-w-md">
              Hôm nay hãy tiếp tục hoàn thành bài tập giao tiếp để giữ vững chuỗi{' '}
              <strong className="text-white">{student?.currentStreak || 7} ngày</strong> nhé!
            </p>
          </div>

          <div className="flex items-center gap-3 bg-white/10 backdrop-blur-md p-3 rounded-2xl border border-white/20">
            <div className="w-12 h-12 rounded-xl bg-amber-400/20 flex items-center justify-center text-amber-300">
              <Trophy className="w-6 h-6" />
            </div>
            <div>
              <div className="text-xs text-emerald-100 font-medium">Cấp độ hiện tại</div>
              <div className="text-lg font-bold text-white">Level {student?.level || 8}</div>
            </div>
          </div>
        </div>

        {/* Decorative circle */}
        <div className="absolute -right-10 -bottom-10 w-44 h-44 rounded-full bg-white/10 blur-2xl pointer-events-none" />
      </div>

      {/* Path Units */}
      {path?.units.map((unit, unitIdx) => (
        <div key={unit.id} className="space-y-4">
          <div className="flex items-center justify-between">
            <div>
              <h2 className="text-lg font-bold text-slate-800 dark:text-slate-100">
                {unit.title}
              </h2>
              <p className="text-xs text-slate-500 dark:text-slate-400 mt-0.5">
                {unit.description}
              </p>
            </div>
            <Badge variant={unit.status === 'active' ? 'success' : 'default'}>
              {unit.status === 'active' ? 'Đang học' : 'Chưa mở'}
            </Badge>
          </div>

          {/* Node path chain (Duolingo progression style) */}
          <Card className="p-6 relative overflow-hidden bg-white/80 dark:bg-slate-900/80 backdrop-blur-sm">
            <div className="flex flex-col items-center gap-6 max-w-sm mx-auto">
              {unit.nodes.map((node, nodeIdx) => {
                const isCompleted = node.status === 'completed';
                const isUnlocked = node.status === 'unlocked';
                const isLocked = node.status === 'locked';

                // Alternating offset for playful path feel
                const xOffset =
                  nodeIdx % 3 === 0 ? 'translate-x-0' : nodeIdx % 3 === 1 ? 'translate-x-8' : '-translate-x-8';

                return (
                  <div key={node.id} className={`flex flex-col items-center ${xOffset} transition-all`}>
                    <div className="relative group">
                      {isCompleted && (
                        <div className="w-16 h-16 rounded-2xl bg-emerald-500 hover:bg-emerald-400 text-white flex items-center justify-center shadow-lg shadow-emerald-500/30 cursor-pointer transition-transform hover:scale-110 active:scale-95">
                          <CheckCircle2 className="w-8 h-8" />
                        </div>
                      )}

                      {isUnlocked && (
                        <div className="w-16 h-16 rounded-2xl bg-gradient-to-tr from-amber-500 to-yellow-400 text-white flex items-center justify-center shadow-lg shadow-amber-500/30 cursor-pointer transition-transform animate-bounce hover:scale-110 active:scale-95">
                          <Play className="w-7 h-7 fill-white translate-x-0.5" />
                        </div>
                      )}

                      {isLocked && (
                        <div className="w-16 h-16 rounded-2xl bg-slate-200 dark:bg-slate-800 text-slate-400 flex items-center justify-center cursor-not-allowed">
                          <Lock className="w-6 h-6" />
                        </div>
                      )}

                      {/* Floating XP Tag */}
                      <span className="absolute -top-2 -right-3 px-2 py-0.5 text-[10px] font-bold rounded-full bg-slate-900 text-amber-400 shadow">
                        +{node.xpReward} XP
                      </span>
                    </div>

                    <div className="text-center mt-2 max-w-[160px]">
                      <div className="text-xs font-bold text-slate-800 dark:text-slate-200 line-clamp-1">
                        {node.title}
                      </div>
                      <div className="text-[10px] text-slate-400">
                        {isCompleted ? 'Hoàn thành 100%' : isUnlocked ? 'Bắt đầu ngay' : 'Đã khóa'}
                      </div>
                    </div>
                  </div>
                );
              })}
            </div>
          </Card>
        </div>
      ))}
    </div>
  );
}
