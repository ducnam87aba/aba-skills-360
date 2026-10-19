'use client';

import React, { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { api } from '@aba/api-client';
import { Card, Badge, Button } from '@aba/ui';
import type { LearningNode } from '@aba/types';
import {
  CheckCircle2,
  Lock,
  Play,
  Sparkles,
  Trophy,
  X,
  Zap,
  Award,
} from 'lucide-react';

export default function LearnPage() {
  const queryClient = useQueryClient();
  const [activeLesson, setActiveLesson] = useState<LearningNode | null>(null);
  const [lessonFinished, setLessonFinished] = useState(false);

  const { data: path, isLoading } = useQuery({
    queryKey: ['learning-path'],
    queryFn: () => api.learning.getPath(1),
  });

  const { data: student } = useQuery({
    queryKey: ['student-me'],
    queryFn: () => api.student.getMe(),
  });

  const completeMutation = useMutation({
    mutationFn: (node: LearningNode) =>
      api.learning.completeNode(node.id, node.xpReward),
    onSuccess: () => {
      setLessonFinished(true);
      queryClient.invalidateQueries({ queryKey: ['learning-path'] });
      queryClient.invalidateQueries({ queryKey: ['student-me'] });
    },
  });

  if (isLoading) {
    return (
      <div className="space-y-6 animate-pulse">
        <div className="h-28 bg-slate-200 dark:bg-slate-800 rounded-3xl" />
        <div className="h-64 bg-slate-200 dark:bg-slate-800 rounded-3xl" />
      </div>
    );
  }

  return (
    <div className="space-y-8">
      {/* Hero Banner with Progression */}
      <div className="relative overflow-hidden rounded-3xl bg-gradient-to-r from-emerald-600 to-teal-700 text-white p-6 sm:p-8 shadow-xl shadow-emerald-900/10">
        <div className="relative z-10 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
          <div>
            <div className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-white/20 backdrop-blur-md text-xs font-semibold mb-2">
              <Sparkles className="w-3.5 h-3.5" /> Lộ trình cá nhân hoá
            </div>
            <h1 className="text-2xl sm:text-3xl font-black tracking-tight">
              Xin chào, {student?.firstName || 'Bạn'}! 🚀
            </h1>
            <p className="text-emerald-100 text-sm mt-1 max-w-md">
              Hôm nay hãy tiếp tục hoàn thành bài tập giao tiếp để giữ vững chuỗi{' '}
              <strong className="text-white">{student?.currentStreak || 7} ngày</strong> nhé!
            </p>
          </div>

          <div className="flex items-center gap-3 bg-white/10 backdrop-blur-md p-3 rounded-2xl border border-white/20">
            <div className="w-12 h-12 rounded-xl bg-amber-400/20 flex items-center justify-center text-amber-300">
              <Trophy className="w-6 h-6" />
            </div>
            <div>
              <div className="text-xs text-emerald-100 font-medium">Cấp độ hiện tại</div>
              <div className="text-lg font-bold text-white">Level {student?.level || 8}</div>
            </div>
          </div>
        </div>

        {/* Decorative circle */}
        <div className="absolute -right-10 -bottom-10 w-44 h-44 rounded-full bg-white/10 blur-2xl pointer-events-none" />
      </div>

      {/* Path Units */}
      {path?.units.map((unit) => (
        <div key={unit.id} className="space-y-4">
          <div className="flex items-center justify-between">
            <div>
              <h2 className="text-lg font-bold text-slate-800 dark:text-slate-100">
                {unit.title}
              </h2>
              <p className="text-xs text-slate-500 dark:text-slate-400 mt-0.5">
                {unit.description}
              </p>
            </div>
            <Badge variant={unit.status === 'active' ? 'success' : 'default'}>
              {unit.status === 'active' ? 'Đang học' : 'Chưa mở'}
            </Badge>
          </div>

          {/* Node path chain (Duolingo progression style) */}
          <Card className="p-6 relative overflow-hidden bg-white/80 dark:bg-slate-900/80 backdrop-blur-sm">
            <div className="flex flex-col items-center gap-6 max-w-sm mx-auto">
              {unit.nodes.map((node, nodeIdx) => {
                const isCompleted = node.status === 'completed';
                const isUnlocked = node.status === 'unlocked';
                const isLocked = node.status === 'locked';

                const xOffset =
                  nodeIdx % 3 === 0
                    ? 'translate-x-0'
                    : nodeIdx % 3 === 1
                    ? 'translate-x-8'
                    : '-translate-x-8';

                return (
                  <div key={node.id} className={`flex flex-col items-center ${xOffset} transition-all`}>
                    <div className="relative group">
                      {isCompleted && (
                        <button
                          onClick={() => {
                            setActiveLesson(node);
                            setLessonFinished(true);
                          }}
                          className="w-16 h-16 rounded-2xl bg-emerald-500 hover:bg-emerald-400 text-white flex items-center justify-center shadow-lg shadow-emerald-500/30 transition-transform hover:scale-110 active:scale-95"
                        >
                          <CheckCircle2 className="w-8 h-8" />
                        </button>
                      )}

                      {isUnlocked && (
                        <button
                          onClick={() => {
                            setActiveLesson(node);
                            setLessonFinished(false);
                          }}
                          className="w-16 h-16 rounded-2xl bg-gradient-to-tr from-amber-500 to-yellow-400 text-white flex items-center justify-center shadow-lg shadow-amber-500/30 transition-transform animate-bounce hover:scale-110 active:scale-95"
                        >
                          <Play className="w-7 h-7 fill-white translate-x-0.5" />
                        </button>
                      )}

                      {isLocked && (
                        <div className="w-16 h-16 rounded-2xl bg-slate-200 dark:bg-slate-800 text-slate-400 flex items-center justify-center cursor-not-allowed">
                          <Lock className="w-6 h-6" />
                        </div>
                      )}

                      {/* Floating XP Tag */}
                      <span className="absolute -top-2 -right-3 px-2 py-0.5 text-[10px] font-bold rounded-full bg-slate-900 text-amber-400 shadow">
                        +{node.xpReward} XP
                      </span>
                    </div>

                    <div className="text-center mt-2 max-w-[160px]">
                      <div className="text-xs font-bold text-slate-800 dark:text-slate-200 line-clamp-1">
                        {node.title}
                      </div>
                      <div className="text-[10px] text-slate-400">
                        {isCompleted ? 'Hoàn thành 100%' : isUnlocked ? 'Bắt đầu ngay' : 'Đã khóa'}
                      </div>
                    </div>
                  </div>
                );
              })}
            </div>
          </Card>
        </div>
      ))}

      {/* Interactive Lesson Modal */}
      {activeLesson && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/70 backdrop-blur-sm p-4">
          <Card className="w-full max-w-lg p-6 space-y-6 relative bg-white dark:bg-slate-900 shadow-2xl animate-in fade-in zoom-in-95 duration-200">
            <button
              onClick={() => setActiveLesson(null)}
              className="absolute top-4 right-4 p-1.5 text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 rounded-lg"
            >
              <X className="w-5 h-5" />
            </button>

            {!lessonFinished ? (
              <div className="space-y-4">
                <div className="flex items-center gap-2">
                  <Badge variant="purple">Bài học kỹ năng tương tác</Badge>
                  <span className="text-xs font-bold text-amber-500 flex items-center gap-1">
                    <Zap className="w-3.5 h-3.5 fill-amber-500" /> +{activeLesson.xpReward} XP
                  </span>
                </div>

                <div>
                  <h3 className="text-lg font-black text-slate-800 dark:text-slate-100">
                    {activeLesson.title}
                  </h3>
                  <p className="text-xs text-slate-500 dark:text-slate-400 mt-1">
                    {activeLesson.description}
                  </p>
                </div>

                <div className="p-4 rounded-xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200/50 dark:border-slate-700/50 space-y-2 text-xs">
                  <strong className="text-slate-800 dark:text-slate-100">Tình huống thực hành:</strong>
                  <p className="text-slate-600 dark:text-slate-300 leading-relaxed">
                    Bạn An đang trình bày ý kiến nhưng bạn Bình liên tục ngắt lời. Theo nguyên tắc giao tiếp chủ động,
                    hành động mẫu mực của em là: Giơ tay nhẹ nhàng, đề xuất để bạn An nói xong rồi mới đến lượt bạn Bình phản biện.
                  </p>
                </div>

                <Button
                  onClick={() => completeMutation.mutate(activeLesson)}
                  disabled={completeMutation.isPending}
                  variant="primary"
                  className="w-full gap-2 text-sm"
                >
                  {completeMutation.isPending ? 'Đang lưu tiến trình...' : 'Hoàn thành bài học'}
                  <CheckCircle2 className="w-4 h-4" />
                </Button>
              </div>
            ) : (
              <div className="space-y-4 text-center py-4">
                <div className="w-16 h-16 rounded-full bg-emerald-100 dark:bg-emerald-950/50 text-emerald-600 flex items-center justify-center mx-auto shadow-md">
                  <Award className="w-8 h-8" />
                </div>

                <div>
                  <h3 className="text-lg font-black text-slate-800 dark:text-slate-100">
                    Tuyệt vời! Bạn đã hoàn thành!
                  </h3>
                  <p className="text-xs text-slate-500 dark:text-slate-400 mt-1">
                    Điểm kinh nghiệm đã được ghi nhận vào sổ cái XP Ledger.
                  </p>
                </div>

                <div className="p-3 bg-emerald-50 dark:bg-emerald-950/30 rounded-xl text-xs font-bold text-emerald-800 dark:text-emerald-300">
                  🎉 +{activeLesson.xpReward} XP Đã cộng vào tài khoản!
                </div>

                <Button
                  onClick={() => setActiveLesson(null)}
                  variant="primary"
                  className="w-full"
                >
                  Tiếp tục lộ trình
                </Button>
              </div>
            )}
          </Card>
        </div>
      )}
    </div>
  );
}
