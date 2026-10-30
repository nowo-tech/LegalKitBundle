# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Table of contents

- [[Unreleased]](#unreleased)
- [[1.3.1] - 2026-10-30](#131---2026-10-30)
  - [Fixed](#fixed)
- [[1.3.0] - 2026-10-28](#130---2026-10-28)
  - [Security](#security)
- [[1.2.0] - 2026-10-28](#120---2026-10-28)
  - [Security](#security-1)
  - [Changed](#changed)
- [[1.1.1] - 2026-10-22](#111---2026-10-22)
  - [Changed](#changed-1)
- [[1.1.0] - 2026-10-20](#110---2026-10-20)
  - [Added](#added)
  - [Changed](#changed-2)
  - [Fixed](#fixed-1)
  - [Security](#security-2)
- [[1.0.1] - 2026-10-18](#101---2026-10-18)
  - [Fixed](#fixed-2)
- [[1.0.0] - 2026-10-18](#100---2026-10-18)
  - [Added](#added-1)

## [Unreleased]

## [1.3.1] - 2026-10-30

Bundle routes are opt-in again: controllers are no longer autoconfigured, so a host's routing.controllers import does not expose them (REQ-SF-004 rule 7). See [UPGRADING](UPGRADING.md#to-131).

### Fixed

- **Admin routes registered without the route import:** on Symfony >= 7.3 the `#[Route]` attributes (plus
  `#[AsController]`) on the autoconfigured `LegalDocumentAdminController` gave it the `routing.controller` tag, so the
  host's default `controllers: { resource: routing.controllers }` registered `nowo_legal_kit_admin_index`,
  `nowo_legal_kit_admin_edit` and `nowo_legal_kit_admin_reset` even when the host never imported the
  `nowo_legal_kit` route type, and regardless of `web_ui.enabled` (access was still enforced by
  `AdminAccessSubscriber`). `LegalDocumentAdminController` and `LegalPageController` are no longer autoconfigured
  and no longer carry `#[AsController]` (explicit `controller.service_arguments` tag); the routes exist only through
  the documented import (REQ-SF-004 rule 7). Regression test with a kernel that only loads `routing.controllers`.

## [1.3.0] - 2026-10-28

Security: allow_unauthenticated is refused in production (prod or non-debug kernels). See [UPGRADING](UPGRADING.md#to-130).

### Security

- **`allow_unauthenticated` refused in production.** The switch opens the legal documents admin to anonymous users; the
  container now refuses to compile with it in the `prod` environment or in any non-debug kernel outside `test*`
  environments (as ContactFormBundle).

## [1.2.0] - 2026-10-28

Security: admin CSRF fails closed; the opt-in shared cache skips visitors with a session. See [UPGRADING](UPGRADING.md#to-120).

### Security

- **Admin CSRF fails closed.** With the admin UI enabled the container refuses to compile without
  `security.csrf.token_manager` + `form.type_extension.csrf`, or with `framework.form.csrf_protection: false`
  (`SecurityPass`): save / restore would otherwise accept cross-site posts.
- **Opt-in shared cache never stores a personalised page.** With `public.cache.shared_max_age`, a page was made
  `public` and Symfony's session auto-private was suppressed even for visitors with a session (layout flashes, user
  menu, logout CSRF token). Visitors that send a session cookie now get a `private` response.

### Changed

- **Developer tooling (REQ-CS-001):** `make validate-phpdoc` is read-only and now part of `make release-check`: it fails when a class or public method in `src/` has no PHPDoc block (the former `@return` check asked for tags that `cs-fix` (`no_superfluous_phpdoc_tags`) removes; `make fix-phpdoc` is gone). Added the 22 missing docblocks.

## [1.1.1] - 2026-10-22

Wave-2 REQ compliance: logged import and seed commands, pinned CI matrix, demo release-check refreshes the bundle. See [UPGRADING](UPGRADING.md#to-111).

### Changed

- `nowo:legal-kit:import` and `nowo:legal-kit:seed` log their start / outcome with counts only through the autowired PSR-3 logger; runtime dependency `psr/log` (REQ-OBS-001).
- CI: Symfony 8.0 cell dropped (end of maintenance, advisories) and every `symfony/*` package pinned to the cell minor (REQ-SF-002).
- Demo `release-check` refreshes the bundle (`update-bundle`) before its tests (REQ-DEMO-007).
- Docs: why the seed uses its own `NowoLegalKitSeed` domain (REQ-I18N-003).
- `.scripts/check-no-cursor-coauthor.sh` accepts git worktrees (REQ-GIT-001).

## [1.1.0] - 2026-10-20

Render-time sanitising of the legal body with the HTML5 parser (REQ-PBK-009, Twig filter nowo_legal_kit_sanitize_html) and translated cookie category labels with an override block. See [UPGRADING](UPGRADING.md#to-110).

### Added

- Twig filter `nowo_legal_kit_sanitize_html` (safe HTML through `LegalHtmlSanitizerInterface`); the public page prints
  the body with it instead of a bare `|raw`.
- Block `nowo_legal_kit_cookie_category_label` in `_cookie_inventory.html.twig` (variables `cookie`,
  `category_label`) so hosts override only the category label.
- `NowoLegalKitBundle` translations `cookies.category.<key>` for the CookieConsent categories (`required`,
  `functionality`, `preferences`, `analytics`, `marketing`, `tracking`) in en, es, de, nl, fr, it, pt.

### Changed

- `DomAllowlistSanitizer` parses with the HTML5 algorithm (`Dom\HTMLDocument` fragment parsing, HTML5 serialiser) on
  PHP 8.4+, so the allowlist sees what the browser sees (REQ-PBK-009); foreign SVG / MathML content is removed. PHP
  8.3 keeps libxml's parser. New constructor argument `html5` (null: automatic). Empty attributes are now written as
  `attr=""` on PHP 8.4+.
- `.php-cs-fixer.dist.php`: `new ParallelConfig(2)` instead of `ParallelConfigFactory::detect()` (REQ-CS-001).

### Fixed

- The cookie inventory table (`%cookie_inventory%`) printed the raw CookieConsent category key (`required`,
  `analytics`…) instead of a translated label; unknown keys still print as they are.

### Security

- Defence in depth for stored legal HTML (REQ-PBK-009): the body is sanitised again at render time in the template
  (on top of the resolver pass), so markup written by SQL, restores or a custom resolver never reaches the page
  unsanitised. Corpus extended with HTML5 named entities (`java&colon;script:`), SVG animation handlers, style
  injection and raw-text elements.

## [1.0.1] - 2026-10-18

### Fixed

- Test suite on Symfony 7.4: the test kernel registers a PSR-20 `clock` service when FrameworkBundle does not (7.4
  only registers it when `symfony/clock` is a root requirement; AuditKit autowires it). No change in the bundle code;
  the GitHub CI matrix (PHP 8.3–8.5 × Symfony 7.4 / 8.0 / 8.1 / 8.2) is green.

## [1.0.0] - 2026-10-18

First release: the legal pages feature extracted from Beacon (spec 155) and the five applications that copied it.

### Added

- Page catalogue (`pages`: notice, privacy, terms, cookies + extra slugs; path, enabled, nav, seed template) and
  locales from a list or a parameter, default locale first.
- Route loader `type: nowo_legal_kit`: `legal_<slug>` (`/{_locale}/legal/<path>`), `unprefixed` style with
  `legal_<slug>_unlocalized` twins and 301 from `/{default}/…`, `prefixed` style with a 302 bare redirect.
- Entity `LegalDocument` mapping the existing `legal_document` table (AuditKit `TimestampableTrait` +
  `BlameableTrait`, explicit index names), repository, `doctrine.table_prefix` listener (tables and index names).
- Resolution stored row → seed → `seed.fallback_locale`, `_content_locale` request attribute, `Cache-Control: private`
  (shared cache opt-in), `Last-Modified`.
- Generic GDPR / LSSI seed (`NowoLegalKitSeed`) in en, es, de, nl, fr, it, pt; `TwigSeedProvider`;
  `LegalSeedProviderInterface`; `seed.materialize` + `nowo:legal-kit:seed --missing-only`.
- Tokens at render time (`LegalTokenResolverInterface`): `%brand%`, `%last_updated%`, `%cookie_inventory%`.
- DOM allowlist sanitiser (podologiapriego-web allowlist + `code`, `pre`, `mark`, `sup`, `sub`), URL policy,
  optional CKEditor 5 strict first pass and OutboundUrlGuard; applied on every write and render; XSS corpus tests.
- Admin pages on the UiKit 2.4 page contract: page × locale matrix, editor with locale tabs (CKEditor 5 or textarea,
  FormKit profile `legal_kit`), restore through the confirm contract (POST + CSRF); REQ-UI-002 security keys;
  deprecated `admin_legal_*` route aliases.
- Public page (unique page on the guest layout), cross navigation, footer partial with "Manage cookies".
- Placeholder gate: `LegalPlaceholderScanner`, `nowo:legal-kit:check-placeholders` (table / JSON, exit codes), Ops
  banner partial, `nowo_legal_kit_placeholder_hits()`.
- Portability: `LegalDocumentPortability` (export / normalize / apply without implicit flush),
  `nowo:legal-kit:adapt-seed`, `nowo:legal-kit:export`, `nowo:legal-kit:import`; legacy aliases
  `app:legal:check-placeholders` and `app:ops-seed:adapt-legal`.
- Integrations: CookieConsent (legal routes prepended to `disabled_routes` / `render_routes`, `privacy_route`
  fallback with CookieConsent 1.17+), SeoKit (head bridge + sitemap provider), AuditKit action log,
  `nowo:legal-kit:doctor`.
- Events `LegalPageRenderEvent`, `LegalDocumentSavedEvent`, `LegalDocumentRestoredEvent`,
  `LegalDocumentsImportedEvent`.
- Demo `demo/symfony8` (FrankenPHP, MySQL 8.4, CookieConsent, CKEditor 5), Playwright e2e and WebP screenshots;
  schema adoption tests (SQLite in the bundle, MySQL with Beacon's DDL in the demo); Spec Kit baseline
  `specs/001-baseline`.
