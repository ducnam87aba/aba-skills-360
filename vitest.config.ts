import { defineConfig } from 'vitest/config';

/**
 * Root Vitest config.
 *
 * The monorepo packages compile their `__tests__` to CommonJS under `dist/`
 * when built; those are published build artifacts, not tests. Restrict Vitest to
 * the authored TypeScript sources so a rebuilt package never re-collects (and
 * fails to import) its own compiled output.
 */
export default defineConfig({
  test: {
    environment: 'node',
    include: ['packages/*/src/**/*.{test,spec}.{ts,tsx}', 'apps/*/src/**/*.{test,spec}.{ts,tsx}'],
    exclude: ['**/node_modules/**', '**/dist/**', '**/.next/**'],
  },
});
