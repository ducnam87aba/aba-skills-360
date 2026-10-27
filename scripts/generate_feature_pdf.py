# -*- coding: utf-8 -*-
"""
Generate `docs/ABA-Skills-360-Gioi-Thieu-Tinh-Nang.pdf` — a Vietnamese
feature-overview brochure for the ABA Skills 360 platform.

Content is sourced from README.md, docs/architecture.md and
docs/assessment-spec.md so the brochure stays in sync with the
product specification.

Usage:
    python scripts/generate_feature_pdf.py
"""

import os

from reportlab.lib import colors
from reportlab.lib.colors import HexColor
from reportlab.lib.enums import TA_CENTER
from reportlab.lib.pagesizes import A4
from reportlab.lib.styles import ParagraphStyle, getSampleStyleSheet
from reportlab.lib.units import mm
from reportlab.lib.utils import ImageReader
from reportlab.pdfbase import pdfmetrics
from reportlab.pdfbase.ttfonts import TTFont
from reportlab.platypus import (
    BaseDocTemplate,
    Frame,
    Image,
    PageTemplate,
    Paragraph,
    Spacer,
    Table,
    TableStyle,
)

OUT_PATH = os.path.join(
    os.path.dirname(os.path.dirname(os.path.abspath(__file__))),
    "docs",
    "ABA-Skills-360-Gioi-Thieu-Tinh-Nang.pdf",
)

SHOTS_DIR = os.path.join(
    os.path.dirname(os.path.dirname(os.path.abspath(__file__))),
    "docs",
    "screenshots",
)

# ----------------------------------------------------------------------
# Palette (brand direction: own design system, not Duolingo branding)
# ----------------------------------------------------------------------
INDIGO = HexColor("#4F46E5")
INDIGO_DARK = HexColor("#312E81")
INDIGO_SOFT = HexColor("#EEF2FF")
EMERALD = HexColor("#059669")
AMBER = HexColor("#D97706")
SLATE = HexColor("#334155")
SLATE_LIGHT = HexColor("#64748B")
LINE = HexColor("#CBD5E1")

# ----------------------------------------------------------------------
# Fonts — Segoe UI ships full Vietnamese diacritic coverage on Windows
# ----------------------------------------------------------------------
FONT = "Segoe"
FONT_BOLD = "Segoe-Bold"
FONT_ITALIC = "Segoe-Italic"

pdfmetrics.registerFont(TTFont(FONT, r"C:\Windows\Fonts\segoeui.ttf"))
pdfmetrics.registerFont(TTFont(FONT_BOLD, r"C:\Windows\Fonts\segoeuib.ttf"))
pdfmetrics.registerFont(TTFont(FONT_ITALIC, r"C:\Windows\Fonts\segoeuii.ttf"))
pdfmetrics.registerFontFamily(
    FONT, normal=FONT, bold=FONT_BOLD, italic=FONT_ITALIC, boldItalic=FONT_BOLD
)

# ----------------------------------------------------------------------
# Styles
# ----------------------------------------------------------------------
ss = getSampleStyleSheet()

H1 = ParagraphStyle(
    "H1", parent=ss["Heading1"], fontName=FONT_BOLD, fontSize=17,
    leading=22, textColor=INDIGO_DARK, spaceBefore=6, spaceAfter=2,
)
H2 = ParagraphStyle(
    "H2", parent=ss["Heading2"], fontName=FONT_BOLD, fontSize=12.5,
    leading=17, textColor=INDIGO, spaceBefore=10, spaceAfter=3,
)
BODY = ParagraphStyle(
    "Body", parent=ss["Normal"], fontName=FONT, fontSize=10, leading=15,
    textColor=SLATE, spaceAfter=4,
)
LEAD = ParagraphStyle(
    "Lead", parent=BODY, fontSize=11, leading=16.5, textColor=SLATE,
    spaceAfter=8,
)
CELL = ParagraphStyle("Cell", parent=BODY, fontSize=9.4, leading=13, spaceAfter=0)
CELL_HEAD = ParagraphStyle(
    "CellHead", parent=CELL, fontName=FONT_BOLD, textColor=colors.white,
)
CELL_ALT = ParagraphStyle(
    "CellAlt", parent=CELL, backColor=None,
)
COVER_TITLE = ParagraphStyle(
    "CoverTitle", parent=BODY, fontName=FONT_BOLD, fontSize=34, leading=42,
    textColor=colors.white, alignment=TA_CENTER,
)
COVER_SUB = ParagraphStyle(
    "CoverSub", parent=BODY, fontSize=13.5, leading=20,
    textColor=HexColor("#C7D2FE"), alignment=TA_CENTER,
)
FOOTER = ParagraphStyle(
    "Footer", parent=BODY, fontSize=8, textColor=SLATE_LIGHT,
)


def bullet(text, color="black"):
    return Paragraph(f"•&nbsp;&nbsp;{text}", CELL)


def feature_table(rows, widths, header):
    """2-col table: Feature name | Description."""
    data = [[Paragraph(header[0], CELL_HEAD), Paragraph(header[1], CELL_HEAD)]]
    for name, desc in rows:
        data.append([
            Paragraph(f"<b>{name}</b>", CELL),
            Paragraph(desc, CELL),
        ])
    t = Table(data, colWidths=widths, repeatRows=1)
    t.setStyle(TableStyle([
        ("BACKGROUND", (0, 0), (-1, 0), INDIGO),
        ("ROWBACKGROUNDS", (0, 1), (-1, -1), [colors.white, INDIGO_SOFT]),
        ("GRID", (0, 0), (-1, -1), 0.5, LINE),
        ("VALIGN", (0, 0), (-1, -1), "TOP"),
        ("LEFTPADDING", (0, 0), (-1, -1), 6),
        ("RIGHTPADDING", (0, 0), (-1, -1), 6),
        ("TOPPADDING", (0, 0), (-1, -1), 5),
        ("BOTTOMPADDING", (0, 0), (-1, -1), 5),
    ]))
    return t


CAPTION = ParagraphStyle(
    "Caption", parent=BODY, fontName=FONT_ITALIC, fontSize=8.6, leading=12,
    textColor=SLATE_LIGHT, spaceBefore=3, spaceAfter=0, alignment=TA_CENTER,
)


def shot(filename, caption, width_mm=150):
    """Embed a UI screenshot scaled to `width_mm`, framed with a caption."""
    path = os.path.join(SHOTS_DIR, filename)
    if not os.path.exists(path):
        return Paragraph(f"[thiếu ảnh: {filename}]", CAPTION)
    iw, ih = ImageReader(path).getSize()
    w = width_mm * mm
    h = w * ih / iw
    img = Image(path, width=w, height=h)
    img.hAlign = "CENTER"
    frame = Table(
        [[img], [Paragraph(caption, CAPTION)]],
        colWidths=[w + 6 * mm],
        style=TableStyle([
            ("BOX", (0, 0), (-1, -1), 0.7, LINE),
            ("BACKGROUND", (0, 0), (-1, -1), colors.white),
            ("LEFTPADDING", (0, 0), (-1, -1), 3),
            ("RIGHTPADDING", (0, 0), (-1, -1), 3),
            ("TOPPADDING", (0, 0), (-1, -1), 3),
            ("BOTTOMPADDING", (0, 0), (-1, -1), 3),
            ("ALIGN", (0, 0), (-1, -1), "CENTER"),
        ]),
        hAlign="CENTER",
    )
    return frame


# ----------------------------------------------------------------------
# Page backgrounds
# ----------------------------------------------------------------------
PAGE_W, PAGE_H = A4


def draw_cover(canvas, doc):
    canvas.saveState()
    canvas.setFillColor(INDIGO_DARK)
    canvas.rect(0, 0, PAGE_W, PAGE_H, stroke=0, fill=1)
    # accent band
    canvas.setFillColor(INDIGO)
    canvas.rect(0, PAGE_H - 52 * mm, PAGE_W, 8 * mm, stroke=0, fill=1)
    canvas.setFillColor(EMERALD)
    canvas.rect(0, PAGE_H - 56 * mm, PAGE_W, 4 * mm, stroke=0, fill=1)
    # deco dots
    canvas.setFillColor(HexColor("#6366F1"))
    for x in range(6):
        canvas.circle(20 * mm + x * 30 * mm, 25 * mm, 3 * mm, stroke=0, fill=1)
    canvas.restoreState()


def draw_page(canvas, doc):
    canvas.saveState()
    # top hairline
    canvas.setStrokeColor(INDIGO)
    canvas.setLineWidth(2)
    canvas.line(18 * mm, PAGE_H - 12 * mm, PAGE_W - 18 * mm, PAGE_H - 12 * mm)
    # running header
    canvas.setFont(FONT, 8)
    canvas.setFillColor(SLATE_LIGHT)
    canvas.drawString(18 * mm, PAGE_H - 10 * mm, "ABA SKILLS 360 — GIỚI THIỆU CÁC TÍNH NĂNG")
    # footer
    canvas.setStrokeColor(LINE)
    canvas.setLineWidth(0.5)
    canvas.line(18 * mm, 14 * mm, PAGE_W - 18 * mm, 14 * mm)
    canvas.setFont(FONT, 8)
    canvas.setFillColor(SLATE_LIGHT)
    canvas.drawString(18 * mm, 10 * mm, "Tài liệu nội bộ • Cập nhật 10/2026")
    canvas.drawRightString(PAGE_W - 18 * mm, 10 * mm, f"Trang {doc.page}")
    canvas.restoreState()


# ----------------------------------------------------------------------
# Document content
# ----------------------------------------------------------------------
def build_flowables():
    story = []

    # ---------------- COVER ----------------
    story.append(Spacer(1, 62 * mm))
    story.append(Paragraph("ABA SKILLS 360", COVER_TITLE))
    story.append(Spacer(1, 4 * mm))
    story.append(Paragraph(
        "Nền tảng Đánh giá &amp; Phát triển Năng lực Cá nhân hóa<br/>"
        "Học sinh lớp 1–12", COVER_SUB))
    story.append(Spacer(1, 26 * mm))
    story.append(Paragraph(
        "Tài liệu giới thiệu các tính năng của website",
        ParagraphStyle("cv3", parent=COVER_SUB, fontSize=11.5,
                       textColor=HexColor("#A5B4FC"))))
    story.append(Spacer(1, 3 * mm))
    story.append(Paragraph("Phiên bản tài liệu: 1.0", ParagraphStyle(
        "cv4", parent=COVER_SUB, fontSize=10, textColor=HexColor("#A5B4FC"))))
    story.append(Spacer(1, 34 * mm))
    story.append(Paragraph(
        "Assessment&nbsp;· Skills&nbsp;Profile&nbsp;· Parent&nbsp;Report&nbsp;· "
        "Recommendation&nbsp;· Learning&nbsp;Path&nbsp;· Gamification",
        ParagraphStyle("cv5", parent=COVER_SUB, fontSize=10,
                       textColor=colors.white)))
    from reportlab.platypus import PageBreak
    story.append(PageBreak())

    # ---------------- 1. TỔNG QUAN ----------------
    story.append(Paragraph("1. Tổng quan nền tảng", H1))
    story.append(Spacer(1, 2 * mm))
    story.append(Paragraph(
        "ABA Skills 360 không đơn thuần là một hệ thống LMS. Đây là nền tảng "
        "<b>đo lường năng lực</b> dành cho học sinh từ lớp 1 đến lớp 12: đánh giá "
        "→ xây dựng hồ sơ năng lực → đưa ra khuyến nghị cá nhân hóa → đồng hành "
        "cùng phụ huynh qua báo cáo dài hạn.", LEAD))
    story.append(Paragraph("Chuỗi giá trị cốt lõi", H2))
    story.append(Paragraph(
        "Đánh giá (Assessment) &nbsp;→&nbsp; Hồ sơ năng lực (Skills Profile) &nbsp;→&nbsp; "
        "Khuyến nghị (Recommendation) &nbsp;→&nbsp; Lộ trình học (Learning Path) &nbsp;→&nbsp; "
        "Hoạt động học tập &nbsp;→&nbsp; Đánh giá lại &nbsp;→&nbsp; "
        "Hồ sơ năng lực theo thời gian &nbsp;→&nbsp; Báo cáo cho Phụ huynh / Nhà trường",
        BODY))
    story.append(Paragraph("Hai cổng trải nghiệm trên website", H2))
    story.append(feature_table([
        ("Cổng Học sinh",
         "Lộ trình học kiểu Duolingo, Khám phá bài đánh giá, Nhiệm vụ nhận XP, "
         "Thành tích huy hiệu, Hồ sơ năng lực cá nhân."),
        ("Cổng Phụ huynh",
         "Tổng quan con em, biểu đồ xu hướng năng lực theo thời gian, báo cáo "
         "&amp; khuyến nghị, nhật ký hoạt động."),
    ], [34 * mm, 134 * mm], ("Cổng", "Mô tả")))
    story.append(Spacer(1, 4 * mm))
    story.append(shot("login.png", "Hình 1. Màn hình đăng nhập — một điểm vào cho cả Học sinh và Phụ huynh."))
    story.append(Spacer(1, 3 * mm))
    story.append(Paragraph(
        "Kỹ thuật: giao diện Next.js 14 + TypeScript (headless), dữ liệu và API "
        "vận hành trên WordPress qua plugin lõi <font face='Courier'>aba-skills-core</font> "
        "với REST API <font face='Courier'>/wp-json/aba/v1/</font>.",
        ParagraphStyle("note", parent=BODY, textColor=SLATE_LIGHT, fontSize=9)))

    # ---------------- 2. ASSESSMENT ----------------
    story.append(Spacer(1, 5 * mm))
    story.append(Paragraph("2. Assessment Engine — Đánh giá chuẩn hóa", H1))
    story.append(Spacer(1, 2 * mm))
    story.append(Paragraph(
        "Trắc nghiệm tình huống theo chuẩn khung năng lực 6 lĩnh vực, hỗ trợ "
        "nhiều đối tượng đánh giá (học sinh tự đánh giá, phụ huynh, giáo viên).", LEAD))
    story.append(feature_table([
        ("Làm bài theo phiên", "Bắt đầu, lưu tự động (autosave), nghỉ và tiếp tục "
         "(resume) — không mất dữ liệu giữa chừng."),
        ("Đa dạng chiến lược chấm điểm", "Chấm điểm tần suất (Likert), tình huống "
         "(Scenario), đánh giá của giáo viên (Teacher Rating); có thể mở rộng IRT "
         "trong tương lai."),
        ("Phiên bản hóa đề thi", "Mỗi phiên lưu phiên bản đề đã làm — điểm số lịch sử "
         "luôn tái hiện được, sửa đề mới không ảnh hưởng kết quả cũ."),
        ("Phân lớp theo nhóm tuổi", "Đề thiết kế riêng cho nhóm lớp 1–3, 4–6, 7–9, 10–12."),
        ("Cờ chất lượng dữ liệu", "Hệ thống gắn cờ phản hồi bất thường (một đáp án lặp "
         "liên tục, làm bài quá nhanh) để bảo đảm độ tin cậy đo lường."),
    ], [52 * mm, 116 * mm], ("Tính năng", "Mô tả")))
    story.append(Spacer(1, 4 * mm))
    story.append(shot("assessment.png", "Hình 2. Assessment Runner — câu hỏi tình huống kèm mốc thời gian gợi nhớ, 4 lựa chọn A–D."))

    # ---------------- 3. SKILLS PROFILE ----------------
    story.append(Spacer(1, 5 * mm))
    story.append(Paragraph("3. Skills Profile — Hồ sơ năng lực 6 lĩnh vực", H1))
    story.append(Spacer(1, 2 * mm))
    story.append(Paragraph(
        "Điểm sau khi chấm được tổng hợp thành 6 năng lực, mỗi năng lực có 3 chỉ báo "
        "hành vi. Hồ sơ lưu cả <b>điểm hiện tại</b> và <b>ảnh chụp lịch sử "
        "(snapshots)</b> — không bao giờ ghi đè dữ liệu đo cũ.", LEAD))
    comp = [
        ("SA", "Tự nhận thức", "Nhận biết cảm xúc, nhu cầu và cách mình thực hiện nhiệm vụ"),
        ("ER", "Quản lý cảm xúc", "Lựa chọn cách phản ứng an toàn, phù hợp khi có cảm xúc mạnh"),
        ("SM", "Tự quản lý", "Tổ chức hành động và duy trì trách nhiệm với nhiệm vụ phù hợp tuổi"),
        ("CO", "Giao tiếp", "Lắng nghe, diễn đạt và trao đổi bất đồng có tôn trọng"),
        ("CE", "Hợp tác &amp; đồng cảm", "Nhận biết góc nhìn người khác, phối hợp và quan tâm"),
        ("PD", "Giải quyết vấn đề", "Xác định vấn đề, cân nhắc lựa chọn và hệ quả phù hợp bối cảnh"),
    ]
    data = [[Paragraph("Mã", CELL_HEAD), Paragraph("Năng lực", CELL_HEAD),
             Paragraph("Ý nghĩa vận hành", CELL_HEAD)]]
    for code, name, meaning in comp:
        data.append([Paragraph(f"<b>{code}</b>", CELL), Paragraph(name, CELL),
                     Paragraph(meaning, CELL)])
    t = Table(data, colWidths=[14 * mm, 48 * mm, 96 * mm], repeatRows=1)
    t.setStyle(TableStyle([
        ("BACKGROUND", (0, 0), (-1, 0), EMERALD),
        ("ROWBACKGROUNDS", (0, 1), (-1, -1), [colors.white, HexColor("#ECFDF5")]),
        ("GRID", (0, 0), (-1, -1), 0.5, LINE),
        ("VALIGN", (0, 0), (-1, -1), "TOP"),
        ("LEFTPADDING", (0, 0), (-1, -1), 6),
        ("RIGHTPADDING", (0, 0), (-1, -1), 6),
        ("TOPPADDING", (0, 0), (-1, -1), 5),
        ("BOTTOMPADDING", (0, 0), (-1, -1), 5),
    ]))
    story.append(t)
    story.append(Spacer(1, 2.5 * mm))
    story.append(Paragraph(
        "Nhờ lưu trữ theo chiều dọc (longitudinal), hệ thống hỗ trợ biểu đồ xu hướng, "
        "so sánh trước/sau sau mỗi lần đánh giá lại — nền tảng cho báo cáo phát triển.",
        BODY))
    story.append(Spacer(1, 4 * mm))
    story.append(shot("profile.png", "Hình 3. Hồ sơ năng lực học sinh — 6 vòng điểm chuẩn hóa 0–100 kèm nhãn mức độ."))

    # ---------------- 4. RECOMMENDATION ----------------
    story.append(Spacer(1, 5 * mm))
    story.append(Paragraph("4. Recommendation Engine — Khuyến nghị cá nhân hóa", H1))
    story.append(Spacer(1, 2 * mm))
    story.append(Paragraph(
        "Phiên bản 1 sử dụng <b>luật tất định, có thể giải thích</b> — minh bạch, kiểm "
        "thử và kiểm toán được. Ví dụ:", LEAD))
    story.append(Paragraph(
        "NẾU điểm Giao tiếp (CO) &lt; 50&nbsp;&nbsp;VÀ&nbsp;&nbsp;nhóm lớp 4–6&nbsp;&nbsp;"
        "THEN&nbsp; đề xuất bài học A, bài học B, nhiệm vụ C.",
        ParagraphStyle("rule", parent=BODY, fontName=FONT, textColor=INDIGO_DARK,
                       backColor=INDIGO_SOFT, borderPadding=6, leading=15)))
    story.append(Spacer(1, 2 * mm))
    story.append(Paragraph(
        "Khuyến nghị dẫn thẳng vào Lộ trình học: mỗi học sinh nhận chuỗi bài học và "
        "nhiệm vụ khác nhau theo “vùng cần phát triển” của hồ sơ năng lực.", BODY))
    story.append(Spacer(1, 4 * mm))
    story.append(shot("explore.png", "Hình 4. Khám phá — đề xuất bài học / luyện ở nhà / đánh giá 360° dựa trên hồ sơ năng lực."))

    # ---------------- 5. LEARNING PATH ----------------
    story.append(Spacer(1, 5 * mm))
    story.append(Paragraph("5. Learning Path — Lộ trình học kiểu Duolingo", H1))
    story.append(Spacer(1, 2 * mm))
    story.append(feature_table([
        ("Nút &amp; đơn vị học", "Lộ trình hiển thị dạng chuỗi nút (nodes) theo module, "
         "trạng thái khóa / mở khóa rõ ràng."),
        ("Phiên học ngắn", "Mỗi bài học thiết kế cho phiên học ngắn, phản hồi tiến độ "
         "tức thì, vi tương tác (micro-interactions) tạo động lực."),
        ("Bảng xếp hạng lớp", "So sánh với mức trung bình lớp được ẩn danh "
         "(benchmark) — bảo mật dữ liệu học sinh."),
    ], [42 * mm, 126 * mm], ("Tính năng", "Mô tả")))
    story.append(Spacer(1, 4 * mm))
    story.append(shot("learn.png", "Hình 5. Lộ trình học kiểu Duolingo — chuỗi nút bài học, đơn vị khóa/mở, cột streak & cấp độ."))

    # ---------------- 6. GAMIFICATION ----------------
    story.append(Spacer(1, 5 * mm))
    story.append(Paragraph("6. Gamification — XP, Streak, Nhiệm vụ, Huy hiệu", H1))
    story.append(Spacer(1, 2 * mm))
    story.append(feature_table([
        ("Sổ cái XP (ledger)", "Mỗi hoạt động cộng XP là một giao dịch ghi sổ: "
         "+20 hoàn thành bài học, +10 streak ngày, +30 nhiệm vụ, +50 đánh giá — "
         "minh bạch, không cộng trừ tùy tiện."),
        ("Streak &amp; đóng băng streak", "Chuỗi ngày học liên tiếp; power-up “đóng băng” "
         "giữ streak khi nghỉ 1 ngày."),
        ("Nhiệm vụ (Missions)", "Thử thách định hướng kỹ năng, hoàn thành nhận XP "
         "và huy hiệu."),
        ("Thành tích &amp; huy hiệu", "Bộ huy hiệu theo mốc năng lực và hành vi học tập; "
         "mức cấp độ (level) tính từ tổng XP."),
    ], [46 * mm, 122 * mm], ("Tính năng", "Mô tả")))
    story.append(Spacer(1, 2 * mm))
    story.append(Paragraph(
        "Nguyên tắc đo lường: <b>XP không bao giờ ảnh hưởng đến điểm năng lực</b> — "
        "game hóa chỉ tạo động lực, không làm sai lệch kết quả đánh giá.",
        ParagraphStyle("note2", parent=BODY, textColor=AMBER)))
    story.append(Spacer(1, 4 * mm))
    story.append(shot("missions.png", "Hình 6. Nhiệm vụ — mission hàng ngày & hàng tuần với thanh tiến độ và trạng thái nhận thưởng."))
    story.append(Spacer(1, 4 * mm))
    story.append(shot("achievements.png", "Hình 7. Thành tích — huy hiệu đã mở khóa, cấp độ, tổng XP và dòng thời gian."))

    # ---------------- 7. PARENT PORTAL ----------------
    story.append(Spacer(1, 5 * mm))
    story.append(Paragraph("7. Cổng Phụ huynh — Đồng hành cùng con", H1))
    story.append(Spacer(1, 2 * mm))
    story.append(feature_table([
        ("Tổng quan", "Toàn cảnh các con đã liên kết tài khoản, tiến độ và điểm mạnh nổi bật."),
        ("Năng lực", "Biểu đồ xu hướng năng lực theo thời gian (longitudinal), "
         "so sánh với mặt bằng lớp (ẩn danh)."),
        ("Báo cáo", "Báo cáo cấu trúc: điểm mạnh, vùng cần phát triển, khuyến nghị, "
         "kế hoạch học tập — xuất PDF, xem trên web, gửi email."),
        ("Hoạt động", "Nhật ký hoạt động: bài học, nhiệm vụ, phiên đánh giá, XP kiếm được."),
    ], [30 * mm, 138 * mm], ("Trang", "Mô tả")))
    story.append(Spacer(1, 4 * mm))
    story.append(shot("parent_overview.png", "Hình 8. Cổng Phụ huynh — Tổng quan: hồ sơ năng lực của con và các chỉ số chính."))
    story.append(Spacer(1, 4 * mm))
    story.append(shot("parent_skills.png", "Hình 9. Năng lực — biểu đồ xu hướng dọc (longitudinal) 6 năng lực theo tháng, kèm mặt bằng lớp ẩn danh."))
    story.append(Spacer(1, 4 * mm))
    story.append(shot("parent_reports.png", "Hình 10. Báo cáo — chuyên biến tăng trưởng so với kỳ trước và xuất CSV."))
    story.append(Spacer(1, 4 * mm))
    story.append(shot("parent_activities.png", "Hình 11. Hoạt động — nhật ký bài học, nhiệm vụ, phiên đánh giá và XP của con."))

    # ---------------- 8. SECURITY / TECH ----------------
    story.append(Spacer(1, 5 * mm))
    story.append(Paragraph("8. Nền tảng kỹ thuật &amp; An toàn dữ liệu", H1))
    story.append(Spacer(1, 2 * mm))
    story.append(Paragraph(
        "Dữ liệu học sinh là dữ liệu nhạy cảm. Mọi endpoint đều thực thi <b>kiểm soát "
        "truy cập phía server</b> — không tin tưởng phân quyền ở giao diện.", LEAD))
    story.append(bullet(
        "<b>Phân quyền máy chủ:</b> học sinh chỉ xem dữ liệu của mình; phụ huynh chỉ "
        "thấy con đã liên kết; giáo viên chỉ thấy lớp được phân công."))
    story.append(bullet(
        "<b>Phạm vi đa trường (school scope):</b> đổi ID không thể truy cập học sinh "
        "của trường khác."))
    story.append(bullet(
        "<b>Kiểm soát cha–con:</b> quan hệ phụ huynh và học sinh được xác minh phía server."))
    story.append(bullet(
        "<b>Lớp học ẩn danh:</b> số liệu tổng hợp lớp tuân thủ ngưỡng k-anonymity, "
        "không lộ điểm cá nhân."))
    story.append(bullet(
        "<b>Dữ liệu dài hạn:</b> 15 bảng MySQL chuyên dụng cho giao dịch "
        "(điểm, snapshots, XP ledger, sự kiện) — lịch sử không bị ghi đè."))
    story.append(bullet(
        "<b>Đầu vào/đầu ra:</b> zod validation phía web, sanitization phía REST, "
        "lỗi trả về theo định dạng thống nhất không lộ stack trace."))

    # ---------------- 9. ROADMAP ----------------
    story.append(Spacer(1, 5 * mm))
    story.append(Paragraph("9. Lộ trình phát triển", H1))
    story.append(Spacer(1, 2 * mm))
    story.append(Paragraph(
        "Ưu tiên xây dựng theo thứ tự: <b>Assessment Engine → Skills Profile → "
        "Parent Report → Recommendation Engine → Learning Path → Gamification → "
        "Content Library → AI</b>.", LEAD))
    story.append(bullet("Đánh giá thích ứng (adaptive/IRT) — giữ nguyên kiến trúc hiện tại."))
    story.append(bullet("AI hỗ trợ diễn giải báo cáo cho phụ huynh — điểm đo vẫn là "
                        "dữ liệu tất định, AI không ghi kết quả đo lường."))
    story.append(bullet("Ứng dụng di động và dashboard Nhà trường/Giáo viên tái sử dụng "
                        "chính REST API này."))
    story.append(Spacer(1, 6 * mm))
    story.append(Paragraph(
        "— Tài liệu được tổng hợp từ README, docs/architecture.md và "
        "docs/assessment-spec.md của dự án ABA Skills 360 —",
        ParagraphStyle("end", parent=BODY, fontName=FONT_ITALIC,
                       textColor=SLATE_LIGHT, alignment=TA_CENTER)))
    return story


def main():
    doc = BaseDocTemplate(
        OUT_PATH, pagesize=A4,
        leftMargin=18 * mm, rightMargin=18 * mm,
        topMargin=18 * mm, bottomMargin=20 * mm,
        title="ABA Skills 360 — Giới thiệu các tính năng",
        author="ABA Skills 360",
    )
    frame_cover = Frame(0, 0, PAGE_W, PAGE_H, id="cover",
                        leftPadding=25 * mm, rightPadding=25 * mm,
                        topPadding=0, bottomPadding=0)
    frame_body = Frame(18 * mm, 20 * mm, PAGE_W - 36 * mm,
                       PAGE_H - 38 * mm, id="body")
    doc.addPageTemplates([
        PageTemplate(id="Cover", frames=[frame_cover], onPage=draw_cover),
        PageTemplate(id="Body", frames=[frame_body], onPage=draw_page),
    ])

    flowables = build_flowables()
    # After the cover PageBreak, switch to the Body template
    from reportlab.platypus import NextPageTemplate
    flowables.insert(1, NextPageTemplate("Body"))
    doc.build(flowables)
    print("PDF written:", OUT_PATH)


if __name__ == "__main__":
    main()
