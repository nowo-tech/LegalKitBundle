# Feature specification: Legal Kit Bundle (baseline)

**Package**: `nowo-tech/legal-kit-bundle`
**Status**: Active (1.0.0)
**Last reviewed**: 2026-10-18
**Origin**: Symfony Beacon spec `155-legal-kit-bundle` (approved 2026-10-18, Q1–Q12 recommended answers)
**Inventory**: [`code-inventory.md`](code-inventory.md) — 100% of the production units under `src/`.

## Summary

Operator-editable legal pages for Symfony, extracted from Beacon's `src/Shared/Legal` (spec 115) and the five
applications that copied it (english-path, sms-bridge, open-agendesk-v2, podologiapriego-web, nowo-tech-web). The
bundle gives integrators:

- a **catalogue** of pages (notice, privacy, terms, cookies + extra slugs) on stable routes `legal_<slug>`, with the
  default locale unprefixed (twins `legal_<slug>_unlocalized`, 301) or prefixed (bare 302);
- an **entity** mapping the existing `legal_document` table as is (AuditKit timestamps + blame, explicit index names);
- a **generic seed** (`NowoLegalKitSeed`, 7 locales), seed providers, a fallback locale and a materialise mode;
- **tokens** resolved at render time, one **DOM allowlist sanitiser** on every write and render;
- **admin pages** on the UiKit 2.4 page contract and a **unique public page** on the guest layout;
- a **placeholder gate** (scanner, command, Ops banner), **ops-seed portability** and console tools;
- soft **integrations** with CookieConsent, SeoKit, CKEditor 5, OutboundUrlGuard and the AuditKit action log.

## User scenarios

### US-01 — Operator edits a page (P1)

**Given** a user granted `security.access_roles` (default `ROLE_ADMIN`),
**when** they open `/admin/legal`, pick a page × locale, edit the text (seed prefilled, tokens kept) and save,
**then** the sanitised body is stored for that page and locale only, the public page shows it at once and the other
locales keep the seed; an empty body after sanitising is refused.

### US-02 — Operator restores the built-in text (P1)

**Given** a stored copy, **when** the operator confirms "Restore built-in text" (UiKit confirm, POST + CSRF),
**then** the row is deleted and the seed is published again.

### US-03 — Visitor reads the legal pages (P1)

**Given** any visitor, **when** they open `/legal/<path>` (default locale) or `/{_locale}/legal/<path>`,
**then** they read the stored copy or the seed in that locale (or the fallback locale), with the footer links to every
page and "Manage cookies"; `/{default}/legal/<path>` answers 301 to the bare URL (unprefixed style).

### US-04 — Deploy gate (P1)

**Given** published pages still containing a seed marker or an unresolved `%token%`,
**when** ops run `nowo:legal-kit:check-placeholders`, **then** it exits 1 and lists the hits (table or JSON); 0 when
clean, always noting that counsel review is still required.

### US-05 — Fleet portability (P2)

**Given** a replacements map, **when** devops run `nowo:legal-kit:adapt-seed`, ship `legal_documents[]` and the host
ops-seed applies it through `LegalDocumentPortability`, **then** every entry is validated before any write and the rows
are upserted in the host transaction.

### US-06 — Product with a real identity (P2)

**Given** a host seed provider and token resolver, **when** pages render, **then** the host text and token values are
used without touching bundle templates; the editor keeps the raw tokens.

### US-07 — Adoption without migration (P1)

**Given** an application with Beacon's `legal_document` table, **when** it replaces its copy by the bundle,
**then** `doctrine:schema:update --dump-sql` is empty for the table and route names / URLs are unchanged (old admin and
console names as deprecated aliases).

## Functional requirements

### Catalogue, routes, locales

- **FR-001** `pages` ordered map slug → {path, enabled, nav, seed_template}; slugs `[a-z0-9-]{1,32}`;
  `LegalPageCatalogInterface` (`slugs`, `pages`, `has`, `page`).
- **FR-002** Route loader `type: nowo_legal_kit`: `legal_<slug>` GET `/{_locale}<path_prefix>/<path>`, `_locale`
  requirement from the locales.
- **FR-003** `default_locale_style`: `unprefixed` (twin `legal_<slug>_unlocalized`, 301 from the prefixed default
  locale, query kept) or `prefixed` (`legal_<slug>_bare`, 302).
- **FR-004** `locales` from a list or parameter / env value; default locale first everywhere.
- **FR-005** `nowo:legal-kit:doctor` warns when a public legal URL is not anonymous-readable.

### Storage and publication

- **FR-010** Entity `LegalDocument` (slug 32, locale 8, title 180, body TEXT, AuditKit timestamp + blame traits),
  unique `uniq_legal_document_slug_locale`, indexes `idx_legal_document_created_by|updated_by`; repository.
- **FR-011** Resolution stored row with text → seed → seed of `seed.fallback_locale`; `ResolvedLegalDocument`;
  request attribute `_content_locale`.
- **FR-012** Seed providers declare `supports(slug, locale)`; `legal_kit_content_locale()` exposes it.
- **FR-013** `seed.materialize` + `nowo:legal-kit:seed --missing-only` (never overwrites).

### Seed, tokens, sanitiser

- **FR-020** `LegalSeedProviderInterface`, tagged, priority; first supporting provider wins.
- **FR-021** `TwigSeedProvider`: seed template rendered with the translator switched to the target locale (restored in
  `finally`); generic scaffold in the 7 locales.
- **FR-022** Hosts customise the seed by keys, templates or providers.
- **FR-023** `LegalTokenResolverInterface` chain at render time; `%brand%`, `%last_updated%`, `%cookie_inventory%`;
  text values escaped; unresolved tokens left and reported.
- **FR-030** `LegalHtmlSanitizerInterface` on save, materialisation, import and every render (tokens first).
- **FR-031** DOM allowlist (+ code, pre, mark, sup, sub), URL policy, optional CKEditor strict first pass and
  OutboundUrlGuard, `rel="noopener noreferrer"` on `target="_blank"`.
- **FR-032** Empty body after sanitising refused on save and import.

### Admin (REQ-UI-001/002/003)

- **FR-040** `web_ui.*` and `security.*` keys; access checker; build fails without SecurityBundle unless
  `allow_unauthenticated`.
- **FR-041** Index on the list scaffold: page × locale, Built-in / Custom, last update + editor, placeholder badge.
- **FR-042** Editor on the form scaffold: locale tabs, title + body, View public page, FormKit profile `legal_kit`.
- **FR-043** Body widget CKEditor 5 (`auto` when installed) or textarea; editor script only on the edit page.
- **FR-044** Restore: confirm contract, POST, CSRF id `nowo_legal_kit_reset_<slug>_<locale>`.
- **FR-045** Deprecated aliases `admin_legal_index|edit|reset`.

### Public pages and navigation

- **FR-050** Unique public page on the guest layout (`public.layout_template`), cross navigation, cache headers
  (private by default, shared opt-in, `Last-Modified`).
- **FR-051** `nowo_legal_kit_links()` + `_footer_nav.html.twig` (pages + Manage cookies).
- **FR-052** `LegalPageRenderEvent`; SeoKit head bridge (title, canonical, hreflang).
- **FR-053** SeoKit sitemap provider.
- **FR-054** Saved / restored / imported events; AuditKit action log listener.

### Gate, portability, CookieConsent

- **FR-060** `LegalPlaceholderScanner` over the effective text, one query, memoised per request.
- **FR-061** Built-in markers (29) + `extra_markers`; `builtin_markers: false`.
- **FR-062** `nowo:legal-kit:check-placeholders` (table / JSON, exit codes, counsel note).
- **FR-063** Ops banner partial + `nowo_legal_kit_placeholder_hits()`.
- **FR-065** `LegalDocumentPortability` export / normalize / apply (no implicit flush).
- **FR-066** `nowo:legal-kit:adapt-seed`.
- **FR-067** `nowo:legal-kit:export` / `import`.
- **FR-068** Console aliases `app:legal:check-placeholders`, `app:ops-seed:adapt-legal`.
- **FR-070** CookieConsent prepend (`disabled_routes`, `render_routes` whitelist, `privacy_route` 1.17+).
- **FR-072** `%cookie_inventory%` from the CookieConsent inventory.
- **FR-073** Doctor checks (routes, access, CookieConsent routes / privacy route, seed markers).

### Packaging

- **FR-080** Doctrine mapping prepended (single entity manager), table prefix listener (tables + index names).
- **FR-081** Asset package `nowo_legal_kit` and `css/nowo-legal-kit.css`; Twig namespace `@NowoLegalKitBundle`.
- **FR-082** Translations `NowoLegalKitBundle` and `NowoLegalKitSeed` in en, es, de, nl, fr, it, pt.

## Key entities

| Entity | Notes |
| --- | --- |
| `LegalDocument` | operator copy of one page in one locale; no row = seed |
| `ResolvedLegalDocument` | what a public request renders (title, sanitised body, source, content locale) |
| `LegalSeed` | seed text (tokens kept) and its locale |
| `PlaceholderHit` | slug, locale, marker, source, kind |

## Assumptions

- The host configures AuditKit's user class (`AuditUserInterface`) and SecurityBundle for the admin.
- Hosts with several entity managers map the bundle entity themselves.

## Success criteria

- **SC-01** The inventory count equals the production units under `src/`.
- **SC-02** PHPUnit line coverage ≥ 99% (target 100%).
- **SC-03** Schema adoption tests (SQLite in the bundle, MySQL 8.4 with Beacon's DDL in the demo) find no change.
- **SC-04** Page contract test green for every bundle page.
- **SC-05** Sanitiser corpus fails closed.

## Non-goals

- Versioned documents, publication workflow, acceptance tracking, per-tenant pages, localised slugs, legal advice.

## Validation

```bash
make release-check
# Inventory count (must equal SC-01):
find src -type f \( -name '*.php' -o -name '*.twig' -o -name '*.yaml' -o -name '*.css' \) | wc -l
```

## Links

- [`../../docs/USAGE.md`](../../docs/USAGE.md) · [`../../docs/CONFIGURATION.md`](../../docs/CONFIGURATION.md) ·
  [`../../docs/TEMPLATES.md`](../../docs/TEMPLATES.md) · [`../../docs/SECURITY.md`](../../docs/SECURITY.md)
