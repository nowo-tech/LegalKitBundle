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
  - [Brand name from the brand contract](#brand-name-from-the-brand-contract)
- [Placeholder gate](#placeholder-gate)
- [Ops-seed portability](#ops-seed-portability)
- [Versions and seed upgrades](#versions-and-seed-upgrades)
  - [Seed revision and seed base](#seed-revision-and-seed-base)
  - [Snapshots](#snapshots)
  - [Upgrading the seed](#upgrading-the-seed)
  - [Outdated copies and the compare page](#outdated-copies-and-the-compare-page)
  - [Minor correction or new version](#minor-correction-or-new-version)
- [Re-acceptance](#re-acceptance)
  - [Terms accepted, privacy acknowledged](#terms-accepted-privacy-acknowledged)
  - [Host contracts](#host-contracts)
  - [The request gate](#the-request-gate)
  - [Personal data](#personal-data)
- [Console commands](#console-commands)
- [Events](#events)
- [CookieConsent](#cookieconsent)
- [SeoKit](#seokit)
- [Templates](#templates)
  - [Overriding](#overriding)
  - [Forms](#forms)
  - [Twig functions](#twig-functions)
- [Extension points](#extension-points)
- [Using it from a DDD / hexagonal application](#using-it-from-a-ddd--hexagonal-application)
  - [Keep your domain free of the bundle](#keep-your-domain-free-of-the-bundle)
  - [Ports you implement (infrastructure adapters)](#ports-you-implement-infrastructure-adapters)
  - [Ports you call](#ports-you-call)
  - [Signals you consume](#signals-you-consume)
  - [Transactions and after-commit events](#transactions-and-after-commit-events)
  - [Example: anti-corruption adapter](#example-anti-corruption-adapter)
  - [Known limitations](#known-limitations)
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
| `%brand%` | the brand name of a `nowo-tech/brand-contracts` provider (`brand: auto`), else `brand_name` |
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

### Brand name from the brand contract

With `nowo-tech/brand-contracts` installed and a `Nowo\Brand\Contract\BrandProviderInterface` service in the app
(InstanceSettings 1.5 registers one with `nowo_instance_settings.brand.enabled: true`; a host can write its own),
`%brand%` is `Brand::$name`, read on every render (the provider memoises per request). The order is: a host token
resolver for `brand` (priority above -100), then the provider, then `brand_name`. A provider that throws is logged and
`brand_name` applies. `nowo_legal_kit.brand: off` ignores the provider.

Only the name is read: legal pages and their exports print no brand logo, so no brand asset is read, linked or inlined.
A host template that adds a logo to its legal layout prints `BrandAsset::$url` in an `<img src>` and inlines bytes only
when `BrandAsset::isInlineSafe()` is true (spec 220 §7.5).

With a provider the public page is validated by an `ETag` over the brand version and the rendered body instead of
`Last-Modified`, so a renamed brand is not answered with a 304 of the old text.

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
// after your commit: dispatch LegalDocumentsImportedEvent (AuditKit action log, your listeners)
$this->legalDocuments->afterCommit($rows);
```

`normalize()` throws `InvalidArgumentException` naming the index of the first invalid entry (unknown slug / locale,
missing title, empty body after sanitising, duplicate).

## Versions and seed upgrades

Opt-in (`versioning.enabled: true`, a literal boolean). It maps two nullable columns on `legal_document` and the
`legal_page_version` table (see [INSTALLATION](INSTALLATION.md#versioning-and-acceptance-tables)); without it nothing
changes.

### Seed revision and seed base

`seed.revision` (default: `seed.date`) names the built-in texts the host ships. Change it whenever you change your
seed texts. Every stored copy records the seed it started from: `seed_revision` and `seed_hash`
(`legalkit-seed-v1`: the body is sanitized with tokens kept, links to LegalKit pages become `%legal_link:<slug>%`,
whitespace and NBSP are collapsed). A copy is **unedited** when its hash equals its `seed_hash`.

| Write | Seed base |
| --- | --- |
| `nowo:legal-kit:seed`, a new copy saved from the editor, "Use the new text", "Keep my text" | the current revision and seed hash |
| Saving an existing copy | unchanged |
| An import equal to the current seed | the current revision and seed hash |
| Any other import | unknown (`NULL`): never overwritten automatically |

Rows created before 1.4 have an unknown base: the first upgrade needs `--from` and a snapshot.

### Snapshots

`nowo:legal-kit:snapshot-seed [--revision=<rev>] [--force]` freezes the current seed of every enabled page and locale
in `seed.snapshots_dir/<revision>/` (one directory per instance and revision, every locale inside):

```
<revision>/manifest.json         {"format": "legalkit-seed-snapshot-v1", "revision": "…", "hash": "legalkit-seed-v1",
                                  "bundle": "…", "pages": {"<slug>": {"<locale>": {"title": "…", "hash": "…"}}}}
<revision>/<slug>.<locale>.html  the sanitized seed body, tokens kept
```

Run it **before** you rewrite your seed texts, and commit the directory. A host on an older LegalKit can write the same
format with its own script: rendered page links are fine (the hash canonicalises them, so a snapshot taken under one
default locale matches copies stored under another), but other tokens (`%brand%`, `%last_updated%`, …) must stay as
tokens. `hash` is optional: the bundle recomputes every hash and treats an entry whose declared hash differs, or an
invalid / oversized / out-of-directory file, as unknown.

### Upgrading the seed

```bash
bin/console nowo:legal-kit:upgrade-seed --from=2026-09-18 --requires-acceptance [--dry-run] [--fail-on-outdated]
```

One transaction, rows re-read with a write lock; run it as a deploy step before traffic. Per stored copy:

| Result | When | Effect |
| --- | --- | --- |
| `current` | the copy already equals the new seed | base set to the current revision |
| `replaced` | the copy equals its known base (`seed_hash`, or the snapshot of its `seed_revision` / `--from`) | title and body replaced by the new seed |
| `outdated` | anything else (edited, or base unknown) | text kept; flagged in the admin |
| `no_seed` | no seed for that page and locale | untouched |

Pages without a copy already show the new seed; they report `seed_changed`, `seed_same` or `seed_unknown` (no
snapshot) for the version decision. With `--new-version` or `--requires-acceptance`, every page with a `replaced`,
`seed_changed` or `seed_unknown` locale gets **one** new version (`source: upgrade`), requiring acceptance only with
`--requires-acceptance` on pages whose mode is not `none`. Its change summary comes from the optional seed key
`<slug>.change_summary` (domain `NowoLegalKitSeed`) of each locale. Re-running publishes nothing twice.

### Outdated copies and the compare page

The admin index shows "Built-in text changed" on copies whose base is another revision. The compare page
(`…/{slug}/{locale}/compare`) has three views: **Changes** (text diff of your copy against the new built-in text),
**Side by side** (both rendered like the public page) and **Built-in changes** (old built-in → new built-in, when the
snapshot of the copy's base exists). Actions: **Keep my text** (POST + CSRF; rebases, no text change, no version) and
**Use the new text** (opens the editor prefilled; saving rebases the copy).

The diff never compares HTML: each side is projected to plain-text lines and every segment is printed autoescaped.
Large or very different texts (over 5000 lines, or more than 600 changed lines) show "use Side by side" instead.

For a dashboard: `nowo_legal_kit_outdated_count()` (one COUNT) and `nowo_legal_kit_outdated()` (`[{slug, locale,
seed_revision, compare_url}]`). They do no access check; show them only to your legal operators.

### Minor correction or new version

With versioning on, the editor asks how to publish the save:

- **Minor correction**: same version, nobody is asked again.
- **New version**: version + 1 for the **page** (all locales); on pages users accept or acknowledge, "Users must
  accept / acknowledge this version" is ticked by default and then a change summary (plain text, ≤ 2000 characters,
  this locale) is required.

On pages with a mode other than `none` nothing is preselected. The current version's summary of a locale can be
edited with a minor correction until somebody accepted or acknowledged that version; from then on it is frozen (the
save keeps the text change and warns that the summary was not changed), because the summary is part of what users
were shown. A save is refused ("changed meanwhile") when another save or version happened since the
form was opened; the check is repeated inside the save transaction (with the row locked), and the new version is
published with the number the form was based on + 1, so two concurrent publishes cannot stack. Who may choose is `LegalVersionPublisherCheckerInterface::canPublish($slug)`
(`versioning.publisher_checker`; default: the admin access checker). When it answers no, pages users accept or
acknowledge cannot be saved (403) and other pages are saved as minor corrections. The aside shows the current version
and, per locale, whether it was updated since.

## Re-acceptance

Opt-in (`acceptance.enabled: true` + `acceptance.subject_resolver`; requires versioning). It maps `legal_acceptance`
and registers the page `GET|POST <routing.path_prefix>/accept` (`nowo_legal_kit_acceptance`, plus
`nowo_legal_kit_acceptance_unlocalized`). `acceptance.enforce: true` adds the request gate.

A page's mode is `pages.<slug>.acceptance`: `accept` (default for `terms`), `acknowledge` (default for `privacy`),
`none` (everything else). A subject satisfies a page with an acceptance of a version ≥ the highest version published
with "users must accept"; version 1 counts, so new users and everybody on the day you switch enforcement on accept
once.

### Terms accepted, privacy acknowledged

| | `accept` (terms) | `acknowledge` (privacy) |
| --- | --- | --- |
| Meaning | agreement to contract terms | evidence that the updated information was shown (GDPR Arts. 12–14, accountability) |
| Page | unticked, required checkbox + "Accept and continue" | statement + "Continue", no checkbox |
| Record | `kind: accept` | `kind: acknowledge` |

An acknowledgement is never consent: do not use it as a legal basis for anything. `pages.privacy.acceptance: accept`
is refused at build time; cookie consent stays with CookieConsentBundle (`doctor` warns if `cookies` is not `none`).

### Host contracts

```php
use Nowo\LegalKitBundle\Acceptance\LegalAcceptanceSubjectResolverInterface;
use Nowo\LegalKitBundle\Acceptance\LegalAcceptanceExemptionInterface;

final class SubjectResolver implements LegalAcceptanceSubjectResolverInterface
{
    // Stable, non-personal id matching ^[A-Za-z0-9._:@-]{1,128}$ (never an e-mail); null = not subject to acceptance.
    public function resolve(UserInterface $user): ?string { return $user instanceof User ? $user->getPublicId() : null; }
}

final class MfaExemption implements LegalAcceptanceExemptionInterface // autoconfigured
{
    public function isExempt(Request $request): bool { return str_starts_with((string) $request->attributes->get('_route'), 'app_mfa_'); }
}
```

### The request gate

The gate (`kernel.request`, priority 4) redirects (302, `no-store`) to the page only when all of this holds: main
request; `GET`/`HEAD`; the request carries a session cookie (API keys and bearer tokens never are); an HTML navigation
(`Accept` lists `text/html`, not XHR, no `Turbo-Frame`, no Turbo stream, request format `html`); not exempt; a signed-in,
non-impersonated user with a subject; something pending. Built-in exemptions: every public legal route, the page
itself, the legal admin, `_wdt*`, `_profiler*`, `_error`, `_preview_error`; add `acceptance.exempt_routes` (names or
`prefix*`), `acceptance.exempt_paths` (segment-boundary prefixes) and voters. Writes are never blocked.
`acceptance.non_html: deny` answers session JSON / XHR GETs with `403 {"error": "legal_acceptance_required",
"acceptance_url": "…"}` instead of letting them through.

After a check with nothing pending, the session remembers it until a version is published (0 queries per request;
otherwise one indexed query). If the check fails (database or cache), the request passes and an error is logged:
the gate informs, it is not an access control. The original URL is kept in the session (relative paths only: no `//`, backslash,
control character or `.` / `..` segment, raw or `%`-encoded; never the page itself) and restored after accepting; else `acceptance.default_target` or `/`.

The page posts back a fingerprint of what it showed (pages, versions, text hashes). If a version or text changed
meanwhile, nothing is recorded and the page asks to review again.

### Personal data

`LegalAcceptanceManagerInterface` (public alias of `LegalAcceptanceManager`) is what your export and erasure providers call:

| Method | |
| --- | --- |
| `pendingFor(string $subjectId, ?string $locale): list<PendingLegalPage>` | pending pages with the offered text |
| `record(string $subjectId, list<PendingLegalPage>): int` | insert-only; an existing row of the same version counts as recorded |
| `recordInTransaction(string $subjectId, list<PendingLegalPage>): LegalAcceptanceBatch` | same, inside your transaction, no event until `afterCommit($batch)` ([details](#transactions-and-after-commit-events)) |
| `exportFor(string $subjectId): list<{page, version, locale, kind, accepted_at, content_hash}>` | export |
| `eraseFor(string $subjectId): int` | erasure; keeps no copy |

A record holds no IP address, user agent or free text. `LegalAcceptedEvent` carries the subject: a listener that
stores it (an audit row, for example) owns that copy's export and erasure. `acceptance.action_log: true` writes an
AuditKit `legal_document.accepted` row instead: it records the signed-in actor and, with AuditKit's default
`capture_ip: true`, the **client IP address**. `eraseFor()` does not remove those rows; erase them through AuditKit's
own personal-data erasure (or keep `action_log: false` and audit `LegalAcceptedEvent` yourself).

## Console commands

| Command | Purpose |
| --- | --- |
| `nowo:legal-kit:check-placeholders [-l xx] [-f table\|json]` | deploy gate (alias `app:legal:check-placeholders`) |
| `nowo:legal-kit:adapt-seed --replacements=map.json [-o file] [--require-clear-markers]` | `legal_documents` JSON from the seed + `{locale: [[from, to], …]}` (falls back to `en`; alias `app:ops-seed:adapt-legal`) |
| `nowo:legal-kit:export [-o file]` | stored rows as JSON |
| `nowo:legal-kit:import <file> [--dry-run]` | list or `{legal_documents: […]}`; validates, then upserts in one flush |
| `nowo:legal-kit:seed [--missing-only] [--force]` | materialise mode: store missing seed rows |
| `nowo:legal-kit:doctor [--strict]` | routes, anonymous access, CookieConsent routes / privacy link, seed markers, copies without a seed base (versioning) |
| `nowo:legal-kit:snapshot-seed [--revision=…] [--force]` | versioning: freeze the current seed (see [Snapshots](#snapshots)) |
| `nowo:legal-kit:upgrade-seed [--from=…] [--dry-run] [--new-version\|--requires-acceptance] [--fail-on-outdated]` | versioning: refresh unedited copies, flag edited ones, publish versions |
| `nowo:legal-kit:verify-acceptance-hash <slug> <locale> <hash> [--brand-name=<past name>]…` | acceptance: compare a recorded `content_hash` with the current text, with and without the anchor ids, and with each given past `%brand%` name; read-only, exit 1 when it does not match |

## Events

| Event | When |
| --- | --- |
| `LegalPageRenderEvent` | before a public page renders: `slug`, `locale`, `contentLocale`, `document`, `alternates` (locale → absolute URL), `canonicalUrl`; `setTemplateVariable()` |
| `LegalDocumentSavedEvent` | after a save was flushed; with versioning also `publication` (`minor`/`new_version`) and `version` |
| `LegalVersionPublishedEvent` | after a new version committed (the editor save and `upgrade-seed` dispatch it after their transaction): `slug`, `version`, `requiresAcceptance`, `source`, `seedRevision` |
| `LegalDocumentRebasedEvent` | after "Keep my text" |
| `LegalSeedUpgradedEvent` | after `upgrade-seed` committed: `revision`, `from`, `counts` |
| `LegalAcceptedEvent` | per recorded acceptance (after the insert committed; with `recordInTransaction()` from `afterCommit()`): `subjectId`, `slug`, `version`, `locale`, `kind`, `acceptedAt`, `contentHash` |
| `LegalDocumentRestoredEvent` | after a restore was flushed |
| `LegalDocumentsImportedEvent` | after `apply(..., flush: true)` / `nowo:legal-kit:import`, or from `afterCommit($rows)` after an `apply(..., flush: false)` |

With the AuditKit action log enabled (`nowo_audit_kit.action_log.entity_class`), saves, restores and imports are
recorded (`legal_document.saved|restored|imported`, and with versioning `version_published`, `rebased`,
`seed_upgraded`; `accepted` only with `acceptance.action_log: true`).

## CookieConsent

When CookieConsentBundle is registered the bundle prepends every legal route to `disabled_routes` (no auto-open on
legal pages, "Manage cookies" still works), adds `legal_*` to a host `render_routes` whitelist and — with
CookieConsent 1.17+ — sets `privacy_route: legal_privacy` as the modal privacy link unless the host sets it. The
cookies page's `%cookie_inventory%` renders the consent inventory (the database profile of the request, else the static
`cookie_inventory` of the CookieConsent configuration), so both always match.

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
| `_cookie_inventory.html.twig` | cookie table | `nowo_legal_kit_cookie_category_label` (`cookie`, `category_label`); `cookies` |
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
| `nowo_legal_kit_outdated_count()` | versioning: number of copies whose built-in text changed (0 when off) |
| `nowo_legal_kit_outdated()` | versioning: `[{slug, locale, seed_revision, compare_url}]` |

| Filter | Returns |
| --- | --- |
| `html\|nowo_legal_kit_sanitize_html` | safe HTML through `LegalHtmlSanitizerInterface` (use it, never `\|raw`, to print legal HTML in host templates) |

The category column of the cookie table shows `cookies.category.<key>` (domain `NowoLegalKitBundle`; shipped for
`required`, `functionality`, `preferences`, `analytics`, `marketing`, `tracking`). To change only the label:

```twig
{# templates/bundles/NowoLegalKitBundle/_cookie_inventory.html.twig #}
{% extends '@!NowoLegalKitBundle/_cookie_inventory.html.twig' %}
{% block nowo_legal_kit_cookie_category_label %}{{ ('cookies.cat_' ~ cookie.category)|trans({}, 'messages') }}{% endblock %}
```

## Extension points

| Point | Contract |
| --- | --- |
| `LegalSeedProviderInterface` | tagged `nowo_legal_kit.seed_provider`, priority |
| `LegalTokenResolverInterface` | tagged `nowo_legal_kit.token_resolver`, priority, render time only |
| `LegalHtmlSanitizerInterface` | alias it to your service to replace the pipeline |
| `LegalPageCatalogInterface` | alias it for a dynamic catalogue |
| `LegalKitAccessCheckerInterface` | `security.access_checker` |
| `LegalVersionPublisherCheckerInterface` | `versioning.publisher_checker` |
| `LegalAcceptanceSubjectResolverInterface` | `acceptance.subject_resolver` (required with acceptance) |
| `LegalAcceptanceExemptionInterface` | autoconfigured, tag `nowo_legal_kit.acceptance_exemption` |
| `LegalAcceptanceManagerInterface` | pending pages, records, `exportFor` / `eraseFor` (alias of `LegalAcceptanceManager`) |
| Events | see [Events](#events) |
| `LegalDocumentPortability` | export / normalize / apply / afterCommit |
| `LegalActorIdResolverInterface` | alias it (blame `actor_id` mode) |

## Using it from a DDD / hexagonal application

LegalKit is a **self-contained bounded context** (legal pages, page versions, acceptances) with its own tables
(`legal_document`, `legal_page_version`, `legal_acceptance`) and its own UI (public pages, admin, re-acceptance page,
request gate). Treat it as a supporting subdomain next to yours: your domain never needs to reference a LegalKit class.

### Keep your domain free of the bundle

- **Acceptances are keyed by a string subject id**, not by a relation to your user. Implement
  `LegalAcceptanceSubjectResolverInterface` in your infrastructure layer and return a stable, non-personal id
  (e.g. the public id of your user aggregate).
- **Blame columns** on `legal_document` and `legal_page_version` follow `nowo_legal_kit.doctrine.blame`:
  - `relation` (default, unchanged): `created_by_id` / `updated_by_id` are an AuditKit `ManyToOne` to
    `AuditUserInterface`, resolved to `nowo_audit_kit.user_class`. Point `user_class` at your infrastructure Doctrine
    user class (persistence model) and implement `AuditUserInterface` there, never on your domain `User`.
  - `actor_id`: nullable string columns `created_by` / `updated_by` (VARCHAR 191), no foreign key, no marker
    interface, no `resolve_target_entities`. The value comes from `LegalActorIdResolverInterface` (default: the
    signed-in user's `getId()`, else its user identifier; the security user does not have to be a Doctrine entity).
    Alias the interface to your own resolver to store your aggregate id.
  - `none`: no blame columns at all.

  See [CONFIGURATION](CONFIGURATION.md#doctrine-blame) for the schema change when you switch an existing table.
- Do not inject `LegalDocumentRepository`, `LegalAcceptanceRepository` or the entities into your application layer;
  use the ports, DTOs and events below.

### Ports you implement (infrastructure adapters)

| Port | Use |
| --- | --- |
| `LegalAcceptanceSubjectResolverInterface` | Symfony user → subject id (`acceptance.subject_resolver`) |
| `LegalActorIdResolverInterface` | actor id of the blame columns (`doctrine.blame: actor_id`; alias) |
| `LegalAcceptanceExemptionInterface` | requests never sent to the re-acceptance page |
| `LegalSeedProviderInterface`, `LegalTokenResolverInterface` | texts and `%token%` values from your own sources |
| `LegalPageCatalogInterface`, `LegalHtmlSanitizerInterface` | replace the catalogue or the sanitiser (alias) |
| `LegalKitAccessCheckerInterface`, `LegalVersionPublisherCheckerInterface` | admin / publish authorisation from your own policy |

### Ports you call

| Port | Use |
| --- | --- |
| `LegalAcceptanceManagerInterface` | `pendingFor`, `record`, `recordInTransaction` + `afterCommit`, `exportFor`, `eraseFor` (aliased to `LegalAcceptanceManager`; decorate the interface, the bundle's gate and accept page use it too) |
| `LegalDocumentResolver::resolve()` | the published text as a `ResolvedLegalDocument` DTO |
| `LegalDocumentPortability` | `normalize` / `apply` / `afterCommit` for imports |

### Signals you consume

All events carry scalars or DTOs, never entities: `LegalAcceptedEvent` (`subjectId`, `slug`, `version`, `locale`,
`kind`, `acceptedAt`, `contentHash`), `LegalVersionPublishedEvent`, `LegalDocumentSavedEvent`,
`LegalDocumentRestoredEvent`, `LegalDocumentRebasedEvent`, `LegalDocumentsImportedEvent`, `LegalSeedUpgradedEvent`,
`LegalPageRenderEvent` (with a `ResolvedLegalDocument`). Translate them into your own domain events in a listener.

### Transactions and after-commit events

- `record()` (the accept page) inserts with autocommit and dispatches `LegalAcceptedEvent` right after: the event
  follows the commit. Inside **your** transaction (sign-up: "create account + accept terms") call
  `recordInTransaction()` instead: it inserts in your transaction (one savepoint per row, so a duplicate does not
  abort it) and dispatches nothing; after your commit pass the returned `LegalAcceptanceBatch` to `afterCommit()`.
  On rollback, drop the batch: no row, no event.
- `LegalDocumentPortability::apply($rows, flush: false)` keeps your transaction open and dispatches nothing; after your
  commit call `afterCommit($rows)` to dispatch `LegalDocumentsImportedEvent` (`apply(..., flush: true)` does it itself).
- `LegalPageVersions::publish()` inside a transaction: call `LegalPageVersions::afterCommit($version)` after the commit.

### Example: anti-corruption adapter

```php
namespace App\Infrastructure\Legal;

use App\Domain\Account\AccountId;
use App\Domain\Account\TermsAccepted;          // your domain event
use App\Domain\Shared\DomainEventBus;           // your port
use Doctrine\DBAL\Connection;
use Nowo\LegalKitBundle\Acceptance\LegalAcceptanceManagerInterface;
use Nowo\LegalKitBundle\Acceptance\LegalAcceptanceSubjectResolverInterface;
use Nowo\LegalKitBundle\Event\LegalAcceptedEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Core\User\UserInterface;

final class LegalSubjectResolver implements LegalAcceptanceSubjectResolverInterface
{
    public function resolve(UserInterface $user): ?string
    {
        return $user instanceof SecurityUser ? $user->accountPublicId() : null; // never an e-mail
    }
}

final readonly class LegalAcceptanceBridge
{
    public function __construct(
        private LegalAcceptanceManagerInterface $legal,
        private Connection $connection,
        private DomainEventBus $bus,
    ) {}

    /** Port used by the application layer: does this account still have to accept something? */
    public function hasPending(AccountId $id, string $locale): bool
    {
        return $this->legal->pendingFor($id->toString(), $locale) !== [];
    }

    /** Sign-up: the account and its acceptances commit together; the events follow the commit. */
    public function registerAndAccept(AccountId $id, string $locale, callable $createAccount): void
    {
        $batch = $this->connection->transactional(function () use ($id, $locale, $createAccount) {
            $createAccount();

            return $this->legal->recordInTransaction($id->toString(), $this->legal->pendingFor($id->toString(), $locale));
        });
        $this->legal->afterCommit($batch);
    }

    /** GDPR erasure provider of the host. */
    public function erase(AccountId $id): int
    {
        return $this->legal->eraseFor($id->toString());
    }

    #[AsEventListener]
    public function onAccepted(LegalAcceptedEvent $event): void
    {
        $this->bus->publish(new TermsAccepted($event->subjectId, $event->slug, $event->version, $event->acceptedAt));
    }
}
```

### Known limitations

- `LegalDocumentResolver` and `LegalDocumentPortability` are final classes without an interface; wrap them in your
  own port if you need to replace them.
- `eraseFor()` and `nowo:legal-kit:seed` dispatch no event.
- `LegalPageVersions::latest()` / `publish()` return the `LegalPageVersion` entity; keep them inside your adapter.
- Hosts that declare `doctrine.orm.entity_managers` must register the bundle mappings themselves; the admin editor
  uses the default entity manager.

## Translations

`NowoLegalKitBundle` (admin, navigation, flashes, banner, cookie table) and `NowoLegalKitSeed` (scaffold), both in en,
es, de, nl, fr, it, pt (REQ-I18N-002). Override keys in `translations/<domain>.<locale>.yaml` (REQ-I18N-001); page
labels are `page.<slug>`.

## Naming conventions

PHP `Nowo\LegalKitBundle\…`, config root `nowo_legal_kit`, route type `nowo_legal_kit`, public routes `legal_<slug>`,
admin routes `nowo_legal_kit_admin_*`, Twig namespace `@NowoLegalKitBundle`, Twig functions `nowo_legal_kit_*` /
`legal_kit_*`, translation domains `NowoLegalKitBundle` / `NowoLegalKitSeed`, CSS classes `nowo-legal-*`, commands
`nowo:legal-kit:*`, asset package `nowo_legal_kit`, table `legal_document`.
