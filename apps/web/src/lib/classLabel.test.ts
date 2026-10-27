import { describe, expect, it } from 'vitest';
import { formatClassLabel } from './classLabel';

// PA41: real API projects the full display name, mock fixtures store the bare
// code — the helper must render both as a single "Lớp …" label.
describe('formatClassLabel', () => {
  it('passes through names that already carry the prefix', () => {
    expect(formatClassLabel('Lớp 4A2')).toBe('Lớp 4A2');
  });

  it('prefixes bare class codes', () => {
    expect(formatClassLabel('4A2')).toBe('Lớp 4A2');
  });

  it('returns an empty label when the class is unknown', () => {
    expect(formatClassLabel(null)).toBe('');
    expect(formatClassLabel(undefined)).toBe('');
  });
});
