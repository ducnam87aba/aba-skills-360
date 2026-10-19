'use client';

import React from 'react';
import { useQuery } from '@tanstack/react-query';
import { api } from '@aba/api-client';
import { Card, Badge, Button } from '@aba/ui';
import { Users, TrendingUp, FileText, CheckCircle2, ArrowRight, ShieldCheck } from 'lucide-react';
import Link from 'next/link';

export default function ParentOverviewPage() {
  const { data: children, isLoading } = useQuery({
    queryKey: ['parent-children'],
    queryFn: () => api.parent.getChildren(),
  });

  const student = children?.[0];

  const { data: reports } = useQuery({
    queryKey: ['student-reports', student?.id],
    queryFn: () => (student?.id ? api.parent.getReports(student.id) : Promise.resolve([])),
    enabled: !!student?.id,
  });

  const latestReport = reports?.[0];

  if (isLoading) {
    return <div className="p-8 text-center text-slate-400">Đang tải thông tin phụ huynh...</div>;
  }

  return (
    <div className="space-y-6">
      {/* Header Info */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
          <h1 className="text-2xl font-black tracking-tight text-slate-800 dark:text-slate-100">
            Tổng Quan Con Em
          </h1>
          <p className="text-sm text-slate-500 dark:text-slate-400 mt-1">
            Theo dõi hành trình phát triển năng lực và hỗ trợ con học tập hiệu quả.
          </p>
        </div>

        {student && (
          <div className="flex items-center gap-3 bg-white dark:bg-slate-900 px-4 py-2 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-sm">
            <img
              src={student.avatarUrl}
              alt={student.fullName}
              className="w-8 h-8 rounded-full object-cover"
            />
            <div>
              <div className="text-xs font-bold text-slate-800 dark:text-slate-100">{student.fullName}</div>
              <div className="text-[10px] text-slate-400">{student.className} • {student.schoolName}</div>
            </div>
          </div>
        )}
      </div>

      {/* Highlights Grid */}
      <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <Card className="p-5 space-y-2 bg-gradient-to-br from-emerald-500/10 to-teal-500/5 border-emerald-200/50">
          <div className="flex items-center justify-between">
            <span className="text-xs font-bold text-emerald-700 dark:text-emerald-400">Chỉ số năng lực</span>
            <TrendingUp className="w-4 h-4 text-emerald-600" />
          </div>
          <div className="text-2xl font-black text-slate-800 dark:text-slate-100">81 / 100</div>
          <p className="text-[11px] text-slate-500 dark:text-slate-400">Tăng +7 điểm so với tháng trước</p>
        </Card>

        <Card className="p-5 space-y-2 bg-gradient-to-br from-blue-500/10 to-indigo-500/5 border-blue-200/50">
          <div className="flex items-center justify-between">
            <span className="text-xs font-bold text-blue-700 dark:text-blue-400">Chuỗi chuyên cần</span>
            <ShieldCheck className="w-4 h-4 text-blue-600" />
          </div>
          <div className="text-2xl font-black text-slate-800 dark:text-slate-100">7 ngày liên tiếp</div>
          <p className="text-[11px] text-slate-500 dark:text-slate-400">Duy trì học tập đều đặn</p>
        </Card>

        <Card className="p-5 space-y-2 bg-gradient-to-br from-purple-500/10 to-pink-500/5 border-purple-200/50">
          <div className="flex items-center justify-between">
            <span className="text-xs font-bold text-purple-700 dark:text-purple-400">Báo cáo gần nhất</span>
            <FileText className="w-4 h-4 text-purple-600" />
          </div>
          <div className="text-lg font-bold text-slate-800 dark:text-slate-100">Tháng 10/2026</div>
          <p className="text-[11px] text-slate-500 dark:text-slate-400">Đã có khuyến nghị phụ huynh</p>
        </Card>
      </div>

      {/* Latest Report Snippet & Recommendations */}
      {latestReport && (
        <Card className="p-6 space-y-4">
          <div className="flex items-center justify-between border-b border-slate-100 dark:border-slate-800 pb-3">
            <div>
              <span className="text-xs font-bold uppercase text-emerald-600">Đánh giá mới nhất</span>
              <h2 className="text-base font-bold text-slate-800 dark:text-slate-100">{latestReport.title}</h2>
            </div>
            <Link href="/parent/reports">
              <Button size="sm" variant="outline" className="gap-1 text-xs">
                Xem toàn bộ <ArrowRight className="w-3.5 h-3.5" />
              </Button>
            </Link>
          </div>

          <p className="text-xs text-slate-600 dark:text-slate-300 leading-relaxed">
            {latestReport.summary}
          </p>

          <div className="grid grid-cols-1 md:grid-cols-2 gap-4 pt-2">
            <div className="p-4 rounded-xl bg-emerald-50 dark:bg-emerald-950/30 border border-emerald-200/40">
              <h3 className="text-xs font-bold text-emerald-800 dark:text-emerald-300 flex items-center gap-1.5 mb-2">
                <CheckCircle2 className="w-4 h-4" /> Điểm mạnh nổi bật
              </h3>
              <ul className="text-xs text-slate-700 dark:text-slate-300 space-y-1 list-disc list-inside">
                {latestReport.strengths.map((str, i) => (
                  <li key={i}>{str}</li>
                ))}
              </ul>
            </div>

            <div className="p-4 rounded-xl bg-amber-50 dark:bg-amber-950/30 border border-amber-200/40">
              <h3 className="text-xs font-bold text-amber-800 dark:text-amber-300 flex items-center gap-1.5 mb-2">
                <TrendingUp className="w-4 h-4" /> Khuyến nghị phụ huynh đồng hành
              </h3>
              <ul className="text-xs text-slate-700 dark:text-slate-300 space-y-1 list-disc list-inside">
                {latestReport.recommendations.map((rec, i) => (
                  <li key={i}>{rec.title}: {rec.description}</li>
                ))}
              </ul>
            </div>
          </div>
        </Card>
      )}
    </div>
  );
}
