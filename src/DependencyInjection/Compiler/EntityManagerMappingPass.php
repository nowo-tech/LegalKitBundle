<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\DependencyInjection\Compiler;

use Doctrine\Bundle\DoctrineBundle\DependencyInjection\Compiler\DoctrineOrmMappingsPass;
use Nowo\LegalKitBundle\DependencyInjection\NowoLegalKitExtension;
use Nowo\LegalKitBundle\Doctrine\ExcludedEntityNamespaceDriver;
use Nowo\LegalKitBundle\Doctrine\OwnEntityManagerSubscriber;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

use function array_keys;
use function array_unshift;
use function implode;
use function in_array;
use function is_array;
use function is_string;
use function sprintf;
use function str_starts_with;

/**
 * Keeps the bundle entities on `doctrine.entity_manager` only (1.10, architecture bar rule 6).
 *
 * With a dedicated manager the bundle maps its entities on that manager ({@see DoctrineOrmMappingsPass}, registered by
 * the bundle class). DoctrineBundle's `auto_mapping` (the Flex default on the default manager) would map them a second
 * time, and `getManagerForClass()` (the bundle repositories) would then return the default manager. This pass removes
 * the bundle namespaces from the mapping driver chain of every other manager and registers an
 * {@see ExcludedEntityNamespaceDriver} for each of them there instead (first in the chain), so a default driver of that
 * chain cannot claim the entities either (DoctrineBundle 3.4 `auto_discover_entities`; only the public `addDriver()` of
 * the `doctrine.orm.<manager>_metadata_driver` chain is used). It fails the build when the configured
 * manager does not exist or does not map {@see self::ENTITY_NAMESPACE}, when it is the default manager, or when it also
 * maps other classes (the bundle owns that manager: {@see OwnEntityManagerSubscriber} resets and clears it, and gets
 * its subscriber tag here, only once the manager is known to be the bundle's own). Without `doctrine.entity_manager` it does
 * nothing (the mapping is prepended as before 1.10).
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class EntityManagerMappingPass implements CompilerPassInterface
{
    /** Namespace of the legal document entity. */
    public const string ENTITY_NAMESPACE = 'Nowo\\LegalKitBundle\\Entity';

    /** Namespace of the page version entity (`versioning.enabled`). */
    public const string VERSIONING_NAMESPACE = 'Nowo\\LegalKitBundle\\Versioning\\Entity';

    /** Namespace of the acceptance entity (`acceptance.enabled`). */
    public const string ACCEPTANCE_NAMESPACE = 'Nowo\\LegalKitBundle\\Acceptance\\Entity';

    /**
     * @param bool $checkOnly only check that the manager exists (registered before the mapping passes, which would
     *                        otherwise fail on its missing driver chain with a less helpful message)
     */
    public function __construct(
        private readonly bool $checkOnly = false,
    ) {
    }

    /**
     * Removes the bundle mapping from the managers other than `doctrine.entity_manager`.
     *
     * @param ContainerBuilder $container The container being compiled
     *
     * @return void Nothing (side effects only)
     */
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter(NowoLegalKitExtension::DEDICATED_MAPPING_PARAMETER)) {
            return;
        }
        $name = $container->hasParameter(NowoLegalKitExtension::ENTITY_MANAGER_PARAMETER) ? $container->getParameter(NowoLegalKitExtension::ENTITY_MANAGER_PARAMETER) : null;
        if (!is_string($name) || $name === '') {
            return;
        }

        $managers = $container->hasParameter('doctrine.entity_managers') ? $container->getParameter('doctrine.entity_managers') : [];
        $managers = is_array($managers) ? $managers : [];
        if (!isset($managers[$name])) {
            throw new InvalidConfigurationException(sprintf('nowo_legal_kit.doctrine.entity_manager is "%s", but no such Doctrine entity manager is configured (known: "%s"). Add it under doctrine.orm.entity_managers or remove the option.', $name, implode('", "', array_keys($managers))));
        }
        $default = $container->hasParameter('doctrine.default_entity_manager') ? $container->getParameter('doctrine.default_entity_manager') : null;
        if ($name === $default) {
            throw new InvalidConfigurationException(sprintf('nowo_legal_kit.doctrine.entity_manager is "%s", your default Doctrine entity manager. The option names a manager the bundle owns (it resets and clears it between requests): add a separate manager that maps only the bundle entities under doctrine.orm.entity_managers, or remove the option to keep the bundle on your default manager.', $name));
        }
        if ($this->checkOnly) {
            return;
        }

        $foreign = [];
        foreach (array_keys($managers) as $manager) {
            $chainId = sprintf('doctrine.orm.%s_metadata_driver', $manager);
            if (!$container->hasDefinition($chainId)) {
                continue;
            }

            $chain = $container->getDefinition($chainId);
            $calls = [];
            $maps  = false;
            foreach ($chain->getMethodCalls() as $call) {
                $namespace = $call[0] === 'addDriver' ? ($call[1][1] ?? null) : null;
                $isBundle  = is_string($namespace) && $this->isBundleNamespace($namespace);
                if ($isBundle && $manager !== $name) {
                    continue;
                }

                if ($manager === $name && !$isBundle && in_array($call[0], ['addDriver', 'setDefaultDriver'], true)) {
                    $foreign[] = is_string($namespace) ? $namespace : 'a default driver (every class)';
                }
                $maps    = $maps || ($isBundle && str_starts_with((string) $namespace, self::ENTITY_NAMESPACE));
                $calls[] = $call;
            }

            if ($manager !== $name) {
                // First in the chain: a chain asks its namespace drivers in order, then its default driver.
                array_unshift(
                    $calls,
                    ['addDriver', [new Definition(ExcludedEntityNamespaceDriver::class), self::ENTITY_NAMESPACE]],
                    ['addDriver', [new Definition(ExcludedEntityNamespaceDriver::class), self::VERSIONING_NAMESPACE]],
                    ['addDriver', [new Definition(ExcludedEntityNamespaceDriver::class), self::ACCEPTANCE_NAMESPACE]],
                );
            }

            $chain->setMethodCalls($calls);

            if ($manager === $name && !$maps) {
                throw new InvalidConfigurationException(sprintf('The Doctrine entity manager "%s" (nowo_legal_kit.doctrine.entity_manager) does not map %s.', $name, self::ENTITY_NAMESPACE));
            }
        }
        if ($foreign !== []) {
            throw new InvalidConfigurationException(sprintf('The Doctrine entity manager "%s" (nowo_legal_kit.doctrine.entity_manager) also maps %s. The bundle owns that manager (it resets and clears it between requests): give it a manager that maps only the bundle entities (auto_mapping: false, no other mappings).', $name, implode(', ', $foreign)));
        }

        // Only now (a manager the bundle owns) is the recovery subscriber wired: it resets and clears that manager.
        if ($container->hasDefinition(OwnEntityManagerSubscriber::class)) {
            $container->getDefinition(OwnEntityManagerSubscriber::class)->addTag('kernel.event_subscriber');
        }
    }

    private function isBundleNamespace(string $namespace): bool
    {
        foreach ([self::ENTITY_NAMESPACE, self::VERSIONING_NAMESPACE, self::ACCEPTANCE_NAMESPACE] as $bundle) {
            if (str_starts_with($namespace, $bundle)) {
                return true;
            }
        }

        return false;
    }
}
