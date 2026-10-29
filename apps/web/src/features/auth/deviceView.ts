'use client';

import type { ComponentType } from 'react';
import { Smartphone, Tablet, Laptop } from 'lucide-react';

/**
 * Shared presentation helpers for the two device lists — the learner's own
 * devices (PA55) and a parent's view of their child's devices (PA56). They read
 * the same server projection, so they must name and explain a row the same way.
 */

/** The label the server derived from the sign-in's user agent. */
export function iconFor(label: string): ComponentType<{ className?: string }> {
  if (label === 'Điện thoại') return Smartphone;
  if (label === 'Máy tính bảng') return Tablet;
  return Laptop;
}

/** The two error shapes the REST layer can produce, in a family-facing line. */
export function readDeviceError(e: unknown): string {
  const err = e as { error?: { code?: string; message?: string }; code?: string } | null;
  const code = err?.error?.code ?? err?.code;
  if (code === 'ABA_DEVICE_NOT_FOUND') return 'Thiết bị này đã đăng xuất từ trước.';
  return err?.error?.message || 'Không đăng xuất được. Vui lòng thử lại.';
}
