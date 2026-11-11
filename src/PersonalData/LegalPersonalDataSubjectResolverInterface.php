<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\PersonalData;

use Nowo\LegalKitBundle\Acceptance\LegalAcceptanceSubjectResolverInterface;

/**
 * SPI (1.11, public API, SemVer): the acceptance subject ids of a user being erased or exported. The UserKit erasure /
 * export adapters call {@see \Nowo\LegalKitBundle\Acceptance\LegalAcceptanceManagerInterface::eraseFor()} /
 * `exportFor()` once per distinct id returned.
 *
 * The default ({@see AcceptanceSubjectPersonalDataResolver}) returns the id that
 * {@see LegalAcceptanceSubjectResolverInterface} (`acceptance.subject_resolver`) gives for the user: the same id the
 * bundle records acceptances under. Set `personal_data.subject_resolver` to your own implementation when the id cannot
 * be derived from the user object any more (e.g. it was rotated) or when the user had several ids over time.
 *
 * Plain PHP: no Doctrine, Symfony or HTTP types. Stateless (worker mode): never keep the user between calls.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
interface LegalPersonalDataSubjectResolverInterface
{
    /**
     * The acceptance subject ids of the user (none = nothing to erase or export). Ids are compared exactly: return
     * them as recorded, never trimmed or case folded.
     *
     * @param object $user The user being erased or exported (UserKit's subject)
     * @param string|null $originalIdentifier The user identifier captured before any eraser ran (UserKit 1.12
     *                                        `ErasureRequest::identifier()`; null on export and before UserKit 1.12)
     *
     * @return list<string> The subject ids
     */
    public function subjectsFor(object $user, ?string $originalIdentifier): array;
}
