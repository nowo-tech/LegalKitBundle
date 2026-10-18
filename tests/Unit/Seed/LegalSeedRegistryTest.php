<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Tests\Unit\Seed;

use Nowo\LegalKitBundle\Catalog\ConfiguredLegalPageCatalog;
use Nowo\LegalKitBundle\Seed\LegalSeed;
use Nowo\LegalKitBundle\Seed\LegalSeedProviderInterface;
use Nowo\LegalKitBundle\Seed\LegalSeedRegistry;
use Nowo\LegalKitBundle\Seed\TwigSeedProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\Loader\ArrayLoader as TranslationArrayLoader;
use Symfony\Component\Translation\Translator;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Contracts\Translation\TranslatorTrait;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\TwigFilter;

final class LegalSeedRegistryTest extends TestCase
{
    public function testHostProviderWinsAndFallbackLocaleApplies(): void
    {
        $host = new class implements LegalSeedProviderInterface {
            public function supports(string $slug, string $locale): bool
            {
                return $slug === 'notice' && $locale === 'es';
            }

            public function title(string $slug, string $locale): string
            {
                return 'Aviso';
            }

            public function body(string $slug, string $locale): string
            {
                return '<p>Cuerpo</p>';
            }
        };
        $registry = new LegalSeedRegistry([$host], 'es');

        $own = $registry->find('notice', 'es');
        self::assertInstanceOf(LegalSeed::class, $own);
        self::assertFalse($own->fallback);
        $fallback = $registry->find('notice', 'en');
        self::assertInstanceOf(LegalSeed::class, $fallback);
        self::assertTrue($fallback->fallback);
        self::assertSame('es', $fallback->locale);
        self::assertSame('Aviso', $fallback->title);
        self::assertNull($registry->find('terms', 'en'));
        self::assertSame('es', $registry->contentLocale('notice', 'en'));
        self::assertSame('es', $registry->contentLocale('notice', 'es'));
        self::assertNull($registry->contentLocale('terms', 'en'));
        self::assertNull((new LegalSeedRegistry([$host]))->find('notice', 'en'));
        self::assertNull((new LegalSeedRegistry([$host], 'es'))->find('terms', 'es'));
    }

    public function testTwigProviderSwitchesTheTranslatorLocaleAndRestoresIt(): void
    {
        $translator = new Translator('en');
        $translator->addLoader('array', new TranslationArrayLoader());
        $translator->addResource('array', ['notice.title' => 'Notice', 'notice.intro' => 'Hello %brand%'], 'en', TwigSeedProvider::DOMAIN);
        $translator->addResource('array', ['notice.title' => 'Aviso', 'notice.intro' => 'Hola %brand%'], 'es', TwigSeedProvider::DOMAIN);
        $twig     = $this->twig($translator);
        $provider = new TwigSeedProvider(new ConfiguredLegalPageCatalog(['notice' => [], 'terms' => []]), $twig, $translator);

        self::assertTrue($provider->supports('notice', 'es'));
        self::assertFalse($provider->supports('notice', 'fr'));
        self::assertFalse($provider->supports('privacy', 'en'));
        self::assertSame('Aviso', $provider->title('notice', 'es'));
        self::assertSame('<p>Hola %brand% (notice/es)</p>', $provider->body('notice', 'es'));
        self::assertSame('en', $translator->getLocale(), 'Locale restored after the render');
    }

    public function testTwigProviderWithAPlainTranslator(): void
    {
        $translator = new class implements TranslatorInterface {
            use TranslatorTrait;
        };
        $provider = new TwigSeedProvider(new ConfiguredLegalPageCatalog(['notice' => []]), $this->twig($translator), $translator);

        self::assertTrue($provider->supports('notice', 'xx'));
        self::assertSame('<p>notice.intro (notice/xx)</p>', $provider->body('notice', 'xx'));
    }

    private function twig(TranslatorInterface $translator): Environment
    {
        $twig = new Environment(new ArrayLoader([
            '@NowoLegalKitBundle/seed/notice.html.twig' => '<p>{{ "notice.intro"|trans }} ({{ legal_slug }}/{{ legal_locale }})</p>',
        ]));
        $twig->addFilter(new TwigFilter('trans', static fn (string $id): string => $translator->trans($id, [], TwigSeedProvider::DOMAIN)));

        return $twig;
    }
}
