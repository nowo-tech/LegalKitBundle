# LegalKitBundle Constitution

## Core Principles

### I. Documented integrator contract
Product behavior lives in `specs/001-baseline/spec.md`, `docs/SPEC-DRIVEN-DEVELOPMENT.md` and the integrator docs (`USAGE.md`, `CONFIGURATION.md`, `ARCHITECTURE.md`, `SECURITY.md`, `TEMPLATES.md`). Demos are illustrative unless promoted in the spec.

### II. Spec-first, test-proven
PHPUnit (unit, integration, functional, page contract, schema adoption) and PHPStan are the mechanical proof. Behavioral changes require tests.

### III. 100% code inventory traceability
Every production unit under `src/` (PHP, Twig, translation YAML, the stylesheet) must appear in `specs/001-baseline/code-inventory.md`. New files require spec updates in the same PR.

### IV. Stable public contract for adopters
Route names `legal_<slug>` and their URLs, the `legal_document` table mapping (columns, lengths, index names) and the extension points (seed providers, token resolvers, sanitiser, catalogue, events, portability) follow SemVer. Old admin route and console names live on as deprecated aliases until 2.0.

### V. Safe HTML by construction
Every write path and every render runs the sanitiser; tokens are resolved before sanitising; titles are never raw. The seed is generic (REQ-CC-011) and carries the markers the placeholder gate reports; legal advice is never written into the bundle.

### VI. Cursor + Spec Kit
GitHub Spec Kit is initialized with **Cursor Agent** (`cursor-agent`). Skills live in `.cursor/skills/speckit-*`.

### VII. Symfony compatibility
Follow the declared PHP/Symfony ranges in `composer.json` and README badges. FrankenPHP worker mode: no per-request state in services unless reset. See `docs/FRANKENPHP-WORKER-AUDIT.md`.

## Governance
Amendments update this file, the baseline spec when principles affect behavior, and `CHANGELOG.md` when consumer-visible.

Versioning (SemVer): **MAJOR** when a principle is removed or redefined incompatibly; **MINOR** when a principle or section is added or materially expanded; **PATCH** for clarifications and factual corrections that do not change intent.

**Version**: 1.0.0 | **Ratified**: 2026-10-18 | **Last Amended**: 2026-10-18
