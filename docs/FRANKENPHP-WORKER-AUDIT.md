# FrankenPHP worker mode audit (kernel not reset between requests)

| Field | Value |
|-------|-------|
| Package | `nowo-tech/legal-kit-bundle` (`symfony-bundle`) |
| Audited revision | `1.0` (`main`, 2026-10-18); boundaries re-audited for 1.11.1 (REQ-RUNTIME-002, 2026-11-11) |
| Audit date | 2026-10-18 (Messenger / console boundaries: 2026-11-11) |
| Method | Manual review of every service under `src/` (see `specs/001-baseline/code-inventory.md`) + Igor audit (`make igor`) + PHPStan FrankenPHP rulesets + kept-alive kernel tests |
| **Verdict** | ✅ **Viable under scenario B** (1.11.1) — the placeholder scanner memo, the pending events and the bundle's own entity manager are all cleared at every unit-of-work boundary (main request, Messenger message, console command) by the bundle itself; before 1.11.1 the scanner memo was cleared only by `kernel.reset` and admin saves |

## Table of contents

- [Execution model assumed](#execution-model-assumed)
- [Summary](#summary)
- [Stateful services and how each is cleared](#stateful-services-and-how-each-is-cleared)
- [Boundary events](#boundary-events)
- [Worker tests](#worker-tests)
- [Host rule: entity managers](#host-rule-entity-managers)
- [Rules for future code](#rules-for-future-code)

## Execution model assumed

FrankenPHP worker mode boots the Symfony kernel once per worker and serves many requests with the same container.

- **A — kernel not rebooted, `services_resetter` still runs:** services tagged `kernel.reset` are reset between requests.
- **B — no reset at all:** any per-request state kept in a service leaks into the next request.

**Precondition, precisely.** `FRANKENPHP_RESET_KERNEL=false` alone does not mean scenario B: with the stock Symfony kernel, `Kernel::handle()` → `boot()` still calls `services_resetter->reset()` before every request after the first. Scenario B happens where the resetter does not run: a host that removes, replaces or bypasses `services_resetter` (or drives `http_kernel` directly), Messenger consumers started with `messenger:consume --no-reset`, and long-running console processes. This bundle is correct in all of them.

## Summary

| Area | Status | Notes |
|------|--------|-------|
| Mutable state in shared services | ✅ | Every service is `final readonly` (or holds only injected collaborators) except those listed in [Stateful services](#stateful-services-and-how-each-is-cleared), each cleared at every boundary |
| Translator locale | ✅ | `TwigSeedProvider` switches the translator locale inside `try / finally` and always restores it |
| Caches | ✅ | No cache pool; the scanner memo only |
| Static properties / `static` locals | ✅ | None |
| Request / user captured in services | ✅ | Controllers and the Twig extension read the request per call (`RequestStack`) |
| Superglobals, `putenv`, `ini_set`, `setlocale`, `exit` | ✅ | None in `src/` |
| Blocking I/O and timeouts | ✅ | No outbound HTTP or subprocess; OutboundUrlGuard is used without DNS resolution |
| Doctrine | ✅ | The bundle's private manager (1.12, default) or its own manager (`doctrine.entity_manager`) is recovered and cleared per unit of work; the host's managers are never touched (see [Host rule](#host-rule-entity-managers)) |
| PHPStan FrankenPHP rulesets | ✅ | `ruleset-classic.neon` + `ruleset-worker.neon` in `phpstan.neon.dist` |
| Igor worker-state audit | ✅ | `make igor` in `release-check` and CI (REQ-CS-008): 131 service files, 0 KO, 0 WARN |

## Stateful services and how each is cleared

| Service | State | Cleared by (scenario B) | Also |
|---|---|---|---|
| `Placeholder\LegalPlaceholderScanner` | `$memo`: hits per locale (site-wide page text, no personal data) | `EventSubscriber\PlaceholderScannerResetSubscriber` at every boundary (1.11.1) | `kernel.reset`; admin save / restore; `reset()` after an edit in the same unit of work |
| `Doctrine\AfterCommitEventDispatcher` | `$transactions` (`WeakMap` per connection) and `$committed` events | dispatched at `kernel.terminate`, `console.terminate`, `WorkerMessageHandledEvent` / `WorkerMessageFailedEvent` and `postFlush`; rolled-back levels dropped | `kernel.reset` drops everything (a request that died mid-transaction) |
| The bundle's own entity manager (`doctrine.entity_manager`, optional) | identity map | `Doctrine\OwnEntityManagerSubscriber`: recovered when closed at main `kernel.request` / `console.command` / `WorkerMessageReceivedEvent`, cleared at `kernel.terminate` / `console.terminate` / `WorkerMessageHandledEvent` / `WorkerMessageFailedEvent` | registered only when `doctrine.entity_manager` is set |
| `Doctrine\LegalKitEntityManager` (1.12, the private manager when `doctrine.entity_manager` is unset) | the wrapped entity manager (identity map) and a "stale after rollback" flag | `Doctrine\LegalKitEntityManagerSubscriber`: dropped when closed or stale and cleared at main `kernel.request` / `console.command` / `WorkerMessageReceivedEvent`, cleared at `kernel.terminate` / `console.terminate` / `WorkerMessageHandledEvent` / `WorkerMessageFailedEvent` | replaced on the next call when closed, stale (a rollback on its connection, told by the after-commit middleware) or on another connection; `kernel.reset` drops it |

## Boundary events

`PlaceholderScannerResetSubscriber` (always registered; the scanner reaches it as an iterator of `IGNORE_ON_UNINITIALIZED_REFERENCE` references, so a boundary never builds it):

| Boundary | Event (subscribed by name) | Priority |
|---|---|---|
| HTTP main request start | `kernel.request` (main only; sub-requests keep the memo of their main request) | 4096 |
| HTTP end | `kernel.terminate` | -1024 |
| Messenger message start | `Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent` | 4096 |
| Messenger message end | `WorkerMessageHandledEvent`, `WorkerMessageFailedEvent` | -1024 |
| Console command start / end | `console.command` / `console.terminate` | 4096 / -1024 |

Messenger and Console stay optional: every event is subscribed by its name. One console command or one Messenger message is **one** unit of work: a command that edits pages and then scans them must call `LegalPlaceholderScanner::reset()` itself (as the admin does).

## Worker tests

- `tests/Integration/WorkerBoundariesTest.php` (1.11.1) boots **one** kernel and never calls `services_resetter`: 500 HTTP requests through the `http_kernel` service (not `Kernel::handle()`, so no resetter; each with a sub-request), 500 messages through a real `Symfony\Component\Messenger\Worker` on the kernel's dispatcher without `ResetServicesListener` (as `--no-reset`; every 7th handler fails), and 500 console `Application::run()` calls. Before each unit another worker alternates the "notice" page between a version with a seed marker and a clean one (a plain DBAL update since 1.12, so a legal entity kept by the bundle's private manager would answer the old text). Each unit must see the stored version; memory margin: less than 1 MiB growth after a 50-unit warm-up. Without the 1.11.1 subscriber all three paths fail.
- `tests/Integration/PersonalDataTest.php::testWorkerModeManyRunsInOneKernel`: the same acceptance manager, exporter and eraser serve 1,000 erase / export runs for three users in one kernel; no cross-user data, no pending event left, an empty identity map, memory growth under 1 MiB.
- `tests/Integration/PrivateEntityManagerTest.php` (1.12): the private manager is cleared at the request boundary through `http_kernel` (the host manager keeps its entities), reads a row changed by another worker after `begin()`, is dropped by `kernel.reset` and re-created on use, is replaced after a failed flush closed it and after a rollback (host transaction or savepoint) of its connection.
- `reset()` is tested directly: `tests/Integration/ServicesTest.php::testScannerIsMemoisedUntilReset`, `tests/Unit/EventSubscriber/PlaceholderScannerResetSubscriberTest.php`.

## Host rule: entity managers

A host that disables or bypasses the services resetter must also clear its **own** entity managers at every boundary (main request start or end, each Messenger message, each console command); otherwise the identity map serves stale rows (for example a downgraded permission or a soft-deleted item) across requests (REQ-RUNTIME-002 rule 7). Since 1.12 the legal entities never live in the host's managers (the private manager, or `doctrine.entity_manager`), and the bundle clears its own manager at every boundary (see above). With the deprecated `doctrine.private_entity_manager: false`, they live in the host's default manager again and that rule covers them.

## Rules for future code

- Never memoise per-request data in a service property without `ResetInterface` + `kernel.reset` **and** a clear at every boundary (add it to `PlaceholderScannerResetSubscriber` or an equivalent subscriber).
- Host seed providers and token resolvers are shared services too: same rule.
- Any outbound client added later needs explicit timeouts (REQ-RUNTIME-001).
