# Configuration

## Table of contents

- [Full reference](#full-reference)
- [Locales](#locales)
- [Pages](#pages)
- [Routing](#routing)
- [Seed](#seed)
- [Editor](#editor)
- [Sanitizer](#sanitizer)
- [Placeholders](#placeholders)
- [Public pages and cache](#public-pages-and-cache)
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
    brand_name: ''                        # %brand% token ('' = unresolved, reported by the gate)
    pages:                                # ordered; defaults shown
        notice:  { path: notice,  enabled: true, nav: true, seed_template: null }
        privacy: { path: privacy, enabled: true, nav: true, seed_template: null }
        terms:   { path: terms,   enabled: true, nav: true, seed_template: null }
        cookies: { path: cookies, enabled: true, nav: true, seed_template: null }
    routing:
        path_prefix: /legal
        default_locale_style: unprefixed  # unprefixed | prefixed
        legacy_admin_aliases: true        # admin_legal_* (deprecated, removed in 2.0)
    seed:
        materialize: false                # nowo:legal-kit:seed stores the seed as rows
        fallback_locale: null             # e.g. es
        date: '2026-10-18'                # %last_updated% of seed pages
    editor:
        type: auto                        # auto | ckeditor5 | textarea
        ckeditor5_profile: legal
    sanitizer:
        outbound_url_guard: auto          # auto | true | false
        ckeditor5_first_pass: false       # true: CKEditor strict sanitiser before the allowlist
        allowed_elements: {}              # element => [attributes], merged into the allowlist
    placeholders:
        builtin_markers: true
        extra_markers: []
    public:
        layout_template: null             # null = nowo_ui_layout.guest
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

## Editor

`auto` uses CKEditor 5 when `nowo-tech/ckeditor5-editor-bundle` is registered (the bundle prepends a `legal` profile:
`standard` preset, toolbar, 420 px), else a textarea. The CKEditor script loads on the edit page only.

## Sanitizer

The DOM allowlist keeps p, br, strong, b, em, i, u, s, h1–h4, ul, ol, li, blockquote, a, img, table / thead / tbody /
tr / th / td, hr, span, div, section, article, header, footer, address, figure, figcaption, code, pre, mark, sup, sub
with `class` (and per element: `href`, `title`, `rel`, `target`, `data-nowo-open-consent`, `src`, `alt`, `width`,
`height`, `loading`, `colspan`, `rowspan`, `scope`). `allowed_elements` adds elements / attributes; `style`, `on*`,
`srcdoc`, `formaction` and the removed elements (script, style, iframe, object, embed, svg, math, form controls,
template, noscript…) can never be allowed. `outbound_url_guard: auto` drops private-network and cloud-metadata targets
when OutboundUrlGuardBundle is registered; `ckeditor5_first_pass: true` runs CKEditor's strict sanitiser first (off by
default: the allowlist is the stricter one, and CKEditor's pass would drop the `data-nowo-open-consent` opener).

## Placeholders

`builtin_markers` are the 29 markers of the shipped seed (`privacy@example.com` + 4 per locale). Seeds with a real
identity set `builtin_markers: false` and list their own `extra_markers`. Unresolved `%tokens%` are always reported.

## Public pages and cache

`Cache-Control: private` by default; `shared_max_age > 0` makes the response public with `s-maxage`. `Last-Modified`
follows the stored row. `layout_template` replaces the UiKit guest layout for these pages only.

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
