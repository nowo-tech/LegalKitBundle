<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Tests\Unit\Routing;

use Nowo\LegalKitBundle\Catalog\ConfiguredLegalPageCatalog;
use Nowo\LegalKitBundle\Locale\LegalLocales;
use Nowo\LegalKitBundle\Routing\LegalRouteLoader;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Routing\AttributeRouteControllerLoader;
use Symfony\Component\Config\Loader\LoaderResolver;

final class LegalRouteLoaderTest extends TestCase
{
    public function testUnprefixedStyle(): void
    {
        $loader = new LegalRouteLoader(new ConfiguredLegalPageCatalog(['notice' => ['path' => 'imprint'], 'privacy' => []]), new LegalLocales(['en', 'es'], 'es'));
        $loader->setResolver(new LoaderResolver([new AttributeRouteControllerLoader()]));
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
        self::assertSame('/%nowo_legal_kit.web_ui.route_path%/{slug}/{locale}', $edit->getPath(), 'Attribute route; the router resolves the parameters');
        self::assertSame('%nowo_legal_kit.slug_requirement%', $edit->getRequirement('slug'));
        self::assertSame(['POST'], $routes->get(LegalRouteLoader::ADMIN_RESET)?->getMethods());
        $alias = $routes->getAlias('admin_legal_index');
        self::assertNotNull($alias);
        self::assertSame(LegalRouteLoader::ADMIN_INDEX, $alias->getId());
        self::assertTrue($alias->isDeprecated());
    }

    public function testPrefixedStyleWithoutAdmin(): void
    {
        $loader = new LegalRouteLoader(new ConfiguredLegalPageCatalog(['terms' => []]), new LegalLocales(['en']), '/rechtliches/', 'prefixed', false, true);
        $routes = $loader->load('.');

        self::assertSame('/{_locale}/rechtliches/terms', $routes->get('legal_terms')?->getPath());
        self::assertSame('/rechtliches/terms', $routes->get('legal_terms_bare')?->getPath());
        self::assertNull($routes->get('legal_terms_unlocalized'));
        self::assertNull($routes->get(LegalRouteLoader::ADMIN_INDEX));
        self::assertNull($routes->getAlias('admin_legal_index'));
    }

    public function testNoAdminWithoutPages(): void
    {
        $loader = new LegalRouteLoader(new ConfiguredLegalPageCatalog([]), new LegalLocales(['en']));
        $loader->setResolver(new LoaderResolver([new AttributeRouteControllerLoader()]));
        $routes = $loader->load('.');

        self::assertCount(0, $routes);
    }
}
