'use client';

import React from 'react';
import { useQuery } from '@tanstack/react-query';
import { api } from '@aba/api-client';
import { Card, Badge, Button } from '@aba/ui';
import { FileText, Download, CheckCircle2, AlertCircle, Compass } from 'lucide-react';

export default function ParentReportsPage() {
  const { data: reports, isLoading } = useQuery({
    queryKey: ['student-reports', 1],
    queryFn: () => api.parent.getReports(1),
  });

  if (isLoading) {
    return <div className="p-8 text-center text-slate-400">Đang tải danh sách báo cáo...</div>;
  }

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-black tracking-tight text-slate-800 dark:text-slate-100">
          Báo Cáo Năng Lực & Khuyến Nghị
        </h1>
        <p className="text-sm text-slate-500 dark:text-slate-400 mt-1">
          Báo cáo định kỳ phân tích năng lực chi tiết và định hướng can thiệp / bồi dưỡng.
        </p>
      </div>

      <div className="space-y-4">
        {reports?.map((report) => (
          <Card key={report.id} className="p-6 space-y-5">
            <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 border-b border-slate-100 dark:border-slate-800 pb-4">
              <div>
                <Badge variant="purple">Định kỳ</Badge>
                <h2 className="text-base font-bold text-slate-800 dark:text-slate-100 mt-1">
                  {report.title}
                </h2>
                <span className="text-[11px] text-slate-400">
                  Ngày lập: {new Date(report.generatedAt).toLocaleDateString('vi-VN')}
                </span>
              </div>

              <div className="flex items-center gap-2">
                <div className="text-right sm:mr-3">
                  <div className="text-xs text-slate-400">Điểm tổng hợp</div>
                  <div className="text-xl font-black text-emerald-600">{report.overallScore}/100</div>
                </div>
                <Button size="sm" variant="outline" className="gap-1.5 text-xs">
                  <Download className="w-3.5 h-3.5" /> Tải PDF
                </Button>
              </div>
            </div>

            <div>
              <h3 className="text-xs font-bold uppercase tracking-wider text-slate-400 mb-1">
                Tóm tắt đánh giá
              </h3>
              <p className="text-xs text-slate-600 dark:text-slate-300 leading-relaxed">
                {report.summary}
              </p>
            </div>

            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div className="p-4 rounded-xl bg-emerald-50 dark:bg-emerald-950/20 border border-emerald-200/50">
                <h4 className="text-xs font-bold text-emerald-800 dark:text-emerald-300 flex items-center gap-1.5 mb-2">
                  <CheckCircle2 className="w-4 h-4" /> Điểm mạnh của con
                </h4>
                <ul className="text-xs text-slate-700 dark:text-slate-300 space-y-1 list-disc list-inside">
                  {report.strengths.map((str, i) => (
                    <li key={i}>{str}</li>
                  ))}
                </ul>
              </div>

              <div className="p-4 rounded-xl bg-rose-50 dark:bg-rose-950/20 border border-rose-200/50">
                <h4 className="text-xs font-bold text-rose-800 dark:text-rose-300 flex items-center gap-1.5 mb-2">
                  <AlertCircle className="w-4 h-4" /> Khía cạnh cần rèn luyện thêm
                </h4>
                <ul className="text-xs text-slate-700 dark:text-slate-300 space-y-1 list-disc list-inside">
                  {report.areasForGrowth.map((area, i) => (
                    <li key={i}>{area}</li>
                  ))}
                </ul>
              </div>
            </div>

            {/* Recommendations */}
            <div className="p-4 rounded-xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200/60 dark:border-slate-700/60">
              <h4 className="text-xs font-bold text-slate-700 dark:text-slate-200 flex items-center gap-1.5 mb-2">
                <Compass className="w-4 h-4 text-emerald-500" /> Khuyến nghị can thiệp & Bài tập tại nhà
              </h4>
              <div className="space-y-2">
                {report.recommendations.map((rec, i) => (
                  <div key={i} className="text-xs flex items-start gap-2">
                    <span className="w-1.5 h-1.5 rounded-full bg-emerald-500 mt-1.5 shrink-0" />
                    <div>
                      <strong className="text-slate-800 dark:text-slate-100">{rec.title}</strong>
                      <p className="text-slate-500 dark:text-slate-400 mt-0.5">{rec.description}</p>
                    </div>
                  </div>
                ))}
              </div>
            </div>
          </Card>
        ))}
      </div>
    </div>
  );
}
