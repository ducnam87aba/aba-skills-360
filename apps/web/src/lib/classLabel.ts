/**
 * PA41 — class label formatting.
 *
 * The API projects className from the class row's display name, which already
 * carries the "Lớp …" prefix (e.g. "Lớp 4A2"), while the mock fixtures store
 * the bare code (e.g. "4A2"). Hardcoding "Lớp " at each call site rendered
 * "Lớp Lớp 4A2" in real mode, so every site now goes through this helper and
 * both data shapes render the same label.
 */
export function formatClassLabel(className?: string | null): string {
  if (!className) return '';
  return /^lớp\b/i.test(className) ? className : `Lớp ${className}`;
}
