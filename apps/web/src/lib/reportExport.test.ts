import { describe, it, expect } from 'vitest';
import type { StudentReport, SkillHistoryEntry } from '@aba/types';
import { csvField, reportToCsv, reportCsvFilename, skillsHistoryToCsv } from './reportExport';

// A fixture that deliberately carries commas, quotes and the em-dash so the
// escaping and stable row shape are exercised, not just the happy path.
const report: StudentReport = {
  id: 42,
  studentId: 7,
  generatedAt: '2026-10-27T08:00:00Z',
  title: 'Báo cáo năng lực, kỳ 1',
  summary: 'Con nổi bật về giao tiếp, cần thêm tự quản lý.',
  overallScore: 78,
  strengths: ['Chủ động phát biểu', 'Hợp tác nhóm tốt'],
  areasForGrowth: ['Kiểm soát cảm xúc khi bất đồng'],
  recommendations: [
    {
      id: 'r1',
      title: 'Bài luyện "Nói trước lớp"',
      description: 'Thực hành 5 phút mỗi ngày, ghi hình lại.',
      type: 'lesson',
      targetCompetency: 'CO',
    },
  ],
};

describe('reportExport — csvField escaping (PA12)', () => {
  it('leaves a plain field untouched', () => {
    expect(csvField('Học viên')).toBe('Học viên');
  });
  it('quotes a field containing a comma', () => {
    expect(csvField('a,b')).toBe('"a,b"');
  });
  it('doubles embedded quotes and wraps the field', () => {
    expect(csvField('say "hi"')).toBe('"say ""hi"""');
  });
  it('renders null/undefined as empty', () => {
    expect(csvField(null)).toBe('');
    expect(csvField(undefined)).toBe('');
  });
});

describe('reportExport — reportToCsv (PA12)', () => {
  const csv = reportToCsv(report);
  const rows = csv.split('\r\n').filter((r) => r.length > 0);

  it('starts with the agreed header', () => {
    expect(rows[0]).toBe('Phần,Nhãn,Giá trị');
  });

  it('uses CRLF line endings and a single trailing line', () => {
    expect(csv.endsWith('\r\n')).toBe(true);
    expect(csv.split('\r\n').pop()).toBe('');
  });

  it('is deterministic — same report yields the identical string', () => {
    expect(reportToCsv(report)).toBe(csv);
  });

  it('emits one row per data point: 5 meta + 1 summary + strengths + growth + recs', () => {
    const expected = 1 + 5 + 1 + report.strengths.length + report.areasForGrowth.length + report.recommendations.length;
    expect(rows.length).toBe(expected);
  });

  it('quotes the title/summary that contain commas', () => {
    expect(csv).toContain('"Báo cáo năng lực, kỳ 1"');
    expect(csv).toContain('"Con nổi bật về giao tiếp, cần thêm tự quản lý."');
  });

  it('quotes the recommendation detail carrying a comma and embedded quotes', () => {
    // The whole `... — ...` detail contains a comma so the value cell is quoted.
    expect(csv).toContain('"Bài luyện ""Nói trước lớp"" [lesson/CO] — Thực hành 5 phút mỗi ngày, ghi hình lại."');
  });

  it('names the file after the student and the report date', () => {
    expect(reportCsvFilename(report)).toBe('bao-cao-nang-luc-7-2026-10-27.csv');
  });
});

describe('reportExport — skillsHistoryToCsv (PA16)', () => {
  // Second snapshot is partial (SM/CE/PD insufficient evidence) — the runtime
  // shape the backend actually returns for a not-fully-measured period.
  const history = [
    { snapshotId: 1, date: '2026-01-01', scores: { SA: 60, ER: 50, SM: 70, CO: 40, CE: 80, PD: 90 } },
    { snapshotId: 2, date: '2026-06-01', scores: { SA: 70, ER: 60, CO: 55 } },
  ] as unknown as SkillHistoryEntry[];

  const csv = skillsHistoryToCsv(history);
  const rows = csv.split('\r\n').filter((r) => r.length > 0);

  it('writes the canonical competency header', () => {
    expect(rows[0]).toBe('Kỳ đánh giá,SA,ER,SM,CO,CE,PD,Trung bình');
  });

  it('emits one row per snapshot plus the header', () => {
    expect(rows.length).toBe(history.length + 1);
  });

  it('computes the average over present competencies only', () => {
    expect(rows[1]).toBe('2026-01-01,60,50,70,40,80,90,65');
    expect(rows[2]).toBe('2026-06-01,70,60,,55,,,61.7');
  });

  it('leaves insufficient competencies blank rather than 0 (§45)', () => {
    // SM/CE/PD absent => empty cells between the measured ones.
    expect(rows[2].split(',')[3]).toBe('');
  });

  it('is deterministic and uses CRLF', () => {
    expect(skillsHistoryToCsv(history)).toBe(csv);
    expect(csv.endsWith('\r\n')).toBe(true);
  });

  it('handles an empty history with just the header', () => {
    expect(skillsHistoryToCsv([])).toBe('Kỳ đánh giá,SA,ER,SM,CO,CE,PD,Trung bình\r\n');
  });
});
