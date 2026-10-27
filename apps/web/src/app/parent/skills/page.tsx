'use client';

import React from 'react';
import { useQuery } from '@tanstack/react-query';
import { api } from '@aba/api-client';
import { Card, Badge, SkillRing, ProgressBar, SectionHeader } from '@aba/ui';
import { TrendingUp, Sparkles, Users } from 'lucide-react';
import {
  ResponsiveContainer,
  LineChart,
  Line,
  XAxis,
  YAxis,
  CartesianGrid,
  Tooltip,
  Legend,
} from 'recharts';
import type { CompetencyCode, SkillLevel } from '@aba/types';
import { useParentChildren } from '../../../features/parent/ParentChildrenContext';

const COMPS: { code: CompetencyCode; name: string; color: string }[] = [
  { code: 'SA', name: 'Tự nhận thức', color: '#7c5cff' },
  { code: 'ER', name: 'Quản lý cảm xúc', color: '#ff5d73' },
  { code: 'SM', name: 'Tự quản lý', color: '#ffae00' },
  { code: 'CO', name: 'Giao tiếp', color: '#1cb0f6' },
  { code: 'CE', name: 'Hợp tác & Đồng cảm', color: '#0aa96f' },
  { code: 'PD', name: 'Giải quyết vấn đề', color: '#4b6bff' },
];

const LEVEL_LABEL: Record<SkillLevel, { text: string; variant: 'success' | 'blue' | 'warning' | 'rose' }> = {
  advanced: { text: 'Thành thạo', variant: 'success' },
  proficient: { text: 'Khá tốt', variant: 'blue' },
  developing: { text: 'Đang phát triển', variant: 'warning' },
  beginning: { text: 'Cần rèn luyện', variant: 'rose' },
};

export default function ParentSkillsPage() {
  // The active child comes from the shared parent context, which only ever
  // holds children the permission-checked /parent/children endpoint returned (§8).
  const { activeChildId: childId, isLoading: isChildrenLoading } = useParentChildren();

  const { data: history, isLoading } = useQuery({
    queryKey: ['skills-history', childId],
    queryFn: () => (childId ? api.skills.getHistory(childId) : Promise.resolve([])),
    enabled: !!childId,
  });

  const { data: currentSkills } = useQuery({
    queryKey: ['skills-current', childId],
    queryFn: () => (childId ? api.skills.getCurrent(childId) : Promise.resolve([])),
    enabled: !!childId,
  });

  // Anonymized class benchmark (PA31, §11): the learner vs the class mean.
  const { data: benchmark } = useQuery({
    queryKey: ['skills-benchmark', childId],
    queryFn: () => (childId ? api.skills.getBenchmark(childId) : Promise.resolve(null)),
    enabled: !!childId,
  });

  // Longitudinal class mean (PA32, §11 over time) — overlay on the trend chart.
  const { data: classTrend } = useQuery({
    queryKey: ['skills-class-trend', childId],
    queryFn: () => (childId ? api.skills.getClassTrend(childId) : Promise.resolve([])),
    enabled: !!childId,
  });

  if (isChildrenLoading || (childId && isLoading)) {
    return <div className="p-8 text-center text-slate-400 font-bold">Đang tải xu hướng năng lực...</div>;
  }

  // Merge the learner's own series with the anonymized class mean so both render
  // on one chart; per-competency class values are keyed cls_<CODE> (§11, PA32).
  const classByDate = new Map((classTrend ?? []).map((t) => [t.date, t.scores]));
  const trendDates = Array.from(
    new Set([...(history ?? []).map((h) => h.date), ...(classTrend ?? []).map((t) => t.date)]),
  ).sort();
  const hasClassTrend = (classTrend?.length ?? 0) > 0;
  const chartData = trendDates.map((date) => {
    const own = (history ?? []).find((h) => h.date === date)?.scores ?? ({} as Record<CompetencyCode, number>);
    const cls = classByDate.get(date) ?? {};
    const row: Record<string, number | string | null> = { date };
    for (const code of ['SA', 'ER', 'SM', 'CO', 'CE', 'PD'] as CompetencyCode[]) {
      row[code] = own[code] ?? null;
      row[`cls_${code}`] = cls[code] ?? null;
    }
    return row;
  });

  return (
    <div className="space-y-6">
      <SectionHeader
        eyebrow="Dữ liệu dọc · Longitudinal"
        title="Xu hướng năng lực theo thời gian"
        description="Tiến bộ qua các kỳ đánh giá chuẩn hóa, lưu giữ bất biến theo Skill Snapshot."
        action={<Badge variant="purple">Thang chuẩn 0–100</Badge>}
      />

      {/* Trend chart */}
      <Card className="p-6 space-y-4">
        <div className="flex items-center gap-2">
          <TrendingUp className="w-5 h-5 text-brand-500" />
          <h2 className="text-sm font-black text-slate-700">Tiến trình 6 năng lực cốt lõi theo tháng</h2>
        </div>

        <div className="h-80 w-full">
          <ResponsiveContainer width="100%" height="100%">
            <LineChart data={chartData} margin={{ top: 10, right: 20, left: -20, bottom: 0 }}>
              <CartesianGrid strokeDasharray="3 3" stroke="#eef2f0" />
              <XAxis dataKey="date" stroke="#94a3b8" fontSize={11} fontWeight={700} />
              <YAxis domain={[40, 100]} stroke="#94a3b8" fontSize={11} />
              <Tooltip
                contentStyle={{
                  backgroundColor: '#ffffff',
                  borderRadius: '14px',
                  border: '1px solid rgba(0,0,0,0.06)',
                  boxShadow: '0 8px 24px -12px rgba(0,0,0,0.25)',
                  fontSize: '12px',
                  fontWeight: 700,
                  color: '#334155',
                }}
              />
              <Legend wrapperStyle={{ fontSize: '11px', fontWeight: 700, paddingTop: '12px' }} />
              {COMPS.map((c) => (
                <Line
                  key={c.code}
                  type="monotone"
                  dataKey={c.code}
                  name={c.name}
                  stroke={c.color}
                  strokeWidth={2.6}
                  dot={{ r: 3, fill: c.color }}
                  activeDot={{ r: 5 }}
                />
              ))}
              {hasClassTrend &&
                COMPS.map((c) => (
                  <Line
                    key={`cls-${c.code}`}
                    type="monotone"
                    dataKey={`cls_${c.code}`}
                    name={`Lớp · ${c.code}`}
                    stroke={c.color}
                    strokeWidth={1.6}
                    strokeDasharray="5 4"
                    strokeOpacity={0.5}
                    dot={false}
                    activeDot={false}
                    connectNulls
                  />
                ))}
            </LineChart>
          </ResponsiveContainer>
        </div>

        <div className="flex flex-wrap gap-2 pt-1">
          {COMPS.map((c) => (
            <span key={c.code} className="inline-flex items-center gap-1.5 text-[11px] font-bold text-slate-500">
              <span className="w-3 h-3 rounded-full" style={{ backgroundColor: c.color }} /> {c.code} · {c.name}
            </span>
          ))}
        </div>
      </Card>

      {/* Current competency cards */}
      <div>
        <div className="flex items-center gap-2 mb-4">
          <Sparkles className="w-5 h-5 text-brand-500" />
          <h3 className="text-lg font-black text-slate-800">Chi tiết 6 năng lực hiện tại</h3>
        </div>

        <div className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
          {currentSkills?.map((skill) => {
            const color = COMPS.find((c) => c.code === skill.skillCode)?.color ?? '#0aa96f';
            const lvl = LEVEL_LABEL[skill.level];
            const insufficient = skill.score === null;
            const val = skill.score ?? 0;
            return (
              <Card key={skill.skillCode} hover className="p-5">
                <div className="flex items-center gap-4">
                  {insufficient ? (
                    <div className="w-[72px] h-[72px] shrink-0 rounded-full border-4 border-dashed border-slate-200 flex items-center justify-center text-slate-400 text-[10px] font-black leading-tight px-1 text-center">
                      Chưa đo
                    </div>
                  ) : (
                    <SkillRing value={val} color={color} size={72} stroke={9} />
                  )}
                  <div className="min-w-0 flex-1">
                    <h4 className="font-black text-slate-800 text-sm leading-tight">{skill.skillName}</h4>
                    <div className="mt-1.5"><Badge variant={insufficient ? 'warning' : lvl.variant}>{insufficient ? 'Chưa đo' : lvl.text}</Badge></div>
                  </div>
                </div>
                <div className="mt-4">
                  <ProgressBar value={val} color={color} height={10} />
                </div>
              </Card>
            );
          })}
        </div>
      </div>

      {/* Class benchmark — learner vs anonymized class mean (PA31, §11) */}
      {benchmark && benchmark.classId !== null && benchmark.competencies.length > 0 && (
        <div>
          <div className="flex items-center gap-2 mb-4">
            <Users className="w-5 h-5 text-brand-500" />
            <h3 className="text-lg font-black text-slate-800">Con so với mặt bằng lớp</h3>
            <Badge variant="blue">{benchmark.memberCount} bạn trong lớp</Badge>
            {!benchmark.reliable && (
              <Badge variant="warning">Chưa đủ {benchmark.minSamples} bạn để so sánh ẩn danh</Badge>
            )}
          </div>

          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            {benchmark.competencies.map((comp) => {
              const meta = COMPS.find((c) => c.code === comp.code);
              const name = meta?.name ?? comp.code;
              const color = meta?.color ?? '#0aa96f';
              const student = comp.studentScore;
              const cls = comp.classAverage;
              const delta = student !== null && cls !== null ? Math.round((student - cls) * 10) / 10 : null;
              // A null class mean with peers present means the cell was suppressed by
              // the k-anonymity floor (§8), not that no one scored it.
              const suppressed = cls === null && comp.samples > 0 && comp.samples < benchmark.minSamples;
              const clamp = (n: number) => Math.min(100, Math.max(0, n));
              return (
                <Card key={comp.code} className="p-4">
                  <div className="flex items-center justify-between mb-2">
                    <span className="text-sm font-black text-slate-700">{name}</span>
                    {delta !== null && (
                      <span className={`text-xs font-black ${delta >= 0 ? 'text-brand-600' : 'text-er'}`}>
                        {delta >= 0 ? `+${delta}` : delta} so với lớp
                      </span>
                    )}
                    {suppressed && (
                      <span className="text-xs font-black text-slate-400">Chưa đủ mẫu ({comp.samples}/{benchmark.minSamples})</span>
                    )}
                  </div>

                  <div className="relative h-2.5 rounded-full bg-slate-100 overflow-hidden">
                    {student !== null && (
                      <div className="absolute inset-y-0 left-0" style={{ width: `${clamp(student)}%`, backgroundColor: color }} />
                    )}
                    {cls !== null && (
                      <div className="absolute inset-y-0 w-[3px] bg-slate-500" style={{ left: `calc(${clamp(cls)}% - 1.5px)` }} title={`Mặt bằng lớp ${cls}`} />
                    )}
                  </div>

                  <div className="flex items-center justify-between mt-2 text-[11px] font-bold text-slate-500">
                    <span>Con: <strong className="text-slate-700">{student ?? 'Chưa đo'}</strong></span>
                    <span>Lớp TB: <strong className="text-slate-700">{cls ?? (suppressed ? 'Ẩn (bảo mật)' : '—')}</strong></span>
                  </div>
                </Card>
              );
            })}
          </div>
        </div>
      )}
    </div>
  );
}
