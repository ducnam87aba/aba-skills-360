'use client';

import React, { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@aba/api-client';
import { Card, Badge, Button, SectionHeader } from '@aba/ui';
import { Phone, Mail, Shield, Plus, Link2, School, ChevronRight, Clock, X } from 'lucide-react';
import Link from 'next/link';
import { parentLinkSchema } from '@aba/validation';
import type { ParentLinkRequest } from '@aba/types';
import { useParentChildren } from '../../../features/parent/ParentChildrenContext';
import { Avatar } from '../../../components/Avatar';
import { formatClassLabel } from '../../../lib/classLabel';

// PA42 — server envelope statuses of a submission, in parent-facing words.
const RESULT_COPY: Record<string, { ok: boolean; text: string }> = {
  created: { ok: true, text: 'Đã gửi yêu cầu. Nhà trường sẽ xem xét và phê duyệt.' },
  exists: { ok: true, text: 'Yêu cầu của bạn đang chờ phê duyệt.' },
  already_linked: { ok: false, text: 'Học sinh này đã được liên kết với tài khoản của bạn.' },
};

const REL_LABEL: Record<string, string> = { father: 'Bố', mother: 'Mẹ', guardian: 'Người giám hộ', parent: 'Phụ huynh' };

const STATUS_META: Record<ParentLinkRequest['status'], { text: string; variant: 'warning' | 'success' | 'rose' }> = {
  pending: { text: 'Chờ phê duyệt', variant: 'warning' },
  approved: { text: 'Đã phê duyệt', variant: 'success' },
  rejected: { text: 'Từ chối', variant: 'rose' },
};

export default function ParentProfilePage() {
  const { data: parentUser } = useQuery({
    queryKey: ['user-me-parent'],
    queryFn: () => api.auth.me(),
  });

  // The linked-children list comes from the shared parent context (single
  // source, no duplicate query); the profile screen lists them all.
  const { children } = useParentChildren();

  // PA42 — "Liên kết thêm con" is request-then-approve: the submission only
  // joins a queue and the school reviews it server-side (§8) — a parent can
  // never add a child by themselves, so the code is all the queue may show.
  const queryClient = useQueryClient();
  const { data: linkRequests } = useQuery({
    queryKey: ['parent-link-requests'],
    queryFn: () => api.parent.getLinkRequests(),
  });
  const [showForm, setShowForm] = useState(false);
  const [studentCode, setStudentCode] = useState('');
  const [relationship, setRelationship] = useState<'father' | 'mother' | 'guardian'>('guardian');
  const [feedback, setFeedback] = useState<{ ok: boolean; text: string } | null>(null);

  const linkMutation = useMutation({
    mutationFn: () => api.parent.createLinkRequest(studentCode.trim(), relationship),
    onSuccess: (res) => {
      setFeedback(RESULT_COPY[res.status] ?? { ok: true, text: 'Đã gửi yêu cầu.' });
      queryClient.invalidateQueries({ queryKey: ['parent-link-requests'] });
      if (res.status === 'created') setStudentCode('');
    },
    onError: (err: unknown) => {
      const message = (err as { error?: { message?: string } })?.error?.message;
      setFeedback({ ok: false, text: message || 'Không gửi được yêu cầu. Vui lòng thử lại.' });
    },
  });

  const submitLinkRequest = () => {
    const parsed = parentLinkSchema.safeParse({ studentCode: studentCode.trim(), relationship });
    if (!parsed.success) {
      setFeedback({ ok: false, text: parsed.error.issues[0]?.message ?? 'Mã học sinh không hợp lệ' });
      return;
    }
    setFeedback(null);
    linkMutation.mutate();
  };

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
          <Button
            size="sm"
            variant="outline"
            className="gap-1"
            onClick={() => { setShowForm((v) => !v); setFeedback(null); }}
          >
            {showForm ? <X className="w-4 h-4" /> : <Plus className="w-4 h-4" />}
            {showForm ? 'Đóng' : 'Liên kết thêm con'}
          </Button>
        </div>

        {showForm && (
          <Card className="p-4 mb-3 space-y-3 bg-brand-50/40">
            <div className="grid gap-3 sm:grid-cols-[1fr_auto_auto] sm:items-end">
              <label className="block">
                <span className="text-xs font-black uppercase tracking-wide text-slate-500">Mã học sinh</span>
                <input
                  value={studentCode}
                  onChange={(e) => setStudentCode(e.target.value)}
                  placeholder="VD: ABA-2026-101"
                  className="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-bold text-slate-700 focus:outline-none focus:ring-2 focus:ring-brand-500"
                />
              </label>
              <label className="block">
                <span className="text-xs font-black uppercase tracking-wide text-slate-500">Quan hệ</span>
                <select
                  value={relationship}
                  onChange={(e) => setRelationship(e.target.value as typeof relationship)}
                  className="mt-1 rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-bold text-slate-700 focus:outline-none focus:ring-2 focus:ring-brand-500"
                >
                  {(['mother', 'father', 'guardian'] as const).map((r) => (
                    <option key={r} value={r}>{REL_LABEL[r]}</option>
                  ))}
                </select>
              </label>
              <Button size="sm" className="gap-1" disabled={linkMutation.isPending} onClick={submitLinkRequest}>
                {linkMutation.isPending ? 'Đang gửi…' : 'Gửi yêu cầu'}
              </Button>
            </div>
            {feedback && (
              <p className={'text-xs font-bold ' + (feedback.ok ? 'text-brand-700' : 'text-rose-600')}>{feedback.text}</p>
            )}
            <p className="text-[11px] font-medium text-slate-400">
              Yêu cầu chỉ mang tính đăng ký — nhà trường phê duyệt thì con mới xuất hiện trên cổng phụ huynh.
            </p>
          </Card>
        )}

        <div className="space-y-3">
          {children?.map((child) => (
            <Link key={child.id} href="/parent">
              <Card hover className="p-4 flex items-center gap-4">
                <Avatar src={child.avatarUrl} name={child.fullName} className="w-12 h-12 rounded-2xl object-cover text-base" />
                <div className="min-w-0 flex-1">
                  <div className="font-black text-slate-800 text-sm leading-tight">{child.fullName}</div>
                  <div className="text-xs text-slate-400 mt-0.5 flex items-center gap-1.5 flex-wrap">
                    <School className="w-3.5 h-3.5" /> {formatClassLabel(child.className)} · {child.schoolName}
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

      {/* PA42 — the parent's own link-request queue (server-scoped, §8). */}
      {!!linkRequests?.length && (
        <div>
          <h3 className="text-lg font-black text-slate-800 flex items-center gap-2 mb-3">
            <Clock className="w-5 h-5 text-sa-500" /> Yêu cầu liên kết
          </h3>
          <div className="space-y-2">
            {linkRequests.map((r) => (
              <Card key={r.id} className="p-3.5 flex items-center gap-3">
                <div className="min-w-0 flex-1">
                  <div className="font-black text-slate-700 text-sm">Mã {r.studentCode}</div>
                  <div className="text-xs text-slate-400 mt-0.5">
                    {REL_LABEL[r.relationship] ?? r.relationship} · Gửi ngày {new Date(r.createdAt).toLocaleDateString('vi-VN')}
                  </div>
                </div>
                <Badge variant={STATUS_META[r.status].variant}>{STATUS_META[r.status].text}</Badge>
              </Card>
            ))}
          </div>
        </div>
      )}
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
