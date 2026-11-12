<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle;

use Doctrine\Bundle\DoctrineBundle\DependencyInjection\Compiler\DoctrineOrmMappingsPass;
use Nowo\LegalKitBundle\DependencyInjection\Compiler\AfterCommitMiddlewarePass;
use Nowo\LegalKitBundle\DependencyInjection\Compiler\ContextCookiesPass;
use Nowo\LegalKitBundle\DependencyInjection\Compiler\DeprecatedPortsPass;
use Nowo\LegalKitBundle\DependencyInjection\Compiler\EntityManagerMappingPass;
use Nowo\LegalKitBundle\DependencyInjection\Compiler\IntegrationPass;
use Nowo\LegalKitBundle\DependencyInjection\Compiler\SecurityPass;
use Nowo\LegalKitBundle\DependencyInjection\Compiler\TwigPathsPass;
use Nowo\LegalKitBundle\DependencyInjection\NowoLegalKitExtension;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
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
     * Registers the compiler passes (Twig paths, admin security, late integrations, dedicated entity manager mapping,
     * after-commit middleware).
     *
     * @param ContainerBuilder $container The container being compiled
     *
     * @return void Nothing (side effects only)
     */
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->addCompilerPass(new TwigPathsPass());
        $container->addCompilerPass(new SecurityPass());
        $container->addCompilerPass(new IntegrationPass());
        // 1.12 (rule 3): deprecation of the host ports typed with framework classes.
        $container->addCompilerPass(new DeprecatedPortsPass());
        // 1.13 (MU-L-I1): acceptance.context_cookies never names a firewall remember-me cookie.
        $container->addCompilerPass(new ContextCookiesPass());
        // 1.10 (rule 7): the after-commit middleware follows the bundle's connection only (before MiddlewaresPass).
        $container->addCompilerPass(new AfterCommitMiddlewarePass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 10);
        // 1.10 (rule 6): with doctrine.entity_manager, the entities are mapped on that manager (not prepended to the
        // default one) and removed from every other manager's chain (auto_mapping), after the mapping passes. An
        // unknown manager fails first, with the option's name in the message.
        $container->addCompilerPass(new EntityManagerMappingPass(true), PassConfig::TYPE_BEFORE_OPTIMIZATION, 20);
        foreach ([
            NowoLegalKitExtension::DEDICATED_MAPPING_PARAMETER            => [EntityManagerMappingPass::ENTITY_NAMESPACE, __DIR__ . '/Entity'],
            NowoLegalKitExtension::DEDICATED_VERSIONING_MAPPING_PARAMETER => [EntityManagerMappingPass::VERSIONING_NAMESPACE, __DIR__ . '/Versioning/Entity'],
            NowoLegalKitExtension::DEDICATED_ACCEPTANCE_MAPPING_PARAMETER => [EntityManagerMappingPass::ACCEPTANCE_NAMESPACE, __DIR__ . '/Acceptance/Entity'],
        ] as $enabled => [$namespace, $directory]) {
            $container->addCompilerPass(DoctrineOrmMappingsPass::createAttributeMappingDriver([$namespace], [$directory], [NowoLegalKitExtension::ENTITY_MANAGER_PARAMETER], $enabled));
        }
        $container->addCompilerPass(new EntityManagerMappingPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, -10);
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
