'use client';

import React from 'react';
import { useQuery } from '@tanstack/react-query';
import { api } from '@aba/api-client';
import { Card, Badge, SkillRing, ProgressBar } from '@aba/ui';
import { School, Flame, Zap, BarChart2, BookOpen, TrendingUp } from 'lucide-react';
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

const COMP_COLORS: Record<CompetencyCode, string> = {
  SA: '#7c5cff', ER: '#ff5d73', SM: '#ffae00', CO: '#1cb0f6', CE: '#0aa96f', PD: '#4b6bff',
};

const COMP_TREND: CompetencyCode[] = ['SA', 'ER', 'SM', 'CO', 'CE', 'PD'];

const LEVEL_LABEL: Record<SkillLevel, { text: string; variant: any }> = {
  advanced: { text: 'Thành thạo', variant: 'success' },
  proficient: { text: 'Khá tốt', variant: 'blue' },
  developing: { text: 'Đang phát triển', variant: 'warning' },
  beginning: { text: 'Cần rèn luyện', variant: 'rose' },
};

export default function ProfilePage() {
  const { data: student } = useQuery({ queryKey: ['student-me'], queryFn: () => api.student.getMe() });
  const { data: skills } = useQuery({ queryKey: ['skills-current'], queryFn: () => api.skills.getCurrent() });
  // Self longitudinal history (resolved from the login, §11).
  const { data: history } = useQuery({ queryKey: ['skills-history-self'], queryFn: () => api.skills.getHistory() });

  const chartData = (history ?? []).map((item) => ({ date: item.date, ...item.scores }));

  const measured = (skills ?? []).filter((x) => x.score !== null);
  const overall = measured.length
    ? Math.round(measured.reduce((s, x) => s + (x.score as number), 0) / measured.length)
    : 0;

  return (
    <div className="space-y-6">
      {/* Identity hero */}
      <Card className="p-6 sm:p-7 flex flex-col sm:flex-row items-center gap-6">
        <div className="relative">
          <div className="w-24 h-24 rounded-3xl bg-gradient-to-br from-brand-400 to-brand-600 p-1 shadow-[0_5px_0_0_#036c4a]">
            <img src={student?.avatarUrl} alt={student?.fullName} className="w-full h-full object-cover rounded-[20px]" />
          </div>
          <div className="absolute -bottom-2 -right-2 px-2.5 py-1 rounded-xl bg-sm text-white text-xs font-black shadow-[0_3px_0_0_#d98c00]">
            Lv {student?.level ?? 1}
          </div>
        </div>

        <div className="flex-1 text-center sm:text-left">
          <h1 className="text-2xl font-black text-slate-800">{student?.fullName}</h1>
          <p className="text-sm text-slate-400 mt-0.5">
            Mã HS <strong className="text-slate-600">{student?.studentCode}</strong>
          </p>
          <div className="flex flex-wrap items-center justify-center sm:justify-start gap-2 mt-3 text-xs font-bold text-slate-500">
            <span className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-slate-50"><School className="w-4 h-4 text-brand-500" /> {student?.schoolName}</span>
            <Badge variant="purple">Khối {student?.gradeLevel} · Lớp {student?.className}</Badge>
          </div>
        </div>

        <div className="flex sm:flex-col gap-3 sm:gap-2">
          <Metric icon={<Flame className="w-5 h-5" />} value={student?.currentStreak ?? 0} label="Chuỗi" color="#ffae00" />
          <Metric icon={<Zap className="w-5 h-5" />} value={(student?.totalXp ?? 0).toLocaleString('vi-VN')} label="Tổng XP" color="#1cb0f6" />
        </div>
      </Card>

      {/* Skills profile */}
      <div>
        <div className="flex items-center justify-between mb-4">
          <h2 className="text-lg font-black text-slate-800 flex items-center gap-2">
            <BarChart2 className="w-5 h-5 text-brand-500" /> Hồ sơ năng lực hiện tại
          </h2>
          <Badge variant="success">Chuẩn hóa 360° · TB {overall}</Badge>
        </div>

        <div className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
          {skills?.map((skill) => {
            const color = COMP_COLORS[skill.skillCode];
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
                    <h3 className="font-black text-slate-800 text-sm leading-tight">{skill.skillName}</h3>
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

      {/* Longitudinal trend (Skill Snapshots over time, §11) */}
      <Card className="p-6 space-y-4">
        <div className="flex items-center gap-2">
          <TrendingUp className="w-5 h-5 text-brand-500" />
          <h2 className="text-sm font-black text-slate-700">Tiến trình năng lực của em theo thời gian</h2>
        </div>

        {chartData.length >= 2 ? (
          <div className="h-72 w-full">
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
                {COMP_TREND.map((code) => (
                  <Line
                    key={code}
                    type="monotone"
                    dataKey={code}
                    name={code}
                    stroke={COMP_COLORS[code]}
                    strokeWidth={2.6}
                    dot={{ r: 3, fill: COMP_COLORS[code] }}
                    activeDot={{ r: 5 }}
                    connectNulls
                  />
                ))}
              </LineChart>
            </ResponsiveContainer>
          </div>
        ) : (
          <p className="text-sm text-slate-400 font-medium">
            Cần ít nhất hai kỳ đánh giá để vẽ đường phát triển. Mỗi lần hoàn thành bài đánh giá, hệ thống lưu
            lại một <strong className="text-slate-600">Skill Snapshot</strong> bất biến để xây dựng biểu đồ xu hướng.
          </p>
        )}
      </Card>

      <Card className="p-5 flex items-center gap-3 text-sm text-slate-500">
        <BookOpen className="w-5 h-5 text-brand-500 shrink-0" />
        Điểm được tổng hợp từ các <strong className="text-slate-700">Skill Snapshot</strong> bất biến — lịch sử đo lường không bị ghi đè theo thời gian.
      </Card>
    </div>
  );
}

function Metric({ icon, value, label, color }: { icon: React.ReactNode; value: React.ReactNode; label: string; color: string }) {
  return (
    <div className="text-center px-4 py-2 rounded-2xl" style={{ backgroundColor: `${color}14` }}>
      <div className="flex items-center justify-center gap-1 font-black" style={{ color }}>{icon}<span className="tabular-nums">{value}</span></div>
      <div className="text-[10px] font-bold text-slate-400 mt-0.5 uppercase tracking-wide">{label}</div>
    </div>
  );
}
