<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Tests\Unit\Token;

use Nowo\CookieConsentBundle\Config\CookieInventoryNormalizer;
use Nowo\CookieConsentBundle\Config\CookieInventoryProvider;
use Nowo\CookieConsentBundle\Repository\CookieDefinitionRepository;
use Nowo\LegalKitBundle\Token\CookieInventoryTokenResolver;
use Nowo\LegalKitBundle\Token\LegalTokenContext;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\TwigFunction;

final class CookieInventoryTokenResolverTest extends TestCase
{
    public function testWithoutCookieConsentTheTokenRendersNothing(): void
    {
        $resolver = new CookieInventoryTokenResolver($this->twig());

        self::assertSame(['cookie_inventory'], $resolver->names());
        self::assertNull($resolver->resolve('brand', new LegalTokenContext('cookies', 'en')));
        $value = $resolver->resolve('cookie_inventory', new LegalTokenContext('cookies', 'en'));
        self::assertNotNull($value);
        self::assertTrue($value->html);
        self::assertSame('', $value->value);
    }

    public function testInventoryRowsAreRendered(): void
    {
        $twig = $this->twig();
        $twig->addFunction(new TwigFunction('nowo_cookie_consent_cookie_inventory', static fn (): array => [
            ['name' => 'PHPSESSID', 'category' => 'necessary', 'duration' => 'session', 'purpose' => 'Session <b>', 'provider' => 'Site'],
            ['name' => 42],
            'broken',
            ['name' => 'consent', 'category' => null],
        ]));

        self::assertSame('PHPSESSID|necessary|session|Session <b>|Site;consent||||;', (new CookieInventoryTokenResolver($twig))->render());
    }

    public function testFailingOrEmptyInventory(): void
    {
        $twig = $this->twig();
        $twig->addFunction(new TwigFunction('nowo_cookie_consent_cookie_inventory', static fn (): array => throw new RuntimeException('no request')));
        self::assertSame('', (new CookieInventoryTokenResolver($twig))->render());

        $twig = $this->twig();
        $twig->addFunction(new TwigFunction('nowo_cookie_consent_cookie_inventory', static fn (): string => 'nope'));
        self::assertSame('', (new CookieInventoryTokenResolver($twig))->render());
    }

    public function testStaticInventoryWhenNoProfileIsResolved(): void
    {
        $twig = $this->twig();
        $twig->addFunction(new TwigFunction('nowo_cookie_consent_cookie_inventory', static fn (): array => []));
        $provider = new CookieInventoryProvider(
            $this->createMock(CookieDefinitionRepository::class),
            true,
            CookieInventoryNormalizer::normalize([['name' => 'PHPSESSID', 'duration' => 'Session', 'category' => 'required', 'purpose' => 'Session', 'provider' => 'Site']]),
        );
        $stack = new RequestStack();
        $stack->push(Request::create('/'));

        self::assertStringStartsWith('PHPSESSID|required|Session|', (new CookieInventoryTokenResolver($twig, $provider, $stack))->render());
        self::assertStringStartsWith('PHPSESSID|', (new CookieInventoryTokenResolver($twig, $provider))->render());
    }

    private function twig(): Environment
    {
        return new Environment(new ArrayLoader([
            CookieInventoryTokenResolver::TEMPLATE => '{% for c in cookies %}{{ c.name }}|{{ c.category }}|{{ c.duration }}|{{ c.purpose|raw }}|{{ c.provider }};{% endfor %}',
        ]));
    }
}
