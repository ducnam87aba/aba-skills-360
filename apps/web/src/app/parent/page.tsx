'use client';

import React from 'react';
import { useQuery } from '@tanstack/react-query';
import { api } from '@aba/api-client';
import { Card, Badge, Button, SkillRing, ProgressBar, SectionHeader } from '@aba/ui';
import {
  Flame,
  Zap,
  TrendingUp,
  CheckCircle2,
  Lightbulb,
  ArrowRight,
  BarChart2,
  FileText,
  Sparkles,
} from 'lucide-react';
import Link from 'next/link';
import type { CompetencyCode, SkillLevel } from '@aba/types';
import { useParentChildren } from '../../features/parent/ParentChildrenContext';
import { Avatar } from '../../components/Avatar';
import { formatClassLabel } from '../../lib/classLabel';

const COMP_COLORS: Record<CompetencyCode, string> = {
  SA: '#7c5cff', ER: '#ff5d73', SM: '#ffae00', CO: '#1cb0f6', CE: '#0aa96f', PD: '#4b6bff',
};

const LEVEL_LABEL: Record<SkillLevel, { text: string; variant: 'success' | 'blue' | 'warning' | 'rose' }> = {
  advanced: { text: 'Thành thạo', variant: 'success' },
  proficient: { text: 'Khá tốt', variant: 'blue' },
  developing: { text: 'Đang phát triển', variant: 'warning' },
  beginning: { text: 'Cần rèn luyện', variant: 'rose' },
};

export default function ParentOverviewPage() {
  // The active child comes from the shared parent context (the switcher selects
  // it); defaulting to the primary child so single-child parents are unchanged.
  const { activeChild: student, isLoading } = useParentChildren();

  const { data: skills } = useQuery({
    queryKey: ['skills-current', student?.id],
    queryFn: () => (student?.id ? api.skills.getCurrent(student.id) : Promise.resolve([])),
    enabled: !!student?.id,
  });

  const { data: reports } = useQuery({
    queryKey: ['student-reports', student?.id],
    queryFn: () => (student?.id ? api.parent.getReports(student.id) : Promise.resolve([])),
    enabled: !!student?.id,
  });

  const latestReport = reports?.[0];
  // Insufficient-evidence skills carry a null score; exclude them from the mean (§45).
  const measured = (skills ?? []).filter((x) => x.score !== null);
  const overall = measured.length
    ? Math.round(measured.reduce((s, x) => s + (x.score as number), 0) / measured.length)
    : 0;

  if (isLoading) {
    return <div className="p-8 text-center text-slate-400 font-bold">Đang tải thông tin phụ huynh...</div>;
  }

  return (
    <div className="space-y-6">
      {/* Child identity hero */}
      <Card className="overflow-hidden">
        <div className="relative bg-gradient-to-br from-brand-500 to-brand-700 text-white p-6 sm:p-7">
          <div className="absolute -right-10 -top-12 w-44 h-44 rounded-full bg-white/10 blur-2xl" />
          <div className="relative flex flex-col sm:flex-row sm:items-center gap-5">
            {student && (
              <Avatar
                src={student.avatarUrl}
                name={student.fullName}
                className="w-20 h-20 rounded-3xl object-cover ring-4 ring-white/40 shadow-lg text-2xl"
              />
            )}
            <div className="flex-1 min-w-0">
              <div className="text-[11px] font-black uppercase tracking-widest text-brand-100 flex items-center gap-1.5">
                <Sparkles className="w-3.5 h-3.5" /> Cổng phụ huynh · Tổng quan
              </div>
              <h1 className="text-2xl sm:text-3xl font-black tracking-tight mt-1">{student?.fullName}</h1>
              <p className="text-brand-100 text-sm mt-0.5">
                {formatClassLabel(student?.className)} · {student?.schoolName}
              </p>
            </div>
            <div className="flex gap-2.5">
              <HeroStat icon={<Flame className="w-5 h-5" />} value={student?.currentStreak ?? 0} label="Chuỗi ngày" />
              <HeroStat icon={<Zap className="w-5 h-5" />} value={(student?.totalXp ?? 0).toLocaleString('vi-VN')} label="Tổng XP" />
              <HeroStat icon={<BarChart2 className="w-5 h-5" />} value={`Lv ${student?.level ?? 1}`} label="Cấp độ" />
            </div>
          </div>
        </div>

        {/* Quick nav */}
        <div className="grid grid-cols-2 sm:grid-cols-4 divide-x divide-black/5 border-t border-black/5">
          <QuickLink href="/parent/skills" icon={<TrendingUp className="w-4 h-4" />} label="Năng lực" />
          <QuickLink href="/parent/reports" icon={<FileText className="w-4 h-4" />} label="Báo cáo" />
          <QuickLink href="/parent/activities" icon={<CheckCircle2 className="w-4 h-4" />} label="Hoạt động" />
          <QuickLink href="/parent/profile" icon={<CheckCircle2 className="w-4 h-4" />} label="Hồ sơ" />
        </div>
      </Card>

      {/* Competency snapshot */}
      <SectionHeader
        eyebrow="Đo lường 360°"
        title="Hồ sơ năng lực của con"
        description="Sáu nhóm năng lực cốt lõi, chuẩn hóa trên thang 0–100."
        action={
          <Link href="/parent/skills">
            <Button size="sm" variant="outline" className="gap-1">
              Xem xu hướng <ArrowRight className="w-3.5 h-3.5" />
            </Button>
          </Link>
        }
      />

      <div className="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-6 gap-4">
        {skills?.map((skill) => (
          <Card key={skill.skillCode} hover className="p-4 flex flex-col items-center text-center">
            {skill.score === null ? (
              <div className="w-[78px] h-[78px] rounded-full border-4 border-dashed border-slate-200 flex items-center justify-center text-slate-400 text-[10px] font-black leading-tight px-2">
                Chưa đủ dữ liệu
              </div>
            ) : (
              <SkillRing value={skill.score} color={COMP_COLORS[skill.skillCode]} size={78} stroke={9} />
            )}
            <h3 className="font-black text-slate-800 text-xs leading-tight mt-3 min-h-[2rem]">{skill.skillName}</h3>
            <div className="mt-2">
              {skill.score === null ? (
                <Badge variant="warning">Chưa đo</Badge>
              ) : (
                <Badge variant={LEVEL_LABEL[skill.level].variant}>{LEVEL_LABEL[skill.level].text}</Badge>
              )}
            </div>
          </Card>
        ))}
      </div>

      {/* Latest report + recommendations */}
      {latestReport && (
        <div className="grid grid-cols-1 lg:grid-cols-3 gap-5">
          <Card className="p-6 lg:col-span-2 space-y-4">
            <div className="flex items-start justify-between gap-3">
              <div>
                <Badge variant="purple">Báo cáo mới nhất</Badge>
                <h2 className="text-base font-black text-slate-800 mt-2 leading-tight">{latestReport.title}</h2>
                <span className="text-[11px] text-slate-400">
                  Phát hành {new Date(latestReport.generatedAt).toLocaleDateString('vi-VN')}
                </span>
              </div>
              <div className="text-right shrink-0">
                <div className="text-[11px] text-slate-400 font-bold uppercase">Tổng thể</div>
                <div className="text-3xl font-black text-brand-600 tabular-nums">{latestReport.overallScore}</div>
              </div>
            </div>

            <ProgressBar value={latestReport.overallScore} color="#0aa96f" height={10} />

            <p className="text-sm text-slate-600 leading-relaxed">{latestReport.summary}</p>

            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4 pt-1">
              <div className="p-4 rounded-2xl bg-brand-50 border border-brand-100">
                <h3 className="text-xs font-black text-brand-700 flex items-center gap-1.5 mb-2 uppercase tracking-wide">
                  <CheckCircle2 className="w-4 h-4" /> Điểm mạnh
                </h3>
                <ul className="text-sm text-slate-700 space-y-1.5">
                  {latestReport.strengths.map((str, i) => (
                    <li key={i} className="flex gap-2"><span className="text-brand-500 font-black">•</span><span>{str}</span></li>
                  ))}
                </ul>
              </div>
              <div className="p-4 rounded-2xl bg-amber-50 border border-amber-100">
                <h3 className="text-xs font-black text-amber-700 flex items-center gap-1.5 mb-2 uppercase tracking-wide">
                  <TrendingUp className="w-4 h-4" /> Cần rèn luyện
                </h3>
                <ul className="text-sm text-slate-700 space-y-1.5">
                  {latestReport.areasForGrowth.map((area, i) => (
                    <li key={i} className="flex gap-2"><span className="text-amber-500 font-black">•</span><span>{area}</span></li>
                  ))}
                </ul>
              </div>
            </div>
          </Card>

          {/* Recommendations rail */}
          <Card className="p-6 space-y-4 bg-gradient-to-br from-sa/5 to-white">
            <h3 className="text-sm font-black text-slate-800 flex items-center gap-2">
              <Lightbulb className="w-5 h-5 text-sm" /> Đồng hành cùng con
            </h3>
            <div className="space-y-3">
              {latestReport.recommendations.map((rec) => (
                <div key={rec.id} className="p-4 rounded-2xl bg-white border border-black/5 shadow-card">
                  <div className="flex items-center gap-2 mb-1">
                    <span
                      className="w-2.5 h-2.5 rounded-full shrink-0"
                      style={{ backgroundColor: COMP_COLORS[rec.targetCompetency] }}
                    />
                    <span className="text-[10px] font-black uppercase tracking-wide text-slate-400">
                      {rec.type === 'home_exercise' ? 'Bài tập gia đình' : 'Bài học'}
                    </span>
                  </div>
                  <h4 className="font-black text-slate-800 text-sm leading-tight">{rec.title}</h4>
                  <p className="text-xs text-slate-500 mt-1">{rec.description}</p>
                </div>
              ))}
            </div>
            <Link href="/parent/reports">
              <Button block variant="purple" className="justify-between">
                Xem toàn bộ báo cáo <ArrowRight className="w-4 h-4" />
              </Button>
            </Link>
          </Card>
        </div>
      )}
    </div>
  );
}

function HeroStat({ icon, value, label }: { icon: React.ReactNode; value: React.ReactNode; label: string }) {
  return (
    <div className="text-center px-3 py-2 rounded-2xl bg-white/15 backdrop-blur min-w-[68px]">
      <div className="flex items-center justify-center gap-1 font-black text-white">{icon}<span className="tabular-nums">{value}</span></div>
      <div className="text-[10px] font-bold text-brand-100 mt-0.5 uppercase tracking-wide">{label}</div>
    </div>
  );
}

function QuickLink({ href, icon, label }: { href: string; icon: React.ReactNode; label: string }) {
  return (
    <Link
      href={href}
      className="flex items-center justify-center gap-2 py-3.5 text-sm font-extrabold text-slate-500 hover:bg-brand-50 hover:text-brand-700 transition-colors"
    >
      <span className="text-brand-500">{icon}</span> {label}
    </Link>
  );
}
