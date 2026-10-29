import { describe, expect, it } from 'vitest';
import { formatDeviceSeen, formatDeviceSignedIn } from './deviceSession';

// PA55: the server sends two ISO timestamps and nothing else — these are the
// only two ways a device row can be worded for a parent or learner.
const now = new Date('2026-10-28T10:00:00Z');

describe('formatDeviceSeen', () => {
  it('says "vừa xong" for a request from this minute', () => {
    expect(formatDeviceSeen('2026-10-28T09:59:30Z', '2026-10-01T00:00:00Z', now)).toBe('Vừa xong');
  });

  it('counts minutes, then hours', () => {
    expect(formatDeviceSeen('2026-10-28T09:45:00Z', '2026-10-01T00:00:00Z', now)).toBe('15 phút trước');
    expect(formatDeviceSeen('2026-10-28T07:00:00Z', '2026-10-01T00:00:00Z', now)).toBe('3 giờ trước');
  });

  it('counts days up to a week, then shows the date', () => {
    expect(formatDeviceSeen('2026-10-26T10:00:00Z', '2026-10-01T00:00:00Z', now)).toBe('2 ngày trước');
    expect(formatDeviceSeen('2026-10-01T10:00:00Z', '2026-09-01T00:00:00Z', now)).toBe('01/10/2026');
  });

  it('falls back to the sign-in time while the token has never been used', () => {
    expect(formatDeviceSeen(null, '2026-10-28T09:30:00Z', now)).toBe('30 phút trước');
  });

  it('reads a future stamp as "vừa xong" instead of a negative count', () => {
    expect(formatDeviceSeen('2026-10-28T10:05:00Z', '2026-10-01T00:00:00Z', now)).toBe('Vừa xong');
  });

  it('never renders an invalid timestamp as a date', () => {
    expect(formatDeviceSeen('not-a-date', '2026-10-01T00:00:00Z', now)).toBe('Không rõ');
  });
});

describe('formatDeviceSignedIn', () => {
  it('is always absolute', () => {
    expect(formatDeviceSignedIn('2026-10-22T08:00:00Z')).toBe('22/10/2026');
  });

  it('degrades to "Không rõ" on a bad stamp', () => {
    expect(formatDeviceSignedIn('')).toBe('Không rõ');
  });
});
