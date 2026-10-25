<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Catalog;

use OutOfBoundsException;

use function array_keys;
use function array_map;
use function array_values;
use function sprintf;

/**
 * Catalogue built from `nowo_legal_kit.pages` (disabled pages are left out).
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class ConfiguredLegalPageCatalog implements LegalPageCatalogInterface
{
    /** Default seed template of a slug. */
    public const string SEED_TEMPLATE = '@NowoLegalKitBundle/seed/%s.html.twig';

    /** @var array<string, LegalPage> */
    private array $pages;

    /**
     * @param array<string, array{path?: string|null, enabled?: bool, nav?: bool, seed_template?: string|null}> $pages Processed `pages` configuration
     */
    public function __construct(array $pages)
    {
        $list = [];
        foreach ($pages as $slug => $options) {
            $slug = (string) $slug;
            if (!($options['enabled'] ?? true)) {
                continue;
            }
            $list[$slug] = new LegalPage(
                $slug,
                $options['path'] ?? $slug,
                $options['nav'] ?? true,
                $options['seed_template'] ?? sprintf(self::SEED_TEMPLATE, $slug),
            );
        }
        $this->pages = $list;
    }

    /**
     * {@inheritDoc}
     */
    public function slugs(): array
    {
        return array_map(strval(...), array_keys($this->pages));
    }

    /**
     * {@inheritDoc}
     */
    public function pages(): array
    {
        return array_values($this->pages);
    }

    /**
     * {@inheritDoc}
     */
    public function has(string $slug): bool
    {
        return isset($this->pages[$slug]);
    }

    /**
     * {@inheritDoc}
     */
    public function page(string $slug): LegalPage
    {
        return $this->pages[$slug] ?? throw new OutOfBoundsException(sprintf('Unknown legal page "%s".', $slug));
    }
}
