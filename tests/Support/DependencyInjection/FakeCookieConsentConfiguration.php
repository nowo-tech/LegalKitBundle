<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Tests\Support\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * Stands in for the CookieConsent 1.17 configuration (has the privacy_route node).
 */
final class FakeCookieConsentConfiguration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $tree = new TreeBuilder('nowo_cookie_consent');
        $tree->getRootNode()->children()->scalarNode('privacy_route')->defaultNull()->end()->end();

        return $tree;
    }
}
