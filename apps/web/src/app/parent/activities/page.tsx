'use client';

import React from 'react';
import { Card, Badge } from '@aba/ui';
import { Activity, Clock, CheckCircle2, BookOpen, Award } from 'lucide-react';

export default function ParentActivitiesPage() {
  const activities = [
    {
      id: 1,
      title: 'Hoàn thành bài học: Nhận biết tín hiệu cảm xúc',
      type: 'lesson',
      timestamp: 'Hôm nay, 14:32',
      xpEarned: 30,
      score: '100%',
    },
    {
      id: 2,
      title: 'Làm trọn vẹn Nhiệm vụ Hàng ngày',
      type: 'mission',
      timestamp: 'Hôm qua, 19:15',
      xpEarned: 25,
    },
    {
      id: 3,
      title: 'Đạt danh hiệu: Chiến Binh Bền Bỉ (Chuỗi 7 ngày)',
      type: 'badge',
      timestamp: '18/10/2026',
      xpEarned: 50,
    },
  ];

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-black tracking-tight text-slate-800 dark:text-slate-100">
          Nhật Ký Hoạt Động Của Con
        </h1>
        <p className="text-sm text-slate-500 dark:text-slate-400 mt-1">
          Lịch sử thời gian thực các bài học, thử thách và thành tích con vừa hoàn thành.
        </p>
      </div>

      <div className="space-y-3">
        {activities.map((act) => (
          <Card key={act.id} className="p-4 flex items-center justify-between gap-4">
            <div className="flex items-center gap-3">
              <div className="w-10 h-10 rounded-xl bg-emerald-50 dark:bg-emerald-950/40 text-emerald-600 flex items-center justify-center shrink-0">
                {act.type === 'lesson' ? (
                  <BookOpen className="w-5 h-5" />
                ) : act.type === 'badge' ? (
                  <Award className="w-5 h-5" />
                ) : (
                  <CheckCircle2 className="w-5 h-5" />
                )}
              </div>

              <div>
                <h3 className="text-xs font-bold text-slate-800 dark:text-slate-100">
                  {act.title}
                </h3>
                <span className="text-[11px] text-slate-400 flex items-center gap-1 mt-0.5">
                  <Clock className="w-3 h-3" /> {act.timestamp}
                </span>
              </div>
            </div>

            <div className="text-right">
              <span className="text-xs font-extrabold text-emerald-600">
                +{act.xpEarned} XP
              </span>
              {act.score && (
                <div className="text-[10px] text-slate-400">Điểm: {act.score}</div>
              )}
            </div>
          </Card>
        ))}
      </div>
    </div>
  );
}
