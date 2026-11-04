<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Controller;

use InvalidArgumentException;
use Nowo\LegalKitBundle\Acceptance\CurrentLegalSubject;
use Nowo\LegalKitBundle\Acceptance\LegalAcceptanceManager;
use Nowo\LegalKitBundle\Acceptance\PendingLegalPage;
use Nowo\LegalKitBundle\Acceptance\RelativeTargetValidator;
use Nowo\LegalKitBundle\EventSubscriber\AcceptanceGateSubscriber;
use Nowo\LegalKitBundle\Form\LegalAcceptanceType;
use Nowo\LegalKitBundle\Locale\LegalLocales;
use Nowo\LegalKitBundle\Versioning\LegalPageVersions;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Logout\LogoutUrlGenerator;
use Twig\Environment;

use function hash_equals;
use function is_string;

/**
 * The re-acceptance page (`nowo_legal_kit_acceptance`): lists the pending pages with their change summary and links,
 * records the acceptance (terms) / acknowledgement (privacy) on submit and returns to the stored target.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class LegalAcceptanceController
{
    /**
     * @param CurrentLegalSubject $subject Subject of the security token
     * @param LegalAcceptanceManager $manager Pending pages and records
     * @param LegalPageVersions $versions Requirements fingerprint
     * @param RelativeTargetValidator $targets Target rules
     * @param FormFactoryInterface $forms Form factory
     * @param Environment $twig Twig
     * @param UrlGeneratorInterface $urls Router
     * @param LegalLocales $locales Enabled locales
     * @param string $template `acceptance.template`
     * @param ?string $layoutTemplate `acceptance.layout_template`
     * @param ?string $defaultTarget `acceptance.default_target` route
     * @param ?string $logoutRoute `acceptance.logout_route`
     * @param ?LogoutUrlGenerator $logoutUrls Firewall logout URLs (SecurityBundle)
     * @param string $defaultLocaleStyle unprefixed|prefixed
     */
    public function __construct(
        private CurrentLegalSubject $subject,
        private LegalAcceptanceManager $manager,
        private LegalPageVersions $versions,
        private RelativeTargetValidator $targets,
        private FormFactoryInterface $forms,
        private Environment $twig,
        private UrlGeneratorInterface $urls,
        private LegalLocales $locales,
        private string $template,
        private ?string $layoutTemplate = null,
        private ?string $defaultTarget = null,
        private ?string $logoutRoute = null,
        private ?LogoutUrlGenerator $logoutUrls = null,
        private string $defaultLocaleStyle = 'unprefixed',
    ) {
    }

    /**
     * GET shows the pending pages; POST records them.
     *
     * @return Response The page or a redirect
     */
    public function accept(Request $request): Response
    {
        if (!$this->subject->isAuthenticated()) {
            throw new AccessDeniedException('The re-acceptance page requires a signed-in user.');
        }
        $subject = $this->subject->id();
        if ($subject === null) {
            throw new AccessDeniedHttpException('This account is not subject to legal acceptance.');
        }
        $impersonating = $this->subject->isImpersonating();
        if ($impersonating && $request->isMethod('POST')) {
            throw new AccessDeniedHttpException('An impersonating user cannot accept on behalf of another user.');
        }

        // Read before the pending pages: a version published meanwhile changes the fingerprint, so the session is never
        // marked satisfied for a version this request did not check.
        $fingerprint = $this->versions->fingerprint();
        $pending     = $this->manager->pendingFor($subject, $request->getLocale());
        if ($pending === []) {
            if ($request->hasSession()) {
                AcceptanceGateSubscriber::markSatisfied($request->getSession(), $fingerprint, $subject);
            }

            return $this->noStore(new RedirectResponse($this->target($request)));
        }

        $offer = LegalAcceptanceManager::offerFingerprint($pending);
        $form  = $this->forms->create(LegalAcceptanceType::class, null, ['pages' => $pending, 'offer' => $offer]);
        $form->handleRequest($request);
        $stale = false;
        if ($form->isSubmitted()) {
            $posted = $form->get('offer')->getData();
            $stale  = !is_string($posted) || !hash_equals($offer, $posted);
            if (!$stale && $form->isValid()) {
                $this->manager->record($subject, $pending);
                if ($request->hasSession()) {
                    AcceptanceGateSubscriber::markSatisfied($request->getSession(), $fingerprint, $subject);
                }

                return $this->noStore(new RedirectResponse($this->target($request), Response::HTTP_SEE_OTHER));
            }
            if ($stale) {
                // Rebuild the form for the current offer: nothing was recorded.
                $form = $this->forms->create(LegalAcceptanceType::class, null, ['pages' => $pending, 'offer' => $offer]);
            }
        }

        return $this->render($request, $pending, $form, $stale, $impersonating);
    }

    /**
     * @param list<PendingLegalPage> $pending
     * @param FormInterface<mixed> $form
     */
    private function render(Request $request, array $pending, FormInterface $form, bool $stale, bool $impersonating): Response
    {
        $locale = $request->getLocale();
        $links  = [];
        foreach ($pending as $page) {
            $links[$page->page->slug] = $this->locales->has($locale) && ($this->defaultLocaleStyle !== 'unprefixed' || $locale !== $this->locales->defaultLocale())
                ? $this->urls->generate($page->page->routeName(), ['_locale' => $locale])
                : $this->urls->generate($page->page->unlocalizedRouteName());
        }
        $status = $form->isSubmitted() || $stale ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK;

        return $this->noStore(new Response($this->twig->render($this->template, [
            'pending'         => $pending,
            'links'           => $links,
            'form'            => $form->createView(),
            'stale'           => $stale,
            'impersonating'   => $impersonating,
            'logout_url'      => $this->logoutUrl(),
            'layout_template' => $this->layoutTemplate,
            'legal_locale'    => $locale,
        ]), $status));
    }

    /**
     * The stored target when it is still valid, else the default target.
     */
    private function target(Request $request): string
    {
        $target = $request->hasSession() ? $request->getSession()->remove(AcceptanceGateSubscriber::SESSION_TARGET) : null;
        if (is_string($target) && $this->targets->isValid($target)) {
            return $target;
        }

        return $this->defaultTarget !== null ? $this->urls->generate($this->defaultTarget) : '/';
    }

    private function logoutUrl(): ?string
    {
        if ($this->logoutRoute !== null) {
            return $this->urls->generate($this->logoutRoute);
        }
        try {
            $path = $this->logoutUrls?->getLogoutPath();
        } catch (InvalidArgumentException) {
            return null; // the current firewall has no logout
        }

        return is_string($path) && $path !== '' ? $path : null;
    }

    private function noStore(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
