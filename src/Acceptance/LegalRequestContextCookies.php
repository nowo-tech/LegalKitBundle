<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Acceptance;

use WeakMap;

use function array_key_exists;

/**
 * Holds the values of the host-allowlisted cookies (`acceptance.context_cookies`) of each {@see LegalRequestContext}
 * outside the object, so no dump of the context (VarDumper / `dump()`, `var_export()`, `print_r()`, `(array)` cast,
 * `json_encode()`, `serialize()`) can reach them.
 *
 * Keyed by the context object in a {@see WeakMap}: an entry disappears with its context, so nothing outlives the
 * request in a FrankenPHP worker (no reset needed, REQ-RUNTIME-002).
 *
 * @internal
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class LegalRequestContextCookies
{
    /** @var WeakMap<LegalRequestContext, array<string, string>>|null */
    private static ?WeakMap $values = null; // @phpstan-ignore frankenphp.worker.noMutableStaticProperty (WeakMap keyed by the per-request VO)

    /**
     * Stores the allowlisted cookies of a context.
     *
     * @param LegalRequestContext $context The per-request context
     * @param array<string, string> $cookies Cookie name => value
     */
    public static function put(LegalRequestContext $context, array $cookies): void
    {
        // A WeakMap keyed by the per-request VO: each entry dies with its context, nothing crosses requests.
        // @igor-ignore - WeakMap keyed by the per-request VO; entries are collected with it.
        self::$values ??= new WeakMap(); // @phpstan-ignore frankenphp.worker.noMutableStaticProperty
        // @igor-ignore - WeakMap keyed by the per-request VO; entries are collected with it.
        self::$values[$context] = $cookies; // @phpstan-ignore frankenphp.worker.noMutableStaticProperty
    }

    /**
     * Whether the context carries this allowlisted cookie.
     *
     * @param LegalRequestContext $context The per-request context
     * @param string $name Cookie name (exact)
     */
    public static function has(LegalRequestContext $context, string $name): bool
    {
        return isset(self::$values[$context]) && array_key_exists($name, self::$values[$context]);
    }

    /**
     * The value of one allowlisted cookie, or null when it is absent (or after `clone` / `unserialize()`).
     *
     * @param LegalRequestContext $context The per-request context
     * @param string $name Cookie name (exact)
     */
    public static function get(LegalRequestContext $context, string $name): ?string
    {
        return self::$values[$context][$name] ?? null;
    }
}
