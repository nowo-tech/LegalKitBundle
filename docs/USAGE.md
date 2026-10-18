# Usage

## Table of contents

- [How it fits together](#how-it-fits-together)
- [Public pages](#public-pages)
- [Footer navigation](#footer-navigation)
- [Admin pages](#admin-pages)
- [Seed text](#seed-text)
  - [Override seed keys](#override-seed-keys)
  - [Own seed templates](#own-seed-templates)
  - [Seed providers](#seed-providers)
  - [Fallback locale](#fallback-locale)
- [Tokens](#tokens)
- [Placeholder gate](#placeholder-gate)
- [Ops-seed portability](#ops-seed-portability)
- [Console commands](#console-commands)
- [Events](#events)
- [CookieConsent](#cookieconsent)
- [SeoKit](#seokit)
- [Templates](#templates)
  - [Overriding](#overriding)
  - [Forms](#forms)
  - [Twig functions](#twig-functions)
- [Extension points](#extension-points)
- [Translations](#translations)
- [Naming conventions](#naming-conventions)

## How it fits together

```
request /es/legal/privacy ──► LegalPageController::show
                                 │  LegalDocumentResolver: stored row with text → seed (es) → seed (fallback locale)
                                 │  LegalTokenRenderer (%brand% …), then LegalHtmlSanitizerInterface on every render
                                 │  LegalPageRenderEvent (head, SEO)
                                 ▼
                     @NowoLegalKitBundle/public/page.html.twig (guest layout)

/admin/legal ──► matrix (ListPage) ──► /admin/legal/{slug}/{locale} (FormPage, tabs per locale)
                                         save: sanitise → non-empty → LegalDocumentSavedEvent
                                         restore: POST + CSRF → row deleted → LegalDocumentRestoredEvent
```

No row means the built-in seed is published; saving stores HTML for **one** page in **one** locale.

## Public pages

| URL | Route | Notes |
| --- | --- | --- |
| `/legal/notice` | `legal_notice_unlocalized` | default locale (unprefixed style) |
| `/es/legal/notice` | `legal_notice` | any enabled locale |
| `/en/legal/notice` (default) | `legal_notice` | 301 → `/legal/notice` |

Generate links with `path('legal_privacy', {_locale: 'es'})` or, better, `nowo_legal_kit_links()` (it picks the twin
for the default locale). The page renders on the UiKit guest layout (`public.layout_template` replaces it), sets the
request attribute `_content_locale`, `lang` on the article, `Cache-Control: private` and `Last-Modified`.

## Footer navigation

```twig
<footer>
    {{ include('@NowoLegalKitBundle/_footer_nav.html.twig') }}
    {# or build your own: #}
    {% for link in nowo_legal_kit_links() %}<a href="{{ link.url }}">{{ link.label }}</a>{% endfor %}
</footer>
```

The partial renders every page with `nav: true` then **Manage cookies**: a `data-nowo-open-consent` opener when the
CookieConsent widget renders on the page, else a link to the cookies page (REQ-CC-014). Labels use the explicit
locale (`{locale: 'de'}`) or the request locale.

## Admin pages

`/admin/legal` (`nowo_legal_kit_admin_index`) lists every page × enabled locale: Built-in / Custom, last update and
editor, placeholder hits. `/admin/legal/{slug}/{locale}` (`nowo_legal_kit_admin_edit`) starts from the seed (tokens
kept) when no row exists; **View public page**; **Restore built-in text** (danger zone, UiKit confirm dialog, POST +
CSRF id `nowo_legal_kit_reset_<slug>_<locale>`). A body that is empty after sanitising is refused. Admin responses are
`Cache-Control: no-store`.

Menu entry (DashboardMenu or your own): route `nowo_legal_kit_admin_index`.

## Seed text

### Override seed keys

The scaffold lives in the `NowoLegalKitSeed` domain. Override single keys in your translations:

```yaml
# translations/NowoLegalKitSeed.es.yaml
privacy:
    authority_body: 'Puedes reclamar ante la AEPD (www.aepd.es).'
```

### Own seed templates

```yaml
nowo_legal_kit:
    pages:
        notice: { seed_template: 'legal/seed/notice.html.twig' }
```

The template renders with the translator switched to the target locale; variables `legal_slug`, `legal_locale`.
Per-locale static HTML works too (`{% include 'legal/seed/notice.' ~ legal_locale ~ '.html.twig' %}`).

### Seed providers

```php
use Nowo\LegalKitBundle\Seed\LegalSeedProviderInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

#[AsTaggedItem(priority: 10)] // the built-in provider has -100
final readonly class CompanySeedProvider implements LegalSeedProviderInterface
{
    public function supports(string $slug, string $locale): bool { return $locale === 'es'; }
    public function title(string $slug, string $locale): string { /* … */ }
    public function body(string $slug, string $locale): string { /* HTML, %tokens% kept */ }
}
```

The first provider that `supports()` the page in the locale wins (autoconfigured, tag `nowo_legal_kit.seed_provider`).

### Fallback locale

`seed.fallback_locale: es` shows the Spanish seed in a locale without its own seed; `legal_kit_content_locale(slug)`
tells the layout which locale the text is in.

## Tokens

`%name%` placeholders are resolved when a page is **rendered** — the editor and exports keep them, so a copy saved
from the seed stays linked to your configuration:

| Token | Value |
| --- | --- |
| `%brand%` | `brand_name` |
| `%last_updated%` | row update date, else `seed.date` |
| `%cookie_inventory%` | CookieConsent cookie table (nothing without CookieConsent) |

Add yours (company identity from a profile, for example):

```php
use Nowo\LegalKitBundle\Token\LegalTokenContext;
use Nowo\LegalKitBundle\Token\LegalTokenResolverInterface;

final readonly class CompanyTokens implements LegalTokenResolverInterface
{
    public function names(): array { return ['legal_name', 'tax_id']; }

    public function resolve(string $name, LegalTokenContext $context): ?string
    {
        return match ($name) { 'legal_name' => $this->profile->legalName(), 'tax_id' => $this->profile->taxId(), default => null };
    }
}
```

Strings are HTML-escaped on insertion; return `LegalTokenValue::html(...)` only for markup you produced safely.
An unresolved token stays visible and is reported by the placeholder gate.

## Placeholder gate

```bash
bin/console nowo:legal-kit:check-placeholders              # every enabled locale, table
bin/console nowo:legal-kit:check-placeholders -l es -f json
```

Exit 1 while any published page (stored row or seed) contains a marker of `placeholders` or an unresolved token. The
Ops banner shows the first hits:

```twig
{{ include('@NowoLegalKitBundle/_placeholder_banner.html.twig', {limit: 8}) }}
```

The gate is an engineering check: counsel review stays required (REQ-CC-010).

## Ops-seed portability

```php
use Nowo\LegalKitBundle\Portability\LegalDocumentPortability;

// export: stored rows only, sorted, tokens kept
$payload['legal_documents'] = $this->legalDocuments->export();

// apply: validate everything first, then upsert inside the host transaction (no flush)
$rows = $this->legalDocuments->normalize($payload['legal_documents'] ?? []);
$this->legalDocuments->apply($rows, $actor);
$this->entityManager->flush();
```

`normalize()` throws `InvalidArgumentException` naming the index of the first invalid entry (unknown slug / locale,
missing title, empty body after sanitising, duplicate).

## Console commands

| Command | Purpose |
| --- | --- |
| `nowo:legal-kit:check-placeholders [-l xx] [-f table\|json]` | deploy gate (alias `app:legal:check-placeholders`) |
| `nowo:legal-kit:adapt-seed --replacements=map.json [-o file] [--require-clear-markers]` | `legal_documents` JSON from the seed + `{locale: [[from, to], …]}` (falls back to `en`; alias `app:ops-seed:adapt-legal`) |
| `nowo:legal-kit:export [-o file]` | stored rows as JSON |
| `nowo:legal-kit:import <file> [--dry-run]` | list or `{legal_documents: […]}`; validates, then upserts in one flush |
| `nowo:legal-kit:seed [--missing-only] [--force]` | materialise mode: store missing seed rows |
| `nowo:legal-kit:doctor [--strict]` | routes, anonymous access, CookieConsent routes / privacy link, seed markers |

## Events

| Event | When |
| --- | --- |
| `LegalPageRenderEvent` | before a public page renders: `slug`, `locale`, `contentLocale`, `document`, `alternates` (locale → absolute URL), `canonicalUrl`; `setTemplateVariable()` |
| `LegalDocumentSavedEvent` | after a save was flushed |
| `LegalDocumentRestoredEvent` | after a restore was flushed |
| `LegalDocumentsImportedEvent` | after `apply(..., flush: true)` / `nowo:legal-kit:import` |

With the AuditKit action log enabled (`nowo_audit_kit.action_log.entity_class`), saves, restores and imports are
recorded (`legal_document.saved|restored|imported`).

## CookieConsent

When CookieConsentBundle is registered the bundle prepends every legal route to `disabled_routes` (no auto-open on
legal pages, "Manage cookies" still works), adds `legal_*` to a host `render_routes` whitelist and — with
CookieConsent 1.17+ — sets `privacy_route: legal_privacy` as the modal privacy link unless the host sets it. The
cookies page's `%cookie_inventory%` renders the consent inventory, so both always match.

## SeoKit

With SeoKitBundle registered: legal pages set the SeoKit title, canonical and hreflang alternates (+ `x-default`), and
`LegalSitemapProvider` lists every page × locale. Indexing policy (`robots`) stays SeoKit's.

## Templates

### Overriding

Every template can be overridden by creating `templates/bundles/NowoLegalKitBundle/<subpath>` in the application
(REQ-TWIG-001); the application's copy **always wins** (the bundle registers the `@NowoLegalKitBundle` namespace
after the application override directory). `@NowoLegalKit/…` resolves to the same files.

| `<subpath>` | What it renders | Blocks / variables |
| --- | --- | --- |
| `public/page.html.twig` | public page (unique page) | `nowo_ui_document_title`, `nowo_ui_styles`, `nowo_ui_content` |
| `public/_nav.html.twig` | cross navigation | `current`, `locale` |
| `_footer_nav.html.twig` | footer links + Manage cookies | `locale`, `attr` |
| `_placeholder_banner.html.twig` | Ops banner | `locale`, `limit` |
| `_cookie_inventory.html.twig` | cookie table | `cookies` |
| `admin/index.html.twig` | admin matrix (list scaffold) | `nowo_ui_list_row` |
| `admin/edit.html.twig` | editor (form scaffold) | `nowo_ui_form_body`, `nowo_ui_scripts` |
| `seed/{notice,privacy,terms,cookies}.html.twig` | built-in bodies | `legal_slug`, `legal_locale` |

**Procedure.** Create the file, start it with `{% extends '@!NowoLegalKitBundle/<subpath>' %}` (the `!` prefix always
points at the bundle's file), redefine only the blocks you need, then `bin/console cache:clear`. Or point
`web_ui.templates.<page>` at your template.

**Freeze vs upgrade.** Extending the original keeps you on the bundle's markup for everything else. A full copy
(without `extends`) **freezes** that file until you delete it or merge upstream changes by hand (check
[UPGRADING.md](UPGRADING.md) on every upgrade). Prefer UiKit theme slots and configuration.

### Forms

The editor is a Symfony Form (`LegalDocumentType`, block prefix `nowo_legal_kit_document`, FormKit profile
`legal_kit`) rendered by the UiKit form scaffold with the child loop (REQ-TWIG-003 / REQ-TWIG-005); restore is a
CSRF-only form behind an `Action`. Templates never contain raw `<form>` / `<input>` / `<textarea>` / `<select>`;
`make check-no-raw-html-form` enforces it.

### Twig functions

| Function | Returns |
| --- | --- |
| `nowo_legal_kit_links(locale = null)` | `[{slug, route, url, label}]` of the pages with `nav: true` |
| `nowo_legal_kit_placeholder_hits(locale = null)` | `[{slug, locale, marker, source, kind}]` |
| `nowo_legal_kit_admin_url()` | admin index URL (null without admin UI) |
| `nowo_legal_kit_consent_widget()` | whether CookieConsent renders on this page |
| `legal_kit_content_locale(slug, locale = null)` | locale the page text is shown in (null without text) |
| `legal_kit_cookie_inventory()` | the cookie table HTML |

## Extension points

| Point | Contract |
| --- | --- |
| `LegalSeedProviderInterface` | tagged `nowo_legal_kit.seed_provider`, priority |
| `LegalTokenResolverInterface` | tagged `nowo_legal_kit.token_resolver`, priority, render time only |
| `LegalHtmlSanitizerInterface` | alias it to your service to replace the pipeline |
| `LegalPageCatalogInterface` | alias it for a dynamic catalogue |
| `LegalKitAccessCheckerInterface` | `security.access_checker` |
| Events | see [Events](#events) |
| `LegalDocumentPortability` | export / normalize / apply |

## Translations

`NowoLegalKitBundle` (admin, navigation, flashes, banner, cookie table) and `NowoLegalKitSeed` (scaffold), both in en,
es, de, nl, fr, it, pt (REQ-I18N-002). Override keys in `translations/<domain>.<locale>.yaml` (REQ-I18N-001); page
labels are `page.<slug>`.

## Naming conventions

PHP `Nowo\LegalKitBundle\…`, config root `nowo_legal_kit`, route type `nowo_legal_kit`, public routes `legal_<slug>`,
admin routes `nowo_legal_kit_admin_*`, Twig namespace `@NowoLegalKitBundle`, Twig functions `nowo_legal_kit_*` /
`legal_kit_*`, translation domains `NowoLegalKitBundle` / `NowoLegalKitSeed`, CSS classes `nowo-legal-*`, commands
`nowo:legal-kit:*`, asset package `nowo_legal_kit`, table `legal_document`.
