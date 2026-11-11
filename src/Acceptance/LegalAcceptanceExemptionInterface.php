<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Acceptance;

use Symfony\Component\HttpFoundation\Request;

/**
 * Host voter (autoconfigured, tag `nowo_legal_kit.acceptance_exemption`): requests that are never sent to the
 * re-acceptance page, e.g. MFA, e-mail verification, account export and deletion.
 *
 * @deprecated since 1.12, implement {@see LegalAcceptanceRequestExemptionInterface} instead (no HTTP type in the
 *             port); removed in 2.0
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
interface LegalAcceptanceExemptionInterface
{
    /** Service tag. */
    public const string TAG = 'nowo_legal_kit.acceptance_exemption';

    /**
     * True = never redirect this request.
     *
     * @return bool The answer
     */
    public function isExempt(Request $request): bool;
}
