<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\PersonalData;

use Nowo\LegalKitBundle\Acceptance\LegalAcceptanceManagerInterface;
use Nowo\UserKitBundle\PersonalData\PersonalDataExportProviderInterface;

use function array_merge;
use function count;

/**
 * UserKit personal-data export section `legal_acceptances` (1.11): every terms acceptance and privacy acknowledgement
 * of the user's subject ids ({@see LegalPersonalDataSubjectResolverInterface}), as
 * `{count: int, entries: list<{page, version, locale, kind, accepted_at, content_hash}>}` (the shape of
 * {@see LegalAcceptanceManagerInterface::exportFor()}; `content_hash` is the SHA-256 of the public text shown, not a
 * secret). Registered with UserKit installed, `acceptance.enabled` and `personal_data.enabled: true` (opt-in, default false).
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class LegalAcceptancesPersonalDataExportProvider implements PersonalDataExportProviderInterface
{
    /** Key of the section in UserKit's export document (same as Beacon's). */
    public const string SECTION = 'legal_acceptances';

    /** Tag priority (section order, higher first; UserKit's own sections are negative). */
    public const int PRIORITY = 50;

    /**
     * @param LegalAcceptanceManagerInterface $acceptances The acceptance port
     * @param LegalPersonalDataSubjectResolverInterface $subjects The subject ids of a user
     */
    public function __construct(
        private LegalAcceptanceManagerInterface $acceptances,
        private LegalPersonalDataSubjectResolverInterface $subjects,
    ) {
    }

    public function getSectionKey(): string
    {
        return self::SECTION;
    }

    public function supports(object $user): bool
    {
        return true;
    }

    /**
     * @return array{count: int, entries: list<array{page: string, version: int, locale: string, kind: string, accepted_at: string, content_hash: string}>}
     */
    public function export(object $user): array
    {
        $entries = [];
        foreach (PersonalDataSubjects::distinct($this->subjects->subjectsFor($user, null)) as $subjectId) {
            $entries = array_merge($entries, $this->acceptances->exportFor($subjectId));
        }

        return ['count' => count($entries), 'entries' => $entries];
    }
}
