'use client';

import React from 'react';
import { Card, Badge, Button } from '@aba/ui';
import { User, Phone, Mail, Shield, Plus } from 'lucide-react';

export default function ParentProfilePage() {
  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-black tracking-tight text-slate-800 dark:text-slate-100">
          Hồ Sơ Phụ Huynh
        </h1>
        <p className="text-sm text-slate-500 dark:text-slate-400 mt-1">
          Quản lý tài khoản phụ huynh và danh sách học sinh liên kết.
        </p>
      </div>

      <Card className="p-6 space-y-4">
        <div className="flex items-center gap-4">
          <div className="w-16 h-16 rounded-2xl bg-indigo-600 text-white flex items-center justify-center font-bold text-xl shadow-md shadow-indigo-500/20">
            NL
          </div>
          <div>
            <h2 className="text-lg font-bold text-slate-800 dark:text-slate-100">
              Nguyễn Thị Lan
            </h2>
            <div className="flex items-center gap-2 mt-0.5">
              <Badge variant="purple">Phụ huynh học sinh</Badge>
              <span className="text-xs text-slate-400">ID: PAR-005</span>
            </div>
          </div>
        </div>

        <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 pt-4 border-t border-slate-100 dark:border-slate-800 text-xs">
          <div className="flex items-center gap-2 text-slate-600 dark:text-slate-300">
            <Phone className="w-4 h-4 text-slate-400" /> +84 912 345 678
          </div>
          <div className="flex items-center gap-2 text-slate-600 dark:text-slate-300">
            <Mail className="w-4 h-4 text-slate-400" /> lan.nguyen@example.com
          </div>
        </div>
      </Card>

      <Card className="p-6 space-y-4">
        <div className="flex items-center justify-between">
          <h3 className="text-sm font-bold text-slate-800 dark:text-slate-100">
            Danh Sách Con Em Đã Liên Kết
          </h3>
          <Button size="sm" variant="outline" className="gap-1 text-xs">
            <Plus className="w-3.5 h-3.5" /> Liên kết thêm con
          </Button>
        </div>

        <div className="p-4 rounded-xl bg-slate-50 dark:bg-slate-800/40 border border-slate-200/50 dark:border-slate-700/50 flex items-center justify-between">
          <div className="flex items-center gap-3">
            <div className="w-10 h-10 rounded-xl bg-emerald-500 text-white flex items-center justify-center font-bold text-sm">
              N
            </div>
            <div>
              <div className="text-xs font-bold text-slate-800 dark:text-slate-100">Dương Đức Nam</div>
              <div className="text-[11px] text-slate-400">Lớp 4A2 • Trường THQT Á Châu (Mã: ABA-2026-001)</div>
            </div>
          </div>

          <Badge variant="success">Đang hoạt động</Badge>
        </div>
      </Card>
    </div>
  );
}
