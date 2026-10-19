Implement Sprint 0 — Foundation for ABA Skills 360.

Before making any changes:

1. Read `/AGENTS.md`.
2. Read `/docs/architecture.md`.
3. Read `/docs/coding-rules.md`.
4. Inspect the current repository.
5. Do not change the agreed architecture.

## Goal

Create the technical foundation for ABA Skills 360.

## Required repository structure

```text
apps/
  web/
  wordpress/

packages/
  ui/
  api-client/
  types/
  validation/

docs/
```

## Web application

Create:

```text
apps/web
```

using:

- Next.js
- TypeScript
- App Router
- Tailwind CSS
- shadcn/ui

Add the minimum base structure:

```text
src/
  app/
  components/
  features/
  hooks/
  lib/
  services/
  stores/
  config/
```

Do not implement product features yet.

## Shared packages

Create:

```text
packages/ui
packages/api-client
packages/types
packages/validation
```

Configure TypeScript imports properly.

## WordPress

Prepare:

```text
apps/wordpress
```

for the ABA WordPress backend.

Do not implement Assessment Engine yet.

## Tooling

Configure:

- pnpm workspace
- ESLint
- Prettier
- TypeScript
- environment examples
- useful npm scripts

The root commands should support at least:

```text
pnpm dev
pnpm build
pnpm lint
pnpm typecheck
pnpm test
```

## Constraints

Do not:

- add Firebase
- add GraphQL
- add a different backend
- implement LMS features
- implement AI features
- implement assessment features yet
- modify the architecture

## Definition of Done

Before finishing:

1. Install dependencies.
2. Run lint.
3. Run TypeScript typecheck.
4. Run build.
5. Fix errors caused by your changes.

Then provide a concise report containing:

- files created
- files modified
- commands executed
- test/build result
- unresolved issues
- recommended next task

Do not proceed to Sprint 1.
