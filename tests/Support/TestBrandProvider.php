<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Tests\Support;

use Nowo\Brand\Contract\Brand;
use Nowo\Brand\Contract\BrandColors;
use Nowo\Brand\Contract\BrandProviderInterface;
use RuntimeException;

/**
 * A host brand provider (spec 220): the name can be changed or made to fail by a test.
 */
final class TestBrandProvider implements BrandProviderInterface
{
    public string $name = 'Acme <Cloud> & "Co"';

    public bool $fail = false;

    public function getBrand(): Brand
    {
        if ($this->fail) {
            throw new RuntimeException('SQLSTATE[HY000] provider-detail-not-for-pages');
        }

        return new Brand($this->name, self::colors(), self::colors());
    }

    public static function colors(): BrandColors
    {
        return new BrandColors('#1f6f54', '#134736', '#b42318', '#b54708', '#f3f6f4', '#0f1c18', '#ffffff');
    }
}
