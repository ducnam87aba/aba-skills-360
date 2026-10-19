'use client';

import React from 'react';
import { useQuery } from '@tanstack/react-query';
import { api } from '@aba/api-client';
import { Card, Badge } from '@aba/ui';
import { TrendingUp, BarChart3, ShieldCheck } from 'lucide-react';
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

export default function ParentSkillsPage() {
  const { data: history, isLoading } = useQuery({
    queryKey: ['skills-history', 1],
    queryFn: () => api.skills.getHistory(1),
  });

  const { data: currentSkills } = useQuery({
    queryKey: ['skills-current', 1],
    queryFn: () => api.skills.getCurrent(1),
  });

  if (isLoading) {
    return (
      <div className="p-8 text-center text-slate-400">
        Đang tải xu hướng năng lực...
      </div>
    );
  }

  // Transform longitudinal data for Recharts matching 6 competencies (docs/assessment-spec.md)
  const chartData = history?.map((item) => ({
    date: item.date,
    'Tự nhận thức (SA)': item.scores.SA,
    'Quản lý cảm xúc (ER)': item.scores.ER,
    'Tự quản lý (SM)': item.scores.SM,
    'Giao tiếp (CO)': item.scores.CO,
    'Hợp tác & Đồng cảm (CE)': item.scores.CE,
    'Giải quyết vấn đề (PD)': item.scores.PD,
  }));

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-black tracking-tight text-slate-800 dark:text-slate-100">
          Biểu Đồ Xu Hướng Năng Lực (Longitudinal History)
        </h1>
        <p className="text-sm text-slate-500 dark:text-slate-400 mt-1">
          Lịch sử tiến bộ qua các kỳ đánh giá chuẩn hóa 6 nhóm năng lực cốt lõi theo thời gian.
        </p>
      </div>

      {/* Longitudinal Chart */}
      <Card className="p-6 space-y-4">
        <div className="flex items-center justify-between">
          <h2 className="text-sm font-bold text-slate-700 dark:text-slate-200 flex items-center gap-2">
            <TrendingUp className="w-4 h-4 text-emerald-500" /> Tiến trình 6 năng lực cốt lõi theo tháng
          </h2>
          <Badge variant="purple">Chuẩn hóa 0–100</Badge>
        </div>

        <div className="h-80 w-full">
          <ResponsiveContainer width="100%" height="100%">
            <LineChart data={chartData} margin={{ top: 10, right: 20, left: -20, bottom: 0 }}>
              <CartesianGrid strokeDasharray="3 3" opacity={0.2} />
              <XAxis dataKey="date" stroke="#94a3b8" fontSize={11} />
              <YAxis domain={[40, 100]} stroke="#94a3b8" fontSize={11} />
              <Tooltip
                contentStyle={{
                  backgroundColor: '#0f172a',
                  borderRadius: '12px',
                  border: 'none',
                  fontSize: '12px',
                  color: '#fff',
                }}
              />
              <Legend wrapperStyle={{ fontSize: '11px', paddingTop: '10px' }} />
              <Line type="monotone" dataKey="Tự nhận thức (SA)" stroke="#10b981" strokeWidth={2.5} dot={{ r: 3 }} />
              <Line type="monotone" dataKey="Quản lý cảm xúc (ER)" stroke="#f43f5e" strokeWidth={2.5} dot={{ r: 3 }} />
              <Line type="monotone" dataKey="Tự quản lý (SM)" stroke="#8b5cf6" strokeWidth={2.5} dot={{ r: 3 }} />
              <Line type="monotone" dataKey="Giao tiếp (CO)" stroke="#06b6d4" strokeWidth={2.5} dot={{ r: 3 }} />
              <Line type="monotone" dataKey="Hợp tác & Đồng cảm (CE)" stroke="#3b82f6" strokeWidth={2.5} dot={{ r: 3 }} />
              <Line type="monotone" dataKey="Giải quyết vấn đề (PD)" stroke="#eab308" strokeWidth={2.5} dot={{ r: 3 }} />
            </LineChart>
          </ResponsiveContainer>
        </div>
      </Card>

      {/* Current Skill Breakdown Cards */}
      <div className="space-y-3">
        <h3 className="text-sm font-bold text-slate-700 dark:text-slate-300">
          Chi tiết 6 năng lực hiện tại
        </h3>

        <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
          {currentSkills?.map((skill) => (
            <Card key={skill.skillCode} className="p-4 space-y-2">
              <div className="flex items-center justify-between">
                <div>
                  <span className="text-xs font-bold text-slate-800 dark:text-slate-200">
                    {skill.skillName}
                  </span>
                  <span className="text-[10px] ml-1.5 px-1.5 py-0.5 rounded bg-slate-100 dark:bg-slate-800 font-bold text-slate-500">
                    {skill.skillCode}
                  </span>
                </div>
                <span className="text-sm font-extrabold text-emerald-600">
                  {skill.score} / {skill.maxScore}
                </span>
              </div>
              <div className="w-full h-2 bg-slate-100 dark:bg-slate-800 rounded-full overflow-hidden">
                <div
                  className="h-full bg-emerald-500 rounded-full"
                  style={{ width: `${(skill.score / skill.maxScore) * 100}%` }}
                />
              </div>
              <div className="text-[10px] text-slate-400 capitalize">
                Cấp độ phát triển: <strong className="text-slate-600 dark:text-slate-300">{skill.level}</strong>
              </div>
            </Card>
          ))}
        </div>
      </div>
    </div>
  );
}
