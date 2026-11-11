# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Table of contents

- [[Unreleased]](#unreleased)
- [[1.9.0] - 2026-11-10](#190---2026-11-10)
  - [Added](#added)
- [[1.8.0] - 2026-11-09](#180---2026-11-09)
  - [Added](#added-1)
  - [Fixed](#fixed)
- [[1.7.0] - 2026-11-09](#170---2026-11-09)
  - [Added](#added-2)
- [[1.6.0] - 2026-11-09](#160---2026-11-09)
  - [Added](#added-3)
  - [Changed](#changed)
  - [Security](#security)
- [[1.5.0] - 2026-11-06](#150---2026-11-06)
  - [Added](#added-4)
  - [Changed](#changed-1)
  - [Security](#security-1)
- [[1.4.1] - 2026-11-05](#141---2026-11-05)
  - [Security](#security-2)
- [[1.4.0] - 2026-11-04](#140---2026-11-04)
  - [Added](#added-5)
  - [Changed](#changed-2)
- [[1.3.2] - 2026-11-04](#132---2026-11-04)
  - [Security](#security-3)
- [[1.3.1] - 2026-10-30](#131---2026-10-30)
  - [Fixed](#fixed-1)
- [[1.3.0] - 2026-10-28](#130---2026-10-28)
  - [Security](#security-4)
- [[1.2.0] - 2026-10-28](#120---2026-10-28)
  - [Security](#security-5)
  - [Changed](#changed-3)
- [[1.1.1] - 2026-10-22](#111---2026-10-22)
  - [Changed](#changed-4)
- [[1.1.0] - 2026-10-20](#110---2026-10-20)
  - [Added](#added-6)
  - [Changed](#changed-5)
  - [Fixed](#fixed-2)
  - [Security](#security-6)
- [[1.0.1] - 2026-10-18](#101---2026-10-18)
  - [Fixed](#fixed-3)
- [[1.0.0] - 2026-10-18](#100---2026-10-18)
  - [Added](#added-7)

## [Unreleased]

Target 1.10.0. Architecture bar (standard Symfony and DDD hosts, rules 1, 3, 4, 6 and 7; audit 2026-11-10).

### Added

- **`doctrine.entity_manager`** (default `null`, unchanged behaviour): maps the bundle entities on that manager only
  (`DoctrineOrmMappingsPass` + `EntityManagerMappingPass`, also removing them from a default manager with
  `auto_mapping: true`), wires every bundle service that takes an entity manager to it and fails the build when the
  manager is missing or does not map the entities. The default manager is then never flushed by the bundle. The build
  also fails when the option is combined with the default `doctrine.blame: relation` (use `actor_id` or `none`), when
  it names the default manager (`doctrine.default_entity_manager`), or when that manager maps other classes too: it
  must be a manager that maps only the bundle entities.
  `OwnEntityManagerSubscriber` resets that manager when a failed write closed it (at the next request, console command
  or Messenger message) and clears it at the end of each, so a kept-alive worker without the services resetter
  recovers; it is wired only once the build has checked the manager is the bundle's own, so the host's managers are
  never reset nor cleared.
- `Versioning\LegalPageVersionsInterface` (public alias of `LegalPageVersions`) with the `LegalPageVersionView` DTO:
  `latestVersion()`, `publishVersion(..., bool $flush = true)` (`false` = persist only, the host flushes and calls
  `afterPublish()`), `afterPublish()`.
- `Event\LegalAcceptancesErasedEvent` (`subjectId`, `count`) from `eraseFor()`, after the commit;
  `nowo:legal-kit:seed` dispatches `LegalDocumentsImportedEvent` with the rows it stored.
- `Blame\LegalActorId`: pass `new LegalActorId('…')` as the `$actor` of `LegalDocumentPortabilityInterface::apply()`
  to write the actor-id columns without a user object.
- `Doctrine\AfterCommitEventDispatcher` + `AfterCommitMiddleware` (DBAL middleware on the bundle's connection only):
  events dispatched after the outermost commit, dropped on rollback (`kernel.reset` clears pending events).

### Changed

- `LegalAcceptanceManager::record()` writes the batch in one transaction of its own when none is open (all pages or
  none; before, each row was autocommitted), and inside a host transaction its `LegalAcceptedEvent`s now wait for the
  outermost commit (dropped on rollback) instead of being dispatched before it. The bundle wires the acceptance
  manager's `$dispatcher` to the after-commit dispatcher.
- Requires `doctrine/dbal ^4.0` (the after-commit middleware uses the DBAL 4 driver API). `doctrine/orm` 3 still
  allows DBAL 3, so hosts on DBAL 3 stay on 1.9.x: Composer will not offer 1.10.

### Deprecated

- `LegalPageVersions::latest()`, `publish()` and `afterCommit()` (entity-typed): use the `LegalPageVersionsInterface`
  methods. Removed in 2.0.
- Passing a user object as the `$actor` of `LegalDocumentPortabilityInterface::apply()`: pass a `LegalActorId`.
- `LegalDocumentResolverInterface::effective()` as a public API (takes the entity; bundle-internal in 2.0).

## [1.9.0] - 2026-11-10

Target: 1.9.0 (DDD / hexagonal P2 round). See [UPGRADING](UPGRADING.md#to-190).

### Added

- `LegalDocumentResolverInterface` (alias of `LegalDocumentResolver`) and `LegalDocumentPortabilityInterface`
  (public alias of `LegalDocumentPortability`): the published-text and import / export ports. Both classes stay
  `final` and implement them; the public page, admin, seed compare, acceptance manager, placeholder scanner and the
  doctor, verify-hash, export and import commands now type-hint the interfaces, so a host alias or decorator applies
  to them too. Neither port checks access (unchanged).

## [1.8.0] - 2026-11-09

Target: 1.8.0 (DDD / hexagonal adapter fixes, audit 2026-11-09). See [UPGRADING](UPGRADING.md#to-180).

### Added

- `LegalAcceptanceManagerInterface` (public alias of `LegalAcceptanceManager`): the acceptance port (`pendingFor`,
  `record`, `recordInTransaction`, `afterCommit`, `exportFor`, `eraseFor`). The re-acceptance gate and page depend on
  it, so a host decorator applies to them too.
- `LegalAcceptanceManager::recordInTransaction()` + `afterCommit(LegalAcceptanceBatch ...)`: record acceptances
  inside the host transaction (sign-up flows) and dispatch `LegalAcceptedEvent` only after the host commit; a
  rollback leaves no row and no event. New DTO `LegalAcceptanceBatch`.
- `LegalDocumentPortability::afterCommit($rows)`: dispatches `LegalDocumentsImportedEvent` after the host committed an
  `apply(..., flush: false)` (before, that path never dispatched the event, so no AuditKit action-log row either).
- `nowo_legal_kit.doctrine.blame: relation|actor_id|none` (default `relation`, unchanged): `actor_id` maps string
  columns `created_by` / `updated_by` filled from the new `LegalActorIdResolverInterface` port (default
  `SecurityLegalActorIdResolver`, works with security users that are no Doctrine entity); `none` drops the blame
  columns. Neither needs `AuditUserInterface` nor `resolve_target_entities`. `doctrine.accepted_user_classes`
  (default `[]` = any actor, one identity domain) limits the recorded actors to the listed classes (audit JX-L1);
  ids ≤ 0 and blank ids are never recorded. Without a signed-in user (CLI) the row keeps its values; a signed-in user
  that cannot be recorded writes null (`LegalActorIdResolverInterface::hasCurrentActor()`, JX-L4).
- Docs: USAGE section "Using it from a DDD / hexagonal application"; CONFIGURATION "Doctrine blame".

### Fixed

- `record()` / `recordInTransaction()` called inside an open transaction insert each row in a savepoint: a duplicate
  row (double submit) no longer aborts the caller's transaction on PostgreSQL.

## [1.7.0] - 2026-11-09

Target: 1.7.0 (MUX-150, mobile UX audit 2026-11-09). See [UPGRADING](UPGRADING.md#unreleased).

### Added

- Optional header on the public legal pages (`nowo_legal_kit.public.header`, off by default): the brand linked to
  the home page and a sign-in link, so a visitor who lands on a legal page has a way back besides the browser. The
  brand is the brand provider's name (spec 220, `LegalBrand`), else `brand_name`. With `logo: true` (the default)
  the provider's light logo prints as an `<img src>` (alt = the brand name); the URL is checked with
  `BrandAsset::isSafeUrl()` again and escaped, and is never inlined. `home` (default `/`) and `sign_in` (default
  none) take a route name, a root-relative path or an `http(s)://` URL; any other scheme (`javascript:`, `data:`…),
  protocol-relative URLs, quotes, angle brackets, backslashes and whitespace fail the configuration. An unknown route
  is logged and its link left out. No sign-in link for a signed-in user; that variant is always `Cache-Control:
  private`, also with `public.cache.shared_max_age` and no session, so a shared cache never serves it to guests.
  With the header off the output is byte-identical to 1.6. `public.header: true` is a shorthand.
- `Header\LegalPublicHeader`, the `legal_header` variable of `public/page.html.twig`, the partial
  `public/_header.html.twig` (printed in `nowo_ui_page_header`, before the layout's own page header), the classes
  `nowo-legal-header*`, the token `--nowo-legal-header-logo-height` and the translations `header.home`,
  `header.sign_in`, `header.account` (7 locales).

## [1.6.0] - 2026-11-09

Target: 1.6.0 (spec 220 S5). See [UPGRADING](UPGRADING.md#unreleased).

### Added

- `%brand%` from the brand contract: when the app has a `nowo-tech/brand-contracts` `BrandProviderInterface` service
  (for example InstanceSettings 1.5 with `brand.enabled: true`), the token is the brand name. `brand_name` stays the
  fallback without a provider, when the provider fails and with `brand: off`.
- `nowo_legal_kit.brand` (`auto` default, or `off`; also `{mode: …}`).
- `nowo:legal-kit:verify-acceptance-hash --brand-name=<name>` (repeatable): also checks the text rendered with a past
  `%brand%` name, for acceptances recorded before a brand rename (audit IW-L1). `LegalTokenContext::$brandName` and
  the optional `$brandName` argument of `LegalDocumentResolver::resolve()` carry it.
- `nowo-tech/brand-contracts` in `suggest` (optional; `conflict` below 1.0 and from 2.0).

### Changed

- With a brand provider, the public page is validated by an `ETag` (`"<brand version>-<body hash>"`) instead of
  `Last-Modified`, so a renamed brand is never answered with a 304 of the old text. Without a provider the headers do
  not change.

### Security

- The brand name is plain text: escaped in the body by the token renderer and auto-escaped in titles. A failing provider
  is logged (warning, or error for a PHP `Error`) and never reaches the page. No brand image is read, inlined or linked.
  Audit: Appendix IW Pass-Low; the IW-L1 follow-up (`--brand-name`) is re-verification pending.

## [1.5.0] - 2026-11-06

### Added

- `sanitizer.heading_anchors` (default empty): an allowlist of anchors; an `h1`–`h4` or `section` with the class
  `legal-anchor--<anchor>` gets `id="<anchor>"`, first occurrence only (`h5`, `h6` if `allowed_elements` adds them with
  `class`). Every input `id` is still dropped.
- `@NowoLegalKitBundle/acceptance/compact.html.twig`: the re-acceptance page as one card that fits a phone, opt-in
  through `acceptance.template`. The default page does not change.
- `public.prose_class` (default null): extra classes of the public article, and `nowo-legal-doc--typography` with the
  tokens `--nowo-legal-doc-h1-size`, `-h2-size`, `-list-indent` and `-gap`. Nothing applies without the class.
- `nowo:legal-kit:verify-acceptance-hash <slug> <locale> <hash>` (with `acceptance.enabled`): compares a recorded
  `content_hash` with the current text, with and without the anchor ids. Read-only.

### Changed

- Snapshots and stored `seed_hash` values also match when the only difference is the anchor ids, so turning
  `sanitizer.heading_anchors` on does not publish a new version of unchanged text.
- `sanitizer.allowed_elements` refuses an invalid attribute name at container build.
- A page path with a trailing newline is refused at container build.

### Security

- `sanitizer.heading_anchors` is the only way an `id` reaches sanitised output. See
  [SECURITY](SECURITY.md#threat-model-and-mitigations). Audited: Appendix HB, Pass (good) / Low, re-verified at `2b0c525`.
  Read [UPGRADING](UPGRADING.md#to-150) before turning it on for text that already carries the classes (legal evidence).
- `xmlns` and `xmlns:*` attributes no longer survive the allowlist on the libxml parser (inert, values stayed escaped).
- A body with an estimated element nesting above 512 is refused, which stops the plain cases of very deep text crashing
  PHP 8.4 on the HTML5 parser. It is an estimate: purpose-built text from an administrator or a seed can still crash
  PHP 8.4.
- A stored row or seed with no text left after the sanitiser is no longer a blank page that users could accept: the page
  answers 404, an error is logged, nobody is asked to accept it, and `nowo:legal-kit:doctor` reports it.
- A seed snapshot entry with no declared hash, or no text left after the sanitiser, counts as unknown.

## [1.4.1] - 2026-11-05

Patch release. See [UPGRADING](UPGRADING.md#to-141).

### Security

- **Re-acceptance target: dot segments refused (SEC-LEGAL-TARGET-DOTSEG).** `RelativeTargetValidator` now refuses
  `.` / `..` segments (also `%2e` / `%2E`-encoded, either case), `//` anywhere in the path, `%5c` backslashes and
  encoded control characters, checking the path raw and once percent-decoded. Browsers normalise `/..//evil.example`
  to `//evil.example`; such targets are refused (fallback to `acceptance.default_target` or `/`), never normalised.
  The encoded form of the re-acceptance page is refused too. One unit case per form.
- README: the re-acceptance gate redirects HTML navigations only (XHR / `fetch` / JSON / Turbo pass unless
  `acceptance.non_html: deny`); it is a UX gate, not an access control (SEC-LEGAL-GATE-NONHTML).

## [1.4.0] - 2026-11-04

### Added

- **Versions and seed upgrades (opt-in, `versioning.enabled`).** Stored copies record the seed they started from
  (`legal_document.seed_revision` / `seed_hash`, mapped only when enabled); a version per page
  (`legal_page_version`); `seed.revision` (default `seed.date`) and `seed.snapshots_dir`.
  - `nowo:legal-kit:snapshot-seed` freezes the current built-in texts (`legalkit-seed-snapshot-v1`: manifest + one
    sanitized body per page and locale; documented so hosts on older versions can produce it).
  - `nowo:legal-kit:upgrade-seed [--from] [--dry-run] [--new-version|--requires-acceptance] [--fail-on-outdated]`
    replaces copies that still equal their base, flags edited ones, publishes one version per changed page; one
    transaction, write-locked rows, idempotent. A copy whose base is unknown is never overwritten.
  - Seed hash `legalkit-seed-v1`: sanitized body with tokens kept, links to LegalKit pages canonicalised (a snapshot
    taken under one default locale matches copies stored under another), whitespace / NBSP collapsed.
  - Admin: "Built-in text changed" badge, compare page (text diff, side by side, built-in changes from the snapshot),
    "Keep my text" (POST + CSRF) and "Use the new text"; the editor asks for *minor correction* or *new version*
    (no preselection where users accept), "users must accept this version" and a per-locale change summary;
    optimistic guard; `LegalVersionPublisherCheckerInterface` (`versioning.publisher_checker`).
  - Twig `nowo_legal_kit_outdated_count()` / `nowo_legal_kit_outdated()`; the public page shows "Version N".
- **Re-acceptance (opt-in, `acceptance.enabled` / `acceptance.enforce`).** `pages.<slug>.acceptance`
  (`accept` for terms, `acknowledge` for privacy, `none`); `legal_acceptance` records (subject, page, version, locale,
  kind, content hash, time; no IP or user agent); the page `<routing.path_prefix>/accept` (accept checkbox for terms,
  statement for privacy, CSRF, stale-offer guard, session-stored relative target, sign-out link); the request gate
  (main GET/HEAD HTML navigations with a session only; built-in and configured exemptions,
  `LegalAcceptanceExemptionInterface`; `non_html: pass|deny`; fails open; satisfied state cached in the session).
  Host contract `LegalAcceptanceSubjectResolverInterface` (required); `LegalAcceptanceManager::pendingFor()`,
  `record()`, `exportFor()`, `eraseFor()`.
- Events `LegalVersionPublishedEvent`, `LegalDocumentRebasedEvent`, `LegalSeedUpgradedEvent`, `LegalAcceptedEvent`;
  AuditKit actions `legal_document.version_published|rebased|seed_upgraded` (and `accepted` with
  `acceptance.action_log: true`).
- Translations of every new string in en, es, de, fr, it, nl, pt (domains `NowoLegalKitBundle` and `validators`).
- Security audit (REQ-SEC-004, appendix EM) fixes before the first release: the requirements cache is deleted again and
  `LegalVersionPublishedEvent` dispatched after the publishing transaction commits; "Use the new text" never changes
  the stored row on GET; the version guard runs inside the save transaction and publishes base + 1; `snapshot-seed
  --force` refuses a symlinked revision; a `|raw` lint and test over the templates; the re-acceptance page reads the
  fingerprint before the pending pages; host exemption voters fail open; summaries of accepted versions are frozen;
  `action_log` documents the AuditKit IP.

### Changed

- `symfony/clock` and `psr/clock` are runtime dependencies (version and acceptance timestamps).
- `LegalDocumentSavedEvent`, `ResolvedLegalDocument`, `LegalPage`, `LegalDocumentResolver` and the admin controller
  get appended optional arguments (BC). `TablePrefixListener` also prefixes the new tables.

## [1.3.2] - 2026-11-04

### Security

- **URL verdict independent of the libxml version:** `UrlPolicy` now judges an `href` / `src` both as parsed and after
  decoding the HTML character references left in it (up to four steps; a value still changing is refused). libxml
  2.14+ decodes HTML5 named entities such as `&colon;` while 2.9 kept them as text, so on the PHP 8.3 fallback parser
  `javascript&colon;alert(1)` used to be kept as an inert relative link on older libxml and dropped on newer; it is now
  dropped everywhere, as are double-encoded forms (`javascript&amp;colon;`, `&amp;#58;`) on both parsers. Query
  strings with `&amp;` are unchanged. New corpus cases.

## [1.3.1] - 2026-10-30

Bundle routes are opt-in again: controllers are no longer autoconfigured, so a host's routing.controllers import does not expose them (REQ-SF-004 rule 7). See [UPGRADING](UPGRADING.md#to-131).

### Fixed

- **Admin routes registered without the route import:** on Symfony >= 7.3 the `#[Route]` attributes (plus
  `#[AsController]`) on the autoconfigured `LegalDocumentAdminController` gave it the `routing.controller` tag, so the
  host's default `controllers: { resource: routing.controllers }` registered `nowo_legal_kit_admin_index`,
  `nowo_legal_kit_admin_edit` and `nowo_legal_kit_admin_reset` even when the host never imported the
  `nowo_legal_kit` route type, and regardless of `web_ui.enabled` (access was still enforced by
  `AdminAccessSubscriber`). `LegalDocumentAdminController` and `LegalPageController` are no longer autoconfigured
  and no longer carry `#[AsController]` (explicit `controller.service_arguments` tag); the routes exist only through
  the documented import (REQ-SF-004 rule 7). Regression test with a kernel that only loads `routing.controllers`.

## [1.3.0] - 2026-10-28

Security: allow_unauthenticated is refused in production (prod or non-debug kernels). See [UPGRADING](UPGRADING.md#to-130).

### Security

- **`allow_unauthenticated` refused in production.** The switch opens the legal documents admin to anonymous users; the
  container now refuses to compile with it in the `prod` environment or in any non-debug kernel outside `test*`
  environments (as ContactFormBundle).

## [1.2.0] - 2026-10-28

Security: admin CSRF fails closed; the opt-in shared cache skips visitors with a session. See [UPGRADING](UPGRADING.md#to-120).

### Security

- **Admin CSRF fails closed.** With the admin UI enabled the container refuses to compile without
  `security.csrf.token_manager` + `form.type_extension.csrf`, or with `framework.form.csrf_protection: false`
  (`SecurityPass`): save / restore would otherwise accept cross-site posts.
- **Opt-in shared cache never stores a personalised page.** With `public.cache.shared_max_age`, a page was made
  `public` and Symfony's session auto-private was suppressed even for visitors with a session (layout flashes, user
  menu, logout CSRF token). Visitors that send a session cookie now get a `private` response.

### Changed

- **Developer tooling (REQ-CS-001):** `make validate-phpdoc` is read-only and now part of `make release-check`: it fails when a class or public method in `src/` has no PHPDoc block (the former `@return` check asked for tags that `cs-fix` (`no_superfluous_phpdoc_tags`) removes; `make fix-phpdoc` is gone). Added the 22 missing docblocks.

## [1.1.1] - 2026-10-22

Wave-2 REQ compliance: logged import and seed commands, pinned CI matrix, demo release-check refreshes the bundle. See [UPGRADING](UPGRADING.md#to-111).

### Changed

- `nowo:legal-kit:import` and `nowo:legal-kit:seed` log their start / outcome with counts only through the autowired PSR-3 logger; runtime dependency `psr/log` (REQ-OBS-001).
- CI: Symfony 8.0 cell dropped (end of maintenance, advisories) and every `symfony/*` package pinned to the cell minor (REQ-SF-002).
- Demo `release-check` refreshes the bundle (`update-bundle`) before its tests (REQ-DEMO-007).
- Docs: why the seed uses its own `NowoLegalKitSeed` domain (REQ-I18N-003).
- `.scripts/check-no-cursor-coauthor.sh` accepts git worktrees (REQ-GIT-001).

## [1.1.0] - 2026-10-20

Render-time sanitising of the legal body with the HTML5 parser (REQ-PBK-009, Twig filter nowo_legal_kit_sanitize_html) and translated cookie category labels with an override block. See [UPGRADING](UPGRADING.md#to-110).

### Added

- Twig filter `nowo_legal_kit_sanitize_html` (safe HTML through `LegalHtmlSanitizerInterface`); the public page prints
  the body with it instead of a bare `|raw`.
- Block `nowo_legal_kit_cookie_category_label` in `_cookie_inventory.html.twig` (variables `cookie`,
  `category_label`) so hosts override only the category label.
- `NowoLegalKitBundle` translations `cookies.category.<key>` for the CookieConsent categories (`required`,
  `functionality`, `preferences`, `analytics`, `marketing`, `tracking`) in en, es, de, nl, fr, it, pt.

### Changed

- `DomAllowlistSanitizer` parses with the HTML5 algorithm (`Dom\HTMLDocument` fragment parsing, HTML5 serialiser) on
  PHP 8.4+, so the allowlist sees what the browser sees (REQ-PBK-009); foreign SVG / MathML content is removed. PHP
  8.3 keeps libxml's parser. New constructor argument `html5` (null: automatic). Empty attributes are now written as
  `attr=""` on PHP 8.4+.
- `.php-cs-fixer.dist.php`: `new ParallelConfig(2)` instead of `ParallelConfigFactory::detect()` (REQ-CS-001).

### Fixed

- The cookie inventory table (`%cookie_inventory%`) printed the raw CookieConsent category key (`required`,
  `analytics`…) instead of a translated label; unknown keys still print as they are.

### Security

- Defence in depth for stored legal HTML (REQ-PBK-009): the body is sanitised again at render time in the template
  (on top of the resolver pass), so markup written by SQL, restores or a custom resolver never reaches the page
  unsanitised. Corpus extended with HTML5 named entities (`java&colon;script:`), SVG animation handlers, style
  injection and raw-text elements.

## [1.0.1] - 2026-10-18

### Fixed

- Test suite on Symfony 7.4: the test kernel registers a PSR-20 `clock` service when FrameworkBundle does not (7.4
  only registers it when `symfony/clock` is a root requirement; AuditKit autowires it). No change in the bundle code;
  the GitHub CI matrix (PHP 8.3–8.5 × Symfony 7.4 / 8.0 / 8.1 / 8.2) is green.

## [1.0.0] - 2026-10-18

First release: the legal pages feature extracted from Beacon (spec 155) and the five applications that copied it.

### Added

- Page catalogue (`pages`: notice, privacy, terms, cookies + extra slugs; path, enabled, nav, seed template) and
  locales from a list or a parameter, default locale first.
- Route loader `type: nowo_legal_kit`: `legal_<slug>` (`/{_locale}/legal/<path>`), `unprefixed` style with
  `legal_<slug>_unlocalized` twins and 301 from `/{default}/…`, `prefixed` style with a 302 bare redirect.
- Entity `LegalDocument` mapping the existing `legal_document` table (AuditKit `TimestampableTrait` +
  `BlameableTrait`, explicit index names), repository, `doctrine.table_prefix` listener (tables and index names).
- Resolution stored row → seed → `seed.fallback_locale`, `_content_locale` request attribute, `Cache-Control: private`
  (shared cache opt-in), `Last-Modified`.
- Generic GDPR / LSSI seed (`NowoLegalKitSeed`) in en, es, de, nl, fr, it, pt; `TwigSeedProvider`;
  `LegalSeedProviderInterface`; `seed.materialize` + `nowo:legal-kit:seed --missing-only`.
- Tokens at render time (`LegalTokenResolverInterface`): `%brand%`, `%last_updated%`, `%cookie_inventory%`.
- DOM allowlist sanitiser (podologiapriego-web allowlist + `code`, `pre`, `mark`, `sup`, `sub`), URL policy,
  optional CKEditor 5 strict first pass and OutboundUrlGuard; applied on every write and render; XSS corpus tests.
- Admin pages on the UiKit 2.4 page contract: page × locale matrix, editor with locale tabs (CKEditor 5 or textarea,
  FormKit profile `legal_kit`), restore through the confirm contract (POST + CSRF); REQ-UI-002 security keys;
  deprecated `admin_legal_*` route aliases.
- Public page (unique page on the guest layout), cross navigation, footer partial with "Manage cookies".
- Placeholder gate: `LegalPlaceholderScanner`, `nowo:legal-kit:check-placeholders` (table / JSON, exit codes), Ops
  banner partial, `nowo_legal_kit_placeholder_hits()`.
- Portability: `LegalDocumentPortability` (export / normalize / apply without implicit flush),
  `nowo:legal-kit:adapt-seed`, `nowo:legal-kit:export`, `nowo:legal-kit:import`; legacy aliases
  `app:legal:check-placeholders` and `app:ops-seed:adapt-legal`.
- Integrations: CookieConsent (legal routes prepended to `disabled_routes` / `render_routes`, `privacy_route`
  fallback with CookieConsent 1.17+), SeoKit (head bridge + sitemap provider), AuditKit action log,
  `nowo:legal-kit:doctor`.
- Events `LegalPageRenderEvent`, `LegalDocumentSavedEvent`, `LegalDocumentRestoredEvent`,
  `LegalDocumentsImportedEvent`.
- Demo `demo/symfony8` (FrankenPHP, MySQL 8.4, CookieConsent, CKEditor 5), Playwright e2e and WebP screenshots;
  schema adoption tests (SQLite in the bundle, MySQL with Beacon's DDL in the demo); Spec Kit baseline
  `specs/001-baseline`.
