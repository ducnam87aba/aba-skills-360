import { describe, it, expect } from 'vitest';
import {
  scoreFrequencyItem,
  scoreScenarioItem,
  evaluateQualityFlags,
} from '../scoring';

describe('Assessment Engine Scoring', () => {
  it('correctly scores regular frequency item (Likert 1..4)', () => {
    // 1 -> 0%
    const res1 = scoreFrequencyItem(1, false);
    expect(res1.scoreValue).toBe(1);
    expect(res1.normalizedScore).toBe(0);

    // 4 -> 100%
    const res4 = scoreFrequencyItem(4, false);
    expect(res4.scoreValue).toBe(4);
    expect(res4.normalizedScore).toBe(100);

    // 2 -> 33.33%
    const res2 = scoreFrequencyItem(2, false);
    expect(res2.scoreValue).toBe(2);
    expect(res2.normalizedScore).toBe(33.33);

    // 3 -> 66.67%
    const res3 = scoreFrequencyItem(3, false);
    expect(res3.scoreValue).toBe(3);
    expect(res3.normalizedScore).toBe(66.67);
  });

  it('correctly scores reverse frequency item (isReverse: true)', () => {
    // Reverse: 1 -> score 4 -> 100%
    const res1 = scoreFrequencyItem(1, true);
    expect(res1.scoreValue).toBe(4);
    expect(res1.normalizedScore).toBe(100);

    // Reverse: 4 -> score 1 -> 0%
    const res4 = scoreFrequencyItem(4, true);
    expect(res4.scoreValue).toBe(1);
    expect(res4.normalizedScore).toBe(0);

    // Reverse: 2 -> score 3 -> 66.67%
    const res2 = scoreFrequencyItem(2, true);
    expect(res2.scoreValue).toBe(3);
    expect(res2.normalizedScore).toBe(66.67);
  });

  it('correctly normalizes scenario item scores', () => {
    expect(scoreScenarioItem(100).normalizedScore).toBe(100);
    expect(scoreScenarioItem(50).normalizedScore).toBe(50);
    expect(scoreScenarioItem(0).normalizedScore).toBe(0);
  });

  it('detects speeding flag when response time < 2000ms', () => {
    const flagsFast = evaluateQualityFlags([
      { latencyMs: 1500, value: 'A' },
      { latencyMs: 4000, value: 'B' },
    ]);
    expect(flagsFast.speedingFlag).toBe(true);

    const flagsNormal = evaluateQualityFlags([
      { latencyMs: 2500, value: 'A' },
      { latencyMs: 4000, value: 'B' },
    ]);
    expect(flagsNormal.speedingFlag).toBe(false);
  });

  it('detects straightlining when 5 or more consecutive answers are identical', () => {
    const flagsStraight = evaluateQualityFlags([
      { value: '3' },
      { value: '3' },
      { value: '3' },
      { value: '3' },
      { value: '3' },
    ]);
    expect(flagsStraight.straightlining).toBe(true);

    const flagsVaried = evaluateQualityFlags([
      { value: '1' },
      { value: '2' },
      { value: '3' },
      { value: '4' },
      { value: '3' },
    ]);
    expect(flagsVaried.straightlining).toBe(false);
  });
});
