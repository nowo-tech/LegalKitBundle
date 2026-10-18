# Installation

## Table of contents

- [Requirements](#requirements)
- [Install the package](#install-the-package)
- [Register the bundles](#register-the-bundles)
- [AuditKit user class](#auditkit-user-class)
- [Configure and import the routes](#configure-and-import-the-routes)
- [Security](#security)
- [Database table](#database-table)
- [Assets](#assets)
- [Adopting the bundle from a legal copy](#adopting-the-bundle-from-a-legal-copy)
- [Verify](#verify)

## Requirements

- PHP 8.3+ (`ext-dom`, `ext-libxml`), Symfony 7.4 or 8.x
- Doctrine ORM 3 with DoctrineBundle
- `nowo-tech/audit-kit-bundle` ^1.7, `nowo-tech/ui-kit-bundle` ^2.4, `nowo-tech/form-kit-bundle` ^2.12 (installed
  with the bundle)
- SecurityBundle for the admin pages (or `web_ui.enabled: false`)

## Install the package

```bash
composer require nowo-tech/legal-kit-bundle
# optional integrations
composer require nowo-tech/ckeditor5-editor-bundle nowo-tech/cookie-consent-bundle
```

## Register the bundles

Symfony Flex registers them. Without Flex:

```php
// config/bundles.php
return [
    // …
    Nowo\AuditKitBundle\NowoAuditKitBundle::class => ['all' => true],
    Nowo\UiKitBundle\NowoUiKitBundle::class => ['all' => true],
    Nowo\FormKitBundle\NowoFormKitBundle::class => ['all' => true],
    Nowo\LegalKitBundle\NowoLegalKitBundle::class => ['all' => true],
];
```

## AuditKit user class

`created_by_id` / `updated_by_id` reference your user through AuditKit's `AuditUserInterface`. Your user class
implements the marker interface and AuditKit resolves it:

```yaml
# config/packages/nowo_audit_kit.yaml
nowo_audit_kit:
    user_class: App\Entity\User
```

```php
use Nowo\AuditKitBundle\Model\AuditUserInterface;

class User implements UserInterface, AuditUserInterface { /* … */ }
```

## Configure and import the routes

```yaml
# config/packages/nowo_legal_kit.yaml
nowo_legal_kit:
    locales: '%app.locales%'                 # list, parameter, "en,es" or "en|es"
    default_locale: '%kernel.default_locale%'
    brand_name: '%env(APP_BRAND_NAME)%'
```

```yaml
# config/routes/nowo_legal_kit.yaml
nowo_legal_kit:
    resource: .
    type: nowo_legal_kit
```

The loader registers the public routes (`legal_<slug>`, `legal_<slug>_unlocalized` or `legal_<slug>_bare`) and,
with `web_ui.enabled`, the admin routes (`nowo_legal_kit_admin_index|edit|reset` under `/admin/legal`).

## Security

The public pages must be anonymous-readable and the admin private (REQ-UI-002 — the bundle checks
`security.access_roles` itself too):

```yaml
# config/packages/security.yaml
security:
    access_control:
        - { path: ^/(?:[a-z]{2}(?:_[A-Z]{2})?/)?legal/, roles: PUBLIC_ACCESS }
        - { path: ^/admin/legal, roles: ROLE_ADMIN }
```

`bin/console nowo:legal-kit:doctor` warns when a public legal URL is not anonymous-readable.

## Database table

Bundles ship no migrations. The bundle maps its entity automatically (prepended `doctrine.orm.mappings`; hosts with
several entity managers add the mapping `Nowo\LegalKitBundle\Entity` → `vendor/nowo-tech/legal-kit-bundle/src/Entity`
themselves). Generate the migration:

```bash
bin/console doctrine:migrations:diff
bin/console doctrine:migrations:migrate
```

Expected DDL (MySQL 8):

```sql
CREATE TABLE legal_document (
    id INT AUTO_INCREMENT NOT NULL,
    slug VARCHAR(32) NOT NULL,
    locale VARCHAR(8) NOT NULL,
    title VARCHAR(180) NOT NULL,
    body LONGTEXT NOT NULL,
    created_at DATETIME DEFAULT NULL,
    updated_at DATETIME DEFAULT NULL,
    created_by_id INT DEFAULT NULL,
    updated_by_id INT DEFAULT NULL,
    UNIQUE INDEX uniq_legal_document_slug_locale (slug, locale),
    INDEX idx_legal_document_created_by (created_by_id),
    INDEX idx_legal_document_updated_by (updated_by_id),
    PRIMARY KEY (id),
    CONSTRAINT FK_legal_document_created_by FOREIGN KEY (created_by_id) REFERENCES user (id) ON DELETE SET NULL,
    CONSTRAINT FK_legal_document_updated_by FOREIGN KEY (updated_by_id) REFERENCES user (id) ON DELETE SET NULL
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB;
```

With `doctrine.table_prefix: app_` the table becomes `app_legal_document` and its index names get the same prefix.

## Assets

The public page loads one small stylesheet (`css/nowo-legal-kit.css`, asset package `nowo_legal_kit`):

```bash
bin/console assets:install
```

## Adopting the bundle from a legal copy

The six applications that copied the legal feature (`src/Shared/Legal/**`) share the same table. The bundle maps it as
is — **no data migration**:

1. Require the bundle and configure it with the application's current behaviour (`default_locale_style: prefixed`
   if its URLs are prefixed today; `seed.materialize: true` if rows are seeded; `seed.fallback_locale` …).
2. In **the same commit**, delete the host entity `App\Shared\Legal\Entity\LegalDocument` and its
   `doctrine.orm.mappings` entry (two entities on one table break the schema tool), the controllers, services,
   commands and templates of the copy, and the legal route lists of `nowo_cookie_consent` (the bundle prepends them).
3. `bin/console doctrine:schema:update --dump-sql` must print nothing for `legal_document`.
4. Index names: if a copy renamed the indexes to Doctrine hashes, add one migration (take the current names from
   `SHOW INDEX FROM legal_document`; the ones below are examples):

```sql
ALTER TABLE legal_document RENAME INDEX idx_5e3f1c4b8b3e3c1a TO idx_legal_document_created_by;
ALTER TABLE legal_document RENAME INDEX idx_5e3f1c4b3a3b5f2d TO idx_legal_document_updated_by;
```

(MySQL index names are case-insensitive: Beacon's `IDX_legal_document_created_by` already matches.)

5. Replace references: `admin_legal_*` route names keep working through deprecated aliases (`nowo_legal_kit_admin_*`
   is the new name), `app:legal:check-placeholders` and `app:ops-seed:adapt-legal` are aliases of
   `nowo:legal-kit:check-placeholders` / `nowo:legal-kit:adapt-seed` until 2.0; the host ops-seed delegates its
   `legal_documents` key to `LegalDocumentPortability`.
6. Delete the `legal.*` seed keys the host no longer needs (keep only the text it changed, as `NowoLegalKitSeed`
   overrides).

## Verify

```bash
bin/console debug:router | grep legal_
bin/console nowo:legal-kit:doctor
bin/console nowo:legal-kit:check-placeholders
```
