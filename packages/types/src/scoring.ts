import { CompetencyCode } from './index';

export interface ScoredItemResult {
  questionId: number;
  competencyCode: CompetencyCode;
  scoreValue: number;
  normalizedScore: number;
}

/**
 * Frequency (Likert 1..4) scoring rule
 * Section 14, 28, 29, 31 of docs/assessment-spec.md
 */
export function scoreFrequencyItem(
  rawValue: number,
  isReverse: boolean = false
): { scoreValue: number; normalizedScore: number } {
  const min = 1;
  const max = 4;
  const clamped = Math.max(min, Math.min(max, rawValue));
  const scoreValue = isReverse ? max + min - clamped : clamped;
  const normalizedScore = Math.round(((scoreValue - min) / (max - min)) * 100 * 100) / 100;
  return { scoreValue, normalizedScore };
}

/**
 * Scenario scoring rule
 * Section 33-36 of docs/assessment-spec.md
 */
export function scoreScenarioItem(
  selectedOptionScore: number
): { scoreValue: number; normalizedScore: number } {
  const normalizedScore = Math.max(0, Math.min(100, selectedOptionScore));
  return { scoreValue: normalizedScore, normalizedScore };
}

/**
 * Quality Flags evaluation
 */
export function evaluateQualityFlags(responses: Array<{ latencyMs?: number; value: any }>): {
  speedingFlag: boolean;
  straightlining: boolean;
  averageLatencyMs: number;
} {
  const latencies = responses
    .map((r) => r.latencyMs || 0)
    .filter((l) => l > 0);

  const speedingFlag = latencies.some((l) => l < 2000);
  const averageLatencyMs =
    latencies.length > 0
      ? Math.round(latencies.reduce((a, b) => a + b, 0) / latencies.length)
      : 0;

  // Straightlining: 5 or more consecutive identical responses
  let straightlining = false;
  let consecutive = 1;
  let lastVal: any = null;

  for (const r of responses) {
    if (lastVal !== null && r.value === lastVal) {
      consecutive++;
      if (consecutive >= 5) {
        straightlining = true;
        break;
      }
    } else {
      consecutive = 1;
      lastVal = r.value;
    }
  }

  return { speedingFlag, straightlining, averageLatencyMs };
}
