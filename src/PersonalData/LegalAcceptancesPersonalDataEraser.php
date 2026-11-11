<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\PersonalData;

use Nowo\LegalKitBundle\Acceptance\LegalAcceptanceManagerInterface;
use Nowo\LegalKitBundle\Event\LegalAcceptancesErasedEvent;
use Nowo\UserKitBundle\PersonalData\ErasureRequest;
use Nowo\UserKitBundle\PersonalData\PersonalDataEraserInterface;

use function get_object_vars;
use function is_string;

/**
 * UserKit personal-data eraser `legal_acceptances` (1.11): deletes every acceptance record of the user's subject ids
 * ({@see LegalPersonalDataSubjectResolverInterface}) through {@see LegalAcceptanceManagerInterface::eraseFor()}.
 * Registered with UserKit installed, `acceptance.enabled` and `personal_data.enabled: true` (opt-in, default false), at priority
 * {@see PRIORITY}: before the host identity eraser (REQ-DATA-002), so the subject id is still derivable.
 *
 * UserKit runs every eraser in one transaction: on the same connection the deletion joins it and
 * {@see LegalAcceptancesErasedEvent} is dispatched after its outermost commit (dropped on rollback); a host listener of
 * that event erases the host's own copies (e.g. audit rows of the acceptances). No flush, no state (worker mode).
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class LegalAcceptancesPersonalDataEraser implements PersonalDataEraserInterface
{
    /** Key of the eraser in UserKit's erasure result (same as Beacon's). */
    public const string KEY = 'legal_acceptances';

    /** Tag priority: higher runs first; host identity erasers run at 100 or below. */
    public const int PRIORITY = 150;

    /**
     * @param LegalAcceptanceManagerInterface $acceptances The acceptance port
     * @param LegalPersonalDataSubjectResolverInterface $subjects The subject ids of a user
     */
    public function __construct(
        private LegalAcceptanceManagerInterface $acceptances,
        private LegalPersonalDataSubjectResolverInterface $subjects,
    ) {
    }

    public function getKey(): string
    {
        return self::KEY;
    }

    public function supports(object $user): bool
    {
        return true;
    }

    public function erase(ErasureRequest $request): void
    {
        // UserKit >= 1.12 captures the identifier before any eraser ran; older releases have no such property.
        $original = get_object_vars($request)['originalIdentifier'] ?? null;
        foreach (PersonalDataSubjects::distinct($this->subjects->subjectsFor($request->user, is_string($original) ? $original : null)) as $subjectId) {
            $this->acceptances->eraseFor($subjectId);
        }
    }
}
