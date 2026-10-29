/**
 * PA57 — the deep link behind "Thiết bị mới đăng nhập" in the parent's activity
 * feed: `/parent/profile?devices=<childId>` opens that child's device panel
 * instead of dropping the parent on a profile page to hunt for the button.
 *
 * A URL is untrusted input like any other, so the rule for "which child opens"
 * lives here as a pure function and is unit-tested, rather than being re-derived
 * inside a component that cannot be run against a query string.
 */

/** The child id the link asks for, or null when the query says nothing usable. */
export function parseDevicesTarget(search: string): number | null {
  const raw = new URLSearchParams(search).get('devices');
  if (raw === null) return null;
  const id = Number(raw);
  // Positive whole numbers only: `2.5`, `-1`, `0`, `abc` and `` all mean
  // "no target", never a lookup on a half-parsed id.
  return Number.isInteger(id) && id > 0 ? id : null;
}
