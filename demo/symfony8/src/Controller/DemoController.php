<?php

declare(strict_types=1);

namespace App\Controller;

use App\Form\LoginType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

/**
 * Demo for NowoLegalKitBundle: landing page, sign-in and an Ops overview with the placeholder banner.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class DemoController extends AbstractController
{
    #[Route('/', name: 'homepage', methods: ['GET'])]
    public function home(): Response
    {
        return $this->render('demo/home.html.twig');
    }

    #[Route('/login', name: 'demo_login', methods: ['GET', 'POST'])]
    public function login(AuthenticationUtils $auth): Response
    {
        $form = $this->createForm(LoginType::class, null, [
            'action'        => $this->generateUrl('demo_login'),
            'last_username' => $auth->getLastUsername() !== '' ? $auth->getLastUsername() : 'admin',
            'target_path'   => $this->generateUrl('nowo_legal_kit_admin_index'),
        ]);

        return $this->render('demo/login.html.twig', [
            'login_form'  => $form,
            'login_error' => $auth->getLastAuthenticationError(),
        ]);
    }

    #[Route('/logout', name: 'demo_logout', methods: ['GET'])]
    public function logout(): never
    {
        throw new \LogicException('Intercepted by the logout key of the firewall.');
    }

    #[Route('/ops', name: 'demo_ops', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function ops(): Response
    {
        return $this->render('demo/ops.html.twig');
    }
}
