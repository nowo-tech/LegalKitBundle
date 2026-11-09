<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Tests\Unit\Brand;

use Error;
use Nowo\Brand\Contract\Brand;
use Nowo\Brand\Contract\BrandProviderInterface;
use Nowo\LegalKitBundle\Brand\LegalBrand;
use Nowo\LegalKitBundle\Tests\Support\RecordingLogger;
use Nowo\LegalKitBundle\Tests\Support\TestBrandProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class LegalBrandTest extends TestCase
{
    public function testWithoutProviderThereIsNoBrand(): void
    {
        $brand = new LegalBrand();

        self::assertNull($brand->current());
        self::assertNull($brand->name());
    }

    public function testTheProviderNameIsReturnedAsIs(): void
    {
        $provider = new TestBrandProvider();
        $brand    = new LegalBrand($provider);

        self::assertSame('Acme <Cloud> & "Co"', $brand->name());
        self::assertInstanceOf(Brand::class, $brand->current());

        $provider->name = 'Renamed';
        self::assertSame('Renamed', $brand->name(), 'Nothing is kept between calls (worker-safe)');
    }

    public function testAFailingProviderIsLoggedAsAWarningAndIgnored(): void
    {
        $provider       = new TestBrandProvider();
        $provider->fail = true;
        $logger         = new RecordingLogger();

        self::assertNull((new LegalBrand($provider, $logger))->name());
        self::assertCount(1, $logger->records);
        self::assertSame('warning', $logger->records[0][0]);
        self::assertStringNotContainsString('SQLSTATE', $logger->records[0][1], 'The provider message stays in the context');
        self::assertInstanceOf(RuntimeException::class, $logger->records[0][2]['exception'] ?? null);

        self::assertNull((new LegalBrand($provider))->name(), 'Without a logger too');
    }

    public function testAnErrorInTheProviderIsLoggedAsAnError(): void
    {
        $provider = new class implements BrandProviderInterface {
            public function getBrand(): Brand
            {
                throw new Error('bug in the provider');
            }
        };
        $logger = new RecordingLogger();

        self::assertNull((new LegalBrand($provider, $logger))->current());
        self::assertSame('error', $logger->records[0][0]);
    }
}
