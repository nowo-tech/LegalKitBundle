<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Tests\Support;

use Nowo\LegalKitBundle\Acceptance\LegalSubjectIdResolverInterface;
use Nowo\LegalKitBundle\Acceptance\LegalSubjectUser;

/**
 * Framework-free subject resolver (1.12 twin port): the id comes from the identifier only; "robot" is not subject to
 * acceptance.
 */
final class TestSubjectIdResolver implements LegalSubjectIdResolverInterface
{
    public function subjectIdFor(LegalSubjectUser $user): ?string
    {
        return $user->identifier === 'robot' ? null : 'acc-' . $user->identifier;
    }
}
