# Release

This checklist helps maintainers prepare and publish a release safely.

## Table of contents

- [Pre-release](#pre-release)
- [Security checklist (12.4.1)](#security-checklist-1241)
- [GitHub About (REQ-DOCS-018)](#github-about-req-docs-018)
- [Tag and publish](#tag-and-publish)
- [Post-release checks](#post-release-checks)
- [Coverage goals](#coverage-goals)
- [Release history](#release-history)

## Pre-release

Run the full release pipeline:

```bash
make release-check
```

Expected steps:

- `check-no-cursor-coauthor` (REQ-GIT-001), open PRs, Twig Extra, raw form controls
- Composer validation and lock sync
- Code style (fix + check), Rector dry run, PHPStan, Igor
- PHPUnit with the coverage gate (≥ 99% lines)
- Translation key parity (7 locales, both domains) + YAML parse
- Demo verification (`demo/Makefile` `release-check`: PHPUnit incl. the MySQL schema adoption test, Playwright e2e,
  HTTP 200 smoke)

After creating the release commit and tag, run `make check-no-cursor-coauthor` again **before** `git push` (REQ-GIT-001).

## Security checklist (12.4.1)

Before tagging, confirm each item in [SECURITY.md — Release security checklist](SECURITY.md#release-security-checklist-1241). Note confirmation in the release PR or tag message.

## GitHub About (REQ-DOCS-018)

GitHub repository **About** is not stored in git. Copy the values from [GITHUB.md](GITHUB.md). After creating or transferring `nowo-tech/LegalKitBundle`, set:

| Field | Value |
| --- | --- |
| **Description** | Operator-editable legal pages for Symfony: notice, privacy, terms and cookies per locale, generic GDPR/LSSI seed in 7 languages, placeholder gate, DOM sanitiser, UiKit admin. |
| **Website** | https://packagist.org/packages/nowo-tech/legal-kit-bundle |
| **Topics** | `php`, `symfony`, `symfony-bundle`, `legal`, `gdpr`, `privacy-policy`, `cookies`, `i18n`, `frankenphp` |

## Tag and publish

1. Move `[Unreleased]` entries in `docs/CHANGELOG.md` to a new `## [X.Y.Z] - YYYY-MM-DD` section.
2. Update `docs/UPGRADING.md` if consumers must change code or configuration.
3. Create an **annotated** tag on the commit `make release-check` passed on: `git tag -a vX.Y.Z -m "Release vX.Y.Z" -m "release-check: OK <sha>"`.
4. Push the tag: `git push origin vX.Y.Z`.
5. Confirm GitHub workflows `release.yml` and `sync-releases.yml` completed successfully (when Actions are unavailable, create the release manually: `gh release create vX.Y.Z --notes-file <CHANGELOG section>`).

## Post-release checks

- Verify Packagist metadata is updated.
- Confirm the GitHub release contains the tag message and changelog section.
- Validate installation in a clean Symfony app:

```bash
composer require nowo-tech/legal-kit-bundle
```

- Smoke-test the demo: a public legal page, the admin matrix, save and restore one locale.

## Coverage goals

- **PHP**: **≥99%** line coverage (target **100%**; `make test-coverage`)
- **TypeScript**: N/A (the bundle ships no JavaScript)

Update README **Tests and coverage** percentages after each release when coverage changes materially.

## Release history

| Version | Date | Notes |
| --- | --- | --- |
| 1.0.0 | 2026-10-18 | First release: legal pages kit extracted from Beacon and five other apps (spec 155). |
