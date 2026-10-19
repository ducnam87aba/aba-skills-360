'use client';

import React from 'react';
import { useQuery } from '@tanstack/react-query';
import { api } from '@aba/api-client';
import { Card, Badge } from '@aba/ui';
import { TrendingUp, BarChart } from 'lucide-react';
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
    return <div className="p-8 text-center text-slate-400">Đang tải xu hướng năng lực...</div>;
  }

  // Transform longitudinal data for Recharts
  const chartData = history?.map((item) => ({
    date: item.date,
    'Tư duy logic': item.scores['COG_LOGIC'],
    'Giao tiếp': item.scores['COMM_VERBAL'],
    'Cảm xúc xã hội': item.scores['SOC_EMOTION'],
    'Thích ứng': item.scores['ADAPT_AUTONOMY'],
    'Vận động tinh': item.scores['MOTOR_FINE'],
  }));

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-black tracking-tight text-slate-800 dark:text-slate-100">
          Biểu Đồ Xu Hướng Năng Lực (Longitudinal History)
        </h1>
        <p className="text-sm text-slate-500 dark:text-slate-400 mt-1">
          Lịch sử tiến bộ qua các kỳ đánh giá chuẩn hóa theo thời gian thực.
        </p>
      </div>

      {/* Longitudinal Chart */}
      <Card className="p-6 space-y-4">
        <div className="flex items-center justify-between">
          <h2 className="text-sm font-bold text-slate-700 dark:text-slate-200 flex items-center gap-2">
            <TrendingUp className="w-4 h-4 text-emerald-500" /> Tiến trình phát triển theo tháng
          </h2>
          <Badge variant="blue">Snapshot 360°</Badge>
        </div>

        <div className="h-72 w-full">
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
              <Line type="monotone" dataKey="Tư duy logic" stroke="#10b981" strokeWidth={3} dot={{ r: 4 }} />
              <Line type="monotone" dataKey="Giao tiếp" stroke="#6366f1" strokeWidth={2} dot={{ r: 4 }} />
              <Line type="monotone" dataKey="Cảm xúc xã hội" stroke="#f43f5e" strokeWidth={2} dot={{ r: 4 }} />
              <Line type="monotone" dataKey="Thích ứng" stroke="#eab308" strokeWidth={2} dot={{ r: 4 }} />
              <Line type="monotone" dataKey="Vận động tinh" stroke="#06b6d4" strokeWidth={2} dot={{ r: 4 }} />
            </LineChart>
          </ResponsiveContainer>
        </div>
      </Card>

      {/* Current Skill Breakdown Cards */}
      <div className="space-y-3">
        <h3 className="text-sm font-bold text-slate-700 dark:text-slate-300">
          Chi tiết từng chỉ số năng lực
        </h3>

        <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
          {currentSkills?.map((skill) => (
            <Card key={skill.skillId} className="p-4 space-y-2">
              <div className="flex items-center justify-between">
                <span className="text-xs font-bold text-slate-800 dark:text-slate-200">
                  {skill.skillName}
                </span>
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
                Cấp độ: {skill.level}
              </div>
            </Card>
          ))}
        </div>
      </div>
    </div>
  );
}
