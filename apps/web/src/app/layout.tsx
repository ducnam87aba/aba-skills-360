import React from 'react';
import type { Metadata } from 'next';
import { Nunito } from 'next/font/google';
import '../styles/globals.css';
import { QueryProvider } from '../providers/QueryProvider';
import { MSWProvider } from '../mocks/MSWProvider';
import { Navigation } from '../components/Navigation';
import { AuthGate } from '../features/auth/AuthGate';

const nunito = Nunito({
  subsets: ['latin', 'vietnamese'],
  weight: ['400', '600', '700', '800', '900'],
  variable: '--font-nunito',
  display: 'swap',
});

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
    <html lang="vi" className={nunito.variable}>
      <body className="font-sans min-h-screen text-slate-800 antialiased selection:bg-brand-200 selection:text-brand-900">
        <MSWProvider>
          <QueryProvider>
            <AuthGate>
              <Navigation />
              <div className="lg:pl-72 pt-16 pb-20 lg:pb-10">
                <main className="mx-auto w-full max-w-6xl px-4 sm:px-6 lg:px-8 py-6 lg:py-8">
                  {children}
                </main>
              </div>
            </AuthGate>
          </QueryProvider>
        </MSWProvider>
      </body>
    </html>
  );
}
