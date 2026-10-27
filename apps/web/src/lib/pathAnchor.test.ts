import { describe, it, expect } from 'vitest';
import { pathAnchorForCompetency, pathUrlForCompetency } from './pathAnchor';

// The deep-link (PA36) relies on the recommendation's competency code resolving to
// the exact DOM anchor /learn renders for that chapter (`unit-<code>`, the same id
// the backend LearningPathService assigns). These lock that contract so the two
// features cannot silently drift apart.
describe('pathAnchorForCompetency', () => {
  it('maps a competency code to its learning-path unit anchor', () => {
    expect(pathAnchorForCompetency('ER')).toBe('unit-er');
    expect(pathAnchorForCompetency('SA')).toBe('unit-sa');
    expect(pathAnchorForCompetency('PD')).toBe('unit-pd');
  });

  it('is case- and whitespace-insensitive', () => {
    expect(pathAnchorForCompetency('er')).toBe('unit-er');
    expect(pathAnchorForCompetency(' Co ')).toBe('unit-co');
  });
});

describe('pathUrlForCompetency', () => {
  it('produces a /learn hash link to the chapter', () => {
    expect(pathUrlForCompetency('CE')).toBe('/learn#unit-ce');
  });
});
