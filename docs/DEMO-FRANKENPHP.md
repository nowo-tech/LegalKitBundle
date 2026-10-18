# Demo applications with FrankenPHP (development and production)

This document describes how the **Legal Kit Bundle** demo runs under **FrankenPHP** in Docker.

## Table of contents

- [Overview](#overview)
- [What the demo includes](#what-the-demo-includes)
- [Development configuration](#development-configuration)
  - [Caddyfile (development)](#caddyfile-development)
  - [PHP and Twig (development)](#php-and-twig-development)
  - [Start (development)](#start-development)
- [Production configuration](#production-configuration)
- [Switching classic vs worker (`FRANKENPHP_MODE`)](#switching-classic-vs-worker-frankenphp_mode)
- [Timeouts](#timeouts)
- [Troubleshooting](#troubleshooting)
  - [Twig or PHP changes do not appear](#twig-or-php-changes-do-not-appear)
  - [Demo does not start](#demo-does-not-start)
  - [Database errors](#database-errors)
  - [Unstyled legal page](#unstyled-legal-page)

## Overview

The `demo/` folder is **not shipped** in the Composer package (`archive.exclude` includes `/demo`). The demo exists only in the source repository.

The stack uses:

- **FrankenPHP** (Caddy + PHP) in a single `php` container
- A **MySQL 8.4** container `db` on the Compose network only (no published port; REQ-DEMO-006 / REQ-DEMO-011)
- Bundle mount `../..` → `/var/legal-kit-bundle`
- Two Caddyfiles: `Caddyfile` (worker) and `Caddyfile.dev` (classic)
- `FRANKENPHP_MODE` (`classic` \| `worker`, default **`worker`**)

| Demo | UI | Default port |
| --- | --- | --- |
| `demo/symfony8/` | Bootstrap 5 (CDN, demo only) + UiKit host theme | **8167** |

```bash
make -C demo up-symfony8
# Demo started at: http://localhost:8167
```

| Aspect | Development (default) | Production |
| --- | --- | --- |
| FrankenPHP worker | Off when `FRANKENPHP_MODE=classic` | On (`worker`) |
| Twig cache | Off (`config/packages/dev/twig.yaml`) | On |
| OPcache revalidation | Every request (`docker/php-dev.ini`) | Default |
| HTTP cache headers | `no-store` in `Caddyfile.dev` | Omitted |
| `APP_ENV` / `APP_DEBUG` | `dev` / `1` | `prod` / `0` |
| Database | MySQL 8.4 (`db`, schema by `make setup`) | Your database + migrations |

## What the demo includes

- **Symfony Web Profiler** — `dev` and `test`
- **Nowo Twig Inspector** and **Nowo Hot Reload** — `dev`/`test` only (Caddyfile Mercure hub + `hot_reload`; do not enable Hot Reload in production)
- **Twig Extra Bundle** (`twig/extra-bundle` + `twig/string-extra`)
- **Legal Kit Bundle** — Symfony 8.1 (PHP 8.5) app: the four legal pages in en / es / de from the generic seed, the
  footer navigation on every page, the admin editor (CKEditor 5) at `/admin/legal` (admin / admin) and `/ops` with the
  placeholder banner. See [`demo/README.md`](../demo/README.md).
- **CookieConsentBundle** (static inventory, no database configuration) — the cookies page renders its inventory and
  "Manage cookies" opens its modal; LegalKit prepends the legal routes to `disabled_routes`.
- **AuditKitBundle** — `App\Entity\User` is the blame target; the action log records edits (`App\Entity\ActionLog`).
- **Tests**: `make -C demo/symfony8 test` (PHPUnit: pages, admin edit / restore, **MySQL schema adoption** with Beacon's
  `legal_document` DDL), `make -C demo/symfony8 test-e2e` (Playwright), `make -C demo/symfony8 demo-screenshots`
  (README images), `make demo-smoke` from the bundle root (boot + HTTP 200, REQ-TEST-011).
- **PHP / Symfony policy**: the demo tracks the latest PHP (8.5) and Symfony 8.1; the bundle itself supports PHP 8.3+
  and Symfony 7.4 / 8.x.
- **No JavaScript build** — Bootstrap from a CDN for the demo chrome only; the bundle ships one CSS file
  (`/bundles/nowolegalkit/css/nowo-legal-kit.css`, `assets:install`).

Example `config/bundles.php` (abridged: the demo also registers WebProfiler and Debug):

```php
return [
    Symfony\Bundle\FrameworkBundle\FrameworkBundle::class => ['all' => true],
    Symfony\Bundle\TwigBundle\TwigBundle::class => ['all' => true],
    Twig\Extra\TwigExtraBundle\TwigExtraBundle::class => ['all' => true],
    Symfony\Bundle\SecurityBundle\SecurityBundle::class => ['all' => true],
    Doctrine\Bundle\DoctrineBundle\DoctrineBundle::class => ['all' => true],
    Nowo\AuditKitBundle\NowoAuditKitBundle::class => ['all' => true],
    Nowo\UiKitBundle\NowoUiKitBundle::class => ['all' => true],
    Nowo\FormKitBundle\NowoFormKitBundle::class => ['all' => true],
    Nowo\Ckeditor5EditorBundle\NowoCkeditor5EditorBundle::class => ['all' => true],
    Nowo\CookieConsentBundle\NowoCookieConsentBundle::class => ['all' => true],
    Nowo\LegalKitBundle\NowoLegalKitBundle::class => ['all' => true],
    Nowo\HotReloadBundle\NowoHotReloadBundle::class => ['dev' => true, 'test' => true],
    Nowo\TwigInspectorBundle\NowoTwigInspectorBundle::class => ['dev' => true, 'test' => true],
];
```

## Development configuration

### Caddyfile (development)

`demo/symfony8/docker/frankenphp/Caddyfile.dev` — no worker, `no-store` cache headers, the Mercure hub used by Hot Reload.

### PHP and Twig (development)

`docker/php-dev.ini` revalidates OPcache. `config/packages/dev/twig.yaml` sets `twig.cache: false`.

### Start (development)

From the bundle root:

```bash
make -C demo up
```

`make up` in `demo/symfony8` prints `Waiting for container to be ready...`, `Installing dependencies...`, runs `setup`
(schema + admin user), then `Demo started at: http://localhost:<PORT>` from `PORT` in `.env` / `.env.example`
(REQ-DEMO-005). The `php` service waits for the MySQL healthcheck.

## Production configuration

Use `FRANKENPHP_MODE=worker` (default) and `APP_ENV=prod`. Do not enable Hot Reload.

## Switching classic vs worker (`FRANKENPHP_MODE`)

Set `FRANKENPHP_MODE=classic` or `worker` in `.env`, then recreate:

```bash
docker compose up -d --force-recreate
```

The entrypoint copies `Caddyfile.dev` when mode is `classic`.

## Timeouts

The bundle opens no outbound connection; a legal page costs one indexed query and one sanitising pass. See
[CONFIGURATION — Timeouts](CONFIGURATION.md#timeouts). The demo layers its deadlines innermost first (REQ-RUNTIME-001):

| Layer | Where in the demo | Value |
| --- | --- | --- |
| Outbound HTTP (none in the bundle) | `framework.http_client.default_options.timeout` (`config/packages/framework.yaml`) | 5 s |
| PHP `max_execution_time` (and `max_input_time`) | `docker/php-dev.ini` | 30 s |
| Caddy / FrankenPHP write timeout | `servers { timeouts { write 60s } }` in `docker/frankenphp/Caddyfile` and `Caddyfile.dev` | 60 s |
| FrankenPHP `max_wait_time` | `frankenphp { max_wait_time 30s }` in both Caddyfiles | 30 s (wait for a free PHP thread, then 504) |

**HTTP client 5 s < PHP 30 s < Caddy write 60 s.** Raise them together if you change one.

## Troubleshooting

### Twig or PHP changes do not appear

Use `FRANKENPHP_MODE=classic`, confirm `twig.cache: false` in `dev`, and hard-refresh the browser.

### Demo does not start

Ensure Docker is running, port **8167** is free, and `make -C demo/symfony8 logs` shows FrankenPHP listening. Composer DNS: Compose sets `8.8.8.8` (REQ-DEMO-009).

### Database errors

`make -C demo/symfony8 setup` creates the schema (`doctrine:schema:update --force`) and the admin user. The `php`
container starts only after the MySQL healthcheck; on a first start MySQL may need ~20 s.

### Unstyled legal page

The page loads `/bundles/nowolegalkit/css/nowo-legal-kit.css`: run `make -C demo/symfony8 assets-install`
(`assets:install public --symlink --relative`, also part of `make install`).
