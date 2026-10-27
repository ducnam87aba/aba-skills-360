'use client';

import React from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { api } from '@aba/api-client';
import { Card, Badge, Button, SectionHeader } from '@aba/ui';
import {
  Download,
  Printer,
  CheckCircle2,
  AlertCircle,
  Lightbulb,
  TrendingUp,
  ArrowUpRight,
  ArrowDownRight,
  Sparkles,
  Home,
  BookOpen,
} from 'lucide-react';
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
import type { CompetencyCode } from '@aba/types';
import { reportToCsv, reportCsvFilename, downloadCsv, printReport, skillsHistoryToCsv, skillsHistoryCsvFilename } from '../../../lib/reportExport';
import { useParentChildren } from '../../../features/parent/ParentChildrenContext';

const COMP_COLORS: Record<CompetencyCode, string> = {
  SA: '#7c5cff', ER: '#ff5d73', SM: '#ffae00', CO: '#1cb0f6', CE: '#0aa96f', PD: '#4b6bff',
};

const COMP_NAMES: Record<string, string> = {
  SA: 'Tự nhận thức', ER: 'Quản lý cảm xúc', SM: 'Tự quản lý',
  CO: 'Giao tiếp', CE: 'Hợp tác & Đồng cảm', PD: 'Giải quyết vấn đề',
};

const COMP_TREND: CompetencyCode[] = ['SA', 'ER', 'SM', 'CO', 'CE', 'PD'];

export default function ParentReportsPage() {
  // The active child comes from the shared parent context (switcher-selected,
  // server-scoped per §8) instead of pinning to the first linked child.
  const { activeChildId: childId, isLoading: isChildrenLoading } = useParentChildren();

  const { data: reports, isLoading: isReportsLoading } = useQuery({
    queryKey: ['student-reports', childId],
    queryFn: () => (childId ? api.parent.getReports(childId) : Promise.resolve([])),
    enabled: !!childId,
  });

  const { data: history, isLoading: isHistoryLoading } = useQuery({
    queryKey: ['skills-history', childId],
    queryFn: () => (childId ? api.skills.getHistory(childId) : Promise.resolve([])),
    enabled: !!childId,
  });

  // PA20: persist a fresh immutable snapshot, then refresh the stored history.
  const queryClient = useQueryClient();
  const generate = useMutation({
    mutationFn: () => (childId ? api.parent.generateReport(childId) : Promise.reject(new Error('Chưa chọn học sinh'))),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['student-reports', childId] }),
  });

  if (isChildrenLoading || (childId && (isReportsLoading || isHistoryLoading))) {
    return <div className="p-8 text-center text-slate-400 font-bold">Đang tải báo cáo năng lực...</div>;
  }

  const latestSnap = history && history.length > 0 ? history[history.length - 1] : null;
  const prevSnap = history && history.length > 1 ? history[history.length - 2] : null;
  // Longitudinal trend series (§11): one point per immutable Skill Snapshot.
  const chartData = (history ?? []).map((item) => ({ date: item.date, ...item.scores }));

  return (
    <div className="space-y-6">
      <SectionHeader
        eyebrow="Định kỳ · Khuyến nghị"
        title="Báo cáo năng lực & lộ trình đồng hành"
        description="Phân tích chi tiết 6 nhóm năng lực và phương pháp hỗ trợ con tại gia đình."
      />

      {childId && (history && history.length > 0) && (
        <div className="flex justify-end gap-2">
          <Button
            size="sm"
            className="gap-1.5"
            onClick={() => generate.mutate()}
            disabled={generate.isPending}
          >
            <Sparkles className="w-4 h-4" /> {generate.isPending ? 'Đang tạo...' : 'Tạo báo cáo mới'}
          </Button>
          <Button
            size="sm"
            variant="outline"
            className="gap-1.5"
            onClick={() => downloadCsv(skillsHistoryCsvFilename(childId), skillsHistoryToCsv(history))}
          >
            <Download className="w-4 h-4" /> Tải xu hướng (CSV)
          </Button>
        </div>
      )}
      {childId && !(history && history.length > 0) && (
        <div className="flex justify-end">
          <Button size="sm" className="gap-1.5" onClick={() => generate.mutate()} disabled={generate.isPending}>
            <Sparkles className="w-4 h-4" /> {generate.isPending ? 'Đang tạo...' : 'Tạo báo cáo mới'}
          </Button>
        </div>
      )}

      {/* Growth delta */}
      {latestSnap && prevSnap && (
        <Card className="overflow-hidden">
          <div className="bg-gradient-to-br from-brand-500 to-brand-700 text-white px-6 py-5 flex items-center justify-between gap-3">
            <div>
              <div className="inline-flex items-center gap-1.5 text-[11px] font-black uppercase tracking-widest text-brand-100 mb-1">
                <Sparkles className="w-3.5 h-3.5" /> Chuyển biến tăng trưởng
              </div>
              <h2 className="text-lg font-black leading-tight">
                So với kỳ đánh giá trước
              </h2>
            </div>
            <div className="text-right shrink-0">
              <div className="text-[11px] text-brand-100 font-bold uppercase">{prevSnap.date}</div>
              <div className="text-brand-100 font-black">→</div>
              <div className="text-[11px] text-white font-black">{latestSnap.date}</div>
            </div>
          </div>

          <div className="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-6 gap-3 p-5">
            {Object.keys(latestSnap.scores).map((key) => {
              const currentVal = latestSnap.scores[key as CompetencyCode] || 0;
              const prevVal = prevSnap.scores[key as CompetencyCode] || 0;
              const delta = Math.round((currentVal - prevVal) * 10) / 10;
              const isPositive = delta >= 0;
              const color = COMP_COLORS[key as CompetencyCode] ?? '#0aa96f';

              return (
                <div key={key} className="p-3 rounded-2xl bg-slate-50 border border-black/5 text-center">
                  <div className="w-8 h-8 mx-auto rounded-full flex items-center justify-center text-white font-black text-xs mb-1.5" style={{ backgroundColor: color }}>
                    {key}
                  </div>
                  <div className="text-[11px] font-bold text-slate-400 leading-tight min-h-[2rem]">{COMP_NAMES[key] || key}</div>
                  <div className="text-xl font-black text-slate-800 mt-1 tabular-nums">{currentVal}</div>
                  <div
                    className={`inline-flex items-center text-xs font-black mt-0.5 ${isPositive ? 'text-brand-600' : 'text-er'}`}
                  >
                    {isPositive ? <ArrowUpRight className="w-3.5 h-3.5 mr-0.5" /> : <ArrowDownRight className="w-3.5 h-3.5 mr-0.5" />}
                    {isPositive ? `+${delta}` : delta}
                  </div>
                </div>
              );
            })}
          </div>
        </Card>
      )}

      {/* Longitudinal trend (Skill Snapshots over time, §11) */}
      {childId && (
        <Card className="p-6 space-y-4">
          <div className="flex items-center gap-2">
            <TrendingUp className="w-5 h-5 text-brand-500" />
            <h2 className="text-sm font-black text-slate-700">Tiến trình năng lực của con theo thời gian</h2>
          </div>

          {chartData.length >= 2 ? (
            <div className="h-72 w-full">
              <ResponsiveContainer width="100%" height="100%">
                <LineChart data={chartData} margin={{ top: 10, right: 20, left: -20, bottom: 0 }}>
                  <CartesianGrid strokeDasharray="3 3" stroke="#eef2f0" />
                  <XAxis dataKey="date" stroke="#94a3b8" fontSize={11} fontWeight={700} />
                  <YAxis domain={[0, 100]} stroke="#94a3b8" fontSize={11} />
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
                      name={COMP_NAMES[code] || code}
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
              Cần ít nhất hai kỳ đánh giá để vẽ đường phát triển. Mỗi lần con hoàn thành bài đánh giá, hệ
              thống lưu lại một <strong className="text-slate-600">Skill Snapshot</strong> bất biến để xây dựng biểu đồ xu hướng.
            </p>
          )}
        </Card>
      )}

      {/* Reports list */}
      <div className="space-y-5">
        {reports?.map((report) => (
          <Card key={report.id} className="p-6 space-y-5">
            <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 pb-4 border-b border-black/5">
              <div>
                <Badge variant="purple">Báo cáo chuẩn hóa 360°</Badge>
                <h2 className="text-lg font-black text-slate-800 mt-2 leading-tight">{report.title}</h2>
                <span className="text-[11px] text-slate-400">
                  Phát hành {new Date(report.generatedAt).toLocaleDateString('vi-VN')}
                </span>
              </div>
              <div className="flex items-center gap-2">
                <div className="text-right mr-1">
                  <div className="text-[11px] text-slate-400 font-bold uppercase">Điểm tổng thể</div>
                  <div className="text-2xl font-black text-brand-600 tabular-nums">{report.overallScore}/100</div>
                </div>
                <Button
                  size="sm"
                  variant="outline"
                  className="gap-1.5"
                  onClick={() => downloadCsv(reportCsvFilename(report), reportToCsv(report))}
                >
                  <Download className="w-4 h-4" /> CSV
                </Button>
                <Button size="sm" variant="outline" className="gap-1.5" onClick={() => printReport()}>
                  <Printer className="w-4 h-4" /> PDF
                </Button>
              </div>
            </div>

            <div>
              <h3 className="text-[11px] font-black uppercase tracking-wider text-slate-400 mb-1">Tóm tắt chuyên môn</h3>
              <p className="text-sm text-slate-600 leading-relaxed">{report.summary}</p>
            </div>

            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div className="p-4 rounded-2xl bg-brand-50 border border-brand-100">
                <h4 className="text-xs font-black text-brand-700 flex items-center gap-1.5 mb-2 uppercase tracking-wide">
                  <CheckCircle2 className="w-4 h-4" /> Điểm mạnh của con
                </h4>
                <ul className="text-sm text-slate-700 space-y-1.5">
                  {report.strengths.map((str, i) => (
                    <li key={i} className="flex gap-2"><span className="text-brand-500 font-black">•</span><span>{str}</span></li>
                  ))}
                </ul>
              </div>
              <div className="p-4 rounded-2xl bg-rose-50 border border-rose-100">
                <h4 className="text-xs font-black text-rose-700 flex items-center gap-1.5 mb-2 uppercase tracking-wide">
                  <AlertCircle className="w-4 h-4" /> Cần rèn luyện thêm
                </h4>
                <ul className="text-sm text-slate-700 space-y-1.5">
                  {report.areasForGrowth.map((area, i) => (
                    <li key={i} className="flex gap-2"><span className="text-rose-400 font-black">•</span><span>{area}</span></li>
                  ))}
                </ul>
              </div>
            </div>

            {/* Recommendations */}
            <div className="p-5 rounded-2xl bg-slate-50 border border-black/5">
              <h4 className="text-sm font-black text-slate-800 flex items-center gap-2 mb-3">
                <Lightbulb className="w-5 h-5 text-sm" /> Khuyến nghị can thiệp & bài tập tại nhà
              </h4>
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                {report.recommendations.map((rec) => {
                  const color = COMP_COLORS[rec.targetCompetency] ?? '#0aa96f';
                  const isHome = rec.type === 'home_exercise';
                  return (
                    <div key={rec.id} className="p-4 rounded-xl bg-white border border-black/5 shadow-card">
                      <div className="flex items-center justify-between mb-1.5">
                        <span className="inline-flex items-center gap-1.5 text-[10px] font-black uppercase tracking-wide text-slate-400">
                          {isHome ? <Home className="w-3.5 h-3.5" /> : <BookOpen className="w-3.5 h-3.5" />}
                          {isHome ? 'Gia đình' : 'Bài học'}
                        </span>
                        <span className="text-[10px] font-black px-2 py-0.5 rounded-full text-white" style={{ backgroundColor: color }}>
                          {rec.targetCompetency}
                        </span>
                      </div>
                      <strong className="text-slate-800 text-sm leading-tight block">{rec.title}</strong>
                      <p className="text-xs text-slate-500 mt-1">{rec.description}</p>
                    </div>
                  );
                })}
              </div>
            </div>
          </Card>
        ))}
      </div>
    </div>
  );
}
