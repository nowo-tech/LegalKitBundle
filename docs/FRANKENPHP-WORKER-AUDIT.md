# FrankenPHP worker mode audit (kernel not reset between requests)

| Field | Value |
|-------|-------|
| Package | `nowo-tech/legal-kit-bundle` (`symfony-bundle`) |
| Audited revision | `1.0` (`main`, 2026-10-18) |
| Audit date | 2026-10-18 |
| Method | Manual review of every service under `src/` (see `specs/001-baseline/code-inventory.md`) + Igor audit (`make igor`) + PHPStan FrankenPHP rulesets |
| **Verdict** | ✅ **Viable under scenario A**; under scenario B only the placeholder scanner memo survives (hits of an earlier request) — it is also reset after every admin save / restore |

## Execution model assumed

FrankenPHP worker mode boots the Symfony kernel once per worker and serves many requests with the same container.

- **A — kernel not rebooted, `services_resetter` still runs:** services tagged `kernel.reset` are reset between requests.
- **B — no reset at all:** any per-request state kept in a service leaks into the next request.

## Summary

| Area | Status | Notes |
|------|--------|-------|
| Mutable state in shared services | ✅ | Every service is `final readonly` (or holds only injected collaborators) except `LegalPlaceholderScanner`, whose memo of hits is per request and tagged `kernel.reset` |
| Translator locale | ✅ | `TwigSeedProvider` switches the translator locale inside `try / finally` and always restores it |
| Caches | ✅ | No cache pool; the scanner memo only (see above) |
| Static properties / `static` locals | ✅ | None |
| Request / user captured in services | ✅ | Controllers and the Twig extension read the request per call (`RequestStack`) |
| Superglobals, `putenv`, `ini_set`, `setlocale`, `exit` | ✅ | None in `src/` |
| Blocking I/O and timeouts | ✅ | No outbound HTTP or subprocess; OutboundUrlGuard is used without DNS resolution |
| PHPStan FrankenPHP rulesets | ✅ | `ruleset-classic.neon` + `ruleset-worker.neon` in `phpstan.neon.dist` |
| Igor worker-state audit | ✅ | `make igor` in `release-check` (REQ-CS-008) |

## Rules for future code

- Never memoise per-request data in a service property without `ResetInterface` + `kernel.reset`.
- Host seed providers and token resolvers are shared services too: same rule.
- Any outbound client added later needs explicit timeouts (REQ-RUNTIME-001).
