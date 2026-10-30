<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Tests\Integration;

use Nowo\LegalKitBundle\Routing\LegalRouteLoader;
use Nowo\LegalKitBundle\Tests\Support\KernelTestCase;
use Symfony\Component\Routing\Loader\AttributeServicesLoader;
use Symfony\Component\Routing\RouterInterface;

use function sprintf;

/**
 * REQ-SF-004 rule 7: the legal pages and admin routes are opt-in (the `nowo_legal_kit` route type); the host's
 * default `controllers: { resource: routing.controllers }` import must not register any of them.
 */
final class HostControllersRoutesTest extends KernelTestCase
{
    public function testBundleRoutesAreNotRegisteredWithoutTheRouteImport(): void
    {
        if (!class_exists(AttributeServicesLoader::class)) {
            self::markTestSkipped('The routing.controllers resource needs symfony/routing >= 7.3.');
        }

        $router = $this->bootKernel([], ['host_controllers'])->getContainer()->get('router');
        self::assertInstanceOf(RouterInterface::class, $router);
        $routes = $router->getRouteCollection();

        foreach ([LegalRouteLoader::ADMIN_INDEX, LegalRouteLoader::ADMIN_EDIT, LegalRouteLoader::ADMIN_RESET] as $name) {
            self::assertNull($routes->get($name), sprintf('Route "%s" must only exist when the host imports the nowo_legal_kit route type.', $name));
        }
        foreach ($routes as $name => $route) {
            self::assertStringStartsNotWith('nowo_legal_kit', $name);
            self::assertStringStartsNotWith('legal_', $name);
        }
    }
}
