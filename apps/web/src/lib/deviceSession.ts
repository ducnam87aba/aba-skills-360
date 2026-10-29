/**
 * PA55 — how one of the caller's own devices reads in the family's words.
 *
 * The server hands over two ISO timestamps and nothing else; turning them into
 * "2 giờ trước" is presentation, so it lives here as a pure function that takes
 * the clock as an argument (a component can't be unit-tested against `now`).
 */

/** dd/MM/yyyy — the fallback once relative wording stops helping. */
function shortDate(iso: string): string {
  const value = new Date(iso);
  if (Number.isNaN(value.getTime())) return 'Không rõ';
  return value.toLocaleDateString('vi-VN', { day: '2-digit', month: '2-digit', year: 'numeric' });
}

/**
 * When this device was last seen. Falls back to the sign-in time while the
 * token has never been used, and to a plain date after a week — "12 ngày
 * trước" tells a parent less than the date does.
 */
export function formatDeviceSeen(
  lastUsedAt: string | null,
  createdAt: string,
  now: Date = new Date()
): string {
  const iso = lastUsedAt ?? createdAt;
  if (!iso) return 'Không rõ';
  const seen = new Date(iso);
  if (Number.isNaN(seen.getTime())) return 'Không rõ';

  // A future stamp (clock skew between server and device) reads as "just now"
  // rather than "-2 phút trước".
  const minutes = Math.floor((now.getTime() - seen.getTime()) / 60_000);
  if (minutes < 1) return 'Vừa xong';
  if (minutes < 60) return `${minutes} phút trước`;

  const hours = Math.floor(minutes / 60);
  if (hours < 24) return `${hours} giờ trước`;

  const days = Math.floor(hours / 24);
  if (days < 7) return `${days} ngày trước`;

  return shortDate(iso);
}

/**
 * When this device signed in — always absolute: "3 ngày trước" cannot answer
 * "is this the login I made on Saturday?".
 */
export function formatDeviceSignedIn(createdAt: string): string {
  return shortDate(createdAt);
}
