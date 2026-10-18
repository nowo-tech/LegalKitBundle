# Architecture

## Table of contents

- [Layers](#layers)
- [Request flow](#request-flow)
- [Services](#services)
- [Data model](#data-model)
- [Design decisions](#design-decisions)

## Layers

| Layer | Classes |
| --- | --- |
| Catalogue and locales | `Catalog\LegalPageCatalogInterface`, `ConfiguredLegalPageCatalog`, `LegalPage`, `Locale\LegalLocales` |
| Storage | `Entity\LegalDocument` (AuditKit timestamp + blame traits), `Repository\LegalDocumentRepository`, `Doctrine\TablePrefixListener` |
| Text | `Seed\LegalSeedProviderInterface`, `TwigSeedProvider`, `LegalSeedRegistry`; `Token\LegalTokenRenderer` + resolvers; `Sanitizer\DomAllowlistSanitizer` + `UrlPolicy`; `Document\LegalDocumentResolver` |
| Gate and portability | `Placeholder\LegalPlaceholderScanner`, `BuiltinMarkers`, `Portability\LegalDocumentPortability` |
| HTTP | `Routing\LegalRouteLoader`, `Controller\LegalPageController`, `Controller\Admin\LegalDocumentAdminController`, `Form\LegalDocumentType`, `Security\*`, `EventSubscriber\AdminAccessSubscriber` |
| Integrations | `Twig\LegalKitExtension`, `Seo\LegalSitemapProvider`, `EventListener\SeoKitHeadListener`, `EventListener\ActionLogListener`, CookieConsent prepend in `NowoLegalKitExtension` |
| Console | `Command\{CheckPlaceholders,AdaptSeed,Export,Import,Seed,Doctor}Command` |

## Request flow

1. `LegalRouteLoader` (route type `nowo_legal_kit`) generates `legal_<slug>` and its twin or bare redirect from the
   catalogue and the locales; admin routes when `web_ui.enabled`.
2. `LegalPageController::show()` redirects `/{default}/…` (unprefixed style), resolves the document, sets
   `_content_locale`, dispatches `LegalPageRenderEvent` and renders the unique public page with cache headers.
3. `LegalDocumentResolver` returns a `ResolvedLegalDocument`: stored row with text, else the seed of the locale, else
   the seed of `seed.fallback_locale`; tokens are resolved, then the body is sanitised (title tokens as plain text).
4. The admin controller builds UiKit view models; `AdminAccessSubscriber` enforces the access checker on every
   `nowo_legal_kit_admin_*` route.

## Services

All services are stateless (`final readonly`) except `LegalPlaceholderScanner`, which memoises hits per request and is
tagged `kernel.reset`. The seed provider switches the translator locale inside `try / finally`.

## Data model

One table `legal_document` (see [INSTALLATION.md](INSTALLATION.md#database-table)): `slug` + `locale` unique,
`title`, `body` (HTML with tokens), timestamps and blame from AuditKit (`created_by_id` / `updated_by_id` → host user,
`ON DELETE SET NULL`). Explicit index names keep the schema identical to the copies the bundle replaces.

## Design decisions

- **No row = seed.** Operators only store what they change; restoring deletes the row. `seed.materialize` supports
  apps that seed rows at install.
- **Sanitise on write and on render.** Rows written by SQL, restores or older sanitisers are cleaned again; stricter
  rules apply without a data migration.
- **Tokens at render time.** Stored copies stay linked to configuration (brand, company profile).
- **Soft integrations.** CookieConsent, SeoKit, CKEditor, OutboundUrlGuard and the AuditKit action log are detected
  by registered bundle / service; nothing breaks without them.
- **Stable public contract.** Route names `legal_<slug>` and URLs are the contract of REQ-CC-003; the old admin route
  names and console names survive as deprecated aliases in 1.x.
