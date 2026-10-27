'use client';

import React, { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { api } from '@aba/api-client';
import { Card, Button, ProgressBar } from '@aba/ui';
import { CheckSquare, Zap, Clock, CheckCircle2, Sparkles, Target } from 'lucide-react';

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
    return (
      <div className="grid gap-4 sm:grid-cols-2">
        {[0, 1, 2, 3].map((i) => (
          <div key={i} className="h-40 rounded-2xl bg-slate-100 animate-pulse" />
        ))}
      </div>
    );
  }

  const daily = missions?.filter((m) => m.type === 'daily') ?? [];
  const weekly = missions?.filter((m) => m.type === 'weekly') ?? [];

  return (
    <div className="space-y-8">
      <div className="flex items-end justify-between">
        <div>
          <div className="flex items-center gap-2 text-[11px] font-black uppercase tracking-widest text-brand-500 mb-1">
            <Target className="w-4 h-4" /> Gamification
          </div>
          <h1 className="text-2xl sm:text-3xl font-black tracking-tight text-slate-800">Nhiệm vụ</h1>
          <p className="text-sm text-slate-500 mt-1">Hoàn thành thử thách để nhận XP — mọi phần thưởng ghi vào sổ cái XP Ledger.</p>
        </div>
        <div className="hidden sm:flex items-center gap-1.5 px-3 py-2 rounded-2xl bg-sky-50 text-sky-700 text-xs font-black border border-sky-100">
          <Clock className="w-4 h-4" /> Làm mới sau 14h
        </div>
      </div>

      <MissionGroup title="Hàng ngày" missions={daily} claimedMap={claimedMap} onClaim={(id) => claimMutation.mutate(id)} pending={claimMutation.isPending} />
      {weekly.length > 0 && (
        <MissionGroup title="Tuần này" missions={weekly} claimedMap={claimedMap} onClaim={(id) => claimMutation.mutate(id)} pending={claimMutation.isPending} />
      )}
    </div>
  );
}

function MissionGroup({
  title,
  missions,
  claimedMap,
  onClaim,
  pending,
}: {
  title: string;
  missions: any[];
  claimedMap: Record<number, boolean>;
  onClaim: (id: number) => void;
  pending: boolean;
}) {
  return (
    <section className="space-y-3">
      <h2 className="text-sm font-black uppercase tracking-widest text-slate-400">{title}</h2>
      <div className="grid gap-4 sm:grid-cols-2">
        {missions.map((mission) => {
          const isClaimed = claimedMap[mission.id] || false;
          const pct = Math.min(100, Math.round((mission.progress / mission.target) * 100));
          return (
            <Card key={mission.id} hover className="p-5">
              <div className="flex items-start justify-between gap-3">
                <div className="flex items-center gap-3">
                  <div className="w-11 h-11 rounded-2xl bg-brand-50 flex items-center justify-center text-brand-600 shrink-0">
                    <CheckSquare className="w-6 h-6" strokeWidth={2.4} />
                  </div>
                  <div>
                    <h3 className="font-black text-slate-800 text-sm leading-tight">{mission.title}</h3>
                    <p className="text-xs text-slate-400 mt-0.5">{mission.description}</p>
                  </div>
                </div>
                <span className="inline-flex items-center gap-1 text-xs font-black text-co shrink-0" style={{ color: '#1cb0f6' }}>
                  <Zap className="w-4 h-4 fill-co" /> +{mission.xpReward}
                </span>
              </div>

              <div className="mt-4">
                <div className="flex justify-between text-[11px] font-bold text-slate-400 mb-1.5">
                  <span>Tiến độ</span>
                  <span>{mission.progress}/{mission.target} · {pct}%</span>
                </div>
                <ProgressBar value={pct} color={pct === 100 ? '#0aa96f' : '#1cb0f6'} />
              </div>

              <div className="mt-4">
                {isClaimed ? (
                  <div className="flex items-center justify-center gap-1.5 text-sm font-black text-brand-600 py-2">
                    <CheckCircle2 className="w-5 h-5" /> Đã nhận thưởng
                  </div>
                ) : mission.isCompleted ? (
                  <Button block onClick={() => onClaim(mission.id)} disabled={pending} className="animate-pulse">
                    <Sparkles className="w-4 h-4" /> Nhận thưởng
                  </Button>
                ) : (
                  <Button block variant="outline" disabled>Đang thực hiện</Button>
                )}
              </div>
            </Card>
          );
        })}
      </div>
    </section>
  );
}
