<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Tests\Support;

use Nowo\LegalKitBundle\Acceptance\LegalAcceptanceExemptionInterface;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;

/**
 * Host exemption voter of the test kernel: "/app/mfa" is exempt (like an MFA step); "/app/voter-boom" makes the voter throw.
 */
final class TestExemption implements LegalAcceptanceExemptionInterface
{
    public function isExempt(Request $request): bool
    {
        if ($request->getPathInfo() === '/app/voter-boom') {
            throw new RuntimeException('Voter down');
        }

        return $request->getPathInfo() === '/app/mfa';
    }
}
