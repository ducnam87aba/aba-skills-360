'use client';

import React, { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import Link from 'next/link';
import { api } from '@aba/api-client';
import { Card, Badge, Button, ProgressBar, SkillRing, StreakCalendar } from '@aba/ui';
import type { LearningNode, LearningUnit, CompetencyCode } from '@aba/types';
import {
  Check,
  Lock,
  Play,
  Crown,
  Star,
  Trophy,
  X,
  Flame,
  Zap,
  Sparkles,
  Dumbbell,
  ChevronRight,
} from 'lucide-react';

const COMP_COLORS: Record<CompetencyCode, string> = {
  SA: '#7c5cff',
  ER: '#ff5d73',
  SM: '#ffae00',
  CO: '#1cb0f6',
  CE: '#0aa96f',
  PD: '#4b6bff',
};

const DAY_LABELS = ['H2', 'H3', 'H4', 'H5', 'T6', 'T7', 'CN'];

export default function LearnPage() {
  const queryClient = useQueryClient();
  const [activeLesson, setActiveLesson] = useState<LearningNode | null>(null);
  const [lessonFinished, setLessonFinished] = useState(false);

  const { data: path, isLoading } = useQuery({
    queryKey: ['learning-path'],
    // No hardcoded id: the server resolves the learner from the cookie session.
    queryFn: () => api.learning.getPath(),
  });
  const { data: student } = useQuery({
    queryKey: ['student-me'],
    queryFn: () => api.student.getMe(),
  });
  const { data: skills } = useQuery({
    queryKey: ['skills-current'],
    queryFn: () => api.skills.getCurrent(),
  });

  const completeMutation = useMutation({
    mutationFn: (node: LearningNode) => api.learning.completeNode(node.id, node.xpReward),
    onSuccess: () => {
      setLessonFinished(true);
      queryClient.invalidateQueries({ queryKey: ['learning-path'] });
      queryClient.invalidateQueries({ queryKey: ['student-me'] });
    },
  });

  const measuredSkills = (skills ?? []).filter((x) => x.score !== null);
  const overall = measuredSkills.length
    ? Math.round(measuredSkills.reduce((s, x) => s + (x.score as number), 0) / measuredSkills.length)
    : 0;
  const xpIntoLevel = (student?.totalXp ?? 0) % 200;

  if (isLoading) {
    return (
      <div className="grid grid-cols-1 lg:grid-cols-[220px_minmax(0,1fr)_320px] gap-6">
        <div className="hidden lg:block h-96 rounded-3xl bg-slate-100 animate-pulse" />
        <div className="h-[540px] rounded-3xl bg-slate-100 animate-pulse" />
        <div className="hidden lg:block h-96 rounded-3xl bg-slate-100 animate-pulse" />
      </div>
    );
  }

  return (
    <div className="grid grid-cols-1 lg:grid-cols-[220px_minmax(0,1fr)_320px] gap-6 lg:gap-8">
      {/* ---------- Left: unit rail ---------- */}
      <div className="order-2 lg:order-1 hidden lg:block">
        <div className="sticky top-24 space-y-2">
          <p className="text-[11px] font-black uppercase tracking-widest text-slate-400 px-2 mb-3">
            Lộ trình của bạn
          </p>
          {path?.units.map((unit, i) => {
            const color = COMP_COLORS[unit.competencyTarget];
            const active = unit.status === 'active';
            return (
              <a
                key={unit.id}
                href={`#${unit.id}`}
                className="flex items-center gap-3 px-3 py-3 rounded-2xl hover:bg-white transition-colors"
                style={active ? { backgroundColor: `${color}12` } : undefined}
              >
                <div
                  className="w-10 h-10 rounded-xl flex items-center justify-center text-white font-black shrink-0"
                  style={{ backgroundColor: unit.status === 'locked' ? '#cbd5e1' : color }}
                >
                  {unit.status === 'locked' ? <Lock className="w-5 h-5" /> : i + 1}
                </div>
                <div className="min-w-0">
                  <p className="text-sm font-black text-slate-700 truncate">Chương {i + 1}</p>
                  <p className="text-xs text-slate-400 truncate">{unit.title.split(':')[1]?.trim() || unit.description}</p>
                </div>
              </a>
            );
          })}
        </div>
      </div>

      {/* ---------- Center: the path ---------- */}
      <div className="order-1 lg:order-2 space-y-10">
        {path?.units.map((unit, unitIdx) => (
          <UnitTrail
            key={unit.id}
            unit={unit}
            index={unitIdx}
            isLast={unitIdx === (path.units.length ?? 1) - 1}
            onNode={(node) => {
              setActiveLesson(node);
              setLessonFinished(node.status === 'completed');
            }}
          />
        ))}
      </div>

      {/* ---------- Right: stats panel ---------- */}
      <aside className="order-3 hidden lg:block">
        <div className="sticky top-24 space-y-4">
          {/* Greeting / continue */}
          <Card className="p-5 bg-gradient-to-br from-brand-500 to-brand-600 border-0 text-white">
            <div className="flex items-center gap-2 text-brand-100 text-xs font-black uppercase tracking-wide mb-1">
              <Sparkles className="w-4 h-4" /> Xin chào {student?.firstName || 'bạn'}
            </div>
            <p className="text-lg font-black leading-snug">Giữ chuỗi {student?.currentStreak ?? 0} ngày bằng 1 bài học hôm nay!</p>
            <Button variant="amber" block className="mt-4" onClick={() => {
              const first = path?.units.find((u) => u.status === 'active')?.nodes.find((n) => n.status === 'unlocked');
              if (first) { setActiveLesson(first); setLessonFinished(false); }
            }}>
              <Play className="w-4 h-4 fill-white" /> Tiếp tục
            </Button>
          </Card>

          {/* Streak calendar */}
          <Card className="p-5">
            <div className="flex items-center justify-between mb-3">
              <h3 className="font-black text-slate-700 flex items-center gap-2">
                <Flame className="w-5 h-5 text-sm" style={{ color: '#ffae00' }} /> Chuỗi ngày
              </h3>
              <Badge variant="warning">{student?.currentStreak ?? 0} 🔥</Badge>
            </div>
            <StreakCalendar
              days={DAY_LABELS.map((label, i) => ({
                label,
                active: i < (student?.currentStreak ?? 0) % 7 || (student?.currentStreak ?? 0) >= 7,
              }))}
            />
          </Card>

          {/* Level progress */}
          <Card className="p-5">
            <div className="flex items-center justify-between mb-3">
              <h3 className="font-black text-slate-700 flex items-center gap-2">
                <Trophy className="w-5 h-5 text-sa" /> Cấp độ {student?.level ?? 1}
              </h3>
              <span className="text-xs font-bold text-slate-400">{xpIntoLevel}/200 XP</span>
            </div>
            <ProgressBar value={xpIntoLevel} max={200} color="#1cb0f6" />
            <p className="text-[11px] text-slate-400 mt-2 font-semibold">Cần {200 - xpIntoLevel} XP nữa để lên cấp kế tiếp.</p>
          </Card>

          {/* Skills snapshot */}
          <Card className="p-5">
            <div className="flex items-center gap-4">
              <SkillRing value={overall} color="#0aa96f" size={92} label={<span>{overall}</span>} sublabel="Năng lực" />
              <div className="min-w-0">
                <h3 className="font-black text-slate-700">Chỉ số tổng quan</h3>
                <p className="text-xs text-slate-400 mt-0.5">Cập nhật từ đánh giá 360°</p>
                <Link href="/profile" className="inline-flex items-center gap-1 text-xs font-black text-brand-600 mt-2 hover:gap-2 transition-all">
                  Xem hồ sơ <ChevronRight className="w-4 h-4" />
                </Link>
              </div>
            </div>
          </Card>
        </div>
      </aside>

      {/* ---------- Lesson modal ---------- */}
      {activeLesson && (
        <LessonModal
          node={activeLesson}
          finished={lessonFinished}
          pending={completeMutation.isPending}
          onClose={() => setActiveLesson(null)}
          onComplete={() => completeMutation.mutate(activeLesson)}
        />
      )}
    </div>
  );
}

/* ================= Unit + winding node trail ================= */

function UnitTrail({
  unit,
  index,
  isLast,
  onNode,
}: {
  unit: LearningUnit;
  index: number;
  isLast: boolean;
  onNode: (n: LearningNode) => void;
}) {
  const color = COMP_COLORS[unit.competencyTarget];
  const locked = unit.status === 'locked';

  // winding offsets (px) — a gentle S-curve
  const pattern = [0, 54, 90, 54, 0, -54, -90, -54];

  return (
    <section id={unit.id} className="scroll-mt-24">
      {/* Unit banner */}
      <div
        className="relative overflow-hidden rounded-2xl px-5 py-4 text-white shadow-[0_4px_0_0_rgba(0,0,0,0.12)]"
        style={{ backgroundColor: locked ? '#94a3b8' : color }}
      >
        <div className="flex items-center justify-between">
          <div className="min-w-0">
            <div className="flex items-center gap-2">
              <span className="text-[11px] font-black uppercase tracking-widest opacity-80">Đơn vị {index + 1}</span>
              {unit.isPriority && !locked && (
                <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-white/25 text-[10px] font-black uppercase tracking-wide">
                  <Flame className="w-3 h-3" /> Ưu tiên
                </span>
              )}
            </div>
            <h2 className="text-lg font-black leading-tight mt-0.5">{unit.title}</h2>
            {unit.reason && (
              <p className="text-xs font-semibold mt-1 opacity-90 leading-snug">{unit.reason}</p>
            )}
          </div>
          <div className="w-11 h-11 rounded-xl bg-white/20 flex items-center justify-center shrink-0">
            {locked ? <Lock className="w-6 h-6" /> : <Crown className="w-6 h-6" />}
          </div>
        </div>
        <div className="absolute -right-6 -bottom-8 w-28 h-28 rounded-full bg-white/10 blur-xl" />
      </div>

      {/* Trail */}
      <div className="relative mt-6 flex flex-col items-center gap-5">
        <div className="absolute top-0 bottom-0 left-1/2 -translate-x-1/2 border-l-2 border-dashed border-slate-200" aria-hidden />

        {unit.nodes.map((node, i) => (
          <div
            key={node.id}
            className="relative z-10 flex flex-col items-center animate-pop-in"
            style={{ transform: `translateX(${locked ? 0 : pattern[i % pattern.length]}px)`, animationDelay: `${i * 60}ms` }}
          >
            <NodeButton node={node} lockedUnit={locked} onNode={onNode} />
            <div className="mt-2 max-w-[170px] text-center">
              <p className="text-[11px] font-bold text-slate-500 line-clamp-2">{node.title}</p>
              <p className="text-[10px] font-black text-slate-300 mt-0.5">+{node.xpReward} XP</p>
            </div>
          </div>
        ))}

        {isLast && (
          <div className="relative z-10 mt-2 flex flex-col items-center gap-1 text-slate-300">
            <div className="w-12 h-12 rounded-full bg-slate-100 flex items-center justify-center">
              <Trophy className="w-6 h-6" />
            </div>
            <span className="text-[11px] font-black uppercase tracking-wide">Hoàn thành chương</span>
          </div>
        )}
      </div>
    </section>
  );
}

function NodeButton({ node, lockedUnit, onNode }: { node: LearningNode; lockedUnit: boolean; onNode: (n: LearningNode) => void }) {
  const isActive = !lockedUnit && node.status === 'unlocked';
  const isDone = node.status === 'completed';
  const isLocked = lockedUnit || node.status === 'locked';
  const isBoss = node.type === 'boss_challenge';
  const isQuiz = node.type === 'quiz';

  let bg = '#e2e8f0';
  let edge = '#cbd5e1';
  let fg = '#94a3b8';
  if (isDone) { bg = '#0aa96f'; edge = '#036c4a'; fg = '#fff'; }
  else if (isActive) { bg = isBoss ? '#ffae00' : '#fff'; edge = isBoss ? '#d98c00' : '#cbd5e1'; fg = isBoss ? '#fff' : '#64748b'; }

  const sizeCls = isBoss ? 'w-24 h-24' : 'w-[72px] h-[72px]';

  return (
    <div className="relative">
      {isActive && (
        <div className="absolute -top-9 left-1/2 -translate-x-1/2 whitespace-nowrap px-3 py-1.5 rounded-lg bg-white text-brand-600 text-[11px] font-black uppercase tracking-wide shadow-card animate-float-y">
          {isBoss ? 'Thử thách' : 'Bắt đầu'}
          <span className="absolute -bottom-1 left-1/2 -translate-x-1/2 w-2.5 h-2.5 bg-white rotate-45" />
        </div>
      )}
      <button
        disabled={isLocked}
        onClick={() => !isLocked && onNode(node)}
        aria-label={node.title}
        className={`pressable ${sizeCls} rounded-full flex items-center justify-center transition-transform ${isLocked ? 'cursor-not-allowed' : 'hover:brightness-105 active:scale-95'} ${isActive && !isBoss ? 'ring-4 ring-offset-2 ring-brand-100' : ''}`}
        style={{ backgroundColor: bg, ['--press-edge' as any]: edge, color: fg }}
      >
        {isDone ? (
          <Check className="w-9 h-9" strokeWidth={3.5} />
        ) : isLocked ? (
          <Lock className="w-7 h-7" />
        ) : isBoss ? (
          <Crown className="w-10 h-10" strokeWidth={2.4} />
        ) : isQuiz ? (
          <Dumbbell className="w-8 h-8" strokeWidth={2.6} />
        ) : (
          <Play className="w-8 h-8 fill-current translate-x-0.5" />
        )}
        {isQuiz && isActive && (
          <Star className="absolute -top-1 -right-1 w-5 h-5 fill-sm text-sm drop-shadow" />
        )}
      </button>
    </div>
  );
}

/* ================= Lesson modal ================= */

function LessonModal({
  node,
  finished,
  pending,
  onClose,
  onComplete,
}: {
  node: LearningNode;
  finished: boolean;
  pending: boolean;
  onClose: () => void;
  onComplete: () => void;
}) {
  // PA17: when the node is bound to a Content Library lesson, show its real body.
  const { data: lesson } = useQuery({
    queryKey: ['lesson', node.contentId ?? null],
    queryFn: () => api.learning.getLesson(node.contentId as number),
    enabled: node.contentId != null,
  });

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 backdrop-blur-sm p-4">
      <Card className="w-full max-w-lg p-6 sm:p-7 relative animate-pop-in">
        <button
          onClick={onClose}
          className="absolute top-4 right-4 p-2 rounded-xl text-slate-400 hover:bg-slate-100 hover:text-slate-600"
          aria-label="Đóng"
        >
          <X className="w-5 h-5" />
        </button>

        {!finished ? (
          <div className="space-y-5">
            <div className="flex items-center gap-2">
              <Badge variant="blue">Bài học kỹ năng</Badge>
              <span className="flex items-center gap-1 text-xs font-black text-sm" style={{ color: '#1cb0f6' }}>
                <Zap className="w-4 h-4 fill-co" /> +{node.xpReward} XP
              </span>
            </div>
            <div>
              <h3 className="text-xl font-black text-slate-800 leading-tight">{lesson?.title ?? node.title}</h3>
              <p className="text-sm text-slate-500 mt-1">{lesson?.excerpt ?? node.description}</p>
            </div>
            <div className="p-4 rounded-2xl bg-slate-50 border border-slate-100 space-y-2">
              <strong className="text-slate-700 text-sm">{lesson ? 'Nội dung bài học' : 'Tình huống thực hành'}</strong>
              <p className="text-sm text-slate-600 leading-relaxed">
                {lesson ? lesson.content : 'Bạn An đang trình bày ý kiến thì bạn Bình liên tục ngắt lời. Theo nguyên tắc lắng nghe chủ động, em sẽ nhẹ nhàng giơ tay và đề xuất để bạn An nói hết lượt rồi mới phản biện.'}
              </p>
            </div>
            <Button block size="lg" onClick={onComplete} disabled={pending}>
              {pending ? 'Đang lưu...' : 'Hoàn thành bài học'}
            </Button>
          </div>
        ) : (
          <div className="text-center py-4 space-y-4">
            <div className="w-20 h-20 rounded-full bg-brand-100 text-brand-600 flex items-center justify-center mx-auto animate-pop-in">
              <Trophy className="w-10 h-10" />
            </div>
            <div>
              <h3 className="text-2xl font-black text-slate-800">Tuyệt vời! 🎉</h3>
              <p className="text-sm text-slate-500 mt-1">Đã ghi nhận vào sổ cái XP của bạn.</p>
            </div>
            <div className="inline-flex items-center gap-2 px-4 py-2 rounded-2xl bg-sm/15 text-sm font-black text-amber-700" style={{ color: '#b57200' }}>
              <Zap className="w-5 h-5 fill-sm" /> +{node.xpReward} XP
            </div>
            <Button block size="lg" onClick={onClose}>Tiếp tục lộ trình</Button>
          </div>
        )}
      </Card>
    </div>
  );
}
