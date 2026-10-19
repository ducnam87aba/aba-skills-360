import { describe, it, expect } from 'vitest';
import {
  loginSchema,
  assessmentStartSchema,
  assessmentResponseSchema,
  parentLinkSchema,
} from '../index';

describe('Validation Schemas', () => {
  it('validates login credentials', () => {
    expect(() => loginSchema.parse({ username: 'student1', password: 'password123' })).not.toThrow();
    expect(() => loginSchema.parse({ username: '', password: '123' })).toThrow();
  });

  it('validates assessment start input', () => {
    expect(() => assessmentStartSchema.parse({ assessmentId: 1, studentId: 1 })).not.toThrow();
    expect(() => assessmentStartSchema.parse({ assessmentId: -1, studentId: 1 })).toThrow();
  });

  it('validates assessment response with latencyMs', () => {
    expect(() =>
      assessmentResponseSchema.parse({
        questionId: 5,
        answer: '3',
        latencyMs: 3500,
      })
    ).not.toThrow();

    expect(() =>
      assessmentResponseSchema.parse({
        questionId: 0,
        answer: 'A',
      })
    ).toThrow();
  });

  it('validates parent linking code', () => {
    expect(() =>
      parentLinkSchema.parse({
        studentCode: 'ABA-2026-001',
        relationship: 'mother',
      })
    ).not.toThrow();

    expect(() =>
      parentLinkSchema.parse({
        studentCode: 'AB',
        relationship: 'unknown_relation' as any,
      })
    ).toThrow();
  });
});
