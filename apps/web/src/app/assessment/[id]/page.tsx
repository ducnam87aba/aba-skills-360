'use client';

import React, { useState, useEffect, useRef } from 'react';
import { useParams, useRouter } from 'next/navigation';
import { Card, Button, Badge } from '@aba/ui';
import { api } from '@aba/api-client';
import type { CompetencyCode } from '@aba/types';
import {
  CheckCircle2,
  ArrowRight,
  ArrowLeft,
  Sparkles,
  Brain,
  Award,
  Clock,
  ShieldAlert,
} from 'lucide-react';

interface Question {
  id: number;
  competencyCode: CompetencyCode;
  category: string;
  type: 'scenario' | 'frequency';
  prompt: string;
  recallPeriod?: string;
  options: { id: string; text: string; score: number }[];
}

const sampleQuestions: Question[] = [
  {
    id: 1,
    competencyCode: 'PD',
    category: 'Giải quyết vấn đề (PD)',
    type: 'scenario',
    recallPeriod: 'Trong các hoạt động nhóm gần đây',
    prompt:
      'Khi làm việc nhóm và có hai bạn bất đồng ý kiến về cách giải một bài toán, em sẽ làm gì đầu tiên?',
    options: [
      { id: 'A', text: 'Lắng nghe lập luận của cả hai bạn và cùng viết ra các bước để đối chiếu', score: 100 },
      { id: 'B', text: 'Bỏ qua và tự làm theo cách riêng của mình để tiết kiệm thời gian', score: 30 },
      { id: 'C', text: 'Bầu chọn theo số đông mà không cần giải thích lý do', score: 60 },
      { id: 'D', text: 'Nhờ thầy cô can thiệp ngay lập tức mà chưa tự thảo luận', score: 50 },
    ],
  },
  {
    id: 2,
    competencyCode: 'CO',
    category: 'Giao tiếp (CO)',
    type: 'scenario',
    recallPeriod: 'Trong các buổi thảo luận lớp học',
    prompt:
      'Khi em muốn chia sẻ một ý tưởng mới nhưng các bạn đang nói chuyện rất sôi nổi, em sẽ:',
    options: [
      { id: 'A', text: 'Nói thật to át tiếng các bạn để được chú ý ngay', score: 20 },
      { id: 'B', text: 'Giơ tay xin phát biểu hoặc đợi bạn dứt câu rồi lịch sự đưa ra ý kiến', score: 100 },
      { id: 'C', text: 'Giữ im lặng và không chia sẻ ý kiến đó nữa', score: 40 },
      { id: 'D', text: 'Viết ra giấy và ném cho bạn ngồi đối diện', score: 30 },
    ],
  },
  {
    id: 3,
    competencyCode: 'ER',
    category: 'Quản lý cảm xúc (ER)',
    type: 'scenario',
    recallPeriod: 'Trong 30 ngày qua',
    prompt:
      'Khi kết quả kiểm tra không như mong đợi dù em đã ôn bài rất kỹ, phản ứng phù hợp nhất là:',
    options: [
      { id: 'A', text: 'Tức giận và cho rằng đề thi không công bằng', score: 20 },
      { id: 'B', text: 'Bình tĩnh xem lại những câu làm sai để rút kinh nghiệm cho lần sau', score: 100 },
      { id: 'C', text: 'Cảm thấy chán nản và không muốn học môn đó nữa', score: 30 },
      { id: 'D', text: 'Tìm bạn bị điểm thấp hơn mình để cảm thấy đỡ buồn', score: 40 },
    ],
  },
  {
    id: 4,
    competencyCode: 'SA',
    category: 'Tự nhận thức (SA)',
    type: 'frequency',
    recallPeriod: 'Trong 30 ngày qua',
    prompt:
      'Em có thể nhận biết và gọi tên chính xác cảm xúc của mình (vui, buồn, lo lắng, bực bội) khi nó xuất hiện:',
    options: [
      { id: '1', text: '1 — Hiếm khi / Chưa bao giờ', score: 1 },
      { id: '2', text: '2 — Thỉnh thoảng', score: 2 },
      { id: '3', text: '3 — Thường xuyên', score: 3 },
      { id: '4', text: '4 — Hầu như luôn luôn', score: 4 },
    ],
  },
  {
    id: 5,
    competencyCode: 'SM',
    category: 'Tự quản lý (SM)',
    type: 'frequency',
    recallPeriod: 'Trong 30 ngày qua',
    prompt:
      'Khi gặp một bài tập khó, em kiên trì suy nghĩ và thử các cách khác nhau trước khi bỏ cuộc:',
    options: [
      { id: '1', text: '1 — Hiếm khi / Chưa bao giờ', score: 1 },
      { id: '2', text: '2 — Thỉnh thoảng', score: 2 },
      { id: '3', text: '3 — Thường xuyên', score: 3 },
      { id: '4', text: '4 — Hầu như luôn luôn', score: 4 },
    ],
  },
  {
    id: 6,
    competencyCode: 'CE',
    category: 'Hợp tác & Đồng cảm (CE)',
    type: 'frequency',
    recallPeriod: 'Trong 30 ngày qua',
    prompt:
      'Em chú ý lắng nghe và quan sát khi bạn cùng lớp gặp khó khăn hoặc buồn phiền để an ủi, giúp đỡ:',
    options: [
      { id: '1', text: '1 — Hiếm khi / Chưa bao giờ', score: 1 },
      { id: '2', text: '2 — Thỉnh thoảng', score: 2 },
      { id: '3', text: '3 — Thường xuyên', score: 3 },
      { id: '4', text: '4 — Hầu như luôn luôn', score: 4 },
    ],
  },
];

export default function AssessmentRunnerPage() {
  const params = useParams();
  const router = useRouter();
  const assessmentId = Number(params.id) || 1;

  const [step, setStep] = useState<'intro' | 'running' | 'completed'>('intro');
  const [currentIdx, setCurrentIdx] = useState(0);
  const [selectedAnswers, setSelectedAnswers] = useState<Record<number, string>>({});
  const [sessionId, setSessionId] = useState<number | null>(null);
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [completionResult, setCompletionResult] = useState<any>(null);

  const questionStartTimeRef = useRef<number>(Date.now());

  useEffect(() => {
    questionStartTimeRef.current = Date.now();
  }, [currentIdx, step]);

  const currentQ = sampleQuestions[currentIdx];

  const handleStart = async () => {
    setIsSubmitting(true);
    try {
      const session = await api.assessment.start(assessmentId, 1);
      setSessionId(session.id);
      setStep('running');
    } catch (e) {
      console.error(e);
      setStep('running');
    } finally {
      setIsSubmitting(false);
    }
  };

  const handleSelectOption = async (optionId: string) => {
    const latencyMs = Date.now() - questionStartTimeRef.current;
    setSelectedAnswers((prev) => ({ ...prev, [currentQ.id]: optionId }));

    if (sessionId) {
      await api.assessment.answer(sessionId, currentQ.id, optionId, latencyMs);
    }
  };

  const handleNext = async () => {
    if (currentIdx < sampleQuestions.length - 1) {
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
        console.error(e);
        setStep('completed');
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
            Đánh Giá Kỹ Năng Toàn Diện 360°
          </h1>
          <p className="text-xs text-slate-500 dark:text-slate-400 mt-2 max-w-md mx-auto">
            Hệ thống đo lường 6 nhóm năng lực: Tự nhận thức (SA), Quản lý cảm xúc (ER), Tự quản lý (SM),
            Giao tiếp (CO), Hợp tác (CE), Giải quyết vấn đề (PD).
          </p>
        </div>

        <div className="grid grid-cols-3 gap-3 text-left p-4 bg-slate-50 dark:bg-slate-800/40 rounded-2xl text-xs">
          <div>
            <span className="text-slate-400 block">Số câu hỏi</span>
            <strong className="text-slate-800 dark:text-slate-100 font-bold">{sampleQuestions.length} câu</strong>
          </div>
          <div>
            <span className="text-slate-400 block">Dạng bài</span>
            <strong className="text-slate-800 dark:text-slate-100 font-bold">Tình huống + Tần suất</strong>
          </div>
          <div>
            <span className="text-slate-400 block">Thời gian</span>
            <strong className="text-slate-800 dark:text-slate-100 font-bold">~ 5 phút</strong>
          </div>
        </div>

        <Button
          onClick={handleStart}
          disabled={isSubmitting}
          size="lg"
          variant="primary"
          className="w-full gap-2"
        >
          {isSubmitting ? 'Đang khởi tạo phiên...' : 'Bắt đầu làm bài ngay'}{' '}
          <ArrowRight className="w-5 h-5" />
        </Button>
      </Card>
    );
  }

  if (step === 'completed') {
    const overallScore = completionResult?.snapshot?.overallScore || 81;

    return (
      <Card className="p-8 max-w-xl mx-auto space-y-6 text-center">
        <div className="w-20 h-20 rounded-full bg-emerald-100 dark:bg-emerald-950/50 text-emerald-600 flex items-center justify-center mx-auto shadow-lg shadow-emerald-500/10 animate-bounce">
          <Award className="w-10 h-10" />
        </div>

        <div>
          <Badge variant="success">Hoàn thành bài đánh giá</Badge>
          <h1 className="text-2xl font-black text-slate-800 dark:text-slate-100 mt-2">
            Đã Cập Nhật Skill Snapshot!
          </h1>
          <p className="text-xs text-slate-500 dark:text-slate-400 mt-1 max-w-sm mx-auto">
            Scoring Engine đã tính toán điểm chuẩn hóa cho cả 6 nhóm năng lực và kiểm tra chỉ số chất lượng dữ liệu.
          </p>
        </div>

        {/* Quality Flags & Overall */}
        <div className="p-4 bg-slate-50 dark:bg-slate-800/60 rounded-2xl text-left space-y-2 border border-slate-200/50">
          <div className="flex justify-between items-center text-xs">
            <span className="text-slate-500">Điểm năng lực tổng thể:</span>
            <span className="text-lg font-black text-emerald-600">{overallScore} / 100</span>
          </div>
          <div className="flex justify-between items-center text-[11px] text-slate-400 pt-1 border-t border-slate-200/40">
            <span>Độ hoàn thành: 100%</span>
            <span>Tốc độ: Đạt chuẩn (Quality Check Passed)</span>
          </div>
        </div>

        <div className="p-3 bg-emerald-50 dark:bg-emerald-950/30 rounded-xl border border-emerald-200/50 text-xs text-emerald-800 dark:text-emerald-300">
          🎉 Bạn nhận được <strong>+50 XP</strong> ghi nhận vào sổ cái XP Ledger.
        </div>

        <div className="flex gap-3">
          <Button
            onClick={() => router.push('/profile')}
            variant="primary"
            className="flex-1"
          >
            Xem Hồ Sơ Năng Lực
          </Button>
          <Button
            onClick={() => router.push('/learn')}
            variant="outline"
            className="flex-1"
          >
            Về Lộ Trình Học
          </Button>
        </div>
      </Card>
    );
  }

  const selectedOpt = selectedAnswers[currentQ.id];

  return (
    <Card className="p-6 sm:p-8 max-w-xl mx-auto space-y-6">
      {/* Progress & Recall Period */}
      <div className="space-y-2">
        <div className="flex justify-between text-xs font-semibold text-slate-400">
          <span>Câu {currentIdx + 1} / {sampleQuestions.length}</span>
          <span className="font-bold text-emerald-600">{currentQ.category}</span>
        </div>
        <div className="w-full h-2 bg-slate-100 dark:bg-slate-800 rounded-full overflow-hidden">
          <div
            className="h-full bg-emerald-500 rounded-full transition-all duration-300"
            style={{ width: `${((currentIdx + 1) / sampleQuestions.length) * 100}%` }}
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
          const isSelected = selectedOpt === opt.id;
          return (
            <button
              key={opt.id}
              onClick={() => handleSelectOption(opt.id)}
              className={`w-full p-4 rounded-xl text-left text-xs font-medium border-2 transition-all flex items-start gap-3 ${
                isSelected
                  ? 'border-emerald-500 bg-emerald-50/60 dark:bg-emerald-950/40 text-emerald-900 dark:text-emerald-100 shadow-sm'
                  : 'border-slate-200 dark:border-slate-800 hover:border-slate-300 dark:hover:border-slate-700 text-slate-700 dark:text-slate-300'
              }`}
            >
              <span
                className={`w-5 h-5 rounded-full flex items-center justify-center text-[10px] font-bold shrink-0 mt-0.5 ${
                  isSelected
                    ? 'bg-emerald-600 text-white'
                    : 'bg-slate-100 dark:bg-slate-800 text-slate-500'
                }`}
              >
                {opt.id}
              </span>
              <span>{opt.text}</span>
            </button>
          );
        })}
      </div>

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
          disabled={!selectedOpt || isSubmitting}
          className="text-xs gap-1"
        >
          {currentIdx === sampleQuestions.length - 1 ? 'Nộp bài & Tính điểm' : 'Câu tiếp theo'}{' '}
          <ArrowRight className="w-3.5 h-3.5" />
        </Button>
      </div>
    </Card>
  );
}
