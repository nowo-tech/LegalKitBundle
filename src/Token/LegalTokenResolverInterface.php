<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Token;

/**
 * Resolves `%token%` placeholders of legal texts at render time (tagged `nowo_legal_kit.token_resolver`, chained by
 * priority; public API, SemVer). The editor and exports keep the raw tokens.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
interface LegalTokenResolverInterface
{
    public const string TAG = 'nowo_legal_kit.token_resolver';

    /**
     * Token names (without `%`) this resolver knows; the placeholder scanner reports any other `%name%`.
     *
     * @return list<string>
     */
    public function names(): array;

    /**
     * The value of a token (name without `%`), a string being plain text; null when this resolver cannot resolve it.
     *
     * @return LegalTokenValue|string|null The result
     */
    public function resolve(string $name, LegalTokenContext $context): LegalTokenValue|string|null;
}
