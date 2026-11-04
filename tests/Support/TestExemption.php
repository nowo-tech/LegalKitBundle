<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Tests\Support;

use Nowo\LegalKitBundle\Acceptance\LegalAcceptanceExemptionInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Host exemption voter of the test kernel: "/app/mfa" is exempt (like an MFA step).
 */
final class TestExemption implements LegalAcceptanceExemptionInterface
{
    public function isExempt(Request $request): bool
    {
        return $request->getPathInfo() === '/app/mfa';
    }
}
