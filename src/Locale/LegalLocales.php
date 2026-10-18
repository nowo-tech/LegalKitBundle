<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Locale;

use InvalidArgumentException;

use function explode;
use function implode;
use function in_array;
use function is_array;
use function is_string;
use function json_decode;
use function ltrim;
use function preg_match;
use function preg_quote;
use function sprintf;
use function str_contains;
use function str_starts_with;
use function trim;

/**
 * The enabled locales of the legal pages, default locale first (admin tabs, scanners, exports).
 *
 * Accepts a literal list or the resolved value of a parameter / env var: "%app.locales%", "en,es", "en|es" or a JSON
 * list. Immutable and request-independent (FrankenPHP worker safe).
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class LegalLocales
{
    /** Locale code accepted in `legal_document.locale` (VARCHAR 8). */
    public const string LOCALE_PATTERN = '/^[a-z]{2,3}(?:[_-][A-Za-z0-9]{2,4})?$/';

    /** @var non-empty-list<string> */
    private array $locales;

    private string $defaultLocale;

    /**
     * @param mixed $locales Raw `nowo_legal_kit.locales` value (list, separated string or JSON list)
     * @param string|null $defaultLocale Raw `nowo_legal_kit.default_locale` (null or '' = first locale)
     *
     * @throws InvalidArgumentException When no valid locale is configured or the default locale is not enabled
     */
    public function __construct(mixed $locales, ?string $defaultLocale = null)
    {
        $list = self::normalize($locales);
        if ($list === []) {
            throw new InvalidArgumentException('nowo_legal_kit.locales: at least one locale is required.');
        }
        foreach ($list as $locale) {
            if (preg_match(self::LOCALE_PATTERN, $locale) !== 1) {
                throw new InvalidArgumentException(sprintf('nowo_legal_kit.locales: invalid locale "%s".', $locale));
            }
        }
        $default = $defaultLocale !== null && trim($defaultLocale) !== '' ? trim($defaultLocale) : $list[0];
        if (!in_array($default, $list, true)) {
            throw new InvalidArgumentException(sprintf('nowo_legal_kit.default_locale "%s" is not one of the enabled locales (%s).', $default, implode(', ', $list)));
        }

        $ordered = [$default];
        foreach ($list as $locale) {
            if ($locale !== $default) {
                $ordered[] = $locale;
            }
        }
        $this->locales       = $ordered;
        $this->defaultLocale = $default;
    }

    /**
     * Normalises a raw locale list (no validation): trimmed, non-empty, unique, in order.
     *
     * @return list<string>
     */
    public static function normalize(mixed $locales): array
    {
        if (is_string($locales)) {
            $decoded = str_starts_with(ltrim($locales), '[') ? json_decode($locales, true) : null;
            $locales = is_array($decoded) ? $decoded : explode(str_contains($locales, '|') ? '|' : ',', $locales);
        }
        if (!is_array($locales)) {
            return [];
        }

        $list = [];
        foreach ($locales as $locale) {
            if (!is_string($locale)) {
                continue;
            }
            $locale = trim($locale);
            if ($locale !== '' && !in_array($locale, $list, true)) {
                $list[] = $locale;
            }
        }

        return $list;
    }

    /**
     * Enabled locales, default locale first.
     *
     * @return non-empty-list<string>
     */
    public function all(): array
    {
        return $this->locales;
    }

    /**
     * The default locale (bare URLs with the unprefixed style).
     *
     * @return string The value
     */
    public function defaultLocale(): string
    {
        return $this->defaultLocale;
    }

    /**
     * Whether a locale is enabled.
     *
     * @return bool The answer
     */
    public function has(string $locale): bool
    {
        return in_array($locale, $this->locales, true);
    }

    /**
     * Route requirement matching exactly the enabled locales (`en|es|de`).
     *
     * @return string The value
     */
    public function requirement(): string
    {
        $quoted = [];
        foreach ($this->locales as $locale) {
            $quoted[] = preg_quote($locale, '#');
        }

        return implode('|', $quoted);
    }
}
