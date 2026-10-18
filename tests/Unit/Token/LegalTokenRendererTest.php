<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Tests\Unit\Token;

use DateTimeImmutable;
use Nowo\LegalKitBundle\Token\BuiltinTokenResolver;
use Nowo\LegalKitBundle\Token\LegalTokenContext;
use Nowo\LegalKitBundle\Token\LegalTokenRenderer;
use Nowo\LegalKitBundle\Token\LegalTokenResolverInterface;
use Nowo\LegalKitBundle\Token\LegalTokenValue;
use PHPUnit\Framework\TestCase;

final class LegalTokenRendererTest extends TestCase
{
    public function testHtmlValuesAreEscapedUnlessTrusted(): void
    {
        $renderer = new LegalTokenRenderer([$this->hostResolver(), new BuiltinTokenResolver('Acme & Co', '2026-10-18')]);
        $context  = new LegalTokenContext('notice', 'en');

        self::assertSame(
            '<p>Acme &amp; Co · <b>T</b> · 2026-10-18 · %unknown% · 100% sure</p>',
            $renderer->renderHtml('<p>%brand% · %table% · %last_updated% · %unknown% · 100% sure</p>', $context),
        );
        self::assertSame('Acme & Co  %unknown%', $renderer->renderText('%brand% %table% %unknown%', $context));
        self::assertSame(['legal_name', 'table', 'brand', 'last_updated'], $renderer->knownNames());
    }

    public function testFirstResolverWins(): void
    {
        $renderer = new LegalTokenRenderer([$this->hostResolver(), new BuiltinTokenResolver('Builtin', '2026-10-18')]);

        self::assertSame('Host Ltd', $renderer->renderText('%legal_name%', new LegalTokenContext('notice', 'en')));
    }

    public function testUnresolvedNames(): void
    {
        $renderer = new LegalTokenRenderer([new BuiltinTokenResolver('', '2026-10-18')]);
        $context  = new LegalTokenContext('notice', 'en', new DateTimeImmutable('2026-01-02 10:00'));

        self::assertSame(['brand', 'foo'], $renderer->unresolvedNames('%brand% %foo% %brand% %last_updated%', $context));
        self::assertSame([], $renderer->unresolvedNames('no tokens', $context));
        self::assertSame('2026-01-02', $renderer->renderText('%last_updated%', $context));
    }

    private function hostResolver(): LegalTokenResolverInterface
    {
        return new class implements LegalTokenResolverInterface {
            public function names(): array
            {
                return ['legal_name', 'table'];
            }

            public function resolve(string $name, LegalTokenContext $context): LegalTokenValue|string|null
            {
                return match ($name) {
                    'legal_name' => 'Host Ltd',
                    'table'      => LegalTokenValue::html('<b>T</b>'),
                    default      => null,
                };
            }
        };
    }
}
