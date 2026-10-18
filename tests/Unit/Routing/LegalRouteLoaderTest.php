<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Tests\Unit\Routing;

use Nowo\LegalKitBundle\Catalog\ConfiguredLegalPageCatalog;
use Nowo\LegalKitBundle\Locale\LegalLocales;
use Nowo\LegalKitBundle\Routing\LegalRouteLoader;
use PHPUnit\Framework\TestCase;

final class LegalRouteLoaderTest extends TestCase
{
    public function testUnprefixedStyle(): void
    {
        $loader = new LegalRouteLoader(new ConfiguredLegalPageCatalog(['notice' => ['path' => 'imprint'], 'privacy' => []]), new LegalLocales(['en', 'es'], 'es'));
        self::assertTrue($loader->supports('.', 'nowo_legal_kit'));
        self::assertFalse($loader->supports('.', 'annotation'));
        $routes = $loader->load('.');

        $notice = $routes->get('legal_notice');
        self::assertNotNull($notice);
        self::assertSame('/{_locale}/legal/imprint', $notice->getPath());
        self::assertSame('es|en', $notice->getRequirement('_locale'));
        self::assertSame(['GET', 'HEAD'], $notice->getMethods());
        self::assertSame('notice', $notice->getDefault('_legal_slug'));
        $twin = $routes->get('legal_notice_unlocalized');
        self::assertNotNull($twin);
        self::assertSame('/legal/imprint', $twin->getPath());
        self::assertSame('es', $twin->getDefault('_locale'));
        self::assertNull($routes->get('legal_notice_bare'));

        $edit = $routes->get(LegalRouteLoader::ADMIN_EDIT);
        self::assertNotNull($edit);
        self::assertSame('/admin/legal/{slug}/{locale}', $edit->getPath());
        self::assertSame('notice|privacy', $edit->getRequirement('slug'));
        self::assertSame(['POST'], $routes->get(LegalRouteLoader::ADMIN_RESET)?->getMethods());
        self::assertSame(LegalRouteLoader::ADMIN_INDEX, $routes->getAlias('admin_legal_index')?->getId());
        self::assertTrue($routes->getAlias('admin_legal_index')?->isDeprecated());
    }

    public function testPrefixedStyleWithoutAdmin(): void
    {
        $loader = new LegalRouteLoader(new ConfiguredLegalPageCatalog(['terms' => []]), new LegalLocales(['en']), '/rechtliches/', 'prefixed', false, '/admin/legal', true);
        $routes = $loader->load('.');

        self::assertSame('/{_locale}/rechtliches/terms', $routes->get('legal_terms')?->getPath());
        self::assertSame('/rechtliches/terms', $routes->get('legal_terms_bare')?->getPath());
        self::assertNull($routes->get('legal_terms_unlocalized'));
        self::assertNull($routes->get(LegalRouteLoader::ADMIN_INDEX));
        self::assertNull($routes->getAlias('admin_legal_index'));
    }

    public function testNoAdminWithoutPages(): void
    {
        $routes = (new LegalRouteLoader(new ConfiguredLegalPageCatalog([]), new LegalLocales(['en'])))->load('.');

        self::assertCount(0, $routes);
    }
}
