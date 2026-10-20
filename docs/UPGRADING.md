# Upgrading

This guide lists the changes integrators must apply when upgrading between versions.

## Table of contents

- [Unreleased](#unreleased)
- [1.0.1 (2026-10-18)](#101-2026-10-18)
- [1.0.0 (2026-10-18)](#100-2026-10-18)
  - [From a legal copy (`src/Shared/Legal`)](#from-a-legal-copy-srcsharedlegal)
  - [Template contract (1.0)](#template-contract-10)
  - [Deprecations (removed in 2.0)](#deprecations-removed-in-20)

## Unreleased

No change is required.

- **Render-time sanitising (REQ-PBK-009).** `public/page.html.twig` prints the body with
  `|nowo_legal_kit_sanitize_html` instead of `|raw`. Overrides of that template that print `document.body|raw`
  should switch to the filter. Cost: one more sanitiser pass per page view, about 1.5 ms for a 20 KB page on PHP 8.4
  (HTML5 parser; about 5 ms with libxml on PHP 8.3). With `public.cache.shared_max_age` the cost is paid once per
  cache entry.
- **HTML5 parser on PHP 8.4+.** The sanitiser output can differ slightly from 1.0 (browser parsing rules, empty
  attributes written as `attr=""`, SVG / MathML removed). Stored rows need no migration: they are re-sanitised on
  every render.
- **Cookie inventory labels.** The category column shows `cookies.category.<key>` (domain `NowoLegalKitBundle`).
  Delete a host copy of `_cookie_inventory.html.twig` that only translated the category; to change just the label,
  extend `@!NowoLegalKitBundle/_cookie_inventory.html.twig` and redefine `nowo_legal_kit_cookie_category_label`
  (variables `cookie`, `category_label`). Custom categories: add `cookies.category.<key>` to the
  `NowoLegalKitBundle` domain of the application.

## 1.0.1 (2026-10-18)

No change for integrators (test kernel fix for Symfony 7.4). Hosts on Symfony 7.4 need a PSR-20 `clock` service for
AuditKit: require `symfony/clock` in the application (Flex applications already do).

## 1.0.0 (2026-10-18)

First release. New installations follow [INSTALLATION.md](INSTALLATION.md).

### From a legal copy (`src/Shared/Legal`)

Applications that copied Beacon's legal feature switch without a data migration — see
[INSTALLATION.md — Adopting the bundle](INSTALLATION.md#adopting-the-bundle-from-a-legal-copy):

| Before (copy) | After (bundle) |
| --- | --- |
| `App\Shared\Legal\Entity\LegalDocument` + mapping | `Nowo\LegalKitBundle\Entity\LegalDocument` (same table; delete the host entity in the same commit) |
| `controllers_legal` route import | `type: nowo_legal_kit` route import |
| `admin_legal_index` / `admin_legal_edit` / `admin_legal_reset` | `nowo_legal_kit_admin_index` / `_edit` / `_reset` (old names: deprecated aliases) |
| `app:legal:check-placeholders` | `nowo:legal-kit:check-placeholders` (alias kept) |
| `app:ops-seed:adapt-legal` | `nowo:legal-kit:adapt-seed` (alias kept) |
| `LegalPlaceholderScanner::MARKERS` | `Placeholder\BuiltinMarkers::MARKERS` (+ `placeholders.extra_markers`) |
| `LegalPublishedHtml::sanitize()` (regex `stripHazards`) | `LegalHtmlSanitizerInterface` (DOM allowlist; drops `style`) |
| `legal.*` keys in `messages` | `NowoLegalKitSeed` (seed) and `NowoLegalKitBundle` (UI) domains |
| `nowo_cookie_consent.disabled_routes` legal entries | prepended by the bundle (delete them from the host) |
| Ops-seed `legal_documents` code | `LegalDocumentPortability::export()` / `normalize()` / `apply()` |

Stored HTML is re-sanitised on render: inline `style` attributes written by older editors disappear from the public
page without changing the rows.

### Template contract (1.0)

Initial contract: pages, blocks and variables in [TEMPLATES.md](TEMPLATES.md).

### Deprecations (removed in 2.0)

- Route aliases `admin_legal_index`, `admin_legal_edit`, `admin_legal_reset` (`routing.legacy_admin_aliases`).
- Console aliases `app:legal:check-placeholders`, `app:ops-seed:adapt-legal` (`console.legacy_aliases`).
