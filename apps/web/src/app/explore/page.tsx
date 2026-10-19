'use client';

import React from 'react';
import { Card, Button, Badge } from '@aba/ui';
import { Compass, Sparkles, Brain, MessageSquare, Heart, Shield, ArrowRight } from 'lucide-react';
import Link from 'next/link';

export default function ExplorePage() {
  const assessments = [
    {
      id: 1,
      title: 'Đánh giá Kỹ năng Toàn diện 360°',
      category: 'Tổng hợp',
      duration: '15 phút',
      questions: 20,
      description: 'Đo lường đa diện: Tư duy logic, Giao tiếp, Cảm xúc xã hội, Thích ứng và Vận động tinh.',
      color: 'from-emerald-500 to-teal-600',
      icon: Sparkles,
      recommended: true,
    },
    {
      id: 2,
      title: 'Đánh giá Năng lực Tư duy Phản biện',
      category: 'Tư duy logic',
      duration: '10 phút',
      questions: 12,
      description: 'Đo lường khả năng lập luận, suy diễn quy nạp và xử lý tình huống logic phức tạp.',
      color: 'from-indigo-500 to-blue-600',
      icon: Brain,
      recommended: false,
    },
    {
      id: 3,
      title: 'Trắc nghiệm Trí tuệ Cảm xúc (EQ)',
      category: 'Cảm xúc xã hội',
      duration: '8 phút',
      questions: 10,
      description: 'Đo lường năng lực nhận diện cảm xúc bản thân, thấu cảm và ứng xử hòa nhập.',
      color: 'from-rose-500 to-pink-600',
      icon: Heart,
      recommended: false,
    },
  ];

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-black tracking-tight text-slate-800 dark:text-slate-100">
          Khám phá & Đánh giá Năng lực
        </h1>
        <p className="text-sm text-slate-500 dark:text-slate-400 mt-1">
          Lựa chọn bài đánh giá chuẩn hóa để cập nhật hồ sơ năng lực kỹ năng của bạn.
        </p>
      </div>

      <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
        {assessments.map((item) => {
          const Icon = item.icon;
          return (
            <Card key={item.id} className="p-6 flex flex-col justify-between hover:shadow-md transition-shadow">
              <div className="space-y-4">
                <div className="flex items-center justify-between">
                  <div className={`w-12 h-12 rounded-2xl bg-gradient-to-tr ${item.color} text-white flex items-center justify-center shadow-md`}>
                    <Icon className="w-6 h-6" />
                  </div>
                  {item.recommended && (
                    <Badge variant="success">Khuyên dùng</Badge>
                  )}
                </div>

                <div>
                  <h3 className="text-lg font-bold text-slate-800 dark:text-slate-100">
                    {item.title}
                  </h3>
                  <div className="flex items-center gap-3 text-xs text-slate-400 mt-1">
                    <span>⏱️ {item.duration}</span>
                    <span>•</span>
                    <span>📝 {item.questions} câu hỏi</span>
                  </div>
                  <p className="text-xs text-slate-600 dark:text-slate-300 mt-2 line-clamp-2">
                    {item.description}
                  </p>
                </div>
              </div>

              <div className="mt-6 pt-4 border-t border-slate-100 dark:border-slate-800 flex items-center justify-between">
                <span className="text-xs font-semibold text-slate-500 dark:text-slate-400">
                  {item.category}
                </span>
                <Link href={`/assessment/${item.id}`}>
                  <Button size="sm" variant="primary" className="gap-1.5">
                    Bắt đầu làm bài <ArrowRight className="w-4 h-4" />
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
