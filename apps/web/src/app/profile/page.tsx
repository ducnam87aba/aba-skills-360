'use client';

import React from 'react';
import { useQuery } from '@tanstack/react-query';
import { api } from '@aba/api-client';
import { Card, Badge, Button } from '@aba/ui';
import { User, School, BookOpen, Flame, Zap, Award, BarChart2 } from 'lucide-react';

export default function ProfilePage() {
  const { data: student, isLoading: isStudentLoading } = useQuery({
    queryKey: ['student-me'],
    queryFn: () => api.student.getMe(),
  });

  const { data: skills, isLoading: isSkillsLoading } = useQuery({
    queryKey: ['skills-current'],
    queryFn: () => api.skills.getCurrent(),
  });

  if (isStudentLoading || isSkillsLoading) {
    return <div className="p-8 text-center text-slate-400">Đang tải hồ sơ...</div>;
  }

  return (
    <div className="space-y-6">
      {/* Student Identity Card */}
      <Card className="p-6">
        <div className="flex flex-col sm:flex-row items-center sm:items-start gap-5">
          <div className="w-20 h-20 rounded-2xl bg-gradient-to-tr from-emerald-500 to-teal-400 p-1 shadow-lg shadow-emerald-500/20">
            <img
              src={student?.avatarUrl}
              alt={student?.fullName}
              className="w-full h-full object-cover rounded-xl"
            />
          </div>

          <div className="flex-1 text-center sm:text-left space-y-2">
            <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-2">
              <div>
                <h1 className="text-xl font-bold text-slate-800 dark:text-slate-100">
                  {student?.fullName}
                </h1>
                <p className="text-xs text-slate-500 dark:text-slate-400 mt-0.5">
                  Mã học sinh: <strong className="text-slate-700 dark:text-slate-300">{student?.studentCode}</strong>
                </p>
              </div>

              <Badge variant="purple">Khối {student?.gradeLevel} • Lớp {student?.className}</Badge>
            </div>

            <div className="flex flex-wrap items-center justify-center sm:justify-start gap-4 text-xs text-slate-500 dark:text-slate-400 pt-2">
              <span className="flex items-center gap-1.5">
                <School className="w-4 h-4 text-emerald-500" />
                {student?.schoolName}
              </span>
              <span className="flex items-center gap-1.5">
                <Flame className="w-4 h-4 text-amber-500" />
                Chuỗi: {student?.currentStreak} ngày
              </span>
              <span className="flex items-center gap-1.5">
                <Zap className="w-4 h-4 text-yellow-500" />
                {student?.totalXp} XP
              </span>
            </div>
          </div>
        </div>
      </Card>

      {/* Current Skills Radar / Breakdown */}
      <div className="space-y-4">
        <div className="flex items-center justify-between">
          <h2 className="text-lg font-bold text-slate-800 dark:text-slate-100 flex items-center gap-2">
            <BarChart2 className="w-5 h-5 text-emerald-500" /> Hồ Sơ Năng Lực Hiện Tại
          </h2>
          <Badge variant="success">Đã chuẩn hóa 360°</Badge>
        </div>

        <div className="grid grid-cols-1 gap-3">
          {skills?.map((skill) => {
            const levelColor =
              skill.level === 'advanced'
                ? 'bg-emerald-500'
                : skill.level === 'proficient'
                ? 'bg-blue-500'
                : 'bg-amber-500';

            return (
              <Card key={skill.skillId} className="p-4 space-y-2">
                <div className="flex items-center justify-between">
                  <div>
                    <h3 className="text-sm font-bold text-slate-800 dark:text-slate-200">
                      {skill.skillName}
                    </h3>
                    <span className="text-[10px] uppercase font-semibold text-slate-400">
                      Mã: {skill.skillCode}
                    </span>
                  </div>

                  <div className="text-right">
                    <span className="text-lg font-black text-slate-900 dark:text-slate-100">
                      {skill.score}
                    </span>
                    <span className="text-xs text-slate-400"> / {skill.maxScore}</span>
                  </div>
                </div>

                {/* Skill progress bar */}
                <div className="w-full h-2.5 bg-slate-100 dark:bg-slate-800 rounded-full overflow-hidden">
                  <div
                    className={`h-full ${levelColor} rounded-full transition-all duration-700`}
                    style={{ width: `${(skill.score / skill.maxScore) * 100}%` }}
                  />
                </div>
              </Card>
            );
          })}
        </div>
      </div>
    </div>
  );
}
