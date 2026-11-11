<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Tests\Support;

use Nowo\LegalKitBundle\Acceptance\LegalAcceptanceRequestExemptionInterface;
use Nowo\LegalKitBundle\Acceptance\LegalRequestContext;

/**
 * Framework-free exemption voter (1.12 twin port): the "skip" routes of the test application.
 */
final class TestRequestExemption implements LegalAcceptanceRequestExemptionInterface
{
    public function isExemptRequest(LegalRequestContext $request): bool
    {
        return $request->route === 'app_skip' && $request->method === 'GET';
    }
}
