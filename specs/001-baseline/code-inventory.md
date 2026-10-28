# Legal Kit Bundle — baseline code inventory

**Package**: `nowo-tech/legal-kit-bundle`  
**Last audited**: 2026-10-28  
**Production units**: **82/82**  
**Audit command**: `find src -type f \( -name '*.php' -o -name '*.twig' -o -name '*.yaml' -o -name '*.css' \) | wc -l`

## Scope notes

- `demo/`, `tests/` and `.symfony/` (Flex recipe, see `docs/INSTALLATION.md`) are out of inventory scope.
- No JavaScript / TypeScript sources: the bundle ships one hand-written stylesheet.

## Coverage summary

| Category | Mapped |
| --- | ---: |
| Bundle, configuration & DI | 8 |
| Catalogue & locales | 4 |
| Storage | 2 |
| Seed | 4 |
| Tokens | 6 |
| Sanitiser | 3 |
| Resolution | 2 |
| Gate & portability | 4 |
| HTTP, routing & security | 8 |
| Events & integrations | 8 |
| Console | 6 |
| Templates & stylesheet | 13 |
| Translations | 14 |
| **Total** | **82** |


## Bundle, configuration & DI

Requirements: FR-004, FR-040, FR-070, FR-080, FR-081.

| File | Role |
| --- | --- |
| `src/DependencyInjection/Compiler/IntegrationPass.php` | Action log listener, doctor CookieConsent args |
| `src/DependencyInjection/Compiler/SecurityPass.php` | Access checker wiring, fail without SecurityBundle |
| `src/DependencyInjection/Compiler/TwigPathsPass.php` | @NowoLegalKitBundle namespace + app override dir |
| `src/DependencyInjection/Configuration.php` | Strict config tree |
| `src/DependencyInjection/NowoLegalKitExtension.php` | Load + prepend (assets, Doctrine mapping, FormKit, CKEditor, CookieConsent) |
| `src/Doctrine/TablePrefixListener.php` | doctrine.table_prefix (tables + indexes) |
| `src/NowoLegalKitBundle.php` | Bundle class, compiler passes |
| `src/Resources/config/services.php` | Service definitions |

## Catalogue & locales

Requirements: FR-001, FR-004.

| File | Role |
| --- | --- |
| `src/Catalog/ConfiguredLegalPageCatalog.php` | Catalogue from `pages` (enabled pages, order, default seed template) |
| `src/Catalog/LegalPage.php` | Page value object: slug, path, nav, seed template, route names, label key |
| `src/Catalog/LegalPageCatalogInterface.php` | Catalogue SPI (slugs, pages, has, page) |
| `src/Locale/LegalLocales.php` | Locales from list / parameter / env, default first, route requirement |

## Storage

Requirements: FR-010.

| File | Role |
| --- | --- |
| `src/Entity/LegalDocument.php` | `legal_document` mapping (AuditKit traits, explicit index names) |
| `src/Repository/LegalDocumentRepository.php` | Lookups, one-query maps (scanner, admin matrix with editors), save / remove |

## Seed

Requirements: FR-012, FR-020, FR-021, FR-022.

| File | Role |
| --- | --- |
| `src/Seed/LegalSeed.php` | Seed text value (title, body, locale, fallback flag) |
| `src/Seed/LegalSeedProviderInterface.php` | Seed provider SPI (supports / title / body) |
| `src/Seed/LegalSeedRegistry.php` | Provider chain + `seed.fallback_locale` |
| `src/Seed/TwigSeedProvider.php` | Twig seed rendered with the translator switched to the target locale |

## Tokens

Requirements: FR-023, FR-072.

| File | Role |
| --- | --- |
| `src/Token/BuiltinTokenResolver.php` | `%brand%`, `%last_updated%` |
| `src/Token/CookieInventoryTokenResolver.php` | `%cookie_inventory%` from CookieConsent |
| `src/Token/LegalTokenContext.php` | Token context (slug, locale, update date) |
| `src/Token/LegalTokenRenderer.php` | Token chain: HTML / text rendering, unresolved names |
| `src/Token/LegalTokenResolverInterface.php` | Token resolver SPI |
| `src/Token/LegalTokenValue.php` | Text or trusted HTML token value |

## Sanitiser

Requirements: FR-030, FR-031.

| File | Role |
| --- | --- |
| `src/Sanitizer/DomAllowlistSanitizer.php` | DOM allowlist sanitiser (+ optional CKEditor strict first pass) |
| `src/Sanitizer/LegalHtmlSanitizerInterface.php` | Sanitiser SPI |
| `src/Sanitizer/UrlPolicy.php` | href / src policy (+ optional OutboundUrlGuard) |

## Resolution

Requirements: FR-011, FR-032.

| File | Role |
| --- | --- |
| `src/Document/LegalDocumentResolver.php` | stored → seed → fallback resolution; effective text; editor seed |
| `src/Document/ResolvedLegalDocument.php` | Published document value object |

## Gate & portability

Requirements: FR-060, FR-061, FR-065.

| File | Role |
| --- | --- |
| `src/Placeholder/BuiltinMarkers.php` | The 29 markers of the shipped seed |
| `src/Placeholder/LegalPlaceholderScanner.php` | Marker / unresolved-token scanner (memoised, reset) |
| `src/Placeholder/PlaceholderHit.php` | Hit value object |
| `src/Portability/LegalDocumentPortability.php` | Export / normalize / apply of `legal_documents[]` |

## HTTP, routing & security

Requirements: FR-002, FR-003, FR-040…FR-045, FR-050.

| File | Role |
| --- | --- |
| `src/Controller/Admin/LegalDocumentAdminController.php` | Admin index / edit / restore on UiKit view models |
| `src/Controller/LegalPageController.php` | Public page, 301 / 302, cache headers, render event |
| `src/EventSubscriber/AdminAccessSubscriber.php` | Enforces the checker on admin routes / controller |
| `src/Form/LegalDocumentType.php` | Editor form (FormKit profile `legal_kit`, CKEditor 5 or textarea) |
| `src/Routing/LegalRouteLoader.php` | Route type `nowo_legal_kit` (public, twins / bare, admin, legacy aliases) |
| `src/Security/AllowAllLegalKitAccessChecker.php` | Demo-only checker (`allow_unauthenticated`) |
| `src/Security/ConfigurableLegalKitAccessChecker.php` | Role checker (`security.access_roles`) |
| `src/Security/LegalKitAccessCheckerInterface.php` | Access checker SPI |

## Events & integrations

Requirements: FR-051…FR-054, FR-063.

| File | Role |
| --- | --- |
| `src/Event/LegalDocumentRestoredEvent.php` | Restored event |
| `src/Event/LegalDocumentSavedEvent.php` | Saved event |
| `src/Event/LegalDocumentsImportedEvent.php` | Imported event |
| `src/Event/LegalPageRenderEvent.php` | Before-render event (head, alternates, template variables) |
| `src/EventListener/ActionLogListener.php` | AuditKit action log of edits |
| `src/EventListener/SeoKitHeadListener.php` | SeoKit title / canonical / hreflang |
| `src/Seo/LegalSitemapProvider.php` | SeoKit sitemap entries |
| `src/Twig/LegalKitExtension.php` | Twig API (links, hits, admin URL, consent widget, content locale, inventory) |

## Console

Requirements: FR-005, FR-013, FR-062, FR-066…FR-068, FR-073.

| File | Role |
| --- | --- |
| `src/Command/AdaptSeedCommand.php` | `nowo:legal-kit:adapt-seed` |
| `src/Command/CheckPlaceholdersCommand.php` | `nowo:legal-kit:check-placeholders` |
| `src/Command/DoctorCommand.php` | `nowo:legal-kit:doctor` |
| `src/Command/ExportCommand.php` | `nowo:legal-kit:export` |
| `src/Command/ImportCommand.php` | `nowo:legal-kit:import` |
| `src/Command/SeedCommand.php` | `nowo:legal-kit:seed` |

## Templates & stylesheet

Requirements: FR-021, FR-041…FR-044, FR-050, FR-051, FR-063, FR-072, FR-081.

| File | Role |
| --- | --- |
| `src/Resources/public/css/nowo-legal-kit.css` | Public page stylesheet |
| `src/Resources/views/_cookie_inventory.html.twig` | Cookie table |
| `src/Resources/views/_footer_nav.html.twig` | Footer navigation + Manage cookies |
| `src/Resources/views/_placeholder_banner.html.twig` | Ops banner |
| `src/Resources/views/admin/_ckeditor_script.html.twig` | CKEditor 5 script of the editor page (bundle asset, no CDN; only with `editor.type` ckeditor5) — FR-043 |
| `src/Resources/views/admin/edit.html.twig` | Editor (form scaffold) |
| `src/Resources/views/admin/index.html.twig` | Matrix (list scaffold) |
| `src/Resources/views/public/_nav.html.twig` | Cross navigation |
| `src/Resources/views/public/page.html.twig` | Public unique page |
| `src/Resources/views/seed/cookies.html.twig` | Cookies seed body |
| `src/Resources/views/seed/notice.html.twig` | Legal notice seed body |
| `src/Resources/views/seed/privacy.html.twig` | Privacy seed body |
| `src/Resources/views/seed/terms.html.twig` | Terms seed body |

## Translations

Requirements: FR-021, FR-082.

| File | Role |
| --- | --- |
| `src/Resources/translations/NowoLegalKitBundle.de.yaml` | UI texts (de) |
| `src/Resources/translations/NowoLegalKitBundle.en.yaml` | UI texts (en) |
| `src/Resources/translations/NowoLegalKitBundle.es.yaml` | UI texts (es) |
| `src/Resources/translations/NowoLegalKitBundle.fr.yaml` | UI texts (fr) |
| `src/Resources/translations/NowoLegalKitBundle.it.yaml` | UI texts (it) |
| `src/Resources/translations/NowoLegalKitBundle.nl.yaml` | UI texts (nl) |
| `src/Resources/translations/NowoLegalKitBundle.pt.yaml` | UI texts (pt) |
| `src/Resources/translations/NowoLegalKitSeed.de.yaml` | Seed scaffold texts (de) |
| `src/Resources/translations/NowoLegalKitSeed.en.yaml` | Seed scaffold texts (en) |
| `src/Resources/translations/NowoLegalKitSeed.es.yaml` | Seed scaffold texts (es) |
| `src/Resources/translations/NowoLegalKitSeed.fr.yaml` | Seed scaffold texts (fr) |
| `src/Resources/translations/NowoLegalKitSeed.it.yaml` | Seed scaffold texts (it) |
| `src/Resources/translations/NowoLegalKitSeed.nl.yaml` | Seed scaffold texts (nl) |
| `src/Resources/translations/NowoLegalKitSeed.pt.yaml` | Seed scaffold texts (pt) |
