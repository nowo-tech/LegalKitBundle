<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Tests\Support\DependencyInjection;

use RuntimeException;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * A configuration whose tree cannot be built (the probe must answer false).
 */
final class BrokenConfiguration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        throw new RuntimeException('broken');
    }
}
