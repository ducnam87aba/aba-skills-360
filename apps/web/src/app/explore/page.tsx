'use client';

import React from 'react';
import { useQuery } from '@tanstack/react-query';
import { api } from '@aba/api-client';
import { Card, Button, Badge } from '@aba/ui';
import { Compass, Sparkles, Brain, Heart, ShieldCheck, Clock, ListChecks, ArrowRight, Lightbulb, BookOpen, Home, Target } from 'lucide-react';
import Link from 'next/link';
import type { CompetencyCode, RecommendationItem } from '@aba/types';
import { pathUrlForCompetency } from '../../lib/pathAnchor';

const COMP_COLORS: Record<CompetencyCode, string> = {
  SA: '#7c5cff', ER: '#ff5d73', SM: '#ffae00', CO: '#1cb0f6', CE: '#0aa96f', PD: '#4b6bff',
};
const COMP_NAMES: Record<string, string> = {
  SA: 'Tự nhận thức', ER: 'Quản lý cảm xúc', SM: 'Tự quản lý',
  CO: 'Giao tiếp', CE: 'Hợp tác & Đồng cảm', PD: 'Giải quyết vấn đề',
};

const assessments = [
  {
    id: 1,
    title: 'Đánh giá Kỹ năng Toàn diện 360°',
    category: 'Tổng hợp 6 năng lực',
    duration: '15 phút',
    questions: 20,
    description: 'Đo lường đa diện: Tự nhận thức, Cảm xúc xã hội, Giao tiếp, Thích ứng và Ra quyết định.',
    color: '#0aa96f',
    icon: Sparkles,
    recommended: true,
  },
  {
    id: 2,
    title: 'Năng lực Tư duy Phản biện',
    category: 'Giải quyết vấn đề',
    duration: '10 phút',
    questions: 12,
    description: 'Đo khả năng lập luận, suy diễn quy nạp và xử lý tình huống logic phức tạp.',
    color: '#4b6bff',
    icon: Brain,
    recommended: false,
  },
  {
    id: 3,
    title: 'Trắc nghiệm Trí tuệ Cảm xúc (EQ)',
    category: 'Cảm xúc xã hội',
    duration: '8 phút',
    questions: 10,
    description: 'Đo năng lực nhận diện cảm xúc bản thân, thấu cảm và ứng xử hòa nhập.',
    color: '#ff5d73',
    icon: Heart,
    recommended: false,
  },
  {
    id: 4,
    title: 'Kỹ năng Hợp tác & Lãnh đạo nhóm',
    category: 'Hợp tác',
    duration: '12 phút',
    questions: 14,
    description: 'Đánh giá cách em phối hợp, chia sẻ và hỗ trợ bạn bè trong hoạt động chung.',
    color: '#1cb0f6',
    icon: ShieldCheck,
    recommended: false,
  },
];

export default function ExplorePage() {
  // Live, deterministic next-best content for this learner's measured gaps (PA6).
  const { data: recResult, isLoading: recsLoading } = useQuery({
    queryKey: ['recommendations-current'],
    queryFn: () => api.recommendations.getCurrent(),
  });

  return (
    <div className="space-y-8">
      <div className="relative overflow-hidden rounded-3xl bg-gradient-to-br from-brand-500 to-brand-700 text-white p-6 sm:p-8 shadow-[0_6px_0_0_#036c4a]">
        <div className="flex items-center gap-2 text-brand-100 text-xs font-black uppercase tracking-widest mb-2">
          <Compass className="w-4 h-4" /> Khám phá
        </div>
        <h1 className="text-2xl sm:text-3xl font-black tracking-tight">Đánh giá để cập nhật hồ sơ năng lực</h1>
        <p className="text-brand-100 text-sm mt-2 max-w-xl">
          Mỗi bài đánh giá chuẩn hóa tạo một <strong className="text-white">Skill Snapshot</strong> — giúp theo dõi sự
          phát triển của em theo thời gian.
        </p>
        <div className="absolute -right-8 -top-10 w-40 h-40 rounded-full bg-white/10 blur-2xl" />
      </div>

      <RecommendationRail items={recResult?.items ?? []} loading={recsLoading} />

      <div className="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-5">
        {assessments.map((item) => {
          const Icon = item.icon;
          return (
            <Card key={item.id} hover className="flex flex-col overflow-hidden">
              <div className="h-24 relative" style={{ backgroundColor: item.color }}>
                <div className="absolute inset-0 flex items-center justify-between px-5">
                  <div className="w-14 h-14 rounded-2xl bg-white/20 backdrop-blur flex items-center justify-center text-white">
                    <Icon className="w-7 h-7" />
                  </div>
                  {item.recommended && <Badge className="bg-white/90 !text-brand-700">Khuyên dùng</Badge>}
                </div>
                <div className="absolute -right-4 -bottom-6 w-24 h-24 rounded-full bg-white/10" />
              </div>

              <div className="p-5 flex-1 flex flex-col">
                <h3 className="text-lg font-black text-slate-800 leading-tight">{item.title}</h3>
                <p className="text-xs text-slate-500 mt-2 flex-1">{item.description}</p>

                <div className="flex items-center gap-4 text-[11px] font-bold text-slate-400 mt-4">
                  <span className="inline-flex items-center gap-1"><Clock className="w-4 h-4" /> {item.duration}</span>
                  <span className="inline-flex items-center gap-1"><ListChecks className="w-4 h-4" /> {item.questions} câu</span>
                </div>

                <Link href={`/assessment/${item.id}`} className="mt-4">
                  <Button block style={{ backgroundColor: item.color }} className="justify-between">
                    Bắt đầu <ArrowRight className="w-4 h-4" />
                  </Button>
                </Link>
              </div>
            </Card>
          );
        })}
      </div>
    </div>
  );
}

/* ================= Personalized next-best rail (deterministic engine) ================= */

function RecommendationRail({ items, loading }: { items: RecommendationItem[]; loading: boolean }) {
  return (
    <section className="space-y-4">
      <div className="flex items-center gap-2">
        <Lightbulb className="w-5 h-5 text-sm" />
        <h2 className="text-lg font-black text-slate-800 tracking-tight">Gợi ý học tiếp cho em</h2>
      </div>

      {loading ? (
        <div className="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-4">
          {[0, 1, 2].map((i) => (
            <div key={i} className="h-32 rounded-2xl bg-slate-100 animate-pulse" />
          ))}
        </div>
      ) : items.length === 0 ? (
        <Card className="p-5 border-dashed">
          <p className="text-sm text-slate-500">
            Chưa có gợi ý cá nhân hóa. Hoàn thành một bài <strong className="text-slate-700">đánh giá 360°</strong> bên dưới để hệ thống
            đo năng lực và đề xuất đúng trọng tâm cho em.
          </p>
        </Card>
      ) : (
        <div className="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-4">
          {items.map((rec) => {
            const color = COMP_COLORS[rec.targetCompetency] ?? '#0aa96f';
            const isHome = rec.type === 'home_exercise';
            const TypeIcon = isHome ? Home : BookOpen;
            return (
              <Card key={rec.id} hover className="flex flex-col p-5">
                <div className="flex items-center justify-between mb-2">
                  <span className="inline-flex items-center gap-1.5 text-[10px] font-black uppercase tracking-wide text-slate-400">
                    <TypeIcon className="w-3.5 h-3.5" />
                    {isHome ? 'Luyện ở nhà' : 'Bài học'}
                  </span>
                  {typeof rec.priority === 'number' && (
                    <span className="text-[10px] font-black px-2 py-0.5 rounded-full bg-slate-100 text-slate-500">
                      Ưu tiên #{rec.priority}
                    </span>
                  )}
                </div>

                <h3 className="text-sm font-black text-slate-800 leading-tight">{rec.title}</h3>
                <p className="text-xs text-slate-500 mt-1.5 flex-1">{rec.description}</p>

                <div className="flex items-center gap-2 mt-3 pt-3 border-t border-black/5">
                  <span
                    className="inline-flex items-center gap-1 text-[10px] font-black px-2 py-0.5 rounded-full text-white"
                    style={{ backgroundColor: color }}
                  >
                    {rec.targetCompetency}
                  </span>
                  <span className="text-[11px] font-bold text-slate-400 truncate">{COMP_NAMES[rec.targetCompetency] || rec.targetCompetency}</span>
                  {typeof rec.targetScore === 'number' && (
                    <span className="ml-auto inline-flex items-center gap-1 text-[11px] font-black text-slate-500" title={rec.reason}>
                      <Target className="w-3.5 h-3.5" /> {Math.round(rec.targetScore)}
                    </span>
                  )}
                </div>

                {/* PA36: close the loop — jump straight to this competency's chapter. */}
                {!isHome && (
                  <Link href={pathUrlForCompetency(rec.targetCompetency)} className="mt-3 block">
                    <Button block size="sm" style={{ backgroundColor: color }} className="justify-between">
                      Luyện chương này <ArrowRight className="w-4 h-4" />
                    </Button>
                  </Link>
                )}
              </Card>
            );
          })}
        </div>
      )}
    </section>
  );
}
