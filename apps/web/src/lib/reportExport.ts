import type { StudentReport, SkillHistoryEntry, CompetencyCode } from '@aba/types';

/**
 * PA12 — parent report export.
 *
 * `reportToCsv` is a pure, deterministic string builder (no DOM, no network) so
 * it can be unit-tested under plain node vitest and reused by any export target.
 * The browser helpers below are thin, guarded wrappers; the app never calls
 * fetch directly (AGENTS.md §16) and this keeps presentation out of the logic.
 */

const CSV_LINE_ENDING = '\r\n';

/** RFC-4180 escape: quote when a field carries a delimiter/quote/newline. */
export function csvField(value: unknown): string {
  const s = value === null || value === undefined ? '' : String(value);
  return /[",\r\n]/.test(s) ? '"' + s.replace(/"/g, '""') + '"' : s;
}

function csvRow(cells: unknown[]): string {
  return cells.map(csvField).join(',');
}

/** Flatten a report into a tidy `Phần,Nhãn,Giá trị` table (one data point per row). */
export function reportToCsv(report: StudentReport): string {
  const lines: string[] = [csvRow(['Phần', 'Nhãn', 'Giá trị'])];

  lines.push(csvRow(['Thông tin chung', 'ID báo cáo', report.id]));
  lines.push(csvRow(['Thông tin chung', 'Học viên', report.studentId]));
  lines.push(csvRow(['Thông tin chung', 'Ngày phát hành', report.generatedAt]));
  lines.push(csvRow(['Thông tin chung', 'Tiêu đề', report.title]));
  lines.push(csvRow(['Thông tin chung', 'Điểm tổng thể', report.overallScore]));
  lines.push(csvRow(['Tóm tắt', 'Chuyên môn', report.summary]));

  report.strengths.forEach((str, i) => lines.push(csvRow(['Điểm mạnh', i + 1, str])));
  report.areasForGrowth.forEach((area, i) => lines.push(csvRow(['Cần rèn luyện', i + 1, area])));

  report.recommendations.forEach((rec) => {
    const detail = `${rec.title} [${rec.type}/${rec.targetCompetency}] — ${rec.description}`;
    lines.push(csvRow(['Khuyến nghị', rec.id, detail]));
  });

  return lines.join(CSV_LINE_ENDING) + CSV_LINE_ENDING;
}

/** Filename derived from the report id + date, safe for the download attribute. */
export function reportCsvFilename(report: StudentReport): string {
  const stamp = (report.generatedAt || '').slice(0, 10);
  return `bao-cao-nang-luc-${report.studentId}-${stamp || report.id}.csv`;
}

/** Trigger a browser download. UTF-8 BOM so Excel renders Vietnamese diacritics. */
export function downloadCsv(filename: string, csv: string): void {
  if (typeof window === 'undefined') return;
  const blob = new Blob(['\uFEFF' + csv], { type: 'text/csv;charset=utf-8;' });
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = filename;
  document.body.appendChild(a);
  a.click();
  a.remove();
  URL.revokeObjectURL(url);
}

/** Native print → the browser's "Save as PDF" covers the PDF affordance (no server dep). */
export function printReport(): void {
  if (typeof window === 'undefined') return;
  window.print();
}

// Canonical competency column order for the longitudinal trend table.
const COMPETENCY_ORDER: CompetencyCode[] = ['SA', 'ER', 'SM', 'CO', 'CE', 'PD'];

/**
 * PA16 — skills trend as a period-by-competency matrix (oldest → newest).
 * A competency absent from a snapshot (insufficient evidence) is left blank,
 * never coerced to 0, so the historical record stays honest (§11, §45).
 */
export function skillsHistoryToCsv(history: SkillHistoryEntry[]): string {
  const header = ['Kỳ đánh giá', ...COMPETENCY_ORDER, 'Trung bình'];
  const lines: string[] = [header.map(csvField).join(',')];

  for (const entry of history) {
    const cells: string[] = [entry.date];
    const present: number[] = [];
    for (const code of COMPETENCY_ORDER) {
      const val = entry.scores?.[code];
      if (val === null || val === undefined) {
        cells.push('');
      } else {
        cells.push(String(val));
        present.push(Number(val));
      }
    }
    const avg = present.length
      ? String(Math.round((present.reduce((a, b) => a + b, 0) / present.length) * 10) / 10)
      : '';
    cells.push(avg);
    lines.push(cells.map(csvField).join(','));
  }

  return lines.join(CSV_LINE_ENDING) + CSV_LINE_ENDING;
}

/** Stable trend filename; uses the student id when the caller has one. */
export function skillsHistoryCsvFilename(studentId?: number): string {
  return `xu-huong-nang-luc-${studentId ?? 'me'}.csv`;
}
