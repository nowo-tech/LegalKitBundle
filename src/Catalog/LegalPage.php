<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Catalog;

/**
 * One legal page of the catalogue (immutable).
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class LegalPage
{
    /** Prefix of the public route names: `legal_<slug>` (REQ-CC-003 contract). */
    public const string ROUTE_PREFIX = 'legal_';

    /** Suffix of the bare (default locale) twin route: `legal_<slug>_unlocalized`. */
    public const string UNLOCALIZED_SUFFIX = '_unlocalized';

    /**
     * @param string $slug Stable identifier (`legal_document.slug`)
     * @param string $path URL segment after the routing path prefix
     * @param bool $nav Listed in the footer / cross-page navigation
     * @param string $seedTemplate Twig template of the built-in body
     */
    public function __construct(
        public string $slug,
        public string $path,
        public bool $nav,
        public string $seedTemplate,
    ) {
    }

    /**
     * Name of the localized public route (`legal_privacy`).
     */
    public function routeName(): string
    {
        return self::ROUTE_PREFIX . $this->slug;
    }

    /**
     * Name of the bare default-locale twin (`legal_privacy_unlocalized`).
     */
    public function unlocalizedRouteName(): string
    {
        return $this->routeName() . self::UNLOCALIZED_SUFFIX;
    }

    /**
     * Translation key (domain NowoLegalKitBundle) of the page label.
     */
    public function labelKey(): string
    {
        return 'page.' . $this->slug;
    }
}
