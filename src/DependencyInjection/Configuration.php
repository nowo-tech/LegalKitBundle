<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

use function is_array;
use function is_string;
use function preg_match;

/**
 * Strict configuration tree of `nowo_legal_kit` (REQ-SF-006); every key is documented in docs/CONFIGURATION.md.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class Configuration implements ConfigurationInterface
{
    public const string ALIAS = 'nowo_legal_kit';

    /** Slug pattern of a legal page (stored in `legal_document.slug`, VARCHAR 32). */
    public const string SLUG_PATTERN = '/^[a-z0-9](?:[a-z0-9-]{0,30}[a-z0-9])?$/';

    /** Path segment of a legal page URL. */
    public const string PATH_PATTERN = '/^[a-z0-9][a-z0-9\/_-]{0,63}$/';

    /** The four canonical pages (REQ-CC-003), in footer order. */
    public const array DEFAULT_PAGES = ['notice', 'privacy', 'terms', 'cookies'];

    /**
     * Builds the `nowo_legal_kit` tree.
     *
     * @return TreeBuilder<'array'> The tree builder
     */
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder(self::ALIAS);
        $root        = $treeBuilder->getRootNode();

        $root
            ->children()
                ->variableNode('locales')
                    ->info('Enabled locales: a list, a "%parameter%" (e.g. "%app.locales%") or a comma / pipe separated string.')
                    ->defaultValue(['en'])
                    ->validate()
                        ->ifTrue(static fn (mixed $value): bool => !is_array($value) && !is_string($value))
                        ->thenInvalid('nowo_legal_kit.locales must be a list of locale codes or a string (parameter, "en,es" or "en|es").')
                    ->end()
                ->end()
                ->scalarNode('default_locale')
                    ->info('Default locale (served on the bare /legal/… URLs with the unprefixed style). Null = first locale.')
                    ->defaultNull()
                ->end()
                ->scalarNode('brand_name')
                    ->info('Value of the %brand% token in the seed and in stored documents.')
                    ->defaultValue('')
                ->end()
                ->append($this->pagesNode())
                ->arrayNode('routing')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('path_prefix')
                            ->info('URL segment of the public pages: /{_locale}<path_prefix>/<page path>.')
                            ->defaultValue('/legal')
                            ->validate()
                                ->ifTrue(static fn (mixed $value): bool => !is_string($value) || preg_match('#^(/[a-z0-9_-]+)*$#', $value) !== 1)
                                ->thenInvalid('nowo_legal_kit.routing.path_prefix must be empty or start with "/" (lowercase segments), got %s.')
                            ->end()
                        ->end()
                        ->enumNode('default_locale_style')
                            ->info('unprefixed: the default locale lives on the bare path (/{default}/… answers 301); prefixed: every locale is prefixed, the bare path redirects (302).')
                            ->values(['unprefixed', 'prefixed'])
                            ->defaultValue('unprefixed')
                        ->end()
                        ->booleanNode('legacy_admin_aliases')
                            ->info('Register the deprecated admin_legal_index / admin_legal_edit / admin_legal_reset route aliases (removed in 2.0).')
                            ->defaultTrue()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('seed')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('materialize')
                            ->info('true: nowo:legal-kit:seed --missing-only stores the seed as rows (no row is never shown as seed).')
                            ->defaultFalse()
                        ->end()
                        ->scalarNode('fallback_locale')
                            ->info('Locale whose seed is shown when no seed provider supports the requested locale (null = none).')
                            ->defaultNull()
                        ->end()
                        ->scalarNode('date')
                            ->info('Date printed by %last_updated% for seed pages (Y-m-d).')
                            ->defaultValue('2026-10-18')
                            ->validate()
                                ->ifTrue(static fn (mixed $value): bool => !is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1)
                                ->thenInvalid('nowo_legal_kit.seed.date must be a Y-m-d date, got %s.')
                            ->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('editor')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->enumNode('type')
                            ->info('auto: CKEditor 5 when nowo-tech/ckeditor5-editor-bundle is installed, else a textarea.')
                            ->values(['auto', 'ckeditor5', 'textarea'])
                            ->defaultValue('auto')
                        ->end()
                        ->scalarNode('ckeditor5_profile')
                            ->info('Ckeditor5EditorBundle configuration name used by the body field.')
                            ->defaultValue('legal')
                            ->cannotBeEmpty()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('sanitizer')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->enumNode('outbound_url_guard')
                            ->info('auto: drop private-network / metadata URLs when nowo-tech/outbound-url-guard-bundle is installed.')
                            ->values(['auto', true, false])
                            ->defaultValue('auto')
                        ->end()
                        ->enumNode('ckeditor5_first_pass')
                            ->info('auto: run the Ckeditor5EditorBundle strict sanitiser before the DOM allowlist when the bundle is installed.')
                            ->values(['auto', true, false])
                            ->defaultValue('auto')
                        ->end()
                        ->arrayNode('allowed_elements')
                            ->info('Extra elements (name => list of attributes) merged into the built-in allowlist.')
                            ->useAttributeAsKey('name')
                            ->arrayPrototype()
                                ->scalarPrototype()->end()
                            ->end()
                            ->defaultValue([])
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('placeholders')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('builtin_markers')
                            ->info('Report the markers of the shipped generic seed (disable for seeds with a real identity).')
                            ->defaultTrue()
                        ->end()
                        ->arrayNode('extra_markers')
                            ->info('Additional strings that must not appear in a published page.')
                            ->scalarPrototype()->cannotBeEmpty()->end()
                            ->defaultValue([])
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('public')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('layout_template')
                            ->info('Layout of the public pages; null = UiKit nowo_ui_layout.guest.')
                            ->defaultNull()
                        ->end()
                        ->arrayNode('cache')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->integerNode('max_age')->min(0)->defaultValue(0)->end()
                                ->integerNode('shared_max_age')
                                    ->info('> 0 makes the response public (shared caches); 0 keeps Cache-Control: private.')
                                    ->min(0)
                                    ->defaultValue(0)
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('web_ui')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')->defaultTrue()->end()
                        ->scalarNode('path_prefix')
                            ->defaultValue('/admin/legal')
                            ->validate()
                                ->ifTrue(static fn (mixed $value): bool => !is_string($value) || preg_match('#^(/[a-z0-9_-]+)+$#', $value) !== 1)
                                ->thenInvalid('nowo_legal_kit.web_ui.path_prefix must start with "/" (lowercase segments), got %s.')
                            ->end()
                        ->end()
                        ->scalarNode('layout_template')
                            ->info('Layout of the admin pages; null = UiKit nowo_ui_layout.admin.')
                            ->defaultNull()
                        ->end()
                        ->arrayNode('templates')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->scalarNode('index')->defaultValue('@NowoLegalKitBundle/admin/index.html.twig')->cannotBeEmpty()->end()
                                ->scalarNode('edit')->defaultValue('@NowoLegalKitBundle/admin/edit.html.twig')->cannotBeEmpty()->end()
                                ->scalarNode('public_page')->defaultValue('@NowoLegalKitBundle/public/page.html.twig')->cannotBeEmpty()->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('security')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('access_roles')
                            ->scalarPrototype()->cannotBeEmpty()->end()
                            ->defaultValue(['ROLE_ADMIN'])
                        ->end()
                        ->scalarNode('access_checker')
                            ->info('Service id implementing LegalKitAccessCheckerInterface; null = role checker.')
                            ->defaultNull()
                        ->end()
                        ->booleanNode('allow_unauthenticated')
                            ->info('DEV / DEMO ONLY: admin pages without SecurityBundle or login.')
                            ->defaultFalse()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('seo')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->enumNode('sitemap')
                            ->info('auto: register a SeoKit sitemap provider when nowo-tech/seo-kit-bundle is installed.')
                            ->values(['auto', true, false])
                            ->defaultValue('auto')
                        ->end()
                        ->enumNode('head')
                            ->info('auto: set the SeoKit title, canonical and hreflang alternates of legal pages when SeoKit is installed.')
                            ->values(['auto', true, false])
                            ->defaultValue('auto')
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('cookie_consent')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->enumNode('prepend_routes')
                            ->info('auto: add the legal routes to nowo_cookie_consent disabled_routes / render_routes and privacy_route when CookieConsent is installed.')
                            ->values(['auto', true, false])
                            ->defaultValue('auto')
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('console')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('legacy_aliases')
                            ->info('Register app:legal:check-placeholders and app:ops-seed:adapt-legal as aliases (removed in 2.0).')
                            ->defaultTrue()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('doctrine')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('table_prefix')
                            ->info('Prefix of the bundle table and its index names (REQ-ORM-002). Adopters of an existing legal_document table keep it empty.')
                            ->defaultValue('')
                            ->validate()
                                ->ifTrue(static fn (mixed $value): bool => !is_string($value) || preg_match('/^[a-z0-9_]*$/', $value) !== 1)
                                ->thenInvalid('nowo_legal_kit.doctrine.table_prefix accepts lowercase letters, digits and "_" only, got %s.')
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->end();

        return $treeBuilder;
    }

    /**
     * The ordered page catalogue (`pages`): slug => {path, enabled, nav, seed_template}.
     */
    private function pagesNode(): ArrayNodeDefinition
    {
        $node = (new TreeBuilder('pages'))->getRootNode();
        $defaults = [];
        foreach (self::DEFAULT_PAGES as $slug) {
            $defaults[$slug] = ['path' => $slug, 'enabled' => true, 'nav' => true, 'seed_template' => null];
        }

        $node
            ->info('Ordered legal pages (slug => options). Defaults: notice, privacy, terms, cookies.')
            ->useAttributeAsKey('slug')
            ->defaultValue($defaults)
            ->arrayPrototype()
                ->children()
                    ->scalarNode('path')->defaultNull()->end()
                    ->booleanNode('enabled')->defaultTrue()->end()
                    ->booleanNode('nav')->defaultTrue()->end()
                    ->scalarNode('seed_template')
                        ->info('Twig template of the built-in body; null = @NowoLegalKitBundle/seed/<slug>.html.twig.')
                        ->defaultNull()
                    ->end()
                ->end()
            ->end()
            ->validate()
                ->always(static function (array $pages): array {
                    // The four canonical pages always exist (disable one with enabled: false); extra slugs follow them.
                    $merged = [];
                    foreach (self::DEFAULT_PAGES as $default) {
                        $merged[$default] = ($pages[$default] ?? []) + ['path' => $default, 'enabled' => true, 'nav' => true, 'seed_template' => null];
                        unset($pages[$default]);
                    }
                    $pages = $merged + $pages;
                    foreach ($pages as $slug => $page) {
                        $slug = (string) $slug;
                        if (preg_match(self::SLUG_PATTERN, $slug) !== 1) {
                            throw new \InvalidArgumentException(\sprintf('nowo_legal_kit.pages: invalid slug "%s" (lowercase letters, digits and "-", at most 32 characters).', $slug));
                        }
                        $path = $page['path'] ?? $slug;
                        if (!is_string($path) || preg_match(self::PATH_PATTERN, $path) !== 1) {
                            throw new \InvalidArgumentException(\sprintf('nowo_legal_kit.pages.%s.path: invalid path "%s".', $slug, is_string($path) ? $path : get_debug_type($path)));
                        }
                        $pages[$slug]['path'] = $path;
                    }

                    return $pages;
                })
            ->end();

        return $node;
    }
}
