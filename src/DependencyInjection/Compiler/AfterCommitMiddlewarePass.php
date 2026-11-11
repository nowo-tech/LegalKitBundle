<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\DependencyInjection\Compiler;

use Nowo\LegalKitBundle\DependencyInjection\NowoLegalKitExtension;
use Nowo\LegalKitBundle\Doctrine\AfterCommitMiddleware;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

use function is_string;
use function preg_match;
use function sprintf;

/**
 * Attaches {@see AfterCommitMiddleware} only to the connection of the bundle's entity manager (1.10): transactions
 * on other connections never delay or drop the bundle's events. Runs before DoctrineBundle's MiddlewaresPass. Without
 * a resolvable entity manager it falls back to the default connection.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class AfterCommitMiddlewarePass implements CompilerPassInterface
{
    /**
     * Rewrites the middleware tag with the `connection` attribute.
     *
     * @param ContainerBuilder $container The container being compiled
     *
     * @return void Nothing (side effects only)
     */
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(AfterCommitMiddleware::class)) {
            return;
        }
        $manager = $container->hasParameter(NowoLegalKitExtension::ENTITY_MANAGER_PARAMETER) ? $container->getParameter(NowoLegalKitExtension::ENTITY_MANAGER_PARAMETER) : null;
        if (!is_string($manager)) {
            $manager = $container->hasParameter('doctrine.default_entity_manager') ? $container->getParameter('doctrine.default_entity_manager') : 'default';
        }
        $id         = sprintf('doctrine.orm.%s_entity_manager', is_string($manager) ? $manager : 'default');
        $argument   = $container->hasDefinition($id) ? ($container->getDefinition($id)->getArguments()[0] ?? null) : null;
        $connection = $argument instanceof Reference && preg_match('/^doctrine\.dbal\.(.+)_connection$/D', (string) $argument, $match) === 1 ? $match[1] : null;
        if ($connection === null) {
            $default    = $container->hasParameter('doctrine.default_connection') ? $container->getParameter('doctrine.default_connection') : null;
            $connection = is_string($default) ? $default : 'default';
        }

        $container->getDefinition(AfterCommitMiddleware::class)
            ->clearTag('doctrine.middleware')
            ->addTag('doctrine.middleware', ['connection' => $connection]);
    }
}
