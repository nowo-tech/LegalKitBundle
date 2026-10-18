# PHP-FIG PSR evaluation (REQ-CS-007)

Package: `nowo-tech/legal-kit-bundle` (`symfony-bundle`)

This document records which [PHP-FIG PSRs](https://www.php-fig.org/psr/) apply to this package. Only contracts that
add clear interoperability or maintainability value are **Adopted**.

## Baseline (always)

| PSR | Decision | How |
| --- | -------- | --- |
| PSR-12 (coding style) | **Adopted** | `@PSR12` in `.php-cs-fixer.dist.php` (Nowo REQ-CS-001). |
| PSR-4 (autoloading) | **Adopted** | `composer.json` PSR-4 map for `src/` and `tests/`. |

## Interface / contract PSRs

| PSR | Decision | Where |
| --- | -------- | ----- |
| PSR-14 Event dispatcher | **Adopted** | `Psr\EventDispatcher\EventDispatcherInterface` in the controllers and `LegalDocumentPortability` (render / saved / restored / imported events). |
| PSR-3 Logger | **N/A** | The bundle logs nothing (the AuditKit action log records edits). |
| PSR-6 / PSR-16 Cache | **N/A** | No cache. |
| PSR-7 / PSR-17 HTTP messages | **N/A** | Symfony HttpFoundation is the controller surface. |
| PSR-18 HTTP client | **N/A** | No outgoing HTTP call. |
| PSR-11 Container | **N/A** | Constructor injection; tagged iterators for seed providers and token resolvers. |
| PSR-15 HTTP middleware | **N/A** | Symfony controllers and listeners. |
| PSR-20 Clock | **N/A** | Timestamps come from AuditKit (which uses PSR-20 itself). |

## Summary

- **Adopted beyond baseline:** PSR-14.
- **Rule:** do not add `psr/*` Composer dependencies without matching type-hints and DI wiring.

---

_REQ-CS-007 evaluation date: 2026-10-18._
