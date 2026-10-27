// The Learning Path unit id scheme is `unit-<competency>` — owned by the backend
// LearningPathService (and mirrored in the mock), and carried by /learn as each
// chapter's DOM anchor. A recommendation only knows its `targetCompetency` code,
// so this helper is the single place that turns a code into the scroll anchor used
// to deep-link a learner straight to the matching chapter. That closes the
// Measurement → Learning loop (§13 → §20) without leaking the id scheme across
// features: if `unit-<code>` ever changes, only this file and the path service move.

/** `ER` -> `unit-er` (case- and whitespace-insensitive so any payload casing resolves). */
export function pathAnchorForCompetency(code: string): string {
  return `unit-${code.trim().toLowerCase()}`;
}

/** Absolute /learn URL that scrolls to the competency's chapter. */
export function pathUrlForCompetency(code: string): string {
  return `/learn#${pathAnchorForCompetency(code)}`;
}
