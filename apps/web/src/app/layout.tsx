import React from 'react';
import type { Metadata } from 'next';
import { Inter } from 'next/font/google';
import '../styles/globals.css';
import { QueryProvider } from '../providers/QueryProvider';
import { MSWProvider } from '../mocks/MSWProvider';
import { Navigation } from '../components/Navigation';

const inter = Inter({ subsets: ['latin', 'vietnamese'] });

export const metadata: Metadata = {
  title: 'ABA Skills 360 - Nền tảng Đánh giá & Phát triển Năng lực',
  description: 'Nền tảng đo lường năng lực và định hướng học tập cá nhân hóa cho học sinh.',
};

export default function RootLayout({
  children,
}: {
  children: React.ReactNode;
}) {
  return (
    <html lang="vi">
      <body className={`${inter.className} min-h-screen pb-24 bg-slate-50 dark:bg-slate-950 text-slate-900 dark:text-slate-100 antialiased selection:bg-emerald-500 selection:text-white`}>
        <MSWProvider>
          <QueryProvider>
            <Navigation />
            <main className="max-w-4xl mx-auto px-4 py-6">{children}</main>
          </QueryProvider>
        </MSWProvider>
      </body>
    </html>
  );
}
