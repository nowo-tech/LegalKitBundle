<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Token;

/**
 * Value of a token: plain text (HTML-escaped on insertion) or markup produced by trusted code.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class LegalTokenValue
{
    private function __construct(
        public string $value,
        public bool $html,
    ) {
    }

    /**
     * Plain text, escaped when inserted into HTML.
     *
     * @return self Fluent interface
     */
    public static function text(string $value): self
    {
        return new self($value, false);
    }

    /**
     * Trusted HTML (e.g. rendered by an auto-escaping Twig template), inserted as is.
     *
     * @return self Fluent interface
     */
    public static function html(string $value): self
    {
        return new self($value, true);
    }
}
