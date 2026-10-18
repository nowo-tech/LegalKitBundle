## AI contribution guidelines — Legal Kit Bundle

Follow these rules when contributing PHP, Twig and documentation to this repository.

---

## Project scope

- **Type:** Standalone Symfony bundle (`nowo-tech/legal-kit-bundle`): operator-editable legal pages (notice, privacy,
  terms, cookies) per locale, generic seed, placeholder gate, ops-seed portability, DOM sanitiser, UiKit admin pages.
- **PHP:** `>=8.3 <8.6` with `declare(strict_types=1);` in every PHP file.
- **Symfony:** **7.4**, **8.0**, and **8.1+** (`^7.4 || ^8.0`). Symfony 8 requires PHP 8.4+.
- **Code:** `src/` (`Nowo\LegalKitBundle\`). No JavaScript / TypeScript.
- **Language:** PHPDoc, inline comments and user-facing docs in **English** only.

---

## PHP standards

- PSR-12 + Symfony coding standards; run `make cs-check` before finishing.
- Strict comparisons, constructor injection, `final` / `readonly` classes.
- Wire services in `src/Resources/config/services.php`; keep the config root `nowo_legal_kit`.
- Public contract: route names `legal_<slug>` (+ `_unlocalized` / `_bare`), admin `nowo_legal_kit_admin_*`, the
  `legal_document` table mapping (column names, lengths, index names) — changes are breaking.

---

## Bundle-specific conventions

- Twig namespace `@NowoLegalKitBundle`; translation domains `NowoLegalKitBundle` (UI) and `NowoLegalKitSeed` (seed),
  7 locales each (en es de nl fr it pt) — `make validate-translations`.
- Every HTML write and render goes through `LegalHtmlSanitizerInterface`; tokens are resolved before sanitising.
- Admin pages use UiKit view models and scaffolds (REQ-UI-003); access through `LegalKitAccessCheckerInterface`.
- No per-request state in services (FrankenPHP worker mode) unless `ResetInterface` + `kernel.reset`.

---

## Tests and quality

- PHPUnit target: **100% line coverage** on `src/` (gate ≥ 99%); justify exclusions in `docs/COVERAGE.md`.
- Full gate: `make release-check` (style, static analysis, Igor, coverage, translations, demos).

---

## Documentation

- Keep `README.md` badges and the **Documentation** section aligned with `docs/`.
- Update `docs/CHANGELOG.md` and `docs/UPGRADING.md` for user-visible changes.
- Flex recipe lives in `.symfony/recipe/nowo-tech/legal-kit-bundle/1.0/`.

---

## Do not

- Commit secrets, `.env` files, or demo `var/` caches.
- Add Spanish PHPDoc or comments in `src/`.
- Write legal advice into the seed (generic placeholders only; counsel review is the operator's duty).

## Commits

- Never add AI co-author trailers: no `Co-authored-by: Cursor` / `cursoragent@cursor.com`, no `Co-Authored-By: Claude …` / `noreply@anthropic.com` (REQ-GIT-001).
