'use client';

/**
 * PA41 — avatar with an initials fallback.
 *
 * Demo learners have no avatar_url in the database, so bare <img> tags rendered
 * broken-image icons across the parent portal (and the student profile). This
 * drops into an existing <img> slot: pass the same sizing/rounding classes and
 * it falls back to a brand-colored initials circle when the src is missing or
 * fails to load. Text-size classes in className only affect the fallback.
 */

import React, { useState } from 'react';

type AvatarProps = {
  src?: string | null;
  name?: string | null;
  className?: string;
};

function initialsOf(name: string): string {
  return name
    .trim()
    .split(/\s+/)
    .slice(0, 2)
    .map((word) => word.charAt(0).toUpperCase())
    .join('');
}

export function Avatar({ src, name, className = '' }: AvatarProps) {
  // Failure is tracked per src so switching children (new avatarUrl props)
  // gets a fresh load attempt instead of inheriting an earlier onError.
  const [failedSrc, setFailedSrc] = useState<string | null>(null);
  const label = name?.trim();

  if (!src || failedSrc === src) {
    return (
      <span
        aria-hidden="true"
        className={
          'inline-flex items-center justify-center font-black bg-brand-100 text-brand-700 shrink-0 '
          + className
        }
      >
        {label ? initialsOf(label) : null}
      </span>
    );
  }

  return <img src={src} alt={name ?? ''} onError={() => setFailedSrc(src)} className={className} />;
}
