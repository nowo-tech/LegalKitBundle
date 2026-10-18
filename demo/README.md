# Legal Kit Bundle — Demo

FrankenPHP demo for **Symfony 8.1** (PHP 8.5) with **MySQL 8.4**, CookieConsent and CKEditor 5: the four legal pages
in English, Spanish and German from the generic seed, the footer navigation on every page, the admin editor and an
Ops page with the placeholder banner. The layouts are bridges of the UiKit admin / guest layouts with the host theme
tokens of `config/packages/nowo_ui_kit.yaml` (REQ-UI-003).

## Accounts

| User | Password | Role |
| --- | --- | --- |
| `admin` | `admin` | `ROLE_ADMIN` (created by `make setup` / `app:demo:setup`) |

## Quick start

```bash
make -C demo/symfony8 up
```

Default URL: **http://localhost:8167** (`PORT` in `demo/symfony8/.env.example`). Open `/legal/privacy`, switch to
`/es/legal/notice`, then sign in and edit a page at `/admin/legal`; `/ops` shows the placeholder banner.

## Commands

| Command | Description |
| ------- | ----------- |
| `make -C demo/symfony8 up` | Build, install dependencies, publish assets, create the schema and the admin |
| `make -C demo/symfony8 down` | Stop containers |
| `make -C demo/symfony8 setup` | `doctrine:schema:update --force` + `app:demo:setup` (bundles ship no migrations) |
| `make -C demo/symfony8 test` | Sync the bundle, run the demo PHPUnit tests (incl. the MySQL schema adoption test with Beacon's DDL) |
| `make -C demo/symfony8 test-e2e` | Playwright: footer, seed pages per locale, admin edit in CKEditor, banner, restore — needs `up` |
| `make -C demo/symfony8 demo-screenshots` | Regenerate `docs/images/demo/*.webp` (README images) |
| `make demo-smoke` | From the bundle root: boot the demo and assert HTTP 200 (REQ-TEST-011) |
| `make update-deps` | Refresh bundle **and** demo Composer dependencies (from the bundle root) |

From `demo/`: `make up`, `make down`, `make test-symfony8`, `make release-check` (tests + e2e + healthcheck).

**Forms:** the login page is a Symfony Form (`src/Form/LoginType.php`) rendered with `form_start` / the child loop /
`form_end`; the legal editor is the bundle's `LegalDocumentType`. No template writes raw `<form>` / `<input>` tags
(REQ-TWIG-005, `make check-no-raw-html-form`).

**Demo-only settings — do not copy:** the `admin` / `admin` account, the GET logout link, root database credentials in
the test environment and the placeholder `APP_SECRET`.

See [`docs/DEMO-FRANKENPHP.md`](../docs/DEMO-FRANKENPHP.md) for the FrankenPHP setup (worker / classic, Caddyfile,
MySQL, troubleshooting).
