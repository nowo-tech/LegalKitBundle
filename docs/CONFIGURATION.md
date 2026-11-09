# Configuration

## Table of contents

- [Full reference](#full-reference)
- [Locales](#locales)
- [Pages](#pages)
- [Routing](#routing)
- [Seed](#seed)
- [Versioning](#versioning)
- [Acceptance](#acceptance)
- [Editor](#editor)
- [Sanitizer](#sanitizer)
- [Placeholders](#placeholders)
- [Public pages and cache](#public-pages-and-cache)
  - [Public header (1.7, MUX-150)](#public-header-17-mux-150)
- [Admin UI and security](#admin-ui-and-security)
- [Integrations](#integrations)
- [Console](#console)
- [Doctrine table prefix](#doctrine-table-prefix)
- [Timeouts](#timeouts)

The tree is strict (unknown keys fail the container build) and validated at compile time (REQ-SF-006).

## Full reference

```yaml
nowo_legal_kit:
    locales: [en]                         # list, "%parameter%", "en,es", "en|es" or a JSON list
    default_locale: null                  # null = first locale
    brand_name: ''                        # %brand% token ('' = unresolved, reported by the gate); fallback of `brand`
    brand: auto                           # auto | off (or {mode: auto|off}); auto: %brand% from a brand-contracts provider
    pages:                                # ordered; defaults shown
        notice:  { path: notice,  enabled: true, nav: true, seed_template: null, acceptance: none }
        privacy: { path: privacy, enabled: true, nav: true, seed_template: null, acceptance: acknowledge }
        terms:   { path: terms,   enabled: true, nav: true, seed_template: null, acceptance: accept }
        cookies: { path: cookies, enabled: true, nav: true, seed_template: null, acceptance: none }
    routing:
        path_prefix: /legal
        default_locale_style: unprefixed  # unprefixed | prefixed
        legacy_admin_aliases: true        # admin_legal_* (deprecated, removed in 2.0)
    seed:
        materialize: false                # nowo:legal-kit:seed stores the seed as rows
        fallback_locale: null             # e.g. es
        date: '2026-10-18'                # %last_updated% of seed pages
        revision: null                    # versioning: seed revision (null = date)
        snapshots_dir: '%kernel.project_dir%/config/legal-kit/seed-snapshots'
    versioning:
        enabled: false                    # literal boolean; maps seed_revision / seed_hash + legal_page_version
        publisher_checker: null           # service id implementing LegalVersionPublisherCheckerInterface
    acceptance:
        enabled: false                    # literal boolean; requires versioning; maps legal_acceptance
        enforce: false                    # request gate
        subject_resolver: null            # REQUIRED when enabled: LegalAcceptanceSubjectResolverInterface service id
        exempt_routes: []                 # route names or "prefix*"
        exempt_paths: []                  # "/api" matches /api and /api/…, not /apix
        non_html: pass                    # pass | deny (403 JSON for session JSON / XHR GETs)
        default_target: null              # route name after accepting without a stored target; null = "/"
        logout_route: null                # null = the firewall logout path (none without one)
        layout_template: null             # null = nowo_ui_layout.guest
        template: '@NowoLegalKitBundle/acceptance/interstitial.html.twig' # or acceptance/compact.html.twig (one card)
        cache_pool: cache.app             # requirements map; null = no cache
        action_log: false                 # AuditKit legal_document.accepted per acceptance
    editor:
        type: auto                        # auto | ckeditor5 | textarea
        ckeditor5_profile: legal
    sanitizer:
        outbound_url_guard: auto          # auto | true | false
        ckeditor5_first_pass: false       # true: CKEditor strict sanitiser before the allowlist
        allowed_elements: {}              # element => [attributes], merged into the allowlist
        heading_anchors: []               # anchors: class legal-anchor--<anchor> on a heading gets id="<anchor>"
    placeholders:
        builtin_markers: true
        extra_markers: []
    public:
        layout_template: null             # null = nowo_ui_layout.guest
        prose_class: null                 # extra classes of the article, e.g. nowo-legal-doc--typography
        header:                           # 1.7 (MUX-150); `header: true` is a shorthand for enabled
            enabled: false                # brand linked home + sign-in link above the article
            logo: true                    # the brand provider's light logo (<img>) instead of the name
            home: /                       # route name, root-relative path or http(s) URL; null = brand not linked
            sign_in: null                 # route name, root-relative path or http(s) URL; null = no link
        cache: { max_age: 0, shared_max_age: 0 }
    web_ui:
        enabled: true
        path_prefix: /admin/legal
        layout_template: null             # null = nowo_ui_layout.admin
        templates:
            index: '@NowoLegalKitBundle/admin/index.html.twig'
            edit: '@NowoLegalKitBundle/admin/edit.html.twig'
            public_page: '@NowoLegalKitBundle/public/page.html.twig'
    security:
        access_roles: [ROLE_ADMIN]
        access_checker: null              # service id implementing LegalKitAccessCheckerInterface
        allow_unauthenticated: false      # DEV / DEMO ONLY
    seo: { sitemap: auto, head: auto }
    cookie_consent: { prepend_routes: auto }
    console: { legacy_aliases: true }
    doctrine: { table_prefix: '' }
```

## Locales

`locales` accepts a list or the value of a parameter / env var (`'%app.locales%'`, `'%kernel.enabled_locales%'`,
`'%env(csv:APP_LOCALES)%'`). Codes match `^[a-z]{2,3}([_-][A-Za-z0-9]{2,4})?$` (the column holds 8 characters).
Admin tabs, scanners and exports list them **default locale first**. A locale without seed text in the bundle (other
than en es de nl fr it pt) needs `seed.fallback_locale` or `NowoLegalKitSeed` keys in the host.

## Pages

Ordered map `slug => options`. Slugs: lowercase letters, digits and `-`, at most 32 characters. Extra pages (for
example `accessibility`) need a seed: `seed_template` and the `NowoLegalKitSeed` key `<slug>.title` (or a seed
provider), plus the label `page.<slug>` in the `NowoLegalKitBundle` domain.

| Option | Default | Meaning |
| --- | --- | --- |
| `path` | slug | URL segment after `routing.path_prefix` |
| `enabled` | `true` | `false` removes the page (routes, admin, scanner) |
| `nav` | `true` | listed by `nowo_legal_kit_links()` and the footer / cross navigation |
| `seed_template` | `@NowoLegalKitBundle/seed/<slug>.html.twig` | Twig template of the built-in body |
| `acceptance` | `accept` (terms), `acknowledge` (privacy), `none` | re-acceptance mode (only with `acceptance.enabled`). `privacy: accept` is refused: privacy information is acknowledged, never accepted |

## Routing

| Style | Routes | Behaviour |
| --- | --- | --- |
| `unprefixed` (default) | `legal_<slug>` `/{_locale}/legal/<path>`, `legal_<slug>_unlocalized` `/legal/<path>` | the default locale lives on the bare path; `/{default}/legal/<path>` answers **301** (query kept). With RoutingKit's `default_locale_unprefixed`, its router generates the twins and its subscriber redirects |
| `prefixed` | `legal_<slug>`, `legal_<slug>_bare` `/legal/<path>` | every locale prefixed; the bare path answers **302** to the default locale |

## Seed

The seed is the generic scaffold rendered by `TwigSeedProvider` (domain `NowoLegalKitSeed`). The seed has its own translation domain on purpose (REQ-I18N-003 exception): it holds long legal scaffold text that hosts override key by key or drop entirely, while the UI strings stay in `NowoLegalKitBundle`; keeping them apart lets a host replace the whole seed without touching the admin / page UI catalogue. `materialize: true`
reproduces "rows seeded at install" (`bin/console nowo:legal-kit:seed --missing-only`, never overwrites). Restore
deletes the row in both modes. `fallback_locale` shows that locale's seed when none exists for the requested one (the
page then declares `lang` = the fallback locale, request attribute `_content_locale`).

## Versioning

Opt-in, see [USAGE: Versions and seed upgrades](USAGE.md#versions-and-seed-upgrades). `versioning.enabled` must be the
literal `true` (the Doctrine mapping is decided before parameters and env vars resolve). `seed.revision` (pattern
`^[A-Za-z0-9][A-Za-z0-9._-]{0,31}$`) defaults to `seed.date`; change it with your seed texts.

## Acceptance

Opt-in, see [USAGE: Re-acceptance](USAGE.md#re-acceptance). Build-time errors: `acceptance.enabled` without
`versioning.enabled` or without `subject_resolver`; `enforce` without `enabled`; a page whose `path` is `accept`
(the re-acceptance page lives at `<routing.path_prefix>/accept`); `exempt_paths` entries that do not start with `/`
or contain wildcards, `?`, `#` or `..`.

## Editor

`auto` uses CKEditor 5 when `nowo-tech/ckeditor5-editor-bundle` is registered (the bundle prepends a `legal` profile:
`standard` preset, toolbar, 420 px), else a textarea. The CKEditor script loads on the edit page only.

## Sanitizer

The DOM allowlist keeps p, br, strong, b, em, i, u, s, h1–h4, ul, ol, li, blockquote, a, img, table / thead / tbody /
tr / th / td, hr, span, div, section, article, header, footer, address, figure, figcaption, code, pre, mark, sup, sub
with `class` (and per element: `href`, `title`, `rel`, `target`, `data-nowo-open-consent`, `src`, `alt`, `width`,
`height`, `loading`, `colspan`, `rowspan`, `scope`). `allowed_elements` adds elements / attributes (an invalid attribute name fails the build); `style`, `on*`,
`srcdoc`, `formaction` and the removed elements (script, style, iframe, object, embed, svg, math, form controls,
template, noscript…) can never be allowed. `outbound_url_guard: auto` drops private-network and cloud-metadata targets
when OutboundUrlGuardBundle is registered; `ckeditor5_first_pass: true` runs CKEditor's strict sanitiser first (off by
default: the allowlist is the stricter one, and CKEditor's pass would drop the `data-nowo-open-consent` opener).
A body with an estimated element nesting above 512 is refused: the page answers 404, see [UPGRADING](UPGRADING.md#unreleased).

`heading_anchors` (default empty: no `id` is ever printed) gives legal pages stable deep links such as
`/legal/privacy#retention`, which the allowlist otherwise cannot keep because every input `id` is dropped (DOM
clobbering). The text marks an `h1`–`h4` or `section` (or `h5`, `h6` if `allowed_elements` adds them with `class`) with the class `legal-anchor--<anchor>`; when `<anchor>` is in the
list, the element gets `id="<anchor>"` as its first attribute. Names outside the list are ignored and each name is
printed once (first occurrence in document order). Names match `^[a-z][a-z0-9_-]{0,63}$`. Pick names your layout does not
use as ids: the bundle cannot see the layout. See [UPGRADING](UPGRADING.md#unreleased) if stored copies already carry
these classes.

## Placeholders

`builtin_markers` are the 29 markers of the shipped seed (`privacy@example.com` + 4 per locale). Seeds with a real
identity set `builtin_markers: false` and list their own `extra_markers`. Unresolved `%tokens%` are always reported.

## Public pages and cache

`Cache-Control: private` by default; `shared_max_age > 0` makes the response public with `s-maxage`. `Last-Modified`
follows the stored row. `layout_template` replaces the UiKit guest layout for these pages only.

`public.prose_class` adds classes to the public `<article class="nowo-legal-doc">`: the host's own prose classes, or
`nowo-legal-doc--typography`, the bundle's document typography (loaded with `css/nowo-legal-kit.css`):

| Token | Without a value | Applies to |
| --- | --- | --- |
| `--nowo-legal-doc-h1-size` | `1.875rem` | `h1` |
| `--nowo-legal-doc-h2-size` | `1.125rem` | `h2` |
| `--nowo-legal-doc-list-indent` | `1.25rem` | `ul`, `ol` |
| `--nowo-legal-doc-gap` | `0.5rem` | below `h1` and `h2`, around lists (above `h2`: 3.5 times the gap) |

The tokens only act under that class, so a host that sets nothing keeps its own heading and list styles.

### Public header (1.7, MUX-150)

`public.header.enabled: true` prints `public/_header.html.twig` in the layout's `nowo_ui_page_header` block, before
the article (and before whatever the layout itself puts there): the brand linked to `home` and, when `sign_in` is set,
a sign-in link (UiKit slot `btn_secondary`). Off by default, so the 1.6 markup is unchanged.

- **Brand:** the brand provider's name (spec 220, with `brand: auto` and a `BrandProviderInterface` service), else
  `brand_name`, else the `header.home` label. Always auto-escaped. With `logo: true` and a provider whose brand has a
  `logo_light` asset, an `<img class="nowo-legal-header__logo" src="…" alt="{brand}">` replaces the name; the URL is the
  contract's (a root-relative path or `https://`), checked again with `BrandAsset::isSafeUrl()` and escaped. Logos are
  never inlined or fetched.
- **Links:** `home` and `sign_in` accept a route name (`[A-Za-z0-9_.-]`, generated without parameters), a
  root-relative path (`/…`, not `//`) or an `http(s)://` URL. Anything else fails the configuration: other schemes
  (`javascript:`, `data:`, `mailto:`), quotes, angle brackets, backslashes, backticks, whitespace. A route that does not
  exist at run time is logged (warning) and its link is left out; the page never fails.
- **Signed-in users:** no sign-in link when the security token storage holds a user.
- **Cache:** with a brand provider the page keeps its brand ETag. With `public.cache.shared_max_age > 0` and a
  `sign_in` target, a request with a signed-in user (even without a session: HTTP basic, stateless authenticators)
  is answered `Cache-Control: private`, so a shared cache only ever stores the guest variant. A guest variant cached
  earlier can still show *Sign in* to a member who reaches the page through that cache; the link is harmless there.
- **Styles:** `nowo-legal-header`, `__brand`, `__logo`, `__name`, `__nav`, `__sign-in` in `css/nowo-legal-kit.css`
  (44px tap targets, wraps on phones); token `--nowo-legal-header-logo-height` (default `2rem`).

UiKit 2.41 adds a generic guest header region (`nowo_ui_guest_header`); a host on that version may use it from its own
layout instead and leave this option off.

## Admin UI and security

REQ-UI-002 vocabulary. `allow_unauthenticated: true` is for demos only; with the admin UI enabled and no
SecurityBundle the build fails unless it is set. Host firewall rule:

```yaml
security:
    access_control:
        - { path: ^/admin/legal, roles: ROLE_ADMIN }
```

## Integrations

| Key | `auto` means |
| --- | --- |
| `seo.sitemap` | register `LegalSitemapProvider` (every page × locale, hreflang) when SeoKit is registered |
| `seo.head` | set SeoKit title, canonical and hreflang alternates on legal pages |
| `cookie_consent.prepend_routes` | prepend every legal route to `nowo_cookie_consent.disabled_routes`, add `legal_*` to a host `render_routes` whitelist and `privacy_route: legal_privacy` (CookieConsent 1.17+) unless the host sets them |

## Console

`legacy_aliases: true` registers `app:legal:check-placeholders` and `app:ops-seed:adapt-legal` (removed in 2.0).

## Doctrine table prefix

`doctrine.table_prefix: app_` → table `app_legal_document`, indexes `app_uniq_legal_document_slug_locale`,
`app_idx_legal_document_created_by`, `app_idx_legal_document_updated_by` (REQ-ORM-002). Adopters of an existing table
keep it empty.

## Timeouts

The bundle makes no outbound HTTP request and starts no process (REQ-RUNTIME-001 not applicable); OutboundUrlGuard is
used without DNS resolution.
