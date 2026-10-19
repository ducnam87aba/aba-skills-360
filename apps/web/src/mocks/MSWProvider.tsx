'use client';

import React, { useEffect, useState } from 'react';

export function MSWProvider({ children }: { children: React.ReactNode }) {
  const [mswReady, setMswReady] = useState(false);

  useEffect(() => {
    async function initMocks() {
      // Enable mocks if NEXT_PUBLIC_ENABLE_MOCKS is 'true' or in development mode
      const isMockEnabled =
        process.env.NEXT_PUBLIC_ENABLE_MOCKS === 'true' ||
        process.env.NODE_ENV === 'development';

      if (isMockEnabled && typeof window !== 'undefined') {
        const { worker } = await import('./browser');
        await worker.start({
          onUnhandledRequest: 'bypass',
        });
      }
      setMswReady(true);
    }

    initMocks();
  }, []);

  if (!mswReady) {
    return (
      <div className="flex items-center justify-center min-h-screen bg-slate-900 text-slate-100">
        <div className="flex flex-col items-center gap-3">
          <div className="w-8 h-8 border-4 border-emerald-500 border-t-transparent rounded-full animate-spin" />
          <p className="text-sm font-medium text-slate-400">Khởi tạo ABA Mock API Service Worker...</p>
        </div>
      </div>
    );
  }

  return <>{children}</>;
}
