# Coverage policy

## Table of contents

- [PHP line coverage gate](#php-line-coverage-gate)
- [TypeScript](#typescript)
- [Justified exclusions](#justified-exclusions)
- [How to refresh](#how-to-refresh)

## PHP line coverage gate

`make coverage-check` enforces **≥ 99%** line coverage on the PHPUnit includable `src/` set (`REQ-TEST-003` /
`REQ-TEST-006`); the target is **100%** (`make test-coverage-100`). The README percentage must match the latest
coverage text / CI artifact.

## TypeScript

N/A: the bundle ships no JavaScript or TypeScript (public pages work without JavaScript; the editor is
Ckeditor5EditorBundle's).

## Justified exclusions

`phpunit.xml.dist` excludes `src/Resources` (Twig, translations, PHP DI configuration, CSS). Two
`@codeCoverageIgnore` lines in `Sanitizer/DomAllowlistSanitizer.php` mark unreachable defensive branches (the wrapper
`div` is always parsed; `DOMElement::attributes` only yields `DOMAttr`).

Do **not** add new `@codeCoverageIgnore` or exclusions without updating this document.

## How to refresh

```bash
make coverage-check
```
