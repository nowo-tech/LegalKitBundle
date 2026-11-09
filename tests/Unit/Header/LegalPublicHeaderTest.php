<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Tests\Unit\Header;

use Nowo\LegalKitBundle\Brand\LegalBrand;
use Nowo\LegalKitBundle\Header\LegalPublicHeader;
use Nowo\LegalKitBundle\Tests\Support\RecordingLogger;
use Nowo\LegalKitBundle\Tests\Support\TestBrandProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;

/**
 * MUX-150: the optional brand / home / sign-in header of the public pages.
 */
final class LegalPublicHeaderTest extends TestCase
{
    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function targets(): iterable
    {
        yield 'root' => ['/', true];
        yield 'path with query' => ['/login?next=/legal', true];
        yield 'https' => ['https://example.com/app', true];
        yield 'http with port' => ['http://localhost:8080', true];
        yield 'route' => ['app_login', true];
        yield 'dotted route' => ['nowo_legal_kit.page-notice', true];
        yield 'javascript' => ['javascript:alert(1)', false];
        yield 'data' => ['data:text/html,x', false];
        yield 'mailto' => ['mailto:a@example.com', false];
        yield 'protocol relative' => ['//evil.example', false];
        yield 'backslash' => ['/\evil.example', false];
        yield 'quote' => ['/x"y', false];
        yield 'angle' => ['/<x>', false];
        yield 'space' => ['/a b', false];
        yield 'empty' => ['', false];
        yield 'newline' => ["app\nlogin", false];
    }

    #[DataProvider('targets')]
    public function testTargets(string $target, bool $valid): void
    {
        self::assertSame($valid, LegalPublicHeader::isValidTarget($target));
    }

    public function testOffByDefault(): void
    {
        self::assertNull((new LegalPublicHeader($this->urls()))->view());
    }

    public function testBrandNameAndResolvedLinks(): void
    {
        $header = new LegalPublicHeader($this->urls(), true, 'Acme', true, 'app_home', '/login');

        self::assertSame(['brand' => 'Acme', 'logo' => null, 'home' => '/app', 'sign_in' => '/login'], $header->view());
    }

    public function testTheProviderWinsAndItsLogoIsUsed(): void
    {
        $provider       = new TestBrandProvider();
        $provider->logo = '/brand/logo.png';
        $header         = new LegalPublicHeader($this->urls(), true, 'Configured', true, '/', null, new LegalBrand($provider));

        self::assertSame(['brand' => 'Acme <Cloud> & "Co"', 'logo' => '/brand/logo.png', 'home' => '/', 'sign_in' => null], $header->view());
    }

    public function testLogoOff(): void
    {
        $provider       = new TestBrandProvider();
        $provider->logo = 'https://cdn.example.com/logo.png';
        $view           = (new LegalPublicHeader($this->urls(), true, '', false, null, null, new LegalBrand($provider)))->view();

        self::assertNotNull($view);
        self::assertNull($view['logo']);
        self::assertNull($view['home']);
    }

    public function testAFailingProviderFallsBackToTheConfiguredName(): void
    {
        $provider       = new TestBrandProvider();
        $provider->fail = true;
        $view           = (new LegalPublicHeader($this->urls(), true, 'Configured', true, '/', null, new LegalBrand($provider)))->view();

        self::assertNotNull($view);
        self::assertSame('Configured', $view['brand']);
        self::assertNull($view['logo']);
    }

    public function testAnUnknownRouteIsLoggedAndLeftOut(): void
    {
        $logger = new RecordingLogger();
        $view   = (new LegalPublicHeader($this->urls(), true, 'Acme', true, 'missing_route', 'app_home', logger: $logger))->view();

        self::assertNotNull($view);
        self::assertNull($view['home']);
        self::assertSame('/app', $view['sign_in']);
        self::assertNotEmpty($logger->records);
    }

    public function testAnInvalidTargetPassedDirectlyIsIgnored(): void
    {
        $view = (new LegalPublicHeader($this->urls(), true, 'Acme', true, 'javascript:alert(1)', '//evil.example'))->view();

        self::assertNotNull($view);
        self::assertNull($view['home']);
        self::assertNull($view['sign_in']);
    }

    public function testNoSignInLinkForASignedInUser(): void
    {
        $tokens    = new TokenStorage();
        $header    = new LegalPublicHeader($this->urls(), true, 'Acme', true, '/', '/login', tokens: $tokens);
        $anonymous = $header->view();
        self::assertNotNull($anonymous);
        self::assertSame('/login', $anonymous['sign_in']);

        $tokens->setToken(new UsernamePasswordToken(new InMemoryUser('ana', null), 'main'));
        $signedIn = $header->view();
        self::assertNotNull($signedIn);
        self::assertNull($signedIn['sign_in']);
        self::assertSame('/', $signedIn['home']);
        self::assertTrue($header->hidesSignInForCurrentUser());

        self::assertFalse((new LegalPublicHeader($this->urls(), true, 'Acme', true, '/', null, tokens: $tokens))->hidesSignInForCurrentUser(), 'No sign-in target');
        self::assertFalse((new LegalPublicHeader($this->urls(), false, 'Acme', true, '/', '/login', tokens: $tokens))->hidesSignInForCurrentUser(), 'Header off');
        $tokens->setToken(null);
        self::assertFalse($header->hidesSignInForCurrentUser(), 'Anonymous');
    }

    private function urls(): UrlGenerator
    {
        $routes = new RouteCollection();
        $routes->add('app_home', new Route('/app'));

        return new UrlGenerator($routes, new RequestContext());
    }
}
