<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Controller\Admin;

use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;
use Nowo\LegalKitBundle\Catalog\LegalPage;
use Nowo\LegalKitBundle\Catalog\LegalPageCatalogInterface;
use Nowo\LegalKitBundle\Document\LegalDocumentResolver;
use Nowo\LegalKitBundle\Entity\LegalDocument;
use Nowo\LegalKitBundle\Event\LegalDocumentRestoredEvent;
use Nowo\LegalKitBundle\Event\LegalDocumentSavedEvent;
use Nowo\LegalKitBundle\Form\LegalDocumentType;
use Nowo\LegalKitBundle\Locale\LegalLocales;
use Nowo\LegalKitBundle\Placeholder\LegalPlaceholderScanner;
use Nowo\LegalKitBundle\Repository\LegalDocumentRepository;
use Nowo\LegalKitBundle\Routing\LegalRouteLoader;
use Nowo\LegalKitBundle\Sanitizer\LegalHtmlSanitizerInterface;
use Nowo\LegalKitBundle\Seed\LegalSeed;
use Nowo\LegalKitBundle\Versioning\Entity\LegalPageVersion;
use Nowo\LegalKitBundle\Versioning\LegalPageVersions;
use Nowo\LegalKitBundle\Versioning\LegalSeedTracker;
use Nowo\LegalKitBundle\Versioning\LegalVersionPublisherCheckerInterface;
use Nowo\UiKitBundle\View\Action;
use Nowo\UiKitBundle\View\AreaNav;
use Nowo\UiKitBundle\View\AreaNavItem;
use Nowo\UiKitBundle\View\Column;
use Nowo\UiKitBundle\View\Confirm;
use Nowo\UiKitBundle\View\Crumb;
use Nowo\UiKitBundle\View\EmptyState;
use Nowo\UiKitBundle\View\FormPage;
use Nowo\UiKitBundle\View\Label;
use Nowo\UiKitBundle\View\ListPage;
use Nowo\UiKitBundle\View\PageHeader;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

use function is_string;
use function preg_replace;
use function str_replace;
use function trim;

/**
 * Admin of the legal pages (REQ-UI-001/002/003): index matrix (page × locale, Built-in / Custom, last editor,
 * placeholder hits) on the UiKit list scaffold, editor on the form scaffold with one tab per locale, and restore
 * through the UiKit confirm contract (POST + CSRF). Access is enforced by AdminAccessSubscriber.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class LegalDocumentAdminController
{
    public const string BUNDLE = 'NowoLegalKitBundle';

    public const string RESET_CSRF_PREFIX = 'nowo_legal_kit_reset_';

    /** Publication choice: same version, no re-acceptance. */
    public const string PUBLICATION_MINOR = 'minor';

    /** Publication choice: version + 1. */
    public const string PUBLICATION_NEW = 'new_version';

    /** `web_ui.path_prefix`, resolved by the router from the container parameter. */
    public const string PATH_PREFIX = '/%nowo_legal_kit.web_ui.route_path%';

    /** Enabled slugs and locales (container parameters computed from the configuration). */
    public const array REQUIREMENTS = ['slug' => '%nowo_legal_kit.slug_requirement%', 'locale' => '%nowo_legal_kit.locale_requirement%'];

    /**
     * @param LegalPageCatalogInterface $catalog Enabled pages
     * @param LegalLocales $locales Enabled locales (default first)
     * @param LegalDocumentRepository $documents Stored rows
     * @param LegalDocumentResolver $resolver Seed for the editor
     * @param LegalHtmlSanitizerInterface $sanitizer Sanitiser run before saving
     * @param LegalPlaceholderScanner $scanner Placeholder hits
     * @param FormFactoryInterface $forms Form factory
     * @param Environment $twig Twig
     * @param UrlGeneratorInterface $urls URL generator
     * @param TranslatorInterface $translator Flash messages
     * @param EventDispatcherInterface $dispatcher Saved / restored events
     * @param array{index: string, edit: string} $templates `web_ui.templates`
     * @param string|null $layoutTemplate `web_ui.layout_template`
     * @param string $editor Resolved body widget (ckeditor5|textarea)
     * @param string $ckeditor5Profile `editor.ckeditor5_profile`
     * @param string $defaultLocaleStyle `routing.default_locale_style`
     * @param ?LegalSeedTracker $seedTracker Seed base (versioning on)
     * @param ?LegalPageVersions $versions Page versions (versioning on)
     * @param ?LegalVersionPublisherCheckerInterface $publisherChecker Who may choose minor / new version (versioning on)
     * @param ?EntityManagerInterface $entityManager Transaction of a save + version (versioning on)
     */
    public function __construct(
        private LegalPageCatalogInterface $catalog,
        private LegalLocales $locales,
        private LegalDocumentRepository $documents,
        private LegalDocumentResolver $resolver,
        private LegalHtmlSanitizerInterface $sanitizer,
        private LegalPlaceholderScanner $scanner,
        private FormFactoryInterface $forms,
        private Environment $twig,
        private UrlGeneratorInterface $urls,
        private TranslatorInterface $translator,
        private EventDispatcherInterface $dispatcher,
        private array $templates,
        private ?string $layoutTemplate,
        private string $editor,
        private string $ckeditor5Profile,
        private string $defaultLocaleStyle = 'unprefixed',
        private ?LegalSeedTracker $seedTracker = null,
        private ?LegalPageVersions $versions = null,
        private ?LegalVersionPublisherCheckerInterface $publisherChecker = null,
        private ?EntityManagerInterface $entityManager = null,
    ) {
    }

    /**
     * Matrix of every page × enabled locale.
     *
     * @return Response The result
     */
    #[Route(self::PATH_PREFIX, name: LegalRouteLoader::ADMIN_INDEX, methods: ['GET', 'HEAD'])]
    public function index(): Response
    {
        $custom = $this->documents->customMap();
        $hits   = [];
        foreach ($this->scanner->findHits() as $hit) {
            $hits[$hit->slug][$hit->locale] = ($hits[$hit->slug][$hit->locale] ?? 0) + 1;
        }

        $columns = [new Column('page', $this->t('admin.column.page'), required: true)];
        foreach ($this->locales->all() as $locale) {
            $columns[] = new Column('locale_' . $locale, new Label('admin.column.locale', self::BUNDLE, ['%locale%' => $locale]));
        }

        $rows = [];
        foreach ($this->catalog->pages() as $page) {
            $cells = [];
            foreach ($this->locales->all() as $locale) {
                $stored         = $custom[$page->slug][$locale] ?? null;
                $cells[$locale] = [
                    'custom'       => $stored !== null,
                    'updated_at'   => $stored['updated_at'] ?? null,
                    'updated_by'   => $stored['updated_by'] ?? null,
                    'hits'         => $hits[$page->slug][$locale] ?? 0,
                    'edit_href'    => $this->urls->generate(LegalRouteLoader::ADMIN_EDIT, ['slug' => $page->slug, 'locale' => $locale]),
                    'outdated'     => $this->seedTracker instanceof LegalSeedTracker && ($stored['seed_revision'] ?? null) !== null && $stored['seed_revision'] !== $this->seedTracker->revision(),
                    'compare_href' => $this->seedTracker instanceof LegalSeedTracker ? $this->urls->generate(LegalRouteLoader::ADMIN_COMPARE, ['slug' => $page->slug, 'locale' => $locale]) : null,
                ];
            }
            $rows[$page->slug] = ['page' => $this->t($page->labelKey()), 'slug' => $page->slug, 'cells' => $cells];
        }

        $page = new ListPage(
            new PageHeader($this->t('admin.title'), $this->t('admin.intro')),
            $columns,
            $rows,
            empty: new EmptyState($this->t('admin.empty')),
            bundle: self::BUNDLE,
            layout: $this->layoutTemplate,
            attributes: ['data-testid' => 'nowo-legal-kit-admin-index'],
            responsive: 'scroll',
        );

        return $this->noStore(new Response($this->twig->render($this->templates['index'], [
            'page'    => $page,
            'locales' => $this->locales->all(),
        ])));
    }

    /**
     * Editor of one page in one locale (prefilled from the seed, tokens kept, when no row exists).
     *
     * @return Response The result
     */
    #[Route(self::PATH_PREFIX . '/{slug}/{locale}', name: LegalRouteLoader::ADMIN_EDIT, requirements: self::REQUIREMENTS, methods: ['GET', 'HEAD', 'POST'])]
    public function edit(Request $request, string $slug, string $locale): Response
    {
        $legalPage  = $this->legalPage($slug, $locale);
        $document   = $this->documents->findOneBySlugAndLocale($slug, $locale);
        $isStored   = $document instanceof LegalDocument;
        $versioning = $this->versioning();
        $fromSeed   = $versioning !== null && $request->query->getBoolean('from_seed');
        if (!$document instanceof LegalDocument || $fromSeed) {
            $document ??= new LegalDocument($slug, $locale);
            $seed = $this->resolver->seedForEditor($slug, $locale);
            if ($seed instanceof LegalSeed) {
                $document->setTitle($seed->title);
                $document->setBody($seed->body);
            }
        }

        $publication = null;
        if ($versioning !== null) {
            [$tracker, $versions] = $versioning;
            $current              = $versions->currentUncached($slug);
            $latest               = $versions->latest($slug);
            $publication          = [
                'mode'         => $legalPage->acceptance,
                'can_publish'  => $this->publisherChecker?->canPublish($slug) ?? true,
                'summary'      => $latest instanceof LegalPageVersion && $latest->getVersion() === $current ? ($latest->summaryFor($locale) ?? '') : '',
                'has_summary'  => $latest instanceof LegalPageVersion && $latest->getVersion() === $current,
                'base_version' => $current,
                'base_updated' => $this->stamp($isStored ? $document : null),
            ];
        }

        $form = $this->forms->create(LegalDocumentType::class, $document, [
            'editor'            => $this->editor,
            'ckeditor5_profile' => $this->ckeditor5Profile,
            'publication'       => $publication,
        ]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $document->setBody($this->sanitizer->sanitize($document->getBody()));
            if (!LegalDocumentResolver::hasText($document->getBody())) {
                $this->flash($request, 'error', 'flash.empty_body');

                return $this->redirectToEdit($slug, $locale);
            }
            if ($versioning === null || $publication === null) {
                $this->documents->save($document);
                $this->scanner->reset();
                $this->dispatcher->dispatch(new LegalDocumentSavedEvent($slug, $locale));
                $this->flash($request, 'success', 'flash.saved');

                return $this->redirectToEdit($slug, $locale);
            }
            $saved = $this->saveVersioned($request, $form, $legalPage, $document, $versioning, $publication, $fromSeed || !$isStored);
            if ($saved instanceof Response) {
                return $saved;
            }
        }

        $tabs = [];
        foreach ($this->locales->all() as $tabLocale) {
            $tabs[] = new AreaNavItem(
                new Label('admin.tab.locale', self::BUNDLE, ['%locale%' => $tabLocale]),
                $this->urls->generate(LegalRouteLoader::ADMIN_EDIT, ['slug' => $slug, 'locale' => $tabLocale]),
                $tabLocale === $locale,
            );
        }
        $pageLabel = $this->translator->trans($legalPage->labelKey(), [], self::BUNDLE);
        $params    = ['%page%' => $pageLabel, '%locale%' => $locale];

        $restore = null;
        if ($isStored) {
            $restore = new Action(
                'restore',
                $this->t('admin.reset'),
                $this->urls->generate(LegalRouteLoader::ADMIN_RESET, ['slug' => $slug, 'locale' => $locale]),
                method: 'POST',
                confirm: new Confirm(
                    new Label('admin.reset_confirm_title', self::BUNDLE, $params),
                    new Label('admin.reset_confirm_message', self::BUNDLE, $params),
                    $this->t('admin.reset_confirm'),
                ),
                variant: 'danger',
                form: $this->resetForm($slug, $locale)->createView(),
            );
        }

        $page = new FormPage(
            new PageHeader(
                new Label('admin.edit_title', self::BUNDLE, $params),
                $this->t('admin.edit_intro'),
                breadcrumbs: [
                    new Crumb($this->t('admin.title'), $this->urls->generate(LegalRouteLoader::ADMIN_INDEX)),
                    new Crumb(new Label('admin.edit_title', self::BUNDLE, $params)),
                ],
                tabs: new AreaNav($tabs, $this->t('admin.tab.label')),
            ),
            $form->createView(),
            submitLabel: $this->t('admin.save'),
            cancelHref: $this->urls->generate(LegalRouteLoader::ADMIN_INDEX),
            cancelLabel: $this->t('admin.back'),
            secondaryActions: [new Action('view', $this->t('admin.view_public'), $this->publicUrl($legalPage, $locale))],
            deleteAction: $restore,
            bundle: self::BUNDLE,
            layout: $this->layoutTemplate,
            attributes: ['data-testid' => 'nowo-legal-kit-admin-edit'],
            dangerZoneTitle: $this->t('admin.reset_zone'),
            dangerZoneIntro: $this->t('admin.reset_intro'),
        );

        $response = new Response($this->twig->render($this->templates['edit'], [
            'page'             => $page,
            'legal_editor'     => $this->editor,
            'legal_slug'       => $slug,
            'legal_locale'     => $locale,
            'is_stored'        => $isStored,
            'legal_versioning' => $versioning !== null ? $this->versionInfo($versioning[1], $slug) : null,
        ]), $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK);

        return $this->noStore($response);
    }

    /**
     * Deletes the operator copy (POST + CSRF): the seed is published again.
     *
     * @return RedirectResponse The result
     */
    #[Route(self::PATH_PREFIX . '/{slug}/{locale}/reset', name: LegalRouteLoader::ADMIN_RESET, requirements: self::REQUIREMENTS, methods: ['POST'])]
    public function reset(Request $request, string $slug, string $locale): RedirectResponse
    {
        $this->legalPage($slug, $locale);
        $form = $this->resetForm($slug, $locale);
        $form->handleRequest($request);
        if (!$form->isSubmitted() || !$form->isValid()) {
            $this->flash($request, 'error', 'flash.invalid_csrf');

            return $this->redirectToEdit($slug, $locale);
        }

        $document = $this->documents->findOneBySlugAndLocale($slug, $locale);
        if ($document instanceof LegalDocument) {
            // @igor-ignore - Repository call; the Doctrine unit of work is request-scoped
            $this->documents->remove($document);
            $this->scanner->reset();
            $this->dispatcher->dispatch(new LegalDocumentRestoredEvent($slug, $locale));
        }
        $this->flash($request, 'success', 'flash.reset_done');

        return $this->redirectToEdit($slug, $locale);
    }

    /**
     * Save with versioning: optimistic guard, publisher check, summary rule, then the row (+ a new version) in one
     * transaction. Returns the response to send, or null when the form gained an error (re-render with 422).
     *
     * @param FormInterface<mixed> $form The submitted editor form
     * @param array{0: LegalSeedTracker, 1: LegalPageVersions, 2: EntityManagerInterface} $versioning
     * @param array{mode: string, can_publish: bool, summary: string, has_summary: bool, base_version: int, base_updated: string} $publication
     */
    private function saveVersioned(Request $request, FormInterface $form, LegalPage $page, LegalDocument $document, array $versioning, array $publication, bool $rebase): ?Response
    {
        [$tracker, $versions, $entityManager] = $versioning;
        $slug                                 = $page->slug;
        $locale                               = $document->getLocale();
        $canPublish                           = $publication['can_publish'];
        if (!$canPublish && $page->requiresAcceptance()) {
            throw new AccessDeniedHttpException('Saving this legal page publishes a text users accept: the publisher check refused it.');
        }
        if ((string) $form->get('base_version')->getData() !== (string) $versions->currentUncached($slug)
            || (string) $form->get('base_updated')->getData() !== $publication['base_updated']) {
            $this->flash($request, 'error', 'flash.changed_meanwhile');

            return $this->redirectToEdit($slug, $locale);
        }

        $choice   = $canPublish && $form->has('publication') ? (string) $form->get('publication')->getData() : self::PUBLICATION_MINOR;
        $requires = $choice === self::PUBLICATION_NEW && $page->requiresAcceptance() && $form->has('requires_acceptance') && $form->get('requires_acceptance')->getData() === true;
        $summary  = self::plainText($form->get('change_summary')->getData());
        if ($requires && $summary === '') {
            $form->get('change_summary')->addError(new FormError($this->translator->trans('admin.publication.summary_required', [], self::BUNDLE)));

            return null;
        }

        $version = $entityManager->wrapInTransaction(function () use ($tracker, $versions, $document, $slug, $locale, $choice, $requires, $summary, $publication, $rebase): int {
            if ($rebase) {
                $tracker->rebase($document);
            }
            $this->documents->save($document);
            if ($choice === self::PUBLICATION_NEW) {
                return $versions->publish($slug, $requires, LegalPageVersion::SOURCE_EDITOR, $summary !== '' ? [$locale => $summary] : [])->getVersion();
            }
            $latest = $versions->latest($slug);
            if ($publication['has_summary'] && $latest instanceof LegalPageVersion && ($latest->summaryFor($locale) ?? '') !== $summary) {
                // @igor-ignore - Doctrine entity loaded for this request (the unit of work is reset between requests)
                $latest->setSummary($locale, $summary);
                $this->documents->save($document);
            }

            return $publication['base_version'];
        });

        $this->scanner->reset();
        $this->dispatcher->dispatch(new LegalDocumentSavedEvent($slug, $locale, $choice, $version));
        if ($choice === self::PUBLICATION_NEW) {
            $this->flashMessage($request, 'success', $this->translator->trans('flash.version_published', ['%version%' => $version], self::BUNDLE));
        } else {
            $this->flash($request, 'success', 'flash.saved');
        }

        return $this->redirectToEdit($slug, $locale);
    }

    /**
     * Version facts of the editor aside: current version, publication time, per-locale "updated since".
     *
     * @return array{current: int, published_at: ?DateTimeInterface, locales: array<string, ?bool>}
     */
    private function versionInfo(LegalPageVersions $versions, string $slug): array
    {
        $latest      = $versions->latest($slug);
        $publishedAt = $latest?->getPublishedAt();
        $stored      = $this->documents->findByLocales($this->locales->all());
        $locales     = [];
        foreach ($this->locales->all() as $locale) {
            $row              = $stored[LegalDocumentRepository::key($slug, $locale)] ?? null;
            $updated          = $row?->getUpdatedAt() ?? $row?->getCreatedAt();
            $locales[$locale] = !$publishedAt instanceof DateTimeImmutable || $updated === null ? null : $updated >= $publishedAt;
        }

        return ['current' => $latest?->getVersion() ?? 1, 'published_at' => $publishedAt, 'locales' => $locales];
    }

    /**
     * The versioning services when versioning is on.
     *
     * @return ?array{0: LegalSeedTracker, 1: LegalPageVersions, 2: EntityManagerInterface}
     */
    private function versioning(): ?array
    {
        if (!$this->seedTracker instanceof LegalSeedTracker || !$this->versions instanceof LegalPageVersions || !$this->entityManager instanceof EntityManagerInterface) {
            return null;
        }

        return [$this->seedTracker, $this->versions, $this->entityManager];
    }

    /**
     * Optimistic-lock stamp of a stored row (second precision, as stored).
     */
    private function stamp(?LegalDocument $document): string
    {
        $time = $document?->getUpdatedAt() ?? $document?->getCreatedAt();

        return $time instanceof DateTimeInterface ? $time->format('Y-m-d H:i:s') : '';
    }

    /**
     * Plain text of a change summary: control characters (except new lines) become spaces, then trimmed.
     */
    public static function plainText(mixed $value): string
    {
        if (!is_string($value)) {
            return '';
        }
        $value = str_replace(["\r\n", "\r"], "\n", $value);

        return trim(preg_replace('/[^\P{Cc}\n]+/u', ' ', $value) ?? '');
    }

    /**
     * @return FormInterface<mixed>
     */
    private function resetForm(string $slug, string $locale): FormInterface
    {
        return $this->forms->createNamedBuilder('nowo_legal_kit_reset', FormType::class, null, [
            'csrf_token_id' => self::RESET_CSRF_PREFIX . $slug . '_' . $locale,
            'action'        => $this->urls->generate(LegalRouteLoader::ADMIN_RESET, ['slug' => $slug, 'locale' => $locale]),
            'method'        => 'POST',
        ])->getForm();
    }

    private function legalPage(string $slug, string $locale): LegalPage
    {
        if (!$this->catalog->has($slug) || !$this->locales->has($locale)) {
            throw new NotFoundHttpException('Unknown legal page or locale.');
        }

        return $this->catalog->page($slug);
    }

    private function publicUrl(LegalPage $page, string $locale): string
    {
        if ($this->defaultLocaleStyle === 'unprefixed' && $locale === $this->locales->defaultLocale()) {
            return $this->urls->generate($page->unlocalizedRouteName());
        }

        return $this->urls->generate($page->routeName(), ['_locale' => $locale]);
    }

    private function redirectToEdit(string $slug, string $locale): RedirectResponse
    {
        return $this->noStore(new RedirectResponse($this->urls->generate(LegalRouteLoader::ADMIN_EDIT, ['slug' => $slug, 'locale' => $locale])));
    }

    private function flashMessage(Request $request, string $type, string $message): void
    {
        $session = $request->hasSession() ? $request->getSession() : null;
        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add($type, $message);
        }
    }

    private function flash(Request $request, string $type, string $key): void
    {
        $session = $request->hasSession() ? $request->getSession() : null;
        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add($type, $this->translator->trans($key, [], self::BUNDLE));
        }
    }

    /**
     * @template T of Response
     *
     * @param T $response
     *
     * @return T
     */
    private function noStore(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    private function t(string $key): Label
    {
        return new Label($key, self::BUNDLE);
    }
}
