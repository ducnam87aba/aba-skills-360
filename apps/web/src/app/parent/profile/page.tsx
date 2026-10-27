'use client';

import React from 'react';
import { useQuery } from '@tanstack/react-query';
import { api } from '@aba/api-client';
import { Card, Badge, Button, SectionHeader } from '@aba/ui';
import { Phone, Mail, Shield, Plus, Link2, School, ChevronRight } from 'lucide-react';
import Link from 'next/link';

export default function ParentProfilePage() {
  const { data: parentUser } = useQuery({
    queryKey: ['user-me-parent'],
    queryFn: () => api.auth.me(),
  });

  const { data: children } = useQuery({
    queryKey: ['parent-children'],
    queryFn: () => api.parent.getChildren(),
  });

  const initials = (parentUser?.displayName ?? 'Nguyễn Thị Lan')
    .split(' ').filter(Boolean).slice(-2).map((w) => w[0]).join('').toUpperCase();

  return (
    <div className="space-y-6">
      <SectionHeader
        eyebrow="Tài khoản"
        title="Hồ sơ phụ huynh"
        description="Quản lý thông tin tài khoản và danh sách học sinh đã liên kết."
      />

      {/* Account card */}
      <Card className="overflow-hidden">
        <div className="bg-gradient-to-br from-sa to-sa/70 text-white p-6 flex items-center gap-5">
          <div className="w-20 h-20 rounded-3xl bg-white/20 backdrop-blur flex items-center justify-center font-black text-2xl ring-4 ring-white/30">
            {initials}
          </div>
          <div className="min-w-0 flex-1">
            <h2 className="text-xl font-black leading-tight">{parentUser?.displayName ?? 'Nguyễn Thị Lan'}</h2>
            <div className="flex items-center gap-2 mt-1.5 flex-wrap">
              <Badge className="!bg-white/90 !text-sa-700"><Shield className="w-3 h-3" /> Phụ huynh</Badge>
              <span className="text-xs font-bold text-white/80">ID: PAR-{parentUser?.parentId ?? 5}</span>
            </div>
          </div>
        </div>
        <div className="grid grid-cols-1 sm:grid-cols-2 divide-y sm:divide-y-0 sm:divide-x divide-black/5 border-t border-black/5">
          <Contact icon={<Phone className="w-4 h-4" />} value="+84 912 345 678" />
          <Contact icon={<Mail className="w-4 h-4" />} value={parentUser?.email ?? 'lan.nguyen@example.com'} />
        </div>
      </Card>

      {/* Linked children */}
      <div>
        <div className="flex items-center justify-between mb-4">
          <h3 className="text-lg font-black text-slate-800 flex items-center gap-2">
            <Link2 className="w-5 h-5 text-brand-500" /> Con em đã liên kết
          </h3>
          <Button size="sm" variant="outline" className="gap-1">
            <Plus className="w-4 h-4" /> Liên kết thêm con
          </Button>
        </div>

        <div className="space-y-3">
          {children?.map((child) => (
            <Link key={child.id} href="/parent">
              <Card hover className="p-4 flex items-center gap-4">
                <img src={child.avatarUrl} alt={child.fullName} className="w-12 h-12 rounded-2xl object-cover" />
                <div className="min-w-0 flex-1">
                  <div className="font-black text-slate-800 text-sm leading-tight">{child.fullName}</div>
                  <div className="text-xs text-slate-400 mt-0.5 flex items-center gap-1.5 flex-wrap">
                    <School className="w-3.5 h-3.5" /> Lớp {child.className} · {child.schoolName}
                    <span className="text-slate-300">·</span> Mã {child.studentCode}
                  </div>
                </div>
                <Badge variant="success">Đang hoạt động</Badge>
                <ChevronRight className="w-5 h-5 text-slate-300 shrink-0" />
              </Card>
            </Link>
          ))}
        </div>
      </div>
    </div>
  );
}

function Contact({ icon, value }: { icon: React.ReactNode; value: string }) {
  return (
    <div className="flex items-center gap-3 px-5 py-4 text-sm font-bold text-slate-600">
      <span className="w-9 h-9 rounded-xl bg-slate-50 flex items-center justify-center text-slate-400">{icon}</span>
      {value}
    </div>
  );
}
