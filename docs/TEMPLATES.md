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
| Admin edit | `@NowoLegalKitBundle/admin/edit.html.twig` | `@NowoUiKit/page/form.html.twig` | `nowo_ui_scripts` (CKEditor script on this page only) | `FormPage` (header with `AreaNav` tabs = locales, breadcrumbs, `secondaryActions` = View public page, `deleteAction` = restore with `Confirm` + CSRF `FormView`) |

Admin pages render on `nowo_ui_layout.admin` (or `web_ui.layout_template`) with `bundle: NowoLegalKitBundle`, so
`theme.bundles.NowoLegalKitBundle` exceptions apply.

## Unique pages

The public legal page has no list / form / show shape: it is prose with a cross navigation.

- `@NowoLegalKitBundle/public/page.html.twig` — extends `public.layout_template ?? nowo_ui_layout.guest`, fills
  `nowo_ui_document_title` (document title), `nowo_ui_styles` (`css/nowo-legal-kit.css`, asset package
  `nowo_legal_kit`) and `nowo_ui_content` (`<article class="nowo-legal-doc">` with the sanitised body, the meta line and
  the cross navigation). Variables: `page` (`LegalPage`), `document` (`ResolvedLegalDocument`), `alternates`,
  `layout_template`, `legal_locale` + `LegalPageRenderEvent` variables. No JavaScript.

## Partials

| Partial | Purpose |
| --- | --- |
| `public/_nav.html.twig` | cross navigation (`current`, `locale`) |
| `_footer_nav.html.twig` | footer links + Manage cookies (`locale`, `attr`) |
| `_placeholder_banner.html.twig` | Ops banner (`locale`, `limit`) |
| `_cookie_inventory.html.twig` | cookie table of `%cookie_inventory%` (`cookies`) |
| `seed/*.html.twig` | built-in bodies (`legal_slug`, `legal_locale`) |

The bundle ships no local copy of UiKit partials (pagination, flashes, confirm, modal, empty state).

## View models

Labels are `Nowo\UiKitBundle\View\Label` objects in the `NowoLegalKitBundle` domain; scaffolds translate them. Index
row cells are plain arrays `{custom: bool, updated_at: ?DateTimeInterface, updated_by: ?string, hits: int,
edit_href: string}`.

## Theme slots used

`table_cell`, `btn_link`, `badge_success` (Custom), `badge_neutral` (Built-in), `badge_warning` (placeholders), `muted`
(last update / meta line), `section` (public article), `flash_warning` (Ops banner), `code`, `table_wrap` / `table`
(cookie inventory). The spec's `legal_article` / `legal_meta` / `legal_nav` slots do not exist in UiKit 2.4; the
bundle uses the slots above plus its semantic `nowo-legal-*` classes. Tokens: `--nowo-ui-legal-max-width` (default
`48rem`), `--nowo-ui-border`.

## Contract test

`tests/Integration/PageContractTest.php` extends `Nowo\UiKitBundle\Test\PageContractTestCase` and renders the admin
index (with rows and empty), the editor (stored and seed) and the public page (seed and stored) against the stub host
layout and theme, with the strict missing-translation rule.

## Template contract changes

Documented in [UPGRADING.md](UPGRADING.md) under "Template contract" whenever a block, variable, view-model field or
theme slot changes.
