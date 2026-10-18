# Legal Kit Bundle — baseline code inventory

**Package**: `nowo-tech/legal-kit-bundle`  
**Last audited**: 2026-10-18  
**Production units**: **81/81**  
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
| Templates & stylesheet | 12 |
| Translations | 14 |
| **Total** | **81** |


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
| `src/Catalog/ConfiguredLegalPageCatalog.php` | ConfiguredLegalPageCatalog |
| `src/Catalog/LegalPage.php` | LegalPage |
| `src/Catalog/LegalPageCatalogInterface.php` | LegalPageCatalogInterface |
| `src/Locale/LegalLocales.php` | LegalLocales |

## Storage

Requirements: FR-010.

| File | Role |
| --- | --- |
| `src/Entity/LegalDocument.php` | LegalDocument |
| `src/Repository/LegalDocumentRepository.php` | LegalDocumentRepository |

## Seed

Requirements: FR-012, FR-020, FR-021, FR-022.

| File | Role |
| --- | --- |
| `src/Seed/LegalSeed.php` | LegalSeed |
| `src/Seed/LegalSeedProviderInterface.php` | LegalSeedProviderInterface |
| `src/Seed/LegalSeedRegistry.php` | LegalSeedRegistry |
| `src/Seed/TwigSeedProvider.php` | TwigSeedProvider |

## Tokens

Requirements: FR-023, FR-072.

| File | Role |
| --- | --- |
| `src/Token/BuiltinTokenResolver.php` | BuiltinTokenResolver |
| `src/Token/CookieInventoryTokenResolver.php` | CookieInventoryTokenResolver |
| `src/Token/LegalTokenContext.php` | LegalTokenContext |
| `src/Token/LegalTokenRenderer.php` | LegalTokenRenderer |
| `src/Token/LegalTokenResolverInterface.php` | LegalTokenResolverInterface |
| `src/Token/LegalTokenValue.php` | LegalTokenValue |

## Sanitiser

Requirements: FR-030, FR-031.

| File | Role |
| --- | --- |
| `src/Sanitizer/DomAllowlistSanitizer.php` | DomAllowlistSanitizer |
| `src/Sanitizer/LegalHtmlSanitizerInterface.php` | LegalHtmlSanitizerInterface |
| `src/Sanitizer/UrlPolicy.php` | UrlPolicy |

## Resolution

Requirements: FR-011, FR-032.

| File | Role |
| --- | --- |
| `src/Document/LegalDocumentResolver.php` | LegalDocumentResolver |
| `src/Document/ResolvedLegalDocument.php` | ResolvedLegalDocument |

## Gate & portability

Requirements: FR-060, FR-061, FR-065.

| File | Role |
| --- | --- |
| `src/Placeholder/BuiltinMarkers.php` | BuiltinMarkers |
| `src/Placeholder/LegalPlaceholderScanner.php` | LegalPlaceholderScanner |
| `src/Placeholder/PlaceholderHit.php` | PlaceholderHit |
| `src/Portability/LegalDocumentPortability.php` | LegalDocumentPortability |

## HTTP, routing & security

Requirements: FR-002, FR-003, FR-040…FR-045, FR-050.

| File | Role |
| --- | --- |
| `src/Controller/Admin/LegalDocumentAdminController.php` | LegalDocumentAdminController |
| `src/Controller/LegalPageController.php` | LegalPageController |
| `src/EventSubscriber/AdminAccessSubscriber.php` | AdminAccessSubscriber |
| `src/Form/LegalDocumentType.php` | LegalDocumentType |
| `src/Routing/LegalRouteLoader.php` | LegalRouteLoader |
| `src/Security/AllowAllLegalKitAccessChecker.php` | AllowAllLegalKitAccessChecker |
| `src/Security/ConfigurableLegalKitAccessChecker.php` | ConfigurableLegalKitAccessChecker |
| `src/Security/LegalKitAccessCheckerInterface.php` | LegalKitAccessCheckerInterface |

## Events & integrations

Requirements: FR-051…FR-054, FR-063.

| File | Role |
| --- | --- |
| `src/Event/LegalDocumentRestoredEvent.php` | LegalDocumentRestoredEvent |
| `src/Event/LegalDocumentSavedEvent.php` | LegalDocumentSavedEvent |
| `src/Event/LegalDocumentsImportedEvent.php` | LegalDocumentsImportedEvent |
| `src/Event/LegalPageRenderEvent.php` | LegalPageRenderEvent |
| `src/EventListener/ActionLogListener.php` | ActionLogListener |
| `src/EventListener/SeoKitHeadListener.php` | SeoKitHeadListener |
| `src/Seo/LegalSitemapProvider.php` | LegalSitemapProvider |
| `src/Twig/LegalKitExtension.php` | LegalKitExtension |

## Console

Requirements: FR-005, FR-013, FR-062, FR-066…FR-068, FR-073.

| File | Role |
| --- | --- |
| `src/Command/AdaptSeedCommand.php` | AdaptSeedCommand |
| `src/Command/CheckPlaceholdersCommand.php` | CheckPlaceholdersCommand |
| `src/Command/DoctorCommand.php` | DoctorCommand |
| `src/Command/ExportCommand.php` | ExportCommand |
| `src/Command/ImportCommand.php` | ImportCommand |
| `src/Command/SeedCommand.php` | SeedCommand |

## Templates & stylesheet

Requirements: FR-021, FR-041…FR-044, FR-050, FR-051, FR-063, FR-072, FR-081.

| File | Role |
| --- | --- |
| `src/Resources/public/css/nowo-legal-kit.css` | nowo-legal-kit |
| `src/Resources/views/_cookie_inventory.html.twig` | _cookie_inventory |
| `src/Resources/views/_footer_nav.html.twig` | _footer_nav |
| `src/Resources/views/_placeholder_banner.html.twig` | _placeholder_banner |
| `src/Resources/views/admin/edit.html.twig` | edit |
| `src/Resources/views/admin/index.html.twig` | index |
| `src/Resources/views/public/_nav.html.twig` | _nav |
| `src/Resources/views/public/page.html.twig` | page |
| `src/Resources/views/seed/cookies.html.twig` | cookies |
| `src/Resources/views/seed/notice.html.twig` | notice |
| `src/Resources/views/seed/privacy.html.twig` | privacy |
| `src/Resources/views/seed/terms.html.twig` | terms |

## Translations

Requirements: FR-021, FR-082.

| File | Role |
| --- | --- |
| `src/Resources/translations/NowoLegalKitBundle.de.yaml` | NowoLegalKitBundle.de |
| `src/Resources/translations/NowoLegalKitBundle.en.yaml` | NowoLegalKitBundle.en |
| `src/Resources/translations/NowoLegalKitBundle.es.yaml` | NowoLegalKitBundle.es |
| `src/Resources/translations/NowoLegalKitBundle.fr.yaml` | NowoLegalKitBundle.fr |
| `src/Resources/translations/NowoLegalKitBundle.it.yaml` | NowoLegalKitBundle.it |
| `src/Resources/translations/NowoLegalKitBundle.nl.yaml` | NowoLegalKitBundle.nl |
| `src/Resources/translations/NowoLegalKitBundle.pt.yaml` | NowoLegalKitBundle.pt |
| `src/Resources/translations/NowoLegalKitSeed.de.yaml` | NowoLegalKitSeed.de |
| `src/Resources/translations/NowoLegalKitSeed.en.yaml` | NowoLegalKitSeed.en |
| `src/Resources/translations/NowoLegalKitSeed.es.yaml` | NowoLegalKitSeed.es |
| `src/Resources/translations/NowoLegalKitSeed.fr.yaml` | NowoLegalKitSeed.fr |
| `src/Resources/translations/NowoLegalKitSeed.it.yaml` | NowoLegalKitSeed.it |
| `src/Resources/translations/NowoLegalKitSeed.nl.yaml` | NowoLegalKitSeed.nl |
| `src/Resources/translations/NowoLegalKitSeed.pt.yaml` | NowoLegalKitSeed.pt |
