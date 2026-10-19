'use client';

import React from 'react';
import { useQuery } from '@tanstack/react-query';
import { api } from '@aba/api-client';
import { Card, Badge, Button } from '@aba/ui';
import {
  FileText,
  Download,
  CheckCircle2,
  AlertCircle,
  Compass,
  TrendingUp,
  ArrowUpRight,
  ArrowDownRight,
  Sparkles,
} from 'lucide-react';

export default function ParentReportsPage() {
  const { data: reports, isLoading: isReportsLoading } = useQuery({
    queryKey: ['student-reports', 1],
    queryFn: () => api.parent.getReports(1),
  });

  const { data: history, isLoading: isHistoryLoading } = useQuery({
    queryKey: ['skills-history', 1],
    queryFn: () => api.skills.getHistory(1),
  });

  if (isReportsLoading || isHistoryLoading) {
    return (
      <div className="p-8 text-center text-slate-400">
        Đang tải báo cáo năng lực và phân tích so sánh...
      </div>
    );
  }

  // Calculate delta between latest two snapshots if available
  const latestSnap = history && history.length > 0 ? history[history.length - 1] : null;
  const prevSnap = history && history.length > 1 ? history[history.length - 2] : null;

  const competencyLabels: Record<string, string> = {
    SA: 'Tự nhận thức',
    ER: 'Quản lý cảm xúc',
    SM: 'Tự quản lý',
    CO: 'Giao tiếp',
    CE: 'Hợp tác & Đồng cảm',
    PD: 'Giải quyết vấn đề',
  };

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-black tracking-tight text-slate-800 dark:text-slate-100">
          Báo Cáo Năng Lực & Khuyến Nghị Phát Triển
        </h1>
        <p className="text-sm text-slate-500 dark:text-slate-400 mt-1">
          Báo cáo định kỳ phân tích chi tiết 6 nhóm năng lực và khuyến nghị phương pháp đồng hành tại gia đình.
        </p>
      </div>

      {/* Month-over-month Longitudinal Delta Comparison */}
      {latestSnap && prevSnap && (
        <Card className="p-6 space-y-4 bg-gradient-to-br from-slate-900 to-slate-800 text-white shadow-xl">
          <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-2 border-b border-slate-700/60 pb-3">
            <div>
              <div className="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full bg-emerald-500/20 text-emerald-300 text-xs font-semibold mb-1">
                <Sparkles className="w-3.5 h-3.5" /> Phân tích tăng trưởng định kỳ
              </div>
              <h2 className="text-base font-bold text-white">
                Chuyển Biến So Với Kỳ Đánh Giá Trước ({prevSnap.date} → {latestSnap.date})
              </h2>
            </div>
            <Badge variant="purple">Longitudinal Delta</Badge>
          </div>

          <div className="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-6 gap-3 pt-1">
            {Object.keys(latestSnap.scores).map((key) => {
              const currentVal = latestSnap.scores[key as keyof typeof latestSnap.scores] || 0;
              const prevVal = prevSnap.scores[key as keyof typeof prevSnap.scores] || 0;
              const delta = Math.round((currentVal - prevVal) * 10) / 10;
              const isPositive = delta >= 0;

              return (
                <div key={key} className="p-3 rounded-xl bg-slate-800/80 border border-slate-700/60 text-center">
                  <div className="text-[11px] font-medium text-slate-400">
                    {competencyLabels[key] || key}
                  </div>
                  <div className="text-lg font-black text-white mt-0.5">
                    {currentVal}
                  </div>
                  <div
                    className={`inline-flex items-center text-xs font-bold mt-1 ${
                      isPositive ? 'text-emerald-400' : 'text-rose-400'
                    }`}
                  >
                    {isPositive ? (
                      <ArrowUpRight className="w-3.5 h-3.5 mr-0.5" />
                    ) : (
                      <ArrowDownRight className="w-3.5 h-3.5 mr-0.5" />
                    )}
                    {isPositive ? `+${delta}` : delta}
                  </div>
                </div>
              );
            })}
          </div>
        </Card>
      )}

      {/* Reports List */}
      <div className="space-y-4">
        {reports?.map((report) => (
          <Card key={report.id} className="p-6 space-y-5">
            <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 border-b border-slate-100 dark:border-slate-800 pb-4">
              <div>
                <Badge variant="purple">Báo cáo chuẩn hóa 360°</Badge>
                <h2 className="text-base font-bold text-slate-800 dark:text-slate-100 mt-1">
                  {report.title}
                </h2>
                <span className="text-[11px] text-slate-400">
                  Ngày phát hành: {new Date(report.generatedAt).toLocaleDateString('vi-VN')}
                </span>
              </div>

              <div className="flex items-center gap-2">
                <div className="text-right sm:mr-3">
                  <div className="text-xs text-slate-400">Điểm tổng thể</div>
                  <div className="text-2xl font-black text-emerald-600">{report.overallScore}/100</div>
                </div>
                <Button size="sm" variant="outline" className="gap-1.5 text-xs">
                  <Download className="w-3.5 h-3.5" /> Tải PDF
                </Button>
              </div>
            </div>

            <div>
              <h3 className="text-xs font-bold uppercase tracking-wider text-slate-400 mb-1">
                Tóm tắt đánh giá chuyên môn
              </h3>
              <p className="text-xs text-slate-600 dark:text-slate-300 leading-relaxed">
                {report.summary}
              </p>
            </div>

            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div className="p-4 rounded-xl bg-emerald-50 dark:bg-emerald-950/20 border border-emerald-200/50">
                <h4 className="text-xs font-bold text-emerald-800 dark:text-emerald-300 flex items-center gap-1.5 mb-2">
                  <CheckCircle2 className="w-4 h-4" /> Điểm mạnh nổi bật của con
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
                {report.recommendations.map((rec) => (
                  <div key={rec.id} className="text-xs flex items-start gap-2">
                    <span className="w-1.5 h-1.5 rounded-full bg-emerald-500 mt-1.5 shrink-0" />
                    <div>
                      <strong className="text-slate-800 dark:text-slate-100">{rec.title}</strong>
                      <span className="text-[10px] ml-2 px-1.5 py-0.2 rounded bg-slate-200 dark:bg-slate-700 font-semibold text-slate-600 dark:text-slate-300">
                        {rec.type === 'home_exercise' ? 'Gia đình' : 'Bài học'}
                      </span>
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
