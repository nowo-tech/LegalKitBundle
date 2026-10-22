# Security

## Table of contents

- [Scope](#scope)
- [Attack surface](#attack-surface)
- [Threat model and mitigations](#threat-model-and-mitigations)
- [Secrets and cryptography](#secrets-and-cryptography)
- [Logging](#logging)
- [Dependencies](#dependencies)
- [Permissions and exposure](#permissions-and-exposure)
- [Host responsibilities (residual risks)](#host-responsibilities-residual-risks)
- [AI security audit](#ai-security-audit)
- [Reporting a vulnerability](#reporting-a-vulnerability)
- [Release security checklist (12.4.1)](#release-security-checklist-1241)

## Scope

Security analysis of **LegalKitBundle** (`nowo-tech/legal-kit-bundle`): what it accepts, what can go wrong, how the
bundle prevents it and what the host must do. The short reporting policy lives in
[`.github/SECURITY.md`](../.github/SECURITY.md).

**Covered:** controllers, route loader, sanitiser, token renderer, seed providers, portability, commands, Twig
extension, templates, DI defaults. **Not covered:** the host firewall, users, host seed providers / token resolvers and
the legal content itself (counsel review stays the operator's duty).

## Attack surface

| Input | Where | Who |
| --- | --- | --- |
| `GET /{_locale}/legal/<path>`, `/legal/<path>` | `LegalPageController` | anyone (public) |
| Admin `GET|POST /admin/legal/{slug}/{locale}`, `POST …/reset` | `LegalDocumentAdminController` | users passing the access checker |
| Stored HTML (`legal_document.body`) | resolver, sanitiser | operators, SQL, restores, ops-seed |
| `legal_documents` JSON | `LegalDocumentPortability`, `nowo:legal-kit:import` | deployers |
| Replacements map | `nowo:legal-kit:adapt-seed` | deployers |
| Configuration | DI extension | deployers |

## Threat model and mitigations

| Threat | Mitigation |
| --- | --- |
| **Stored XSS** | Every body is sanitised on save, on seed materialisation, on import **and on every render** by a DOM allowlist: unknown elements unwrapped, script / style / iframe / object / embed / svg / math / form controls / template / noscript removed with their content, comments dropped, only allowlisted attributes (`class` is the only presentational one, never `style`, never `on*`, `srcdoc`, `formaction`). Optional CKEditor strict first pass. A corpus test (script, handlers, `javascript:` with entities / whitespace / control characters, `data:`, SVG, iframe `srcdoc`, `style=url()`, nested `noscript`) must fail closed. On PHP 8.4+ the sanitiser parses with the HTML5 algorithm (`Dom\HTMLDocument`) and re-serialises, so the allowlist sees what the browser sees (REQ-PBK-009). The public template prints the body through `\|nowo_legal_kit_sanitize_html` (a second, render-time pass), never `\|raw`. Titles are always auto-escaped. |
| **Token injection** | Token values are HTML-escaped on insertion and the result is sanitised afterwards, so even a host resolver value (or `LegalTokenValue::html()` markup) cannot add elements, attributes or URLs outside the allowlist. |
| **Dangerous links / SSRF** | `href`: relative, `#`, `mailto:`, `tel:`, `http(s)` with a host; `src`: relative or `https`. With OutboundUrlGuardBundle, private-network and cloud-metadata targets are dropped (no DNS lookup, no request). `target="_blank"` gets `rel="noopener noreferrer"`. |
| **Unauthorised edits** | Admin routes pass the access checker (`security.access_roles`, default `ROLE_ADMIN`, empty list = deny) in a kernel controller listener; the build fails when the admin UI is enabled without SecurityBundle unless `allow_unauthenticated` (demo only). Hosts add `access_control` for `web_ui.path_prefix`. |
| **CSRF** | Saves use the Symfony form CSRF token; restore is POST-only with its own token id `nowo_legal_kit_reset_<slug>_<locale>` (REQ-SEC-005) behind the UiKit confirm dialog (no inline `confirm()`). |
| **Information leaks / caching** | Admin pages `Cache-Control: no-store, private`; public pages `private` unless the host opts into shared caching. No exception text in responses. |
| **Import abuse** | `normalize()` validates every entry (enabled slug / locale, title length, non-empty sanitised body, duplicates) before any write. |
| **Worker-mode leaks** | Stateless services; the scanner's per-request memo is reset (`kernel.reset`); the translator locale is restored in `finally` ([FRANKENPHP-WORKER-AUDIT.md](FRANKENPHP-WORKER-AUDIT.md)). |
| **CSP** | No inline script or style in bundle templates; one external stylesheet from the `nowo_legal_kit` asset package; CKEditor assets only on the edit page. |

## Secrets and cryptography

The bundle ships no secret, stores none and signs nothing (CSRF tokens come from Symfony).

## Logging

The writing console commands log through the autowired `Psr\Log\LoggerInterface` (REQ-OBS-001), with counts only:
`nowo:legal-kit:import` (info: start with `rows`, end with `written`; warning: rejected file with the exception
class) and `nowo:legal-kit:seed` (info: `inserted`, `locales`). Titles, bodies and file paths are never logged. With
the AuditKit action log enabled, admin edits are recorded with `{slug, locale}` only.

## Dependencies

`composer audit` runs in CI and before each release; Dependabot keeps dependencies current. Security fixes are
released as patch versions of the supported line ([`.github/SECURITY.md`](../.github/SECURITY.md)).

## Permissions and exposure

| Exposure | Restriction |
| --- | --- |
| Public legal pages | anonymous (`PUBLIC_ACCESS` for the legal prefix); `nowo:legal-kit:doctor` checks it |
| Admin pages | access checker + host `access_control` for `/admin/legal` |
| Commands | CLI only (deployers) |

**`allow_unauthenticated` footgun:** it opens the admin to anyone — demos only, never production.

## Host responsibilities (residual risks)

- Keep `allow_unauthenticated: false` and add the `access_control` rule for the admin prefix.
- Host seed providers and token resolvers return trusted text; use `LegalTokenValue::html()` only for markup you
  escape yourself.
- Extra `sanitizer.allowed_elements` widen the attack surface — keep them minimal. `class` values are free (operators
  are trusted): an operator could position a link over the page with utility classes of the host CSS framework.
- Legal content: counsel review (REQ-CC-010); the placeholder gate is only an engineering check.

## AI security audit

| Field | Value |
| --- | --- |
| Date | 2026-10-18 |
| Method | Independent AI static review (Claude Code agent, read-only) of the 1.0.0 code against `BUNDLES_SECURITY_ANALYSIS.md` §1 / REQ-SEC-004: sanitiser and URL policy (mutation XSS, entities, control characters, wrapper escape), token rendering order, admin access + CSRF, import validation, Twig `|raw`, worker state |
| First result | Overall **Medium**, **Pass with fixes** — no exploitable XSS with the default configuration |
| Re-audit | Every finding **fixed or documented** before tagging 1.0.0 (same day) → Overall **Low**, **Pass (good)** |
| Findings | LEGAL-SEC-01 (Medium) tokens resolved after sanitising (a host resolver value could reach an attribute or bring markup) → **fixed** (tokens first, then sanitise on every render); LEGAL-SEC-02 (Low) `/\host` / `\\host` accepted as relative URLs → **fixed** (URLs with `\` refused); LEGAL-SEC-03 (Low) an unbalanced `</div>` dropped the rest of the body → **fixed** (following nodes kept and sanitised); LEGAL-SEC-04 (Low) unbounded body size → **fixed** (1 000 000 characters in the editor and imports); LEGAL-SEC-05 (Info) extra attributes from `allowed_elements` (`srcset`, `ping`, `id`, `name`…) → **fixed** (fixed deny list); LEGAL-SEC-06 (Info) `class` accepts any host class (UI redress by an operator) → **documented** (operators are trusted; keep the allowlist minimal); LEGAL-SEC-07 (Info) sub-requests to the admin controller without an admin route → **fixed** (the subscriber also guards the controller) |
| Recorded in | `BUNDLES_SECURITY_ANALYSIS.md` (monorepo security report) |

## Reporting a vulnerability

Report security issues **privately**:

1. Do **not** open a public GitHub issue for security-sensitive bugs.
2. Use [GitHub Security Advisories](https://github.com/nowo-tech/LegalKitBundle/security/advisories) or email **hectorfranco@nowo.tech**.
3. Include steps to reproduce, affected versions and impact.
4. We acknowledge and coordinate disclosure after a fix is available.

## Release security checklist (12.4.1)

| Item | Check |
| --- | --- |
| `docs/SECURITY.md` and `.github/SECURITY.md` up to date | ☐ |
| `.env` / secrets in `.gitignore`; no credentials in the repository | ☐ |
| Published config contains no secrets | ☐ |
| Input validated, output sanitised (corpus test green) | ☐ |
| Authorization on admin routes (access checker + documented `access_control`) | ☐ |
| CSRF on every session-authenticated mutation (save, restore) | ☐ |
| `composer audit` run; known issues reviewed | ☐ |
| Logs contain no personal data or secrets | ☐ |
| Limits: import validated before writing; no outbound I/O | ☐ |
| AI security audit (REQ-SEC-004) passed — latest grade in `BUNDLES_SECURITY_ANALYSIS.md` | ☐ |
