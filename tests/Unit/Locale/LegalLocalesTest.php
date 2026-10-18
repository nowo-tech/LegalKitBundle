<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Tests\Unit\Locale;

use InvalidArgumentException;
use Nowo\LegalKitBundle\Locale\LegalLocales;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LegalLocalesTest extends TestCase
{
    /**
     * @return iterable<string, array{mixed, list<string>}>
     */
    public static function rawLists(): iterable
    {
        yield 'list' => [['en', ' es ', 'en', '', 3], ['en', 'es']];
        yield 'comma string' => ['en, es,de', ['en', 'es', 'de']];
        yield 'pipe string' => ['en|es', ['en', 'es']];
        yield 'json list' => ['["fr","it"]', ['fr', 'it']];
        yield 'invalid' => [42, []];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('rawLists')]
    public function testNormalize(mixed $raw, array $expected): void
    {
        self::assertSame($expected, LegalLocales::normalize($raw));
    }

    public function testDefaultLocaleFirst(): void
    {
        $locales = new LegalLocales(['en', 'es', 'de'], 'es');

        self::assertSame(['es', 'en', 'de'], $locales->all());
        self::assertSame('es', $locales->defaultLocale());
        self::assertTrue($locales->has('de'));
        self::assertFalse($locales->has('fr'));
        self::assertSame('es|en|de', $locales->requirement());
    }

    public function testFirstLocaleIsTheDefault(): void
    {
        self::assertSame('pt', (new LegalLocales('pt_BR,pt', ''))->all()[1]);
        self::assertSame('pt_BR', (new LegalLocales('pt_BR,pt'))->defaultLocale());
    }

    public function testRejectsAnEmptyList(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new LegalLocales([]);
    }

    public function testRejectsAnInvalidLocale(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new LegalLocales(['en', 'not a locale']);
    }

    public function testRejectsADefaultLocaleThatIsNotEnabled(): void
    {
        $this->expectExceptionMessage('is not one of the enabled locales');
        new LegalLocales(['en'], 'es');
    }
}
