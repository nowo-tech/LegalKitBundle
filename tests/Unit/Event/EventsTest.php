<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Tests\Unit\Event;

use Nowo\LegalKitBundle\Document\ResolvedLegalDocument;
use Nowo\LegalKitBundle\Event\LegalDocumentRestoredEvent;
use Nowo\LegalKitBundle\Event\LegalDocumentSavedEvent;
use Nowo\LegalKitBundle\Event\LegalDocumentsImportedEvent;
use Nowo\LegalKitBundle\Event\LegalPageRenderEvent;
use Nowo\LegalKitBundle\EventListener\SeoKitHeadListener;
use Nowo\LegalKitBundle\Locale\LegalLocales;
use Nowo\SeoKitBundle\Service\SeoRuntime;
use PHPUnit\Framework\TestCase;

final class EventsTest extends TestCase
{
    public function testRenderEventCarriesThePageAndTemplateVariables(): void
    {
        $document = new ResolvedLegalDocument('privacy', 'es', 'Privacidad', '<p>x</p>', ResolvedLegalDocument::SOURCE_SEED, 'es');
        $event    = new LegalPageRenderEvent('privacy', 'es', 'es', $document, ['en' => 'https://x.test/legal/privacy', 'es' => 'https://x.test/es/legal/privacy'], 'https://x.test/es/legal/privacy');
        $event->setTemplateVariable('head', 'custom');

        self::assertSame(['head' => 'custom'], $event->templateVariables());
        self::assertFalse($event->document->isStored());

        $runtime = new SeoRuntime();
        (new SeoKitHeadListener($runtime, new LegalLocales(['en', 'es'])))($event);
        $overrides = $runtime->getOverrides();
        self::assertSame('Privacidad', $overrides['title']);
        self::assertSame('https://x.test/es/legal/privacy', $overrides['canonical']);
        self::assertSame(['locale' => 'x-default', 'url' => 'https://x.test/legal/privacy', 'hreflang' => 'x-default'], $overrides['alternates'][2]);
    }

    public function testDocumentEvents(): void
    {
        self::assertSame('terms', (new LegalDocumentSavedEvent('terms', 'en'))->slug);
        self::assertSame('de', (new LegalDocumentRestoredEvent('terms', 'de'))->locale);
        self::assertSame([['slug' => 'a', 'locale' => 'en']], (new LegalDocumentsImportedEvent([['slug' => 'a', 'locale' => 'en']]))->documents);
    }
}
