'use client';

import React, { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { api } from '@aba/api-client';
import { Card, Badge, Button } from '@aba/ui';
import { CheckSquare, Zap, Clock, CheckCircle2, Sparkles } from 'lucide-react';

export default function MissionsPage() {
  const queryClient = useQueryClient();
  const [claimedMap, setClaimedMap] = useState<Record<number, boolean>>({});

  const { data: missions, isLoading } = useQuery({
    queryKey: ['gamification-missions'],
    queryFn: () => api.gamification.getMissions(),
  });

  const claimMutation = useMutation({
    mutationFn: (missionId: number) => api.gamification.claimMission(missionId),
    onSuccess: (_, missionId) => {
      setClaimedMap((prev) => ({ ...prev, [missionId]: true }));
      queryClient.invalidateQueries({ queryKey: ['gamification-missions'] });
      queryClient.invalidateQueries({ queryKey: ['student-me'] });
    },
  });

  if (isLoading) {
    return <div className="p-8 text-center text-slate-400">Đang tải nhiệm vụ...</div>;
  }

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-black tracking-tight text-slate-800 dark:text-slate-100">
            Nhiệm Vụ Hàng Ngày
          </h1>
          <p className="text-sm text-slate-500 dark:text-slate-400 mt-1">
            Hoàn thành các thử thách để nhận thêm điểm thưởng XP và ghi nhận vào sổ cái Gamification.
          </p>
        </div>

        <div className="flex items-center gap-1.5 px-3 py-1.5 rounded-full bg-amber-50 dark:bg-amber-950/40 text-amber-700 dark:text-amber-300 text-xs font-bold border border-amber-200/50">
          <Clock className="w-4 h-4" /> Làm mới sau 14h
        </div>
      </div>

      <div className="space-y-3">
        {missions?.map((mission) => {
          const isClaimed = claimedMap[mission.id] || false;
          const progressPercent = Math.min(
            100,
            Math.round((mission.progress / mission.target) * 100)
          );

          return (
            <Card
              key={mission.id}
              className="p-5 flex flex-col sm:flex-row sm:items-center justify-between gap-4"
            >
              <div className="space-y-2 flex-1">
                <div className="flex items-center gap-2">
                  <Badge variant={mission.type === 'daily' ? 'blue' : 'purple'}>
                    {mission.type === 'daily' ? 'Hàng ngày' : 'Tuần này'}
                  </Badge>
                  <h3 className="font-bold text-slate-800 dark:text-slate-100 text-sm">
                    {mission.title}
                  </h3>
                </div>

                <p className="text-xs text-slate-500 dark:text-slate-400">
                  {mission.description}
                </p>

                {/* Progress Bar */}
                <div className="space-y-1">
                  <div className="flex justify-between text-[11px] font-semibold text-slate-400">
                    <span>Tiến độ</span>
                    <span>
                      {mission.progress} / {mission.target} ({progressPercent}%)
                    </span>
                  </div>
                  <div className="w-full h-2 bg-slate-100 dark:bg-slate-800 rounded-full overflow-hidden">
                    <div
                      className="h-full bg-emerald-500 rounded-full transition-all duration-500"
                      style={{ width: `${progressPercent}%` }}
                    />
                  </div>
                </div>
              </div>

              <div className="flex items-center sm:flex-col justify-between sm:justify-center gap-2 sm:min-w-[140px]">
                <div className="flex items-center gap-1 text-emerald-600 dark:text-emerald-400 font-extrabold text-sm">
                  <Zap className="w-4 h-4 fill-emerald-500" />
                  +{mission.xpReward} XP
                </div>

                {isClaimed ? (
                  <div className="flex items-center gap-1 text-xs font-bold text-emerald-600 dark:text-emerald-400">
                    <CheckCircle2 className="w-4 h-4" /> Đã nhận thưởng
                  </div>
                ) : mission.isCompleted ? (
                  <Button
                    size="sm"
                    variant="primary"
                    onClick={() => claimMutation.mutate(mission.id)}
                    disabled={claimMutation.isPending}
                    className="text-xs gap-1 shadow-md shadow-emerald-500/20 animate-pulse"
                  >
                    <Sparkles className="w-3.5 h-3.5" /> Nhận thưởng
                  </Button>
                ) : (
                  <Button size="sm" variant="outline" disabled className="text-xs">
                    Chưa hoàn thành
                  </Button>
                )}
              </div>
            </Card>
          );
        })}
      </div>
    </div>
  );
}
