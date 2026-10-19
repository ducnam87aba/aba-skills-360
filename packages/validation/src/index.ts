import { z } from 'zod';

export const loginSchema = z.object({
  username: z.string().min(1, 'Vui lòng nhập tên đăng nhập hoặc email'),
  password: z.string().min(6, 'Mật khẩu phải có ít nhất 6 ký tự'),
});

export type LoginInput = z.infer<typeof loginSchema>;

export const assessmentStartSchema = z.object({
  assessmentId: z.number().positive(),
  studentId: z.number().positive(),
});

export type AssessmentStartInput = z.infer<typeof assessmentStartSchema>;

export const assessmentResponseSchema = z.object({
  questionId: z.number().positive(),
  answer: z.union([z.string(), z.number()]),
  latencyMs: z.number().nonnegative().optional(),
});

export type AssessmentResponseInput = z.infer<typeof assessmentResponseSchema>;

export const assessmentCompleteSchema = z.object({
  sessionId: z.number().positive(),
});

export type AssessmentCompleteInput = z.infer<typeof assessmentCompleteSchema>;

export const parentLinkSchema = z.object({
  studentCode: z.string().min(4, 'Mã học sinh không hợp lệ'),
  relationship: z.enum(['father', 'mother', 'guardian']),
});

export type ParentLinkInput = z.infer<typeof parentLinkSchema>;
