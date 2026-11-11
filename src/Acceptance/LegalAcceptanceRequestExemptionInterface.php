<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Acceptance;

/**
 * Host voter (1.12, architecture bar rule 3; autoconfigured, tag `nowo_legal_kit.acceptance_request_exemption`), the
 * framework-free twin of the deprecated {@see LegalAcceptanceExemptionInterface}: requests that are never sent to the
 * re-acceptance page, e.g. MFA, e-mail verification, account export and deletion. Both kinds of voter are asked; in
 * 2.0 this is the only form.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
interface LegalAcceptanceRequestExemptionInterface
{
    /** Service tag. */
    public const string TAG = 'nowo_legal_kit.acceptance_request_exemption';

    /**
     * True = never redirect this request.
     *
     * @return bool The answer
     */
    public function isExemptRequest(LegalRequestContext $request): bool;
}
