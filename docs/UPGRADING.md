# Upgrading

This guide lists the changes integrators must apply when upgrading between versions.

## Table of contents

- [Unreleased](#unreleased)
- [To 1.4.1](#to-141)
- [To 1.4.0](#to-140)
  - [Versions and re-acceptance](#versions-and-re-acceptance)
- [To 1.3.2](#to-132)
- [To 1.3.1](#to-131)
- [To 1.3.0](#to-130)
- [To 1.2.0](#to-120)
- [To 1.1.1](#to-111)
- [To 1.1.0](#to-110)
- [1.0.1 (2026-10-18)](#101-2026-10-18)
- [1.0.0 (2026-10-18)](#100-2026-10-18)
  - [From a legal copy (`src/Shared/Legal`)](#from-a-legal-copy-srcsharedlegal)
  - [Template contract (1.0)](#template-contract-10)
  - [Deprecations (removed in 2.0)](#deprecations-removed-in-20)

## Unreleased

No action needed: all three additions are opt-in, and a host that changes nothing gets the same pages.

- **`sanitizer.heading_anchors`.** Mark headings with `legal-anchor--<anchor>` and list the anchors. Seed hashes are
  computed on sanitised text, so turning this on for text that already carries these classes changes its hash. Stored
  `seed_hash` values and existing seed snapshots of that text stop matching: `upgrade-seed` treats those copies as edited
  and leaves them alone, and the snapshots count as unknown until you take them again. A host that already added the
  same ids with a sanitiser decorator (`id` first, then `class`) gets identical output, so its hashes hold. Remove the
  decorator and set the option in the same deploy.
- **Compact re-acceptance page.** Set `acceptance.template: '@NowoLegalKitBundle/acceptance/compact.html.twig'`. It
  loads `css/nowo-legal-kit.css`, so run `assets:install` and keep `nowo_ui_styles` in your layout. The card uses the
  `card` slot and the page type `auth`; set `theme.pages.auth` or `theme.bundles.NowoLegalKitBundle` for your own classes.
- **Document typography.** Set `public.prose_class: nowo-legal-doc--typography` and any of the tokens listed in
  [CONFIGURATION](CONFIGURATION.md#public-pages-and-cache). Rules of your own on `.nowo-legal-doc h1` that load later
  still win.
- **Template contract.** `public/page.html.twig` receives a new variable, `prose_class`. A host that overrides the
  template can ignore it.

## To 1.4.1

From **1.4.0**:

```bash
composer update nowo-tech/legal-kit-bundle
```

No host change. After accepting, a stored target with a `.` / `..` segment (also `%2e`-encoded), an empty segment
(`/a//b`), an encoded backslash (`%5c`) or an encoded control character is no longer restored: the user lands on
`acceptance.default_target` (or `/`) instead.

## To 1.4.0

From **1.3.2**:

```bash
composer update nowo-tech/legal-kit-bundle
```

### Versions and re-acceptance

```bash
composer update nowo-tech/legal-kit-bundle
```

Nothing changes until you opt in: no new column, table, route, listener or admin field.

- New runtime dependencies `symfony/clock` and `psr/clock` (already present in Symfony apps).
- **Versioning** (`versioning.enabled: true`, a literal boolean): run `doctrine:migrations:diff` (two nullable columns
  on `legal_document`, table `legal_page_version`; reference DDL in [INSTALLATION](INSTALLATION.md#versioning-and-acceptance-tables)).
  Rows that existed before have no seed base:
  1. **before** you change your seed texts, freeze them: `nowo:legal-kit:snapshot-seed --revision=<current revision>`
     (default `seed.revision` = `seed.date`) and commit `config/legal-kit/seed-snapshots/<revision>/`;
  2. change the texts and `seed.date` / `seed.revision`;
  3. deploy step: `nowo:legal-kit:upgrade-seed --from=<old revision> [--requires-acceptance]`.
  If your texts already changed, write the snapshot of the old texts yourself in the documented format (see
  [USAGE](USAGE.md#snapshots)).
- **Acceptance** (`acceptance.enabled: true` + `acceptance.subject_resolver`; `acceptance.enforce: true` for the
  gate): migration for `legal_acceptance`; implement `LegalAcceptanceSubjectResolverInterface`; exempt your MFA /
  verification / account export and deletion flows (`exempt_routes`, `exempt_paths` or a
  `LegalAcceptanceExemptionInterface` voter); call `LegalAcceptanceManager::exportFor()` / `eraseFor()` from your
  personal-data export and erasure. A page path `accept` is reserved while acceptance is on.
- `pages.privacy.acceptance: accept` is refused (privacy information is acknowledged).
- Template contract: `admin/index` cells gain `outdated` / `compare_href`; `admin/edit` fills `nowo_ui_form_aside`
  (variable `legal_versioning`); `public/page` prints "Version N" when `document.version` is set; new templates
  `admin/compare.html.twig`, `admin/_diff.html.twig`, `acceptance/interstitial.html.twig` (see
  [TEMPLATES](TEMPLATES.md)). Overridden templates keep working.
- PHP: appended optional arguments only (`LegalDocumentSavedEvent`, `ResolvedLegalDocument`, `LegalPage`,
  `LegalDocumentResolver`, `LegalDocumentAdminController`, `LegalKitExtension`, `SeedCommand`, `DoctorCommand`,
  `LegalDocumentPortability`). `LegalKitAccessCheckerInterface` is unchanged.

## To 1.3.2

From **1.3.1**:

```bash
composer update nowo-tech/legal-kit-bundle
```

## To 1.3.1

From **1.3.0**:

```bash
composer update nowo-tech/legal-kit-bundle
```

From **1.3.0**: no action needed if you import the `nowo_legal_kit` route type (see [INSTALLATION](INSTALLATION.md)).
If the admin was reachable in your app only through the default `routing.controllers` import (Symfony >= 7.3), import
the route type explicitly; with `web_ui.enabled: false` the admin routes are no longer registered at all.

## To 1.3.0

From **1.2.0**:

```bash
composer update nowo-tech/legal-kit-bundle
```

## To 1.2.0

From **1.1.1**:

```bash
composer update nowo-tech/legal-kit-bundle
```

## To 1.1.1

From **1.1.0**:

```bash
composer update nowo-tech/legal-kit-bundle
```

## To 1.1.0

From **1.0.1**:

```bash
composer update nowo-tech/legal-kit-bundle
```

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
