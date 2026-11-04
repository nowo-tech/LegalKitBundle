<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Tests\Support;

use Nowo\LegalKitBundle\Acceptance\LegalAcceptanceSubjectResolverInterface;
use RuntimeException;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Subject resolver of the test kernel: "u-<identifier>"; the user "robot" is not subject to acceptance and "bad" gets
 * an invalid id, and "boom" makes the resolver throw.
 */
final class TestSubjectResolver implements LegalAcceptanceSubjectResolverInterface
{
    public function resolve(UserInterface $user): ?string
    {
        return match ($user->getUserIdentifier()) {
            'robot' => null,
            'bad'   => 'not valid!',
            'boom'  => throw new RuntimeException('Resolver down'),
            default => 'u-' . $user->getUserIdentifier(),
        };
    }
}
