<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Event;

use Nowo\LegalKitBundle\Document\ResolvedLegalDocument;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched before a public legal page renders, so the host feeds its page head (title, canonical, hreflang) or adds
 * template variables (public API, SemVer).
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class LegalPageRenderEvent extends Event
{
    /** @var array<string, mixed> */
    private array $templateVariables = [];

    /**
     * @param string $slug Page slug
     * @param string $locale Requested locale
     * @param string $contentLocale Locale the text is written in
     * @param ResolvedLegalDocument $document Published document
     * @param array<string, string> $alternates Locale => absolute URL of the same page
     * @param string $canonicalUrl Absolute URL of this page
     */
    public function __construct(
        public readonly string $slug,
        public readonly string $locale,
        public readonly string $contentLocale,
        public readonly ResolvedLegalDocument $document,
        public readonly array $alternates,
        public readonly string $canonicalUrl,
    ) {
    }

    /**
     * Adds a variable to the page template context.
     */
    public function setTemplateVariable(string $name, mixed $value): void
    {
        $this->templateVariables[$name] = $value;
    }

    /**
     * Extra template variables set by listeners.
     *
     * @return array<string, mixed>
     */
    public function templateVariables(): array
    {
        return $this->templateVariables;
    }
}
