<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\DependencyInjection;

use InvalidArgumentException;
use Nowo\LegalKitBundle\Catalog\ConfiguredLegalPageCatalog;
use Nowo\LegalKitBundle\Header\LegalPublicHeader;
use Nowo\LegalKitBundle\Sanitizer\DomAllowlistSanitizer;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

use function is_array;
use function is_string;
use function preg_match;
use function sprintf;
use function str_contains;
use function strlen;
use function strtolower;

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
    public const string PATH_PATTERN = '/^[a-z0-9][a-z0-9\/_-]{0,63}$/D';

    /** Seed revision pattern (`seed.revision`, `legal_document.seed_revision` VARCHAR 32, snapshot directory names). */
    public const string REVISION_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,31}$/';

    /** Classes of `public.prose_class` (autoescaped by Twig; no quotes, angle brackets or URL syntax needed). */
    public const string PROSE_CLASS_PATTERN = '/^[A-Za-z0-9_:\/.-]+(?: [A-Za-z0-9_:\/.-]+)*$/D';

    /** Path of the re-acceptance interstitial under `routing.path_prefix` (a page may not use it). */
    public const string ACCEPTANCE_PATH = 'accept';

    /** Re-acceptance modes of a page. */
    public const array ACCEPTANCE_MODES = ['accept', 'acknowledge', 'none'];

    /** `brand.mode` values (spec 220 S5): read `%brand%` from a brand provider when the app has one, or ignore it. */
    public const string BRAND_AUTO = 'auto';

    public const string BRAND_OFF = 'off';

    /** `doctrine.blame` values: AuditKit ManyToOne to the host user (default), a string actor id, or no blame columns. */
    public const string BLAME_RELATION = 'relation';

    public const string BLAME_ACTOR_ID = 'actor_id';

    public const string BLAME_NONE = 'none';

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
                    ->info('Value of the %brand% token in the seed and in stored documents (the fallback when a brand provider applies).')
                    ->defaultValue('')
                ->end()
                ->arrayNode('brand')
                    ->info('Spec 220: take %brand% from a nowo-tech/brand-contracts BrandProviderInterface service when the app has one. "auto" or "off" is accepted as a shorthand for mode.')
                    ->addDefaultsIfNotSet()
                    ->beforeNormalization()
                        ->ifString()
                        ->then(static fn (string $mode): array => ['mode' => $mode])
                    ->end()
                    ->children()
                        ->enumNode('mode')
                            ->info('auto: the provider\'s brand name wins over brand_name (which stays the fallback without a provider or when it fails). off: brand_name only.')
                            ->values([self::BRAND_AUTO, self::BRAND_OFF])
                            ->defaultValue(self::BRAND_AUTO)
                        ->end()
                    ->end()
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
                        ->scalarNode('revision')
                            ->info('Seed revision of the built-in texts (versioning). Null = seed.date. Change it whenever the host changes its seed texts.')
                            ->defaultNull()
                            ->validate()
                                ->ifTrue(static fn (mixed $value): bool => $value !== null && (!is_string($value) || preg_match(self::REVISION_PATTERN, $value) !== 1))
                                ->thenInvalid('nowo_legal_kit.seed.revision must match ' . self::REVISION_PATTERN . ', got %s.')
                            ->end()
                        ->end()
                        ->scalarNode('snapshots_dir')
                            ->info('Directory of the seed snapshots (nowo:legal-kit:snapshot-seed / upgrade-seed).')
                            ->defaultValue('%kernel.project_dir%/config/legal-kit/seed-snapshots')
                            ->cannotBeEmpty()
                        ->end()
                    ->end()
                ->end()
                ->append($this->versioningNode())
                ->append($this->acceptanceNode())
                ->append($this->personalDataNode())
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
                        ->booleanNode('ckeditor5_first_pass')
                            ->info('Run the Ckeditor5EditorBundle strict sanitiser before the DOM allowlist (off by default: the allowlist is stricter, and the CKEditor pass drops the data-nowo-open-consent opener of the seed).')
                            ->defaultFalse()
                        ->end()
                        ->arrayNode('allowed_elements')
                            ->info('Extra elements (name => list of attributes) merged into the built-in allowlist.')
                            ->useAttributeAsKey('name')
                            ->arrayPrototype()
                                ->scalarPrototype()
                                    ->validate()
                                        ->ifTrue(static fn (mixed $value): bool => !is_string($value) || preg_match(DomAllowlistSanitizer::ATTRIBUTE_NAME_PATTERN, strtolower($value)) !== 1)
                                        ->thenInvalid('nowo_legal_kit.sanitizer.allowed_elements attributes must be valid attribute names, got %s.')
                                    ->end()
                                ->end()
                            ->end()
                            ->defaultValue([])
                        ->end()
                        ->arrayNode('heading_anchors')
                            ->info('Anchor names: a heading or section with the class legal-anchor--<anchor> gets id="<anchor>" (first occurrence only). Empty: no id is ever printed.')
                            ->scalarPrototype()
                                ->validate()
                                    ->ifTrue(static fn (mixed $value): bool => !is_string($value) || preg_match(DomAllowlistSanitizer::ANCHOR_PATTERN, $value) !== 1)
                                    ->thenInvalid('nowo_legal_kit.sanitizer.heading_anchors entries must match ' . DomAllowlistSanitizer::ANCHOR_PATTERN . ', got %s.')
                                ->end()
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
                        ->scalarNode('prose_class')
                            ->info('Extra classes of the public article: nowo-legal-doc--typography (the --nowo-legal-doc-* tokens) or the host\'s prose classes. Null = none.')
                            ->defaultNull()
                            ->validate()
                                ->ifTrue(static fn (mixed $value): bool => $value !== null && (!is_string($value) || strlen($value) > 200 || preg_match(self::PROSE_CLASS_PATTERN, $value) !== 1))
                                ->thenInvalid('nowo_legal_kit.public.prose_class must be space separated class names (at most 200 characters), got %s.')
                            ->end()
                        ->end()
                        ->append($this->headerNode())
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
                        ->enumNode('blame')
                            ->info('Blame columns of legal_document / legal_page_version: "relation" = AuditKit ManyToOne to the host user (created_by_id / updated_by_id, needs resolve_target_entities); "actor_id" = nullable string columns created_by / updated_by filled from LegalActorIdResolverInterface (no foreign key, no marker interface); "none" = no blame columns.')
                            ->values([self::BLAME_RELATION, self::BLAME_ACTOR_ID, self::BLAME_NONE])
                            ->defaultValue(self::BLAME_RELATION)
                        ->end()
                        ->scalarNode('entity_manager')
                            ->info('1.10: Doctrine entity manager of the bundle entities (null = the one autowired as EntityManagerInterface, mapping prepended as before). A literal name maps the entities on that manager only (removed from the others, auto_mapping included), wires every bundle service to it and fails the build when it does not exist.')
                            ->defaultNull()
                            ->validate()
                                ->ifTrue(static fn (mixed $value): bool => $value !== null && (!is_string($value) || preg_match('/^[A-Za-z0-9_]+$/', $value) !== 1))
                                ->thenInvalid('nowo_legal_kit.doctrine.entity_manager must be null or a literal entity manager name (letters, digits, "_"), got %s.')
                            ->end()
                        ->end()
                        ->arrayNode('accepted_user_classes')
                            ->info('doctrine.blame: actor_id only. Security-user classes whose id the default actor resolver records; actors of other classes are recorded as unknown (null). Empty (default): any actor, which suits one identity domain only (Admin#5 and Customer#5 would both be "5").')
                            ->scalarPrototype()->cannotBeEmpty()->end()
                            ->defaultValue([])
                        ->end()
                    ->end()
                ->end()
            ->end()
            ->validate()
                ->always(static function (array $config): array {
                    self::validateAcceptance($config);

                    return $config;
                })
            ->end();

        return $treeBuilder;
    }

    /**
     * Cross-key rules of versioning / acceptance (default-deny: an incomplete setup fails the container build).
     *
     * @param array<string, mixed> $config Processed root configuration
     *
     * @return void Nothing (throws on an invalid combination)
     */
    public static function validateAcceptance(array $config): void
    {
        /** @var array{enabled: bool} $versioning */
        $versioning = $config['versioning'];
        /** @var array{enabled: bool, enforce: bool, subject_resolver: string|null} $acceptance */
        $acceptance = $config['acceptance'];
        if ($acceptance['enforce'] && !$acceptance['enabled']) {
            throw new InvalidArgumentException('nowo_legal_kit.acceptance.enforce requires acceptance.enabled: true.');
        }
        if (!$acceptance['enabled']) {
            return;
        }
        if (!$versioning['enabled']) {
            throw new InvalidArgumentException('nowo_legal_kit.acceptance.enabled requires versioning.enabled: true (acceptances are recorded per page version).');
        }
        if ($acceptance['subject_resolver'] === null || $acceptance['subject_resolver'] === '') {
            throw new InvalidArgumentException('nowo_legal_kit.acceptance.enabled requires acceptance.subject_resolver: the id of a service implementing LegalAcceptanceSubjectResolverInterface (a stable, non-personal user id; the bundle never guesses one).');
        }
        /** @var array<string, array{path: string}> $pages */
        $pages = $config['pages'];
        foreach ($pages as $slug => $page) {
            if ($page['path'] === self::ACCEPTANCE_PATH) {
                throw new InvalidArgumentException(sprintf('nowo_legal_kit.pages.%s.path "%s" is reserved for the re-acceptance page while acceptance.enabled is true.', $slug, self::ACCEPTANCE_PATH));
            }
        }
    }

    /**
     * `public.header` (MUX-150, 1.7): the optional brand / home / sign-in header of the public pages. Off by default.
     *
     * @return ArrayNodeDefinition The node
     */
    private function headerNode(): ArrayNodeDefinition
    {
        $node = new ArrayNodeDefinition('header');
        $node
            ->info('MUX-150: header of the public pages with the brand (name, or the brand provider\'s light logo) linked home and a sign-in link. true / false is accepted as a shorthand for enabled.')
            ->canBeEnabled()
            ->children()
                ->booleanNode('logo')
                    ->info('Print the brand provider\'s light logo (an escaped <img src>, alt = the brand name) instead of the name when it has one.')
                    ->defaultTrue()
                ->end()
                ->scalarNode('home')
                    ->info('Target of the brand link: a route name, a root-relative path or an http(s) URL. Null = the brand is not a link.')
                    ->defaultValue('/')
                    ->validate()
                        ->ifTrue(static fn (mixed $value): bool => $value !== null && (!is_string($value) || !LegalPublicHeader::isValidTarget($value)))
                        ->thenInvalid('nowo_legal_kit.public.header.home must be a route name, a root-relative path or an http(s) URL, got %s.')
                    ->end()
                ->end()
                ->scalarNode('sign_in')
                    ->info('Target of the sign-in link (hidden for a signed-in user): a route name, a root-relative path or an http(s) URL. Null = no link.')
                    ->defaultNull()
                    ->validate()
                        ->ifTrue(static fn (mixed $value): bool => $value !== null && (!is_string($value) || !LegalPublicHeader::isValidTarget($value)))
                        ->thenInvalid('nowo_legal_kit.public.header.sign_in must be a route name, a root-relative path or an http(s) URL, got %s.')
                    ->end()
                ->end()
            ->end();

        return $node;
    }

    /**
     * `versioning`: seed tracking, page versions, the outdated notice and the publication choice (opt-in).
     */
    private function versioningNode(): ArrayNodeDefinition
    {
        $node = (new TreeBuilder('versioning'))->getRootNode();
        $node
            ->info('Seed tracking, page versions, the outdated notice and the minor / new version choice (opt-in; maps two legal_document columns and legal_page_version).')
            ->addDefaultsIfNotSet()
            ->children()
                ->booleanNode('enabled')->defaultFalse()->end()
                ->scalarNode('publisher_checker')
                    ->info('Service id implementing LegalVersionPublisherCheckerInterface; null = the admin access checker decides.')
                    ->defaultNull()
                ->end()
            ->end();

        return $node;
    }

    /**
     * `acceptance`: acceptance records, the re-acceptance page and the request gate (opt-in).
     */
    private function acceptanceNode(): ArrayNodeDefinition
    {
        $node = (new TreeBuilder('acceptance'))->getRootNode();
        $node
            ->info('Acceptance records, the re-acceptance page and the request gate (opt-in; requires versioning; maps legal_acceptance).')
            ->addDefaultsIfNotSet()
            ->children()
                ->booleanNode('enabled')->defaultFalse()->end()
                ->booleanNode('enforce')
                    ->info('Redirect signed-in HTML navigations to the re-acceptance page while something is pending.')
                    ->defaultFalse()
                ->end()
                ->scalarNode('subject_resolver')
                    ->info('Service id implementing LegalAcceptanceSubjectResolverInterface (required when enabled).')
                    ->defaultNull()
                ->end()
                ->arrayNode('exempt_routes')
                    ->info('Route names never redirected: exact names or "prefix*".')
                    ->scalarPrototype()
                        ->validate()
                            ->ifTrue(static fn (mixed $value): bool => !is_string($value) || preg_match('/^[A-Za-z0-9_.:-]+\*?$/', $value) !== 1)
                            ->thenInvalid('nowo_legal_kit.acceptance.exempt_routes entries are route names or "prefix*", got %s.')
                        ->end()
                    ->end()
                    ->defaultValue([])
                ->end()
                ->arrayNode('exempt_paths')
                    ->info('Path prefixes never redirected, matched on segment boundaries ("/api" matches /api and /api/…, not /apix).')
                    ->scalarPrototype()
                        ->validate()
                            ->ifTrue(static fn (mixed $value): bool => !is_string($value) || preg_match('#^/[A-Za-z0-9._~/-]*$#', $value) !== 1 || str_contains($value, '..') || str_contains($value, '//'))
                            ->thenInvalid('nowo_legal_kit.acceptance.exempt_paths entries must start with "/" and contain no wildcard, "..", "?" or "#", got %s.')
                        ->end()
                    ->end()
                    ->defaultValue([])
                ->end()
                ->enumNode('non_html')
                    ->info('pass: JSON / XHR / Turbo requests are never refused; deny: session requests get 403 JSON legal_acceptance_required.')
                    ->values(['pass', 'deny'])
                    ->defaultValue('pass')
                ->end()
                ->scalarNode('default_target')
                    ->info('Route name used after accepting when there is no stored target; null = "/".')
                    ->defaultNull()
                ->end()
                ->scalarNode('logout_route')
                    ->info('Route name of the sign-out link; null = the current firewall logout path (SecurityBundle), none without one.')
                    ->defaultNull()
                ->end()
                ->scalarNode('layout_template')
                    ->info('Layout of the re-acceptance page; null = UiKit nowo_ui_layout.guest.')
                    ->defaultNull()
                ->end()
                ->scalarNode('template')
                    ->defaultValue('@NowoLegalKitBundle/acceptance/interstitial.html.twig')
                    ->cannotBeEmpty()
                ->end()
                ->scalarNode('cache_pool')
                    ->info('Cache pool service of the version requirements map; null = no cache (one query per check).')
                    ->defaultValue('cache.app')
                ->end()
                ->booleanNode('action_log')
                    ->info('Write an AuditKit action log row legal_document.accepted per acceptance (off: the host audits LegalAcceptedEvent itself).')
                    ->defaultFalse()
                ->end()
            ->end();

        return $node;
    }

    /**
     * GDPR erasure / export of the acceptance records through UserKit (1.11).
     */
    private function personalDataNode(): ArrayNodeDefinition
    {
        $node = (new TreeBuilder('personal_data'))->getRootNode();
        $node
            ->info('GDPR erasure and export of the acceptance records through UserKit, opt-in (needs acceptance.enabled and nowo-tech/user-kit-bundle installed).')
            ->addDefaultsIfNotSet()
            ->children()
                ->booleanNode('enabled')
                    ->info('Opt in: register the UserKit eraser and export section "legal_acceptances". Off by default: acceptance records may have to be kept as proof (GDPR Art. 17(3)).')
                    ->defaultFalse()
                ->end()
                ->scalarNode('subject_resolver')
                    ->info('Service id implementing LegalPersonalDataSubjectResolverInterface; null = the id of acceptance.subject_resolver for the user.')
                    ->defaultNull()
                ->end()
            ->end();

        return $node;
    }

    /**
     * The ordered page catalogue (`pages`): slug => {path, enabled, nav, seed_template, acceptance}.
     */
    private function pagesNode(): ArrayNodeDefinition
    {
        $node     = (new TreeBuilder('pages'))->getRootNode();
        $defaults = [];
        foreach (self::DEFAULT_PAGES as $slug) {
            $defaults[$slug] = ['path' => $slug, 'enabled' => true, 'nav' => true, 'seed_template' => null, 'acceptance' => null];
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
                    ->enumNode('acceptance')
                        ->info('Re-acceptance mode (acceptance.enabled): accept (terms), acknowledge (information), none. Null = accept for terms, acknowledge for privacy, none otherwise.')
                        ->values([...self::ACCEPTANCE_MODES, null])
                        ->defaultNull()
                    ->end()
                ->end()
            ->end()
            ->validate()
                ->always(static function (array $pages): array {
                    // The four canonical pages always exist (disable one with enabled: false); extra slugs follow them.
                    $merged = [];
                    foreach (self::DEFAULT_PAGES as $default) {
                        $merged[$default] = ($pages[$default] ?? []) + ['path' => $default, 'enabled' => true, 'nav' => true, 'seed_template' => null, 'acceptance' => null];
                        unset($pages[$default]);
                    }
                    $pages = $merged + $pages;
                    foreach ($pages as $slug => $page) {
                        $slug = (string) $slug;
                        if (preg_match(self::SLUG_PATTERN, $slug) !== 1) {
                            throw new InvalidArgumentException(sprintf('nowo_legal_kit.pages: invalid slug "%s" (lowercase letters, digits and "-", at most 32 characters).', $slug));
                        }
                        $path = $page['path'] ?? $slug;
                        if (!is_string($path) || preg_match(self::PATH_PATTERN, $path) !== 1) {
                            throw new InvalidArgumentException(sprintf('nowo_legal_kit.pages.%s.path: invalid path "%s".', $slug, is_string($path) ? $path : get_debug_type($path)));
                        }
                        $pages[$slug]['path'] = $path;
                        $acceptance           = $page['acceptance'] ?? null;
                        $acceptance ??= ConfiguredLegalPageCatalog::defaultAcceptance($slug);
                        if ($slug === 'privacy' && $acceptance === 'accept') {
                            throw new InvalidArgumentException('nowo_legal_kit.pages.privacy.acceptance cannot be "accept": privacy information is acknowledged, never accepted or consented to (use "acknowledge" or "none").');
                        }
                        $pages[$slug]['acceptance'] = $acceptance;
                    }

                    return $pages;
                })
            ->end();

        return $node;
    }
}
