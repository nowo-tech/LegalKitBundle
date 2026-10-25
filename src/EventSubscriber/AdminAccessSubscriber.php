<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\EventSubscriber;

use Nowo\LegalKitBundle\Controller\Admin\LegalDocumentAdminController;
use Nowo\LegalKitBundle\Routing\LegalRouteLoader;
use Nowo\LegalKitBundle\Security\LegalKitAccessCheckerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

use function is_array;
use function is_string;
use function str_starts_with;

/**
 * Enforces the access checker on every admin route (`nowo_legal_kit_admin_*`), so a new action cannot forget it
 * (REQ-UI-002). Admin pages also answer `Cache-Control: no-store` (set by the controller).
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class AdminAccessSubscriber implements EventSubscriberInterface
{
    /**
     * @param LegalKitAccessCheckerInterface $accessChecker Access checker
     */
    public function __construct(
        private LegalKitAccessCheckerInterface $accessChecker,
    ) {
    }

    /**
     * Listens to the controller event to guard the admin routes.
     */
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::CONTROLLER => ['onKernelController', 0]];
    }

    /**
     * Denies admin routes when the checker refuses.
     *
     * @throws AccessDeniedException
     *
     * @return void Nothing (side effects only)
     */
    public function onKernelController(ControllerEvent $event): void
    {
        $route      = $event->getRequest()->attributes->get('_route');
        $controller = $event->getController();
        $isAdmin    = (is_string($route) && str_starts_with($route, LegalRouteLoader::ADMIN_ROUTE_PREFIX))
            // Sub-requests (render(controller(…)), ESI) carry no admin route: guard the controller itself too.
            || (is_array($controller) && $controller[0] instanceof LegalDocumentAdminController);
        if (!$isAdmin) {
            return;
        }
        if (!$this->accessChecker->canAccess()) {
            throw new AccessDeniedException('The legal pages admin requires an authorized user.');
        }
    }
}
