# Templates (UiKit page contract, REQ-UI-003)

## Table of contents

- [Pages and scaffolds](#pages-and-scaffolds)
- [Unique pages](#unique-pages)
- [Partials](#partials)
- [View models](#view-models)
- [Theme slots used](#theme-slots-used)
- [Contract test](#contract-test)
- [Template contract changes](#template-contract-changes)

## Pages and scaffolds

| Page | Template | Scaffold | Data blocks filled | View model |
| --- | --- | --- | --- | --- |
| Admin index | `@NowoLegalKitBundle/admin/index.html.twig` | `@NowoUiKit/page/list.html.twig` | `nowo_ui_list_row` | `ListPage` (header, one `Column` per locale, rows `{page: Label, slug, cells}`, `EmptyState`), extra variable `locales` |
| Admin edit | `@NowoLegalKitBundle/admin/edit.html.twig` | `@NowoUiKit/page/form.html.twig` | `nowo_ui_scripts` (CKEditor script on this page only), `nowo_ui_form_aside` (1.4: version facts when `legal_versioning` is set) | `FormPage` (header with `AreaNav` tabs = locales, breadcrumbs, `secondaryActions` = View public page, `deleteAction` = restore with `Confirm` + CSRF `FormView`); with versioning the form adds `publication`, `requires_acceptance`, `change_summary`, `base_version`, `base_updated` |
| Admin compare (1.4, versioning) | `@NowoLegalKitBundle/admin/compare.html.twig` | `@NowoUiKit/page/show.html.twig` | `nowo_ui_styles`, `nowo_ui_show_after` | `ShowPage` (header actions "Use the new text" (link) and "Keep my text" (POST + CSRF `FormView`), `AreaNav` = views); extra variables `view`, `title_diff` / `body_diff` (`TextDiff`), `left` / `right` (`{title, body}`), `legal_slug`, `legal_locale` |

Admin pages render on `nowo_ui_layout.admin` (or `web_ui.layout_template`) with `bundle: NowoLegalKitBundle`, so
`theme.bundles.NowoLegalKitBundle` exceptions apply.

## Unique pages

The public legal page has no list / form / show shape: it is prose with a cross navigation.

- `@NowoLegalKitBundle/public/page.html.twig` — extends `public.layout_template ?? nowo_ui_layout.guest`, fills
  `nowo_ui_document_title` (document title), `nowo_ui_styles` (`css/nowo-legal-kit.css`, asset package
  `nowo_legal_kit`) and `nowo_ui_content` (`<article class="nowo-legal-doc">` with the body printed through
  `|nowo_legal_kit_sanitize_html` — never `|raw` —, the meta line and the cross navigation). Variables: `page` (`LegalPage`), `document` (`ResolvedLegalDocument`), `alternates`,
  `layout_template`, `legal_locale`, `prose_class` (1.5, `public.prose_class`) + `LegalPageRenderEvent` variables. No JavaScript.

- `@NowoLegalKitBundle/acceptance/interstitial.html.twig` (1.4, acceptance) — the re-acceptance page: extends
  `acceptance.layout_template ?? nowo_ui_layout.guest` (no app navigation), fills `nowo_ui_document_title` and
  `nowo_ui_content` (one card per pending page: title, version, change summary printed escaped + `|nl2br`, link,
  the accept checkbox for terms only; the form rows come from `LegalAcceptanceType`). Variables: `pending`
  (`list<PendingLegalPage>`), `links`, `form`, `stale`, `impersonating`, `logout_url`, `layout_template`,
  `legal_locale`. No JavaScript.

- `@NowoLegalKitBundle/acceptance/compact.html.twig` (1.5, opt-in through `acceptance.template`) — the same page as one
  card that fits a phone screen without scrolling: same variables and `nowo-legal-kit-*` test ids as the interstitial,
  plus `legal-acceptance-read-<slug>`, `legal-acceptance-sign-out` and `legal-acceptance-impersonating`. Each document
  is a row (linked title, version, summary, checkbox or statement), the page type is `auth`, it also fills
  `nowo_ui_styles` (`css/nowo-legal-kit.css`) and an impersonating user sees no submit button. No JavaScript. Semantic
  classes: `nowo-legal-acceptance--compact`, `nowo-legal-acceptance__*`. On a phone the host still has to keep its shell
  from adding height of its own (bottom padding, a tall footer).

## Partials

| Partial | Purpose |
| --- | --- |
| `public/_nav.html.twig` | cross navigation (`current`, `locale`) |
| `admin/_diff.html.twig` | side-by-side text diff (`diff`, `caption`, `bundle`); plain-text segments printed autoescaped, never `\|raw` |
| `_footer_nav.html.twig` | footer links + Manage cookies (`locale`, `attr`) |
| `_placeholder_banner.html.twig` | Ops banner (`locale`, `limit`) |
| `_cookie_inventory.html.twig` | cookie table of `%cookie_inventory%` (`cookies`); block `nowo_legal_kit_cookie_category_label` |
| `seed/*.html.twig` | built-in bodies (`legal_slug`, `legal_locale`) |

The bundle ships no local copy of UiKit partials (pagination, flashes, confirm, modal, empty state).

## View models

Labels are `Nowo\UiKitBundle\View\Label` objects in the `NowoLegalKitBundle` domain; scaffolds translate them. Index
row cells are plain arrays `{custom: bool, updated_at: ?DateTimeInterface, updated_by: ?string, hits: int,
edit_href: string, outdated?: bool, compare_href?: ?string}` (1.4 adds the last two with versioning).

## Theme slots used

`table_cell`, `table_head_cell`, `table_caption`, `card`, `card_header`, `card_title`, `card_body`, `form`,
`form_actions`, `btn_primary`, `btn_secondary`, `flash_warning` (1.4 pages), `btn_link`, `badge_success` (Custom), `badge_neutral` (Built-in), `badge_warning` (placeholders), `muted`
(last update / meta line), `section` (public article), `flash_warning` (Ops banner), `code`, `table_wrap` / `table`
(cookie inventory). The spec's `legal_article` / `legal_meta` / `legal_nav` slots do not exist in UiKit 2.4; the
bundle uses the slots above plus its semantic `nowo-legal-*` classes. Tokens: `--nowo-ui-legal-max-width` (default
`48rem`), `--nowo-ui-border`; under `nowo-legal-doc--typography` (1.5): `--nowo-legal-doc-h1-size`, `-h2-size`,
`-list-indent`, `-gap`.

## Contract test

`tests/Integration/PageContractTest.php` extends `Nowo\UiKitBundle\Test\PageContractTestCase` and renders the admin
index (with rows and empty), the editor (stored and seed), the public page (seed and stored), the compare page
(changes, too large, side by side) and the re-acceptance page, full and compact (normal and stale), against the stub host
layout and theme, with the strict missing-translation rule.

## Template contract changes

Documented in [UPGRADING.md](UPGRADING.md) under "Template contract" whenever a block, variable, view-model field or
theme slot changes.
