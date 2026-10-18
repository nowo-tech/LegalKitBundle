<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Catalog;

/**
 * The ordered catalogue of enabled legal pages (public API, SemVer).
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
interface LegalPageCatalogInterface
{
    /**
     * Enabled slugs, in footer order.
     *
     * @return list<string>
     */
    public function slugs(): array;

    /**
     * Enabled pages, in footer order.
     *
     * @return list<LegalPage>
     */
    public function pages(): array;

    /**
     * Whether the slug is an enabled page.
     */
    public function has(string $slug): bool;

    /**
     * The page of an enabled slug.
     *
     * @throws \OutOfBoundsException When the slug is not an enabled page
     */
    public function page(string $slug): LegalPage;
}
