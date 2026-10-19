'use client';

import React, { useState } from 'react';
import { useParams, useRouter } from 'next/navigation';
import { Card, Button, Badge } from '@aba/ui';
import { api } from '@aba/api-client';
import { CheckCircle2, ArrowRight, ArrowLeft, Sparkles, Brain, Award } from 'lucide-react';

interface Question {
  id: number;
  prompt: string;
  category: string;
  options: { id: string; text: string; score: number }[];
}

const sampleQuestions: Question[] = [
  {
    id: 1,
    category: 'Tư duy Logic & Phân tích',
    prompt: 'Khi làm việc nhóm và có hai bạn bất đồng ý kiến về cách giải một bài toán, em sẽ làm gì đầu tiên?',
    options: [
      { id: 'A', text: 'Lắng nghe lập luận của cả hai bạn và cùng viết ra các bước để đối chiếu', score: 100 },
      { id: 'B', text: 'Bỏ qua và tự làm theo cách riêng của mình để tiết kiệm thời gian', score: 30 },
      { id: 'C', text: 'Bầu chọn theo số đông mà không cần giải thích lý do', score: 60 },
      { id: 'D', text: 'Nhờ thầy cô can thiệp ngay lập tức mà chưa tự thảo luận', score: 50 },
    ],
  },
  {
    id: 2,
    category: 'Giao tiếp & Diễn đạt',
    prompt: 'Trong buổi thảo luận, khi em muốn chia sẻ một ý tưởng mới nhưng các bạn đang nói chuyện rất sôi nổi, em sẽ:',
    options: [
      { id: 'A', text: 'Nói thật to át tiếng các bạn để được chú ý ngay', score: 20 },
      { id: 'B', text: 'Giơ tay xin phát biểu hoặc đợi bạn dứt câu rồi lịch sự đưa ra ý kiến', score: 100 },
      { id: 'C', text: 'Giữ im lặng và không chia sẻ ý kiến đó nữa', score: 40 },
      { id: 'D', text: 'Viết ra giấy và ném cho bạn ngồi đối diện', score: 30 },
    ],
  },
  {
    id: 3,
    category: 'Quản lý Cảm xúc & Thích ứng',
    prompt: 'Khi kết quả kiểm tra không như mong đợi dù em đã ôn bài rất kỹ, phản ứng phù hợp nhất là:',
    options: [
      { id: 'A', text: 'Tức giận và cho rằng đề thi không công bằng', score: 20 },
      { id: 'B', text: 'Bình tĩnh xem lại những câu làm sai để rút kinh nghiệm cho lần sau', score: 100 },
      { id: 'C', text: 'Cảm thấy chán nản và không muốn học môn đó nữa', score: 30 },
      { id: 'D', text: 'Tìm bạn bị điểm thấp hơn mình để cảm thấy đỡ buồn', score: 40 },
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
    setSelectedAnswers((prev) => ({ ...prev, [currentQ.id]: optionId }));
    if (sessionId) {
      await api.assessment.answer(sessionId, currentQ.id, optionId);
    }
  };

  const handleNext = async () => {
    if (currentIdx < sampleQuestions.length - 1) {
      setCurrentIdx((prev) => prev + 1);
    } else {
      setIsSubmitting(true);
      try {
        if (sessionId) {
          await api.assessment.complete(sessionId);
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
          <Badge variant="purple">Assessment Engine v1.0</Badge>
          <h1 className="text-2xl font-black text-slate-800 dark:text-slate-100 mt-2">
            Đánh Giá Kỹ Năng Toàn Diện 360°
          </h1>
          <p className="text-xs text-slate-500 dark:text-slate-400 mt-2 max-w-md mx-auto">
            Hệ thống sẽ ghi nhận phiên làm bài và tính toán điểm chuẩn hóa cho hồ sơ năng lực của bạn.
          </p>
        </div>

        <div className="grid grid-cols-3 gap-3 text-left p-4 bg-slate-50 dark:bg-slate-800/40 rounded-2xl text-xs">
          <div>
            <span className="text-slate-400 block">Số câu hỏi</span>
            <strong className="text-slate-800 dark:text-slate-100 font-bold">{sampleQuestions.length} câu</strong>
          </div>
          <div>
            <span className="text-slate-400 block">Thời gian</span>
            <strong className="text-slate-800 dark:text-slate-100 font-bold">~ 5 phút</strong>
          </div>
          <div>
            <span className="text-slate-400 block">Phương pháp</span>
            <strong className="text-slate-800 dark:text-slate-100 font-bold">Tình huống</strong>
          </div>
        </div>

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
    return (
      <Card className="p-8 max-w-xl mx-auto space-y-6 text-center">
        <div className="w-20 h-20 rounded-full bg-emerald-100 dark:bg-emerald-950/50 text-emerald-600 flex items-center justify-center mx-auto shadow-lg shadow-emerald-500/10 animate-bounce">
          <Award className="w-10 h-10" />
        </div>

        <div>
          <Badge variant="success">Hoàn thành xuất sắc</Badge>
          <h1 className="text-2xl font-black text-slate-800 dark:text-slate-100 mt-2">
            Đã Cập Nhật Hồ Sơ Năng Lực!
          </h1>
          <p className="text-xs text-slate-500 dark:text-slate-400 mt-1 max-w-sm mx-auto">
            Kết quả đã được tính toán bởi Scoring Engine và tạo thành Skill Snapshot phiên bản mới.
          </p>
        </div>

        <div className="p-4 bg-emerald-50 dark:bg-emerald-950/30 rounded-2xl border border-emerald-200/50 text-xs text-emerald-800 dark:text-emerald-300">
          🎉 Bạn nhận được <strong>+50 XP</strong> và ghi nhận vào sổ cái XP Ledger.
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
      {/* Progress */}
      <div className="space-y-2">
        <div className="flex justify-between text-xs font-semibold text-slate-400">
          <span>Câu hỏi {currentIdx + 1} / {sampleQuestions.length}</span>
          <span>{currentQ.category}</span>
        </div>
        <div className="w-full h-2 bg-slate-100 dark:bg-slate-800 rounded-full overflow-hidden">
          <div
            className="h-full bg-emerald-500 rounded-full transition-all duration-300"
            style={{ width: `${((currentIdx + 1) / sampleQuestions.length) * 100}%` }}
          />
        </div>
      </div>

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
          {currentIdx === sampleQuestions.length - 1 ? 'Hoàn thành' : 'Câu tiếp theo'}{' '}
          <ArrowRight className="w-3.5 h-3.5" />
        </Button>
      </div>
    </Card>
  );
}
