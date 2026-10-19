# ABA Skills 360

Hệ thống Nền tảng Đánh giá & Phát triển Năng lực Cá nhân hóa (Assessment Engine, Skills Profile, Parent Report, Recommendation Engine, Learning Path, Gamification).

---

## 1. Cấu trúc dự án (Monorepo)

Tuân thủ kiến trúc đã định nghĩa trong `AGENTS.md` và `docs/`:

```text
aba-skills-360/
├── apps/
│   ├── web/                     # Next.js 14 App (Student & Parent Portals + MSW Mock API)
│   └── wordpress/               # WordPress CMS & Custom Plugin (aba-skills-core)
│       └── wp-content/plugins/aba-skills-core/
├── packages/
│   ├── types/                   # Shared TypeScript domain types
│   ├── validation/              # Zod validation schemas
│   ├── api-client/              # Shared API client cho tất cả frontend calls
│   └── ui/                      # Shared UI components & design system
├── docker/
│   └── mysql-init/              # Script khởi tạo 15 bảng cơ sở dữ liệu custom MySQL
├── docker-compose.yml           # Cấu hình Docker cho WordPress, MySQL 8.0, phpMyAdmin
├── pnpm-workspace.yaml          # Monorepo pnpm workspaces
└── AGENTS.md                    # Quy chuẩn kiến trúc & quy tắc phát triển
```

---

## 2. Công nghệ & Môi trường triển khai

- **Node.js & pnpm**: Đã cài đặt pnpm v12 & Node v22. Quản lý monorepo qua `pnpm-workspace.yaml`.
- **Next.js & TypeScript**: Next.js 14 App Router, Tailwind CSS, TanStack Query, Framer Motion, Recharts.
- **Mock API với MSW (Mock Service Worker)**: Tích hợp đầy đủ tại `apps/web/src/mocks/`. Tự động mock các REST API `/wp-json/aba/v1/` trong môi trường dev giúp đội ngũ frontend phát triển độc lập mà không cần chờ backend WordPress.
- **Docker & Docker Desktop**:
  - `docker-compose.yml` định nghĩa cụm dịch vụ:
    - MySQL 8.0 (Port 3306) + script khởi tạo 15 bảng theo chuẩn `docs/database-schema.md`.
    - WordPress 6.7 (Port 8080) với mount trực tiếp plugin `aba-skills-core`.
    - phpMyAdmin (Port 8081) để quản trị database trực quan.
- **Git & GitHub**: Kho lưu trữ đã liên kết với `https://github.com/ducnam87aba/aba-skills-360.git`.

---

## 3. Hướng dẫn khởi chạy

### A. Khởi chạy Next.js Web App với MSW Mock API

```bash
# 1. Cài đặt các gói phụ thuộc
pnpm install

# 2. Khởi chạy máy chủ phát triển
pnpm dev
```

Truy cập: [http://localhost:3000](http://localhost:3000)
- **Cổng học sinh**: Lộ trình học (Duolingo style), Khám phá bài đánh giá, Nhiệm vụ nhận XP, Thành tích huy hiệu, Hồ sơ năng lực.
- **Cổng phụ huynh**: Tổng quan con em, Biểu đồ xu hướng năng lực theo thời gian (Longitudinal History), Báo cáo & Khuyến nghị, Nhật ký hoạt động.
- **Assessment Runner**: Làm bài trắc nghiệm tình huống chuẩn hóa, tự động tính điểm và lưu Skill Snapshot.

### B. Khởi chạy Docker Desktop (WordPress + MySQL + phpMyAdmin)

1. Mở **Docker Desktop** trên macOS (hoặc cài đặt nếu chưa có: `brew install --cask docker-desktop`).
2. Chạy lệnh:

```bash
# Khởi động các container trong nền
docker compose up -d

# Xem log hoạt động
docker compose logs -f

# Dừng môi trường
docker compose down
```

Các cổng truy cập:
- **WordPress**: `http://localhost:8080`
- **phpMyAdmin**: `http://localhost:8081` (Tài khoản: `aba_user` / `aba_secret_password` hoặc `root` / `root_secret_password`)
- **MySQL Direct**: `localhost:3306`
