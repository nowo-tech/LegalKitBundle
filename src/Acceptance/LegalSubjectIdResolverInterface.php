<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Acceptance;

/**
 * Host contract (1.12, architecture bar rule 3), the framework-free twin of the deprecated
 * {@see LegalAcceptanceSubjectResolverInterface}: who the subject of an acceptance is. The service named by
 * `acceptance.subject_resolver` implements this interface or the deprecated one (this one wins when it implements
 * both). In 2.0 it is the only form.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
interface LegalSubjectIdResolverInterface
{
    /**
     * A stable, non-reusable, non-personal id of the user (e.g. a public id; never an e-mail address), matching
     * `^[A-Za-z0-9._:@-]{1,128}$`. Null = this user is not subject to acceptance (service or system accounts).
     *
     * @return ?string The subject id
     */
    public function subjectIdFor(LegalSubjectUser $user): ?string;
}
