# Concurrency

How the bundle writes under contention (REQ-RUNTIME-003): one transaction per write unit, a control on every write,
and a conflict answer clients can act on. Legal acceptances are evidence, so the rules below also guarantee that a race
never records an acceptance against a version other than the one offered and current.

## Table of contents

- [Rules the bundle follows](#rules-the-bundle-follows)
- [Write paths](#write-paths)
- [Conflict answers and codes](#conflict-answers-and-codes)
- [What is not a conflict](#what-is-not-a-conflict)
- [Acceptance evidence under races](#acceptance-evidence-under-races)
- [Host options](#host-options)
- [Retries](#retries)
- [Tests and CI](#tests-and-ci)
- [Residual risks](#residual-risks)

## Rules the bundle follows

- **Private entity manager.** The bundle writes through its own unit of work (`LegalKitEntityManager` since 1.12, or
  the manager named by `doctrine.entity_manager`). It never flushes, clears or resets the application's entity manager.
  A failed flush closes only the bundle's manager, which is replaced on the next call. See
  [FRANKENPHP-WORKER-AUDIT.md](FRANKENPHP-WORKER-AUDIT.md).
- **One transaction per write unit.** The acceptance batch and the erasure run in one `transactional()` of their own,
  or join the caller's open transaction. The editor's save and its new version run in one `wrapInTransaction()`. The
  seed upgrade runs in one explicit transaction.
- **Events after the commit.** `AfterCommitMiddleware` (on the bundle's connection only) holds the bundle's events until
  the outermost commit, and drops them on rollback.
- **Controls.** Unique keys for "only one" rules (`(subject_id, slug, version)` acceptances, `(slug, version)` versions,
  `(slug, locale)` texts), `PESSIMISTIC_WRITE` on the document plus an in-transaction version re-check in the editor, and
  explicit version numbers (`base + 1`, never `MAX() + 1` at write time).
- **Worker mode.** Nothing here keeps per-request state in a service. The commit ledger is a monotonic counter that is
  only read by difference against a mark taken on each main request.

## Write paths

| Write path | Transaction | Control | Conflict answer |
| --- | --- | --- | --- |
| Acceptance (`LegalAcceptanceController::accept` POST, `LegalAcceptanceManagerInterface::record()` / `recordInTransaction()`) | One `transactional()` for the batch (all pages or none), or the caller's transaction; one savepoint per row inside a transaction | Insert only; unique `(subject_id, slug, version)` + catch ("already accepted" = 0 rows); since 1.14 every page must still be at the offered version, read uncached inside the transaction (else `StaleLegalOfferException`, nothing recorded) | 409 `CONFLICT_001` / `CONFLICT_002` on the page; a stale offer re-renders the current offer (422) |
| Acceptance erasure (`eraseFor()`, UserKit eraser) | One `transactional()`, or the caller's | Delete by primary key in batches, exact subject id; idempotent | Caller's storage error (no HTTP route of the bundle) |
| Editor save with versioning (`LegalDocumentAdminController::edit` POST) | One `wrapInTransaction()`: the text and the new version row | Form carries `base_version` + `base_updated`; inside the transaction `PESSIMISTIC_WRITE` on the document and the version re-read; new version = `base + 1` on the unique `(slug, version)` | `StaleVersionException` / unique key → "changed meanwhile" flash; lock conflict → 409 `CONFLICT_001` / `CONFLICT_002` |
| Editor save without versioning, restore (`reset`) | One flush | Unique `(slug, locale)` | Lock conflict → 409 `CONFLICT_001` / `CONFLICT_002`; unique key (two admins creating the same page text at once) → 409 `LEGAL_KIT_DUPLICATE_KEY` |
| `LegalPageVersions::publishVersion()` (host API) | One flush, or the caller's transaction (then call `afterPublish()`) | Unique `(slug, version)` | Caller's storage error |
| Seed upgrade (`nowo:legal-kit:upgrade-seed`) | One explicit transaction for the whole run | `PESSIMISTIC_WRITE` reads of the documents; events after the commit | Console: the storage error; nothing of the run is kept |
| Seed (`nowo:legal-kit:seed`), import (`LegalDocumentPortability::apply()`) | One flush (or the caller's transaction with `flush: false`) | Unique `(slug, locale)`; operator-only | Console / caller's storage error |

## Conflict answers and codes

Lock conflicts on the bundle's routes (the acceptance page, the public pages and the admin pages) answer with the
`conflict_response.status` (409 by default) and these headers. The JSON / HTML body is the application's error page:
the message is fixed and client-safe, the storage error stays in the logs.

| Code | When | Status | `Retry-After` | Client should |
| --- | --- | --- | --- | --- |
| `CONFLICT_001` (`X-Conflict-Code`) | Deadlock, lock wait timeout or serialization failure (MySQL 1213 / 1205, MariaDB 1020, PostgreSQL 40001 / 40P01 / 55P03, anywhere in the exception chain), or a `TransientStorageFailure`, and nothing committed since the request started | 409 (`conflict_response.status`) | Yes: the runner's hint, else `conflict_response.retry_after`, else 1 s | Retry |
| `CONFLICT_002` (`X-Conflict-Code`) | The same after a commit earlier in the request (transaction-doctrine's request mark, `ConflictCode::forRequest()`, when its bundle is registered with `commit_detection`; otherwise the bundle's own ledger, fed by transaction-doctrine's commit-tracking middleware on the bundle's connection), a `ConcurrencyConflict` that committed, or a lost savepoint with no deadlock behind it (outcome unknown) | 409 (`conflict_response.status`) | No | Reload, do not repeat blindly |
| `LEGAL_KIT_DUPLICATE_KEY` (`X-Error-Code`) | An admin write refused by a unique key that the editor does not already turn into "changed meanwhile" (two admins creating the same page text at once) | 409 | No | Reload the page and apply the change again |

`X-Conflict-Code` only ever carries `CONFLICT_001` or `CONFLICT_002`.

## What is not a conflict

- **Unique violations** outside the admin. The acceptance insert catches its own duplicate (the same version already
  recorded, a double submit) and records nothing more: no error at all.
- **Lost connections** and every other storage error: a real error (500), never a conflict code.
- **Host routes.** The listener only answers for the bundle's controllers. The acceptance gate on host routes only
  reads (and fails open). The host's own routes keep the host's error handling, also when they call the bundle's
  services.

## Acceptance evidence under races

A recorded acceptance says: subject S accepted (or acknowledged) page P **at version N**, offered with the text whose
hash is H. The bundle keeps N and H consistent:

- The re-acceptance form posts back the fingerprint of the offer (pages, versions, text hashes). An offer that changed
  since it was shown (a version or a text published meanwhile) records nothing and shows the new offer.
- Since 1.14 the batch re-reads the current version of every offered page from the database (never the cached map)
  inside its own transaction, before the first insert. The offer's version and its text hash come from two reads, and
  the version could come from a stale cached map (the moment between a publication's commit and its cache delete, a
  failed cache delete, or a cache pool that is not shared between servers). Without this check, version N could be
  recorded with the hash of the text of N+1. Now such an offer raises `StaleLegalOfferException` and nothing is
  recorded; the bundle drops the cached map before refusing, so the next `pendingFor()` (the page does it at once)
  shows the current offer. Rows recorded by 1.4.0–1.13.2 can be checked with the query in
  [UPGRADING.md](UPGRADING.md#acceptances-recorded-against-a-stale-version-140-to-1132).
- A publication that commits after that check commits after the acceptance of the previous version: version N with the
  text of version N, which is exactly what was offered and current when read. The gate then asks again for N+1.
- Two submits of the same offer: one row per page (unique key). On two connections the second waits for the first's
  key, then either finds the row (0 rows recorded) or, when the wait exceeds the lock timeout, answers `CONFLICT_001`.

## Host options

```yaml
nowo_legal_kit:
    conflict_response:
        status: null          # 409 or 503; null = transaction-doctrine's conflict_response, else 409
        retry_after: null     # seconds of a CONFLICT_001 answer; null = transaction-doctrine's, else 1
        commit_tracking: bundle   # bundle | all | off
```

- `status: 503` keeps a "try later" status for hosts that want it; the `X-Conflict-Code` header is sent either way.
- `commit_tracking` decides which commits count for `CONFLICT_002` when nowo-tech/transaction-doctrine's
  `commit_detection` is off:
  - `bundle` (default): transaction-doctrine's `CommitTrackingMiddleware` wraps **only the bundle's connection**
    (`doctrine.connection`, else the connection of `doctrine.entity_manager`, else of the default manager): the same
    connection as the after-commit middleware. The host's other connections are never wrapped.
  - `all`: every Doctrine connection (a host whose request writes elsewhere first and wants that to count).
  - `off`: no middleware; only the failure itself and transaction-doctrine's request mark decide.
- With transaction-doctrine's bundle registered and `commit_detection` on, the bundle reuses its `CommitLedger` and adds
  no middleware of its own: no connection is wrapped twice.
- `acceptance.cache_pool`: in a deployment with several servers, use a pool they share (Redis, database) so a
  publication invalidates the requirements map everywhere. A per-server pool (the filesystem default of `cache.app`)
  keeps an old map on the other servers for up to `CACHE_TTL` (1 hour): the gate does not ask again there until it
  expires. Since 1.14 a stale map can no longer record a wrong version (see above).

## Retries

- A `CONFLICT_001` answer is safe to retry as is: nothing of the request was committed, and the acceptance insert is
  idempotent by its natural key.
- After `CONFLICT_002`, reload: the acceptance page then shows what is still pending.
- The bundle keeps no entity across attempts: the private manager is replaced after a failed flush, and the editor
  reloads the document on the next request.
- Events (`LegalAcceptedEvent`, `LegalVersionPublishedEvent`, …) are dispatched only after the commit, so a retried
  write never sends them twice for one row.

## Tests and CI

- `tests/Integration/AcceptanceRaceDatabaseTest.php` (group `database`): two kernels, so two connections, on a real
  server with a 2 s `innodb_lock_wait_timeout` / `lock_timeout`:
  - the same offer accepted on two connections: one row per page, the loser answers 409 `CONFLICT_001`, its retry
    records nothing more;
  - a publication racing an acceptance: the acceptance records version N with the text of N; after the commit the old
    offer records nothing and the new one records N+1 with the new text's hash;
  - two admins publishing the same version: one row; the loser answers `CONFLICT_001`, then the unique key (domain 409);
  - a commit earlier in the request, then a lock conflict: `CONFLICT_002` without `Retry-After` (real middleware).
- `tests/Functional/AcceptanceStaleOfferTest.php`: a stale cached map never records a wrong version.
- `tests/Unit/EventSubscriber/LegalKitConflictListenerTest.php`: the conflict mapping (001, 002, 503, hints, domain
  409, what is not mapped, host routes). `tests/Unit/DependencyInjection/Compiler/CommitTrackingScopeTest.php`: the
  commit tracking wraps the bundle's connection only (DoctrineBundle's real `MiddlewaresPass`).
- CI job "Subject ids on database servers" runs the `database` group on MySQL 8.0 and 8.4, MariaDB 11.4 and
  PostgreSQL 16 and 17 (required checks). Locally: `LEGAL_KIT_TEST_DATABASE_URL=… vendor/bin/phpunit --group database`.
- CI job "Dependency floor (nowo-tech/transaction-* lowest, demo lock)": PHPStan and the unit + integration suites with
  the lowest allowed transaction-* versions, and `.scripts/check-demo-lock.py` (the demo lock installs what the bundle
  requires).

## Residual risks

- **Last edit wins on minor saves without versioning.** Without `versioning.enabled`, the editor saves the text with
  one flush and no version guard: a stale edit page overwrites a text another admin saved meanwhile (the form posts
  every field). With versioning, `base_updated` refuses it.
- **Per-server cache pool.** See `acceptance.cache_pool` above: the gate can lag on other servers for up to an hour
  after a publication; acceptances recorded there stay consistent.
- **Host transactions.** Inside a transaction the host opened, the bundle's rows commit or roll back with it and the
  host maps its own errors (`recordInTransaction()` can also throw `StaleLegalOfferException`).
- **Console and host APIs** (seed, import, upgrade-seed, `publishVersion()`, erasure) rethrow lock conflicts to their
  caller; run them again.
- **Commit tracking limits** (transaction-doctrine): writes through the native PDO handle, and a `SELECT` calling a
  writing function, are not seen. With `commit_tracking: bundle`, a commit on another connection earlier in the request
  does not make the answer `CONFLICT_002` (use `all`, or transaction-doctrine's `commit_detection`).
