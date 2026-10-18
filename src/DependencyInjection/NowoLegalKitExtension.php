<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\DependencyInjection;

use Nowo\Ckeditor5EditorBundle\Form\Ckeditor5EditorType;
use Nowo\Ckeditor5EditorBundle\Security\AllowlistCkeditor5HtmlSanitizer;
use Nowo\LegalKitBundle\Command\AdaptSeedCommand;
use Nowo\LegalKitBundle\Command\CheckPlaceholdersCommand;
use Nowo\LegalKitBundle\Doctrine\TablePrefixListener;
use Nowo\LegalKitBundle\EventListener\SeoKitHeadListener;
use Nowo\LegalKitBundle\Locale\LegalLocales;
use Nowo\LegalKitBundle\Seed\LegalSeedProviderInterface;
use Nowo\LegalKitBundle\Seo\LegalSitemapProvider;
use Nowo\LegalKitBundle\Token\LegalTokenResolverInterface;
use Nowo\OutboundUrlGuardBundle\Guard\OutboundUrlGuard;
use Nowo\SeoKitBundle\Service\SeoRuntime;
use Nowo\SeoKitBundle\Service\SitemapUrlProviderInterface;
use Symfony\Component\Config\Definition\ArrayNode;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\Component\DependencyInjection\Reference;
use Throwable;

use function array_fill_keys;
use function array_key_exists;
use function class_exists;
use function in_array;
use function interface_exists;
use function is_a;
use function is_array;

/**
 * Loads `nowo_legal_kit`: services, the resolved optional integrations (CKEditor 5, OutboundUrlGuard, SeoKit,
 * CookieConsent, AuditKit action log) and the prepended host configuration (asset package, Doctrine mapping,
 * FormKit profile `legal_kit`, CKEditor profile `legal`, CookieConsent legal routes).
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class NowoLegalKitExtension extends Extension implements PrependExtensionInterface
{
    /**
     * @param string $cookieConsentConfiguration CookieConsent configuration class to probe (tests pass another one)
     */
    public function __construct(
        private readonly string $cookieConsentConfiguration = self::COOKIE_CONSENT_CONFIGURATION,
    ) {
    }

    /** Public path created by `assets:install` (REQ-ASSETS-004). */
    public const string ASSET_BASE_PATH = '/bundles/nowolegalkit';

    public const string CKEDITOR_BUNDLE = 'Nowo\\Ckeditor5EditorBundle\\NowoCkeditor5EditorBundle';

    public const string COOKIE_CONSENT_BUNDLE = 'Nowo\\CookieConsentBundle\\NowoCookieConsentBundle';

    public const string SEO_KIT_BUNDLE = 'Nowo\\SeoKitBundle\\SeoKitBundle';

    public const string OUTBOUND_URL_GUARD_BUNDLE = 'Nowo\\OutboundUrlGuardBundle\\NowoOutboundUrlGuardBundle';

    /** CookieConsentBundle configuration class (probed for the 1.17 `privacy_route` node). */
    public const string COOKIE_CONSENT_CONFIGURATION = 'Nowo\\CookieConsentBundle\\DependencyInjection\\Configuration';

    /** FormKit profile of the editor form. */
    public const string FORM_KIT_PROFILE = 'legal_kit';

    /**
     * Prepends the host configuration the kit needs (never overriding what the host set).
     *
     * @param ContainerBuilder $container The container being built
     *
     * @return void Nothing (side effects only)
     */
    public function prepend(ContainerBuilder $container): void
    {
        if ($container->hasExtension('framework')) {
            $container->prependExtensionConfig('framework', [
                'assets' => ['packages' => [Configuration::ALIAS => ['base_path' => self::ASSET_BASE_PATH]]],
            ]);
        }

        if ($container->hasExtension('doctrine') && !$this->hostHasKey($container, 'doctrine', ['orm', 'entity_managers'])) {
            $container->prependExtensionConfig('doctrine', ['orm' => ['mappings' => ['NowoLegalKitBundle' => [
                'type'      => 'attribute',
                'is_bundle' => false,
                'dir'       => __DIR__ . '/../Entity',
                'prefix'    => 'Nowo\\LegalKitBundle\\Entity',
                'alias'     => 'NowoLegalKit',
            ]]]]);
        }

        if ($container->hasExtension('nowo_form_kit') && !$this->hostHasKey($container, 'nowo_form_kit', ['profiles', self::FORM_KIT_PROFILE])) {
            $container->prependExtensionConfig('nowo_form_kit', ['profiles' => [self::FORM_KIT_PROFILE => [
                'alias'              => self::FORM_KIT_PROFILE,
                'translation_domain' => 'NowoLegalKitBundle',
                'auto_placeholder'   => false,
                'auto_help'          => false,
            ]]]);
        }

        $raw = $this->rawConfig($container);
        if ($container->hasExtension('nowo_ckeditor5_editor')) {
            $profile = $raw['editor']['ckeditor5_profile'] ?? 'legal';
            if (!$this->hostHasKey($container, 'nowo_ckeditor5_editor', ['profiles', $profile]) && !$this->hostHasKey($container, 'nowo_ckeditor5_editor', ['configs', $profile])) {
                $profiles = [$profile => ['preset' => 'standard', 'toolbar' => true, 'min_height' => '420px']];
                // The editor bundle requires its default profile to exist: keep it when the host defines no profiles.
                if (!$this->hostHasKey($container, 'nowo_ckeditor5_editor', ['profiles', 'default']) && !$this->hostHasKey($container, 'nowo_ckeditor5_editor', ['configs', 'default'])) {
                    $profiles = ['default' => []] + $profiles;
                }
                $container->prependExtensionConfig('nowo_ckeditor5_editor', ['profiles' => $profiles]);
            }
        }

        $prepend = $raw['cookie_consent']['prepend_routes'] ?? 'auto';
        if ($prepend !== false && $container->hasExtension('nowo_cookie_consent')) {
            $this->prependCookieConsent($container, $raw);
        }
    }

    /**
     * Processes the configuration and loads `Resources/config/services.php`.
     *
     * @param array<array<string, mixed>> $configs Raw `nowo_legal_kit` configs
     * @param ContainerBuilder $container The container being built
     *
     * @return void Nothing (side effects only)
     */
    public function load(array $configs, ContainerBuilder $container): void
    {
        /** @var array<string, mixed> $config */
        $config = $this->processConfiguration(new Configuration(), $configs);
        /** @var array{path_prefix: string, default_locale_style: string, legacy_admin_aliases: bool} $routing */
        $routing = $config['routing'];
        /** @var array{materialize: bool, fallback_locale: string|null, date: string} $seed */
        $seed = $config['seed'];
        /** @var array{type: string, ckeditor5_profile: string} $editor */
        $editor = $config['editor'];
        /** @var array{outbound_url_guard: bool|string, ckeditor5_first_pass: bool, allowed_elements: array<string, list<string>>} $sanitizer */
        $sanitizer = $config['sanitizer'];
        /** @var array{builtin_markers: bool, extra_markers: list<string>} $placeholders */
        $placeholders = $config['placeholders'];
        /** @var array{layout_template: string|null, cache: array{max_age: int, shared_max_age: int}} $public */
        $public = $config['public'];
        /** @var array{enabled: bool, path_prefix: string, layout_template: string|null, templates: array{index: string, edit: string, public_page: string}} $webUi */
        $webUi = $config['web_ui'];
        /** @var array{access_roles: list<string>, access_checker: string|null, allow_unauthenticated: bool} $security */
        $security = $config['security'];

        $bundles = $container->hasParameter('kernel.bundles') ? (array) $container->getParameter('kernel.bundles') : [];
        $has     = static fn (string $bundleClass): bool => in_array($bundleClass, $bundles, true);

        $p = Configuration::ALIAS . '.';
        $container->setParameter($p . 'locales', $config['locales']);
        $container->setParameter($p . 'default_locale', $config['default_locale']);
        $container->setParameter($p . 'brand_name', $config['brand_name']);
        $container->setParameter($p . 'pages', $config['pages']);
        $container->setParameter($p . 'routing.path_prefix', $routing['path_prefix']);
        $container->setParameter($p . 'routing.default_locale_style', $routing['default_locale_style']);
        $container->setParameter($p . 'routing.legacy_admin_aliases', $routing['legacy_admin_aliases']);
        $container->setParameter($p . 'seed.materialize', $seed['materialize']);
        $container->setParameter($p . 'seed.fallback_locale', $seed['fallback_locale']);
        $container->setParameter($p . 'seed.date', $seed['date']);
        $container->setParameter($p . 'editor.type', $this->editorType($editor['type'], $has(self::CKEDITOR_BUNDLE)));
        $container->setParameter($p . 'editor.ckeditor5_profile', $editor['ckeditor5_profile']);
        $container->setParameter($p . 'sanitizer.allowed_elements', $sanitizer['allowed_elements']);
        $container->setParameter($p . 'placeholders.builtin_markers', $placeholders['builtin_markers']);
        $container->setParameter($p . 'placeholders.extra_markers', $placeholders['extra_markers']);
        $container->setParameter($p . 'public.layout_template', $public['layout_template']);
        $container->setParameter($p . 'public.cache.max_age', $public['cache']['max_age']);
        $container->setParameter($p . 'public.cache.shared_max_age', $public['cache']['shared_max_age']);
        $container->setParameter($p . 'web_ui.enabled', $webUi['enabled']);
        $container->setParameter($p . 'web_ui.path_prefix', $webUi['path_prefix']);
        $container->setParameter($p . 'web_ui.layout_template', $webUi['layout_template']);
        $container->setParameter($p . 'web_ui.templates', $webUi['templates']);
        $container->setParameter($p . 'web_ui.templates.public_page', $webUi['templates']['public_page']);
        $container->setParameter($p . 'security.access_roles', $security['access_roles']);
        $container->setParameter($p . 'security.access_checker', $security['access_checker']);
        $container->setParameter($p . 'security.allow_unauthenticated', $security['allow_unauthenticated']);
        $container->setParameter($p . 'cookie_consent.installed', $has(self::COOKIE_CONSENT_BUNDLE));

        $container->registerForAutoconfiguration(LegalSeedProviderInterface::class)->addTag(LegalSeedProviderInterface::TAG);
        $container->registerForAutoconfiguration(LegalTokenResolverInterface::class)->addTag(LegalTokenResolverInterface::TAG);

        (new PhpFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config')))->load('services.php');

        $this->configureSanitizer($container, $sanitizer, $has);
        $this->configureCommands($container, (bool) ($config['console']['legacy_aliases'] ?? true));

        /** @var array{table_prefix: string} $doctrine */
        $doctrine = $config['doctrine'];
        if ($doctrine['table_prefix'] !== '') {
            $container->register(TablePrefixListener::class, TablePrefixListener::class)
                ->setArguments([$doctrine['table_prefix']])
                ->addTag('doctrine.event_listener', ['event' => 'loadClassMetadata']);
        }

        /** @var array{sitemap: bool|string, head: bool|string} $seo */
        $seo          = $config['seo'];
        $seoKit       = $has(self::SEO_KIT_BUNDLE);
        $defaultStyle = $routing['default_locale_style'];
        if ($seoKit && $seo['sitemap'] !== false && interface_exists(SitemapUrlProviderInterface::class)) {
            $container->register(LegalSitemapProvider::class, LegalSitemapProvider::class)
                ->setAutowired(true)
                ->setArgument('$defaultLocaleStyle', $defaultStyle)
                ->addTag('nowo_seo_kit.sitemap_url_provider');
        }
        if ($seoKit && $seo['head'] !== false && class_exists(SeoRuntime::class)) {
            $container->register(SeoKitHeadListener::class, SeoKitHeadListener::class)
                ->setArguments([new Reference(SeoRuntime::class), new Reference(LegalLocales::class)])
                ->addTag('kernel.event_listener');
        }
    }

    /**
     * Returns the configuration alias (`nowo_legal_kit`).
     *
     * @return string The value
     */
    public function getAlias(): string
    {
        return Configuration::ALIAS;
    }

    /**
     * @param array{outbound_url_guard: bool|string, ckeditor5_first_pass: bool, allowed_elements: array<string, list<string>>} $sanitizer
     * @param callable(string): bool $has
     */
    private function configureSanitizer(ContainerBuilder $container, array $sanitizer, callable $has): void
    {
        $definition = $container->getDefinition('nowo_legal_kit.url_policy');
        $guard      = $sanitizer['outbound_url_guard'];
        if ($guard === true || ($guard === 'auto' && $has(self::OUTBOUND_URL_GUARD_BUNDLE) && class_exists(OutboundUrlGuard::class))) {
            $definition->setArgument('$guard', new Reference(OutboundUrlGuard::class));
        }

        $firstPass = $sanitizer['ckeditor5_first_pass'];
        if ($firstPass && class_exists(AllowlistCkeditor5HtmlSanitizer::class)) {
            $container->register('nowo_legal_kit.ckeditor5_strict_sanitizer', AllowlistCkeditor5HtmlSanitizer::class)->setArguments([false]);
            $container->getDefinition('nowo_legal_kit.sanitizer')->setArgument('$firstPass', new Reference('nowo_legal_kit.ckeditor5_strict_sanitizer'));
        }
    }

    private function configureCommands(ContainerBuilder $container, bool $legacyAliases): void
    {
        foreach ([CheckPlaceholdersCommand::class, AdaptSeedCommand::class] as $command) {
            $name = $command::NAME . ($legacyAliases ? '|' . $command::LEGACY_ALIAS : '');
            $container->getDefinition($command)->clearTag('console.command')->addTag('console.command', ['command' => $name]);
        }
    }

    private function editorType(string $type, bool $ckeditorBundle): string
    {
        if ($type !== 'auto') {
            return $type;
        }

        return $ckeditorBundle && class_exists(Ckeditor5EditorType::class) ? 'ckeditor5' : 'textarea';
    }

    /**
     * Legal routes in CookieConsent: no auto-open on legal pages (disabled_routes), render whitelist (when the host
     * uses one) and `legal_privacy` as the modal privacy link fallback (CookieConsent 1.17+ `privacy_route`).
     *
     * @param array<string, mixed> $raw Merged raw nowo_legal_kit config
     */
    private function prependCookieConsent(ContainerBuilder $container, array $raw): void
    {
        $pages = $raw['pages'] ?? null;
        $pages = (is_array($pages) ? $pages : []) + array_fill_keys(Configuration::DEFAULT_PAGES, []);
        $slugs = [];
        foreach ($pages as $slug => $options) {
            if (!is_array($options) || ($options['enabled'] ?? true) !== false) {
                $slugs[] = (string) $slug;
            }
        }
        $routes = [];
        foreach ($slugs as $slug) {
            $routes[] = 'legal_' . $slug;
            $routes[] = 'legal_' . $slug . '_unlocalized';
            $routes[] = 'legal_' . $slug . '_bare';
        }

        $prepend = ['disabled_routes' => $routes];
        if ($this->hostRenderWhitelist($container)) {
            $prepend['render_routes'] = ['legal_*'];
        }
        if (in_array('privacy', $slugs, true) && $this->cookieConsentHasPrivacyRoute() && !$this->hostHasKey($container, 'nowo_cookie_consent', ['privacy_route'])) {
            $prepend['privacy_route'] = 'legal_privacy';
        }
        $container->prependExtensionConfig('nowo_cookie_consent', $prepend);
    }

    private function hostRenderWhitelist(ContainerBuilder $container): bool
    {
        foreach ($container->getExtensionConfig('nowo_cookie_consent') as $config) {
            if (isset($config['render_routes']) && is_array($config['render_routes']) && $config['render_routes'] !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the installed CookieConsentBundle has the `privacy_route` config fallback (1.17+).
     */
    private function cookieConsentHasPrivacyRoute(): bool
    {
        $class = $this->cookieConsentConfiguration;
        if (!is_a($class, ConfigurationInterface::class, true)) {
            return false;
        }
        try {
            $tree = (new $class())->getConfigTreeBuilder()->buildTree();
        } catch (Throwable) {
            return false;
        }

        return $tree instanceof ArrayNode && isset($tree->getChildren()['privacy_route']);
    }

    /**
     * Whether any host config of `$extension` defines the nested key path.
     *
     * @param list<string> $path
     */
    private function hostHasKey(ContainerBuilder $container, string $extension, array $path): bool
    {
        foreach ($container->getExtensionConfig($extension) as $config) {
            $node  = $config;
            $found = true;
            foreach ($path as $key) {
                if (!is_array($node) || !array_key_exists($key, $node)) {
                    $found = false;

                    break;
                }
                $node = $node[$key];
            }
            if ($found) {
                return true;
            }
        }

        return false;
    }

    /**
     * The raw nowo_legal_kit configs merged shallowly (prepend runs before processing; only plain keys are read).
     *
     * @return array<string, mixed>
     */
    private function rawConfig(ContainerBuilder $container): array
    {
        $merged = [];
        foreach ($container->getExtensionConfig(Configuration::ALIAS) as $config) {
            foreach ($config as $key => $value) {
                $merged[$key] = is_array($value) && is_array($merged[$key] ?? null) ? $value + $merged[$key] : $value;
            }
        }

        return $merged;
    }
}
