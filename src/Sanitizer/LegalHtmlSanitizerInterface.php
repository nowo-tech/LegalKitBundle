<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Sanitizer;

/**
 * Sanitises legal HTML on save, on seed materialisation, on import and on every public render (public API, SemVer).
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
interface LegalHtmlSanitizerInterface
{
    /**
     * Returns safe HTML (fails closed: unknown markup is unwrapped, dangerous markup removed).
     *
     * @return string Sanitised HTML
     */
    public function sanitize(string $html): string;
}
