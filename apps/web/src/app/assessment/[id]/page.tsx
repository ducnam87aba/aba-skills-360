'use client';

import React, { useState, useEffect, useRef } from 'react';
import { useParams, useRouter } from 'next/navigation';
import { useQuery } from '@tanstack/react-query';
import { Card, Button, Badge } from '@aba/ui';
import { api } from '@aba/api-client';
import type { SessionQuestionItem, AssessmentCompleteResult } from '@aba/types';
import { ArrowRight, ArrowLeft, Brain, Award, Clock, ShieldAlert } from 'lucide-react';

type Option = SessionQuestionItem['options'][number];

// Likert answers carry a numeric scale value; scenario answers carry an option id.
function answerValue(q: SessionQuestionItem, opt: Option): string | number {
  return q.type === 'likert' ? (opt.value as number) : (opt.id as string);
}

export default function AssessmentRunnerPage() {
  const params = useParams();
  const router = useRouter();
  const assessmentId = Number(params.id) || 1;

  const [step, setStep] = useState<'intro' | 'running' | 'completed'>('intro');
  const [questions, setQuestions] = useState<SessionQuestionItem[]>([]);
  const [currentIdx, setCurrentIdx] = useState(0);
  const [selectedAnswers, setSelectedAnswers] = useState<Record<number, string | number>>({});
  const [sessionId, setSessionId] = useState<number | null>(null);
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [completionResult, setCompletionResult] = useState<AssessmentCompleteResult | null>(null);

  const questionStartTimeRef = useRef<number>(Date.now());

  const { data: meta } = useQuery({
    queryKey: ['assessment-meta', assessmentId],
    queryFn: () => api.assessment.getMeta(assessmentId),
    enabled: step === 'intro',
  });

  useEffect(() => {
    questionStartTimeRef.current = Date.now();
  }, [currentIdx, step]);

  const questionCount = meta?.assessment?.questionCount ?? questions.length;
  const currentQ = questions[currentIdx];

  const handleStart = async () => {
    setIsSubmitting(true);
    setError(null);
    try {
      const res = await api.assessment.start(assessmentId);
      setSessionId(res.session.id);
      setQuestions(res.questionBatch.items);
      setCurrentIdx(0);
      setStep('running');
    } catch (e) {
      const msg = (e as { error?: { message?: string } })?.error?.message;
      setError(msg || 'Không thể khởi tạo phiên đánh giá. Vui lòng thử lại.');
    } finally {
      setIsSubmitting(false);
    }
  };

  const handleSelectOption = async (opt: Option) => {
    if (!currentQ) return;
    const payload = answerValue(currentQ, opt);
    const latencyMs = Date.now() - questionStartTimeRef.current;
    setSelectedAnswers((prev) => ({ ...prev, [currentQ.id]: payload }));

    if (sessionId) {
      await api.assessment.answer(sessionId, currentQ.id, payload, latencyMs);
    }
  };

  const handleNext = async () => {
    if (currentIdx < questions.length - 1) {
      setCurrentIdx((prev) => prev + 1);
    } else {
      setIsSubmitting(true);
      try {
        if (sessionId) {
          const res = await api.assessment.complete(sessionId);
          setCompletionResult(res);
        }
        setStep('completed');
      } catch (e) {
        const msg = (e as { error?: { message?: string } })?.error?.message;
        setError(msg || 'Không thể nộp bài. Vui lòng thử lại.');
      } finally {
        setIsSubmitting(false);
      }
    }
  };

  if (step === 'intro') {
    return (
      <Card className="p-8 max-w-xl mx-auto space-y-6 text-center">
        <div className="w-16 h-16 rounded-3xl bg-gradient-to-tr from-emerald-500 to-teal-400 text-white flex items-center justify-center mx-auto shadow-lg shadow-emerald-500/20">
          <Brain className="w-8 h-8" />
        </div>

        <div>
          <Badge variant="purple">Assessment Engine v0.1 • Chuẩn 6 Năng Lực</Badge>
          <h1 className="text-2xl font-black text-slate-800 dark:text-slate-100 mt-2">
            {meta?.assessment?.title || 'Đánh Giá Kỹ Năng Toàn Diện 360°'}
          </h1>
          <p className="text-xs text-slate-500 dark:text-slate-400 mt-2 max-w-md mx-auto">
            Hệ thống đo lường 6 nhóm năng lực: Tự nhận thức (SA), Quản lý cảm xúc (ER), Tự quản lý (SM),
            Giao tiếp (CO), Hợp tác (CE), Giải quyết vấn đề (PD).
          </p>
        </div>

        <div className="grid grid-cols-3 gap-3 text-left p-4 bg-slate-50 dark:bg-slate-800/40 rounded-2xl text-xs">
          <div>
            <span className="text-slate-400 block">Số câu hỏi</span>
            <strong className="text-slate-800 dark:text-slate-100 font-bold">
              {questionCount ? `${questionCount} câu` : '—'}
            </strong>
          </div>
          <div>
            <span className="text-slate-400 block">Dạng bài</span>
            <strong className="text-slate-800 dark:text-slate-100 font-bold">Tình huống + Tần suất</strong>
          </div>
          <div>
            <span className="text-slate-400 block">Phiên bản</span>
            <strong className="text-slate-800 dark:text-slate-100 font-bold">{meta?.assessment?.version || '0.1'}</strong>
          </div>
        </div>

        {error && (
          <div className="flex items-center gap-2 text-xs font-bold text-rose-600 bg-rose-50 dark:bg-rose-950/30 rounded-xl px-3 py-2">
            <ShieldAlert className="w-4 h-4 shrink-0" /> {error}
          </div>
        )}

        <Button
          onClick={handleStart}
          disabled={isSubmitting}
          size="lg"
          variant="primary"
          className="w-full gap-2"
        >
          {isSubmitting ? 'Đang khởi tạo phiên...' : 'Bắt đầu làm bài ngay'} <ArrowRight className="w-5 h-5" />
        </Button>
      </Card>
    );
  }

  if (step === 'completed') {
    const overallScore = completionResult?.result?.overallScore ?? null;
    const xpAwarded = completionResult?.rewards?.xpAwarded ?? 0;

    return (
      <Card className="p-8 max-w-xl mx-auto space-y-6 text-center">
        <div className="w-20 h-20 rounded-full bg-emerald-100 dark:bg-emerald-950/50 text-emerald-600 flex items-center justify-center mx-auto shadow-lg shadow-emerald-500/10 animate-bounce">
          <Award className="w-10 h-10" />
        </div>

        <div>
          <Badge variant="success">Hoàn thành bài đánh giá</Badge>
          <h1 className="text-2xl font-black text-slate-800 dark:text-slate-100 mt-2">Đã Cập Nhật Skill Snapshot!</h1>
          <p className="text-xs text-slate-500 dark:text-slate-400 mt-1 max-w-sm mx-auto">
            Scoring Engine đã tính điểm chuẩn hóa và lưu bất biến theo phiên bản đánh giá.
          </p>
        </div>

        <div className="p-4 bg-slate-50 dark:bg-slate-800/60 rounded-2xl text-left space-y-2 border border-slate-200/50">
          <div className="flex justify-between items-center text-xs">
            <span className="text-slate-500">Điểm năng lực tổng thể:</span>
            <span className="text-lg font-black text-emerald-600">
              {overallScore === null ? 'Chưa đủ dữ liệu' : `${Math.round(overallScore)} / 100`}
            </span>
          </div>
          {completionResult?.result?.scoringVersion && (
            <div className="flex justify-between items-center text-[11px] text-slate-400 pt-1 border-t border-slate-200/40">
              <span>Scoring {completionResult.result.scoringVersion}</span>
              <span>{completionResult.result.idempotent ? 'Replay (đã tính trước đó)' : 'Quality Check Passed'}</span>
            </div>
          )}
        </div>

        <div className="p-3 bg-emerald-50 dark:bg-emerald-950/30 rounded-xl border border-emerald-200/50 text-xs text-emerald-800 dark:text-emerald-300">
          🎉 Bạn nhận được <strong>+{xpAwarded} XP</strong> ghi nhận vào sổ cái XP Ledger.
        </div>

        <div className="flex gap-3">
          <Button onClick={() => router.push('/profile')} variant="primary" className="flex-1">
            Xem Hồ Sơ Năng Lực
          </Button>
          <Button onClick={() => router.push('/learn')} variant="outline" className="flex-1">
            Về Lộ Trình Học
          </Button>
        </div>
      </Card>
    );
  }

  // Running — guard against a half-initialised session.
  if (!currentQ) {
    return (
      <Card className="p-8 max-w-xl mx-auto text-center space-y-4">
        <p className="text-sm font-bold text-slate-500">Đang tải bộ câu hỏi...</p>
        <Button variant="outline" size="sm" onClick={() => setStep('intro')}>
          Quay lại
        </Button>
      </Card>
    );
  }

  const selectedOpt = selectedAnswers[currentQ.id];
  const typeLabel = currentQ.type === 'likert' ? 'Tần suất tự đánh giá' : 'Tình huống';

  return (
    <Card className="p-6 sm:p-8 max-w-xl mx-auto space-y-6">
      {/* Progress & Recall Period */}
      <div className="space-y-2">
        <div className="flex justify-between text-xs font-semibold text-slate-400">
          <span>Câu {currentIdx + 1} / {questions.length}</span>
          <span className="font-bold text-emerald-600">{typeLabel}</span>
        </div>
        <div className="w-full h-2 bg-slate-100 dark:bg-slate-800 rounded-full overflow-hidden">
          <div
            className="h-full bg-emerald-500 rounded-full transition-all duration-300"
            style={{ width: `${((currentIdx + 1) / questions.length) * 100}%` }}
          />
        </div>
      </div>

      {/* Recall Context Banner */}
      {currentQ.recallPeriod && (
        <div className="flex items-center gap-1.5 text-[11px] text-slate-500 bg-slate-100 dark:bg-slate-800/80 px-3 py-1.5 rounded-lg w-fit">
          <Clock className="w-3.5 h-3.5 text-slate-400" />
          <span>Khung thời gian gợi nhớ: <strong>{currentQ.recallPeriod}</strong></span>
        </div>
      )}

      {/* Question Prompt */}
      <div>
        <h2 className="text-base sm:text-lg font-bold text-slate-800 dark:text-slate-100 leading-snug">
          {currentQ.prompt}
        </h2>
      </div>

      {/* Options */}
      <div className="space-y-2.5">
        {currentQ.options.map((opt) => {
          const value = answerValue(currentQ, opt);
          const isSelected = selectedOpt === value;
          const bubble = currentQ.type === 'likert' ? String(opt.value) : opt.id;
          return (
            <button
              key={String(value)}
              onClick={() => handleSelectOption(opt)}
              className={`w-full p-4 rounded-xl text-left text-xs font-medium border-2 transition-all flex items-start gap-3 ${
                isSelected
                  ? 'border-emerald-500 bg-emerald-50/60 dark:bg-emerald-950/40 text-emerald-900 dark:text-emerald-100 shadow-sm'
                  : 'border-slate-200 dark:border-slate-800 hover:border-slate-300 dark:hover:border-slate-700 text-slate-700 dark:text-slate-300'
              }`}
            >
              <span
                className={`w-5 h-5 rounded-full flex items-center justify-center text-[10px] font-bold shrink-0 mt-0.5 ${
                  isSelected ? 'bg-emerald-600 text-white' : 'bg-slate-100 dark:bg-slate-800 text-slate-500'
                }`}
              >
                {bubble}
              </span>
              <span>{opt.label}</span>
            </button>
          );
        })}
      </div>

      {error && (
        <div className="flex items-center gap-2 text-xs font-bold text-rose-600 bg-rose-50 dark:bg-rose-950/30 rounded-xl px-3 py-2">
          <ShieldAlert className="w-4 h-4 shrink-0" /> {error}
        </div>
      )}

      {/* Navigation Buttons */}
      <div className="flex items-center justify-between pt-4 border-t border-slate-100 dark:border-slate-800">
        <Button
          variant="ghost"
          size="sm"
          onClick={() => setCurrentIdx((p) => Math.max(0, p - 1))}
          disabled={currentIdx === 0}
          className="text-xs"
        >
          <ArrowLeft className="w-3.5 h-3.5 mr-1" /> Câu trước
        </Button>

        <Button
          variant="primary"
          size="sm"
          onClick={handleNext}
          disabled={selectedOpt === undefined || isSubmitting}
          className="text-xs gap-1"
        >
          {currentIdx === questions.length - 1 ? 'Nộp bài & Tính điểm' : 'Câu tiếp theo'} <ArrowRight className="w-3.5 h-3.5" />
        </Button>
      </div>
    </Card>
  );
}
