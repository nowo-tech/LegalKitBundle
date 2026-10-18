<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Tests\Integration;

use ArrayObject;
use InvalidArgumentException;
use Nowo\LegalKitBundle\Document\LegalDocumentResolver;
use Nowo\LegalKitBundle\Event\LegalDocumentsImportedEvent;
use Nowo\LegalKitBundle\Placeholder\LegalPlaceholderScanner;
use Nowo\LegalKitBundle\Portability\LegalDocumentPortability;
use Nowo\LegalKitBundle\Tests\Support\Entity\User;
use Nowo\LegalKitBundle\Tests\Support\KernelTestCase;
use Nowo\LegalKitBundle\Tests\Support\TestKernel;
use Nowo\LegalKitBundle\Twig\LegalKitExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\TwigFunction;

/**
 * Portability, scanner and the Twig API against the real container.
 */
final class ServicesTest extends KernelTestCase
{
    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function invalidPayloads(): iterable
    {
        $ok = ['slug' => 'notice', 'locale' => 'en', 'title' => 'T', 'body' => '<p>x</p>'];
        yield 'not a list' => [['a' => $ok], 'must be a list'];
        yield 'entry' => [['x'], 'legal_documents[0] must be an object'];
        yield 'missing field' => [[['slug' => 'notice']], 'legal_documents[0].locale must be a string'];
        yield 'slug' => [[['slug' => 'imprint'] + $ok], 'is not an enabled legal page'];
        yield 'locale' => [[['locale' => 'fr'] + $ok], 'is not an enabled locale'];
        yield 'title' => [[['title' => ' '] + $ok], 'title must have 1 to 180'];
        yield 'body' => [[['body' => '<script>x</script>'] + $ok], 'body is empty after sanitising'];
        yield 'body length' => [[['body' => str_repeat('a', 1_000_001)] + $ok], 'body exceeds 1000000 characters'];
        yield 'duplicate' => [[$ok, $ok], 'legal_documents[1] duplicates notice/en'];
    }

    #[DataProvider('invalidPayloads')]
    public function testNormalizeRejects(mixed $payload, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        $this->service($this->bootKernel(), LegalDocumentPortability::class)->normalize($payload);
    }

    public function testApplyKeepsTheHostTransactionAndBlamesTheActor(): void
    {
        $kernel  = $this->bootKernel();
        $manager = $this->entityManager($kernel);
        $actor   = new User('ops@example.com');
        $manager->persist($actor);
        $manager->flush();

        $events = new ArrayObject();
        $this->service($kernel, EventDispatcherInterface::class)->addListener(LegalDocumentsImportedEvent::class, static function (LegalDocumentsImportedEvent $event) use ($events): void {
            $events->append($event->documents);
        });
        $portability = $this->service($kernel, LegalDocumentPortability::class);
        $rows        = $portability->normalize([['slug' => 'notice', 'locale' => 'en', 'title' => ' Notice ', 'body' => '<p>A</p><iframe></iframe>']]);
        self::assertSame('Notice', $rows[0]['title']);

        self::assertSame(1, $portability->apply($rows, $actor));
        self::assertSame([], $events->getArrayCopy(), 'No flush, no event');
        $manager->flush();
        $stored = $this->find($kernel, 'notice', 'en');
        self::assertNotNull($stored);
        self::assertSame('<p>A</p>', $stored->getBody());
        self::assertNull($stored->getCreatedBy(), 'AuditKit blames the signed-in user (none in CLI) over the given actor');

        self::assertSame(1, $portability->apply([['slug' => 'notice', 'locale' => 'en', 'title' => 'Notice 2', 'body' => '<p>B</p>']], null, true));
        self::assertSame([[['slug' => 'notice', 'locale' => 'en']]], $events->getArrayCopy());
        self::assertSame('Notice 2', $this->find($kernel, 'notice', 'en')?->getTitle());
        self::assertSame([['slug' => 'notice', 'locale' => 'en', 'title' => 'Notice 2', 'body' => '<p>B</p>']], $portability->export());
    }

    public function testScannerIsMemoisedUntilReset(): void
    {
        $kernel  = $this->bootKernel(['locales' => ['en'], 'brand_name' => 'Acme']);
        $scanner = $this->service($kernel, LegalPlaceholderScanner::class);

        $hits = $scanner->findHits();
        self::assertNotSame([], $hits);
        self::assertTrue($scanner->hasPlaceholders('en'));
        self::assertSame('seed', $hits[0]->source);
        self::assertSame(['slug' => $hits[0]->slug, 'locale' => 'en', 'marker' => $hits[0]->marker, 'source' => 'seed', 'kind' => 'marker'], $hits[0]->toArray());
        self::assertContains('privacy@example.com', $scanner->markers());

        $this->store($kernel, 'notice', 'en', 'Notice', '<p>Acme Ltd</p>');
        self::assertSame($hits, $scanner->findHits(), 'Memoised');
        $scanner->reset();
        self::assertNotContains('notice', array_map(static fn ($hit): string => $hit->slug, $scanner->findHits()));
    }

    public function testResolverEffectiveText(): void
    {
        $kernel   = $this->bootKernel(['locales' => ['en', 'ca']]);
        $resolver = $this->service($kernel, LegalDocumentResolver::class);

        self::assertNull($resolver->effective('notice', 'ca', null));
        self::assertNull($resolver->seedForEditor('notice', 'ca'));
        self::assertSame('seed', $resolver->effective('notice', 'en', null)['source'] ?? null);
        self::assertFalse(LegalDocumentResolver::hasText('<p>&nbsp;&#8203;</p>'));
        self::assertTrue(LegalDocumentResolver::hasText('<p>x</p>'));
    }

    public function testTwigApi(): void
    {
        $kernel = $this->bootKernel();
        $this->store($kernel, 'terms', 'es', 'Términos', '<p>Hola</p>');
        $extension = $this->service($kernel, LegalKitExtension::class);
        $this->service($kernel, RequestStack::class)->push(Request::create('/es/x'));

        $links = $extension->links('es');
        self::assertSame(['notice', 'privacy', 'terms', 'cookies'], array_column($links, 'slug'));
        self::assertSame('/es/legal/notice', $links[0]['url']);
        self::assertSame('Aviso legal', $links[0]['label']);
        self::assertSame('/legal/notice', $extension->links('en')[0]['url']);
        self::assertSame('/legal/notice', $extension->links('fr')[0]['url'], 'Unknown locale: request locale, else default');
        self::assertSame('/admin/legal', $extension->adminUrl());
        self::assertSame('es', $extension->contentLocale('terms', 'es'));
        self::assertSame('en', $extension->contentLocale('terms', 'en'));
        self::assertNull($extension->contentLocale('imprint'));
        self::assertNotSame([], $extension->placeholderHits('de'));

        self::assertFalse($extension->consentWidget($this->service($kernel, Environment::class)));
        $twig = new Environment(new ArrayLoader());
        $twig->addFunction(new TwigFunction('nowo_cookie_consent_should_render', static fn (): bool => true));
        self::assertTrue($extension->consentWidget($twig));
        $failing = new Environment(new ArrayLoader());
        $failing->addFunction(new TwigFunction('nowo_cookie_consent_should_render', static fn (): bool => throw new RuntimeException('cold start')));
        self::assertFalse($extension->consentWidget($failing));
    }

    public function testTwigApiWithoutAdmin(): void
    {
        $extension = $this->service($this->bootKernel(['web_ui' => ['enabled' => false]]), LegalKitExtension::class);

        self::assertNull($extension->adminUrl());
    }

    public function testFooterNavAndBannerPartials(): void
    {
        $kernel = $this->bootKernel();
        $twig   = $this->service($kernel, Environment::class);
        $this->service($kernel, RequestStack::class)->push(Request::create('/legal/notice'));

        $footer = $twig->render('@NowoLegalKitBundle/_footer_nav.html.twig', ['locale' => 'de']);
        self::assertStringContainsString('href="/de/legal/privacy"', $footer);
        self::assertStringContainsString('data-nowo-legal-link="manage-cookies"', $footer);
        self::assertStringContainsString('Cookie-Einstellungen', $footer);
        self::assertStringNotContainsString('data-nowo-open-consent', $footer, 'Without CookieConsent: a link to the cookies page');

        $stubbed = $this->bootKernel([], ['consent_stub']);
        $this->service($stubbed, RequestStack::class)->push(Request::create('/legal/notice'));
        self::assertStringContainsString('data-nowo-open-consent', $this->service($stubbed, Environment::class)->render('@NowoLegalKitBundle/_footer_nav.html.twig'));

        $banner = $twig->render('@NowoLegalKitBundle/_placeholder_banner.html.twig', ['limit' => 2]);
        self::assertStringContainsString('data-testid="nowo-legal-kit-placeholder-banner"', $banner);
        self::assertStringContainsString('href="/admin/legal"', $banner);
        self::assertStringContainsString('more', $banner);
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $id
     *
     * @return T
     */
    private function service(TestKernel $kernel, string $id): object
    {
        $service = $this->testContainer($kernel)->get($id);
        self::assertInstanceOf($id, $service);

        return $service;
    }
}
