<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Tests\Support;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Host pages of the test kernel (`app_routes` option): an HTML page that starts the session, and a JSON endpoint.
 */
final class AppController
{
    public function page(Request $request): Response
    {
        $request->getSession()->set('visited', true);

        return new Response('<html><body>app ' . $request->getPathInfo() . '</body></html>');
    }

    public function data(): JsonResponse
    {
        return new JsonResponse(['ok' => true]);
    }
}
