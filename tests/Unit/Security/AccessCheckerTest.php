<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Tests\Unit\Security;

use Nowo\LegalKitBundle\Controller\Admin\LegalDocumentAdminController;
use Nowo\LegalKitBundle\EventSubscriber\AdminAccessSubscriber;
use Nowo\LegalKitBundle\Security\AllowAllLegalKitAccessChecker;
use Nowo\LegalKitBundle\Security\ConfigurableLegalKitAccessChecker;
use Nowo\LegalKitBundle\Security\LegalKitAccessCheckerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class AccessCheckerTest extends TestCase
{
    public function testRoleChecker(): void
    {
        $authorization = $this->createMock(AuthorizationCheckerInterface::class);
        $authorization->method('isGranted')->willReturnCallback(static fn (mixed $role): bool => $role === 'ROLE_LEGAL');

        self::assertTrue((new ConfigurableLegalKitAccessChecker($authorization, ['ROLE_ADMIN', 'ROLE_LEGAL']))->canAccess());
        self::assertFalse((new ConfigurableLegalKitAccessChecker($authorization, ['ROLE_ADMIN']))->canAccess());
        self::assertFalse((new ConfigurableLegalKitAccessChecker($authorization, []))->canAccess(), 'Empty roles fail closed');
        self::assertTrue((new AllowAllLegalKitAccessChecker())->canAccess());
    }

    public function testSubscriberOnlyGuardsAdminRoutes(): void
    {
        $deny = new class implements LegalKitAccessCheckerInterface {
            public function canAccess(): bool
            {
                return false;
            }
        };
        $subscriber = new AdminAccessSubscriber($deny);
        self::assertSame([KernelEvents::CONTROLLER => ['onKernelController', 0]], AdminAccessSubscriber::getSubscribedEvents());

        $subscriber->onKernelController($this->event('legal_privacy'));
        $subscriber->onKernelController($this->event(null));

        $this->expectException(AccessDeniedException::class);
        $subscriber->onKernelController($this->event('nowo_legal_kit_admin_index'));
    }

    public function testSubscriberGuardsTheAdminControllerWithoutARoute(): void
    {
        $deny = new class implements LegalKitAccessCheckerInterface {
            public function canAccess(): bool
            {
                return false;
            }
        };
        $controller = (new \ReflectionClass(LegalDocumentAdminController::class))->newInstanceWithoutConstructor();
        $event      = new ControllerEvent($this->createMock(HttpKernelInterface::class), [$controller, 'index'], new Request(), HttpKernelInterface::SUB_REQUEST);

        $this->expectException(AccessDeniedException::class);
        (new AdminAccessSubscriber($deny))->onKernelController($event);
    }

    private function event(?string $route): ControllerEvent
    {
        $request = new Request();
        if ($route !== null) {
            $request->attributes->set('_route', $route);
        }

        return new ControllerEvent($this->createMock(HttpKernelInterface::class), static fn (): null => null, $request, HttpKernelInterface::MAIN_REQUEST);
    }
}
