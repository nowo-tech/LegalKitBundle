<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Tests\Unit\Token;

use Nowo\LegalKitBundle\Token\CookieInventoryTokenResolver;
use Nowo\LegalKitBundle\Token\LegalTokenContext;
use PHPUnit\Framework\TestCase;
use RuntimeException;
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

    private function twig(): Environment
    {
        return new Environment(new ArrayLoader([
            CookieInventoryTokenResolver::TEMPLATE => '{% for c in cookies %}{{ c.name }}|{{ c.category }}|{{ c.duration }}|{{ c.purpose|raw }}|{{ c.provider }};{% endfor %}',
        ]));
    }
}
