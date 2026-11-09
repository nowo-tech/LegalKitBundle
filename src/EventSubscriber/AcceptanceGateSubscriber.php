<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\EventSubscriber;

use Nowo\LegalKitBundle\Acceptance\AcceptanceRequestMatcher;
use Nowo\LegalKitBundle\Acceptance\CurrentLegalSubject;
use Nowo\LegalKitBundle\Acceptance\LegalAcceptanceManagerInterface;
use Nowo\LegalKitBundle\Acceptance\RelativeTargetValidator;
use Nowo\LegalKitBundle\Locale\LegalLocales;
use Nowo\LegalKitBundle\Routing\LegalRouteLoader;
use Nowo\LegalKitBundle\Versioning\LegalPageVersions;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Throwable;

use function hash;
use function in_array;
use function is_array;

/**
 * Re-acceptance gate (`acceptance.enforce`): sends signed-in browser navigations (main GET / HEAD requests with a
 * session, HTML, not exempt) to the re-acceptance page while a page is pending. Never touches session-less (API key)
 * requests, writes, or impersonations; fails open (a duty to inform, not an access control) when the check fails.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class AcceptanceGateSubscriber implements EventSubscriberInterface
{
    /** Session key of the satisfied state {fp, subject, ok}. */
    public const string SESSION_STATE = '_nowo_legal_kit.acceptance';

    /** Session key of the post-accept target. */
    public const string SESSION_TARGET = '_nowo_legal_kit.acceptance_target';

    /** Priority on kernel.request: after the router (32), the locale (16) and the firewall (8). */
    public const int PRIORITY = 4;

    /**
     * @param CurrentLegalSubject $subject Subject of the security token
     * @param LegalAcceptanceManagerInterface $manager Pending pages
     * @param LegalPageVersions $versions Requirements fingerprint
     * @param AcceptanceRequestMatcher $matcher Exemptions and the HTML test
     * @param RelativeTargetValidator $targets Target rules
     * @param UrlGeneratorInterface $urls Router
     * @param LegalLocales $locales Enabled locales
     * @param string $nonHtml pass|deny
     * @param string $defaultLocaleStyle unprefixed|prefixed
     * @param LoggerInterface $logger Logger (never the subject)
     */
    public function __construct(
        private CurrentLegalSubject $subject,
        private LegalAcceptanceManagerInterface $manager,
        private LegalPageVersions $versions,
        private AcceptanceRequestMatcher $matcher,
        private RelativeTargetValidator $targets,
        private UrlGeneratorInterface $urls,
        private LegalLocales $locales,
        private string $nonHtml = 'pass',
        private string $defaultLocaleStyle = 'unprefixed',
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onKernelRequest', self::PRIORITY]];
    }

    /**
     * Redirects (HTML) or refuses (non-HTML with non_html: deny) while a page is pending.
     *
     * @return void Nothing (sets the response when pending)
     */
    public function onKernelRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !in_array($request->getMethod(), ['GET', 'HEAD'], true) || !$request->hasPreviousSession()) {
            return;
        }
        $html = AcceptanceRequestMatcher::isHtmlNavigation($request);
        if (!$html && $this->nonHtml !== 'deny') {
            return;
        }

        // Host voters and the token run inside the try too: a failing exemption voter fails open like the rest.
        try {
            if ($this->matcher->isExempt($request) || $this->subject->isImpersonating()) {
                return;
            }
            $subject = $this->subject->id();
            if ($subject === null) {
                return;
            }
            $session     = $request->getSession();
            $fingerprint = $this->versions->fingerprint();
            if (self::isSatisfied($session, $fingerprint, $subject)) {
                return;
            }
            $pending = $this->manager->pendingFor($subject, $request->getLocale());
            if ($pending === []) {
                self::markSatisfied($session, $fingerprint, $subject);

                return;
            }
            $url = $this->interstitialUrl($request->getLocale());
        } catch (Throwable) {
            $this->logger->error('Legal acceptance check failed; request let through.', ['bundle' => 'NowoLegalKitBundle', 'action' => 'acceptance_check_failed']);

            return;
        }

        if (!$html) {
            $event->setResponse($this->noStore(new JsonResponse(['error' => 'legal_acceptance_required', 'acceptance_url' => $url], Response::HTTP_FORBIDDEN)));

            return;
        }
        $target = $request->getRequestUri();
        if ($this->targets->isValid($target)) {
            $session->set(self::SESSION_TARGET, $target);
        }
        $event->setResponse($this->noStore(new RedirectResponse($url)));
    }

    /**
     * Whether the session holds a satisfied state for this fingerprint and subject.
     */
    public static function isSatisfied(SessionInterface $session, string $fingerprint, string $subject): bool
    {
        $state = $session->get(self::SESSION_STATE);

        return is_array($state) && ($state['ok'] ?? false) === true && ($state['fp'] ?? null) === $fingerprint && ($state['subject'] ?? null) === hash('sha256', $subject);
    }

    /**
     * Stores the satisfied state (no query on the next requests until a version changes).
     *
     * @return void Nothing (side effects only)
     */
    public static function markSatisfied(SessionInterface $session, string $fingerprint, string $subject): void
    {
        $session->set(self::SESSION_STATE, ['fp' => $fingerprint, 'subject' => hash('sha256', $subject), 'ok' => true]);
    }

    /**
     * Path of the re-acceptance page in a locale.
     *
     * @return string The path
     */
    public function interstitialUrl(string $locale): string
    {
        if (!$this->locales->has($locale) || ($this->defaultLocaleStyle === 'unprefixed' && $locale === $this->locales->defaultLocale())) {
            return $this->urls->generate(LegalRouteLoader::ACCEPTANCE_ROUTE_UNLOCALIZED);
        }

        return $this->urls->generate(LegalRouteLoader::ACCEPTANCE_ROUTE, ['_locale' => $locale]);
    }

    private function noStore(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
