import { describe, expect, it } from 'vitest';
import { parseDevicesTarget } from './deviceLink';

// PA57: the activity feed links to `?devices=<childId>`. Everything else in a
// query string must resolve to "no child", so the panel never opens on a
// half-parsed id.
describe('parseDevicesTarget', () => {
  it('opens the child named in the link', () => {
    expect(parseDevicesTarget('?devices=42')).toBe(42);
  });

  it('finds the target among other parameters', () => {
    expect(parseDevicesTarget('?tab=devices&devices=7&from=activities')).toBe(7);
  });

  it('reads no target when the query is empty or unrelated', () => {
    expect(parseDevicesTarget('')).toBeNull();
    expect(parseDevicesTarget('?devices')).toBeNull();
    expect(parseDevicesTarget('?child=7')).toBeNull();
  });

  it('rejects anything that is not a positive whole number', () => {
    expect(parseDevicesTarget('?devices=')).toBeNull();
    expect(parseDevicesTarget('?devices=abc')).toBeNull();
    expect(parseDevicesTarget('?devices=0')).toBeNull();
    expect(parseDevicesTarget('?devices=-3')).toBeNull();
    expect(parseDevicesTarget('?devices=2.5')).toBeNull();
  });
});
