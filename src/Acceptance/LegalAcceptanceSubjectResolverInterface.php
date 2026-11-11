<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Acceptance;

use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Host contract (required with `acceptance.enabled`): who the subject of an acceptance is.
 *
 * @deprecated since 1.12, implement {@see LegalSubjectIdResolverInterface} instead (no security type in the port);
 *             removed in 2.0
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
interface LegalAcceptanceSubjectResolverInterface
{
    /**
     * A stable, non-reusable, non-personal id of the user (e.g. a public id; never an e-mail address), matching
     * `^[A-Za-z0-9._:@-]{1,128}$`. Null = this user is not subject to acceptance (service or system accounts).
     *
     * @return ?string The subject id
     */
    public function resolve(UserInterface $user): ?string;
}
