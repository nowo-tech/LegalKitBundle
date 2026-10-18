# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Table of contents

- [[Unreleased]](#unreleased)
- [[1.0.1] - 2026-10-18](#101---2026-10-18)
  - [Fixed](#fixed)
- [[1.0.0] - 2026-10-18](#100---2026-10-18)
  - [Added](#added)

## [Unreleased]

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
