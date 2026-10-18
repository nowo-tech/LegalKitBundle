<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle;

use Nowo\LegalKitBundle\DependencyInjection\Compiler\IntegrationPass;
use Nowo\LegalKitBundle\DependencyInjection\Compiler\SecurityPass;
use Nowo\LegalKitBundle\DependencyInjection\Compiler\TwigPathsPass;
use Nowo\LegalKitBundle\DependencyInjection\NowoLegalKitExtension;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * Operator-editable legal pages (notice, privacy, terms, cookies) per locale: generic seed, placeholder gate,
 * ops-seed portability, DOM allowlist sanitiser and UiKit admin pages; see docs/USAGE.md.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class NowoLegalKitBundle extends Bundle
{
    /**
     * Registers the compiler passes (Twig paths, admin security, late integrations).
     *
     * @param ContainerBuilder $container The container being compiled
     */
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->addCompilerPass(new TwigPathsPass());
        $container->addCompilerPass(new SecurityPass());
        $container->addCompilerPass(new IntegrationPass());
    }

    /**
     * Returns the bundle extension (alias `nowo_legal_kit`).
     *
     * @return ExtensionInterface The lazily created NowoLegalKitExtension
     */
    public function getContainerExtension(): ExtensionInterface
    {
        if (!$this->extension instanceof ExtensionInterface) {
            // @igor-ignore - Symfony bundle extension lazy-init at boot; not request state
            $this->extension = new NowoLegalKitExtension();
        }

        return $this->extension;
    }
}
