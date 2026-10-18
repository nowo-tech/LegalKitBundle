# Spec-driven development

In this repository, **spec-driven development** has three layers that stay in sync:

1. **GitHub Spec Kit baseline** — [`specs/001-baseline/`](../specs/001-baseline/) ([`spec.md`](../specs/001-baseline/spec.md), [`code-inventory.md`](../specs/001-baseline/code-inventory.md)), initialized with [GitHub Spec Kit](https://github.com/github/spec-kit) (`.specify/`, **Cursor Agent** skills in `.cursor/skills/speckit-*`). The inventory maps **100%** of production code in `src/`. **How to install, initialize, and use Spec Kit:** [`SPEC-KIT.md`](SPEC-KIT.md).
2. **Product behavior** — what **LegalKitBundle** guarantees to applications that integrate it (see [`USAGE.md`](USAGE.md), [`CONFIGURATION.md`](CONFIGURATION.md), [`INSTALLATION.md`](INSTALLATION.md)). **PHPUnit** and **PHPStan** enforce contracts in CI where applicable.
3. **Traceability anchors** — stable **`REQ-*`** identifiers in Makefiles and demos (when present) so changes to scripts, ports, and demo workflows stay discoverable from issues and PRs.

There is no separate executable spec language (for example Gherkin); Spec Kit specs, tests, and static analysis are the mechanical proof alongside this document.

---

## Table of contents

- [User stories](#user-stories)
- [Bundle functional scope](#bundle-functional-scope)
- [Validating the functional spec](#validating-the-functional-spec)
- [Requirement identifiers (`REQ-*`)](#requirement-identifiers-req-)
- [Suggested workflow for contributors](#suggested-workflow-for-contributors)
- [Relationship to Engram / external checklists](#relationship-to-engram--external-checklists)
- [GitHub Spec Kit (summary)](#github-spec-kit-summary)
- [See also](#see-also)

## User stories

The IDs match the user scenarios of the baseline spec ([`specs/001-baseline/spec.md`](../specs/001-baseline/spec.md#user-scenarios)), where each has Given/When/Then acceptance criteria. They come from the Beacon spec 155 that extracted the kit.

| ID | Story | Integrator docs |
| --- | --- | --- |
| US-01 | **As an** operator (ROLE_ADMIN), **I want** a page × locale matrix and an editor per locale **so that** I publish my own legal texts without forking the app. | [USAGE — Admin pages](USAGE.md#admin-pages) |
| US-02 | **As an** operator, **I want** to restore the built-in text of one page in one locale **so that** a bad edit is one confirm away from undone. | [USAGE — Admin pages](USAGE.md#admin-pages) |
| US-03 | **As a** visitor, **I want** notice, privacy, terms, cookies and "Manage cookies" in the footer, in my locale, on stable URLs **so that** I can read them before using the service. | [USAGE — Public pages](USAGE.md#public-pages) · [Footer](USAGE.md#footer-navigation) |
| US-04 | **As** ops, **I want** a command that fails while placeholders remain **so that** a deploy never ships the generic scaffold. | [USAGE — Placeholder gate](USAGE.md#placeholder-gate) |
| US-05 | **As** devops, **I want** to build and apply `legal_documents[]` for a fleet **so that** every instance publishes its identity reproducibly. | [USAGE — Ops-seed portability](USAGE.md#ops-seed-portability) |
| US-06 | **As a** developer of a product with a real company identity, **I want** seed providers and tokens **so that** I customise the text without touching bundle templates. | [USAGE — Seed text](USAGE.md#seed-text) · [Tokens](USAGE.md#tokens) |
| US-07 | **As a** developer replacing a legal copy, **I want** the bundle to map my existing table and keep route names **so that** the upgrade needs no data migration. | [INSTALLATION — Adopting](INSTALLATION.md#adopting-the-bundle-from-a-legal-copy) |

**Out of scope (v1):** versioned documents, publication workflow, acceptance tracking, per-tenant pages, localised slugs, writing legal content.

---

## Bundle functional scope

**Goal:** operator-editable legal pages (notice, privacy, terms, cookies + extra slugs) per locale, with a generic seed, a placeholder gate, ops-seed portability, one strict sanitiser and admin pages on the UiKit page contract.

**Current state:** `1.0.x` — implemented and covered by the baseline spec [`specs/001-baseline/spec.md`](../specs/001-baseline/spec.md) (user scenarios US-01…US-07, `FR-*` by domain) and its 100% code inventory. See [`ARCHITECTURE.md`](ARCHITECTURE.md).

**In scope**

- Page catalogue, route loader (unprefixed / prefixed default locale), locales from configuration.
- `legal_document` entity (AuditKit timestamps + blame), repository, table prefix.
- Seed providers (Twig scaffold in 7 locales), tokens, DOM allowlist sanitiser, resolver with fallback locale.
- Admin index / editor / restore (REQ-UI-001/002/003), public unique page, footer partial, Ops banner.
- Placeholder scanner + command, portability service + commands, doctor.
- Soft integrations: CookieConsent, SeoKit, CKEditor 5, OutboundUrlGuard, AuditKit action log.

**Explicit non-goals**

- Legal advice or counsel review (REQ-CC-010 stays the operator's duty).
- **`demo/`** UI: illustrative unless explicitly documented as stable API.

---

## Validating the functional spec

- Run **`make qa`** or **`make release-check`** as documented in [`CONTRIBUTING.md`](CONTRIBUTING.md).
- Run **PHPUnit** locally and in CI for code changes; the page contract test and the sanitiser corpus guard markup and HTML handling.
- New or changed behaviour adds or adjusts tests under `tests/`.

---

## Requirement identifiers (`REQ-*`)

| ID | Where | What it marks |
| --- | --- | --- |
| `REQ-DEMO-005` | `demo/symfony8/Makefile` | Canonical `make up`: `Waiting…`, `Installing…`, `Demo started at:` from `PORT`. |
| `REQ-DEMO-007` | `demo/Makefile`, demo Makefiles | `update-bundle` before demo tests in `release-check`. |
| `REQ-MAKE-001` | Root `Makefile` | Docker-driven development workflow for the bundle. |
| `REQ-MAKE-008` | Root `Makefile` | `update-deps` via shared `.scripts/`. |
| `REQ-MAKE-002` | Root `Makefile` | `release-check` runs its steps sequentially in spec order. |
| `REQ-CS-008` | Root `Makefile` | `make igor` worker-state audit (FrankenPHP). |
| `REQ-TWIG-005` | Root `Makefile` | `check-no-raw-html-form`: no raw `<form>` / `<input>` / `<textarea>` / `<select>` in Twig. |
| `REQ-GIT-001` | Root `Makefile` | `setup-hooks`, `check-no-cursor-coauthor`, `strip-cursor-coauthor-from-history`. |
| `REQ-TEST-011` | Root / demo `Makefile` | `demo-smoke`: the demo boots and answers HTTP 200. |
| `REQ-DEMO-011` | `demo/symfony8/docker-compose.yml`, `Makefile` `setup` | MySQL 8.4 service (no published port); schema + demo user. |
| `REQ-DEMO-013` | `demo/symfony8/Makefile` | `test-e2e` (Playwright) and `demo-screenshots` (WebP under `docs/images/demo/`). |

When you change scripted behavior, update the existing `REQ-*` comment or add a new ID and document it here.

---

## Suggested workflow for contributors

1. Clarify behavior in an issue or draft PR (product + Makefiles/demos).
2. Implement with tests and static analysis.
3. Anchor scripts and demos when dev UX changes.
4. Ship integrator docs when configuration or public behavior changes.
5. **Keep Spec Kit artifacts in sync** when production code under `src/` changes:
   - Update [`specs/001-baseline/spec.md`](../specs/001-baseline/spec.md) and [`code-inventory.md`](../specs/001-baseline/code-inventory.md).
   - Follow the maintainer checklist in [`SPEC-KIT.md`](SPEC-KIT.md).
   - For **new features**, use Cursor Agent skills (`/speckit-specify`, `/speckit-plan`, `/speckit-tasks`) as documented in SPEC-KIT.

---

## Relationship to Engram / external checklists

[`ENGRAM.md`](ENGRAM.md) covers Nowo-wide documentation checklist items. This document ties together **what the package does**, **how we verify it**, and **local `REQ-*` habits**. Both coexist: Engram for org-level compliance, this file for product + traceability.

---

## GitHub Spec Kit (summary)

This repository uses [GitHub Spec Kit](https://github.com/github/spec-kit) with **Cursor Agent** (`cursor-agent` integration).

| Artifact | Path |
| --- | --- |
| **Operator manual** (install, init, usage) | [`SPEC-KIT.md`](SPEC-KIT.md) |
| Baseline spec | [`specs/001-baseline/spec.md`](../specs/001-baseline/spec.md) |
| Code inventory (100%) | [`specs/001-baseline/code-inventory.md`](../specs/001-baseline/code-inventory.md) |
| Constitution | [`.specify/memory/constitution.md`](../.specify/memory/constitution.md) |
| Cursor Agent skills | [`.cursor/skills/`](../.cursor/skills/) (`speckit-*`) |

**Quick start (maintainers):**

```bash
# Install Specify CLI (once per machine) — see SPEC-KIT.md
specify init --here --force --integration cursor-agent --script sh
specify integration list   # Cursor → installed (default)
```

In Cursor Agent, start a new feature with `/speckit-specify <description>`. For day-to-day tooling details, skills reference, folder layout, and troubleshooting, read **[`SPEC-KIT.md`](SPEC-KIT.md)**.

---

## See also

- [`SPEC-KIT.md`](SPEC-KIT.md) — GitHub Spec Kit manual (install, structure, usage)
- [`specs/001-baseline/spec.md`](../specs/001-baseline/spec.md)
- [`USAGE.md`](USAGE.md)
- [`CONFIGURATION.md`](CONFIGURATION.md)
- [`INSTALLATION.md`](INSTALLATION.md)
- [`CONTRIBUTING.md`](CONTRIBUTING.md)
- [`RELEASE.md`](RELEASE.md)
- [`DEMO-FRANKENPHP.md`](DEMO-FRANKENPHP.md)
