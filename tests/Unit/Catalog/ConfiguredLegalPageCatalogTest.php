<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Tests\Unit\Catalog;

use Nowo\LegalKitBundle\Catalog\ConfiguredLegalPageCatalog;
use OutOfBoundsException;
use PHPUnit\Framework\TestCase;

final class ConfiguredLegalPageCatalogTest extends TestCase
{
    public function testEnabledPagesInOrder(): void
    {
        $catalog = new ConfiguredLegalPageCatalog([
            'notice'        => ['path' => 'imprint', 'enabled' => true, 'nav' => true, 'seed_template' => null],
            'terms'         => ['enabled' => false],
            'accessibility' => ['nav' => false, 'seed_template' => 'legal/accessibility.html.twig'],
        ]);

        self::assertSame(['notice', 'accessibility'], $catalog->slugs());
        self::assertTrue($catalog->has('notice'));
        self::assertFalse($catalog->has('terms'));
        $notice = $catalog->page('notice');
        self::assertSame('imprint', $notice->path);
        self::assertSame('legal_notice', $notice->routeName());
        self::assertSame('legal_notice_unlocalized', $notice->unlocalizedRouteName());
        self::assertSame('page.notice', $notice->labelKey());
        self::assertSame('@NowoLegalKitBundle/seed/notice.html.twig', $notice->seedTemplate);
        $extra = $catalog->pages()[1];
        self::assertSame('accessibility', $extra->path);
        self::assertFalse($extra->nav);
        self::assertSame('legal/accessibility.html.twig', $extra->seedTemplate);
    }

    public function testUnknownPage(): void
    {
        $this->expectException(OutOfBoundsException::class);
        (new ConfiguredLegalPageCatalog([]))->page('notice');
    }
}
