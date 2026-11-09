<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Tests\Unit\Token;

use Nowo\LegalKitBundle\Brand\LegalBrand;
use Nowo\LegalKitBundle\Tests\Support\TestBrandProvider;
use Nowo\LegalKitBundle\Token\BuiltinTokenResolver;
use Nowo\LegalKitBundle\Token\LegalTokenContext;
use Nowo\LegalKitBundle\Token\LegalTokenRenderer;
use PHPUnit\Framework\TestCase;

final class BuiltinTokenResolverTest extends TestCase
{
    public function testWithoutBrandTheConfiguredNameApplies(): void
    {
        $context = new LegalTokenContext('notice', 'en');

        self::assertSame('Acme', (new BuiltinTokenResolver('Acme', '2026-01-01'))->resolve('brand', $context));
        self::assertNull((new BuiltinTokenResolver('', '2026-01-01'))->resolve('brand', $context));
        self::assertSame('Acme', (new BuiltinTokenResolver('Acme', '2026-01-01', new LegalBrand()))->resolve('brand', $context), 'No provider service');
    }

    public function testTheBrandProviderWinsOverBrandName(): void
    {
        $provider = new TestBrandProvider();
        $resolver = new BuiltinTokenResolver('Configured', '2026-01-01', new LegalBrand($provider));
        $context  = new LegalTokenContext('notice', 'en');

        self::assertSame('Acme <Cloud> & "Co"', $resolver->resolve('brand', $context));
        self::assertSame('2026-01-01', $resolver->resolve('last_updated', $context));

        $provider->fail = true;
        self::assertSame('Configured', $resolver->resolve('brand', $context), 'A failing provider falls back to brand_name');
        self::assertNull((new BuiltinTokenResolver('', '2026-01-01', new LegalBrand($provider)))->resolve('brand', $context), 'Unresolved: reported by the gate');
    }

    public function testTheBrandNameIsEscapedInHtmlAndPlainInText(): void
    {
        $renderer = new LegalTokenRenderer([new BuiltinTokenResolver('', '2026-01-01', new LegalBrand(new TestBrandProvider()))]);
        $context  = new LegalTokenContext('notice', 'en');

        self::assertSame('<p>Acme &lt;Cloud&gt; &amp; &quot;Co&quot;</p>', $renderer->renderHtml('<p>%brand%</p>', $context));
        self::assertSame('Acme <Cloud> & "Co"', $renderer->renderText('%brand%', $context));
        self::assertSame([], $renderer->unresolvedNames('%brand%', $context));
    }
}
