'use client';

import React from 'react';
import { useQuery } from '@tanstack/react-query';
import { api } from '@aba/api-client';
import { Card, Badge, SectionHeader } from '@aba/ui';
import { Zap, BookOpen, Award, CheckCircle2 } from 'lucide-react';
import type { ParentActivityType } from '@aba/types';

const TYPE_META: Record<ParentActivityType, { icon: React.ReactNode; color: string; label: string }> = {
  lesson: { icon: <BookOpen className="w-5 h-5" />, color: '#1cb0f6', label: 'Bài học' },
  mission: { icon: <CheckCircle2 className="w-5 h-5" />, color: '#0aa96f', label: 'Nhiệm vụ' },
  badge: { icon: <Award className="w-5 h-5" />, color: '#7c5cff', label: 'Thành tích' },
};

// PA40: the feed is derived server-side from the progress/XP ledgers and the
// achievement timeline (AGENTS.md §12) — the page renders what the API returns
// and never ships hardcoded activity content.
function formatOccurredAt(iso: string | null): string {
  if (!iso) return '';
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return '';
  return d.toLocaleDateString('vi-VN', { day: '2-digit', month: '2-digit', year: 'numeric' })
    + ', ' + d.toLocaleTimeString('vi-VN', { hour: '2-digit', minute: '2-digit' });
}

export default function ParentActivitiesPage() {
  // Resolve the child from the parent link instead of hardcoding an id, so the
  // same page is correct against the real (permission-checked) backend.
  const { data: children, isLoading: isChildrenLoading } = useQuery({
    queryKey: ['parent-children'],
    queryFn: () => api.parent.getChildren(),
  });
  const childId = children?.[0]?.id;

  const { data: feed, isLoading: isFeedLoading } = useQuery({
    queryKey: ['parent-activities', childId],
    queryFn: () => (childId ? api.parent.getActivities(childId) : Promise.resolve(null)),
    enabled: !!childId,
  });

  if (isChildrenLoading || (childId && isFeedLoading)) {
    return <div className="p-8 text-center text-slate-400 font-bold">Đang tải nhật ký hoạt động...</div>;
  }

  const items = feed?.items ?? [];

  return (
    <div className="space-y-6">
      <SectionHeader
        eyebrow="Thời gian thực"
        title="Nhật ký hoạt động của con"
        description="Các bài học, nhiệm vụ và thành tích con vừa hoàn thành gần đây."
        action={
          <Badge variant="success">
            <Zap className="w-3.5 h-3.5" /> +{feed?.weeklyXp ?? 0} XP tuần này
          </Badge>
        }
      />

      {items.length === 0 ? (
        <Card className="p-8 text-center text-slate-400 font-bold">
          Con chưa có hoạt động nào được ghi nhận. Khi con hoàn thành bài học, nhiệm vụ hoặc đạt danh
          hiệu, nhật ký của con sẽ xuất hiện tại đây.
        </Card>
      ) : (
        <Card className="p-6">
          <ol className="relative space-y-2">
            {/* vertical connector */}
            <span className="absolute left-[23px] top-4 bottom-4 w-0.5 bg-slate-100 rounded-full" aria-hidden />
            {items.map((act) => {
              const meta = TYPE_META[act.type];
              return (
                <li key={act.id} className="relative flex items-center gap-4 py-2.5">
                  <span
                    className="relative z-10 w-12 h-12 rounded-2xl flex items-center justify-center text-white shrink-0 shadow-[0_4px_0_0_rgba(0,0,0,0.12)]"
                    style={{ backgroundColor: meta.color }}
                  >
                    {meta.icon}
                  </span>
                  <div className="min-w-0 flex-1">
                    <div className="flex items-center gap-2 flex-wrap">
                      <Badge variant="default">{meta.label}</Badge>
                      <span className="text-[11px] font-bold text-slate-400">{formatOccurredAt(act.occurredAt)}</span>
                    </div>
                    <h3 className="text-sm font-black text-slate-800 mt-1 leading-tight">{act.title}</h3>
                  </div>
                  <div className="text-right shrink-0">
                    {act.xpEarned !== null && (
                      <div className="inline-flex items-center gap-1 font-black text-co text-sm">
                        <Zap className="w-4 h-4 fill-co" /> +{act.xpEarned}
                      </div>
                    )}
                    {act.score !== null && (
                      <div className="text-[11px] font-bold text-slate-400 mt-0.5">Điểm {act.score}%</div>
                    )}
                  </div>
                </li>
              );
            })}
          </ol>
        </Card>
      )}
    </div>
  );
}
