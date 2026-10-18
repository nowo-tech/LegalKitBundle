<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Controller\Admin;

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
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

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
    ) {
    }

    /**
     * Matrix of every page × enabled locale.
     *
     * @return Response The result
     */
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
                    'custom'     => $stored !== null,
                    'updated_at' => $stored['updated_at'] ?? null,
                    'updated_by' => $stored['updated_by'] ?? null,
                    'hits'       => $hits[$page->slug][$locale] ?? 0,
                    'edit_href'  => $this->urls->generate(LegalRouteLoader::ADMIN_EDIT, ['slug' => $page->slug, 'locale' => $locale]),
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
    public function edit(Request $request, string $slug, string $locale): Response
    {
        $legalPage = $this->legalPage($slug, $locale);
        $document  = $this->documents->findOneBySlugAndLocale($slug, $locale);
        $isStored  = $document instanceof LegalDocument;
        if (!$document instanceof LegalDocument) {
            $document = new LegalDocument($slug, $locale);
            $seed     = $this->resolver->seedForEditor($slug, $locale);
            if ($seed instanceof LegalSeed) {
                $document->setTitle($seed->title);
                $document->setBody($seed->body);
            }
        }

        $form = $this->forms->create(LegalDocumentType::class, $document, [
            'editor'            => $this->editor,
            'ckeditor5_profile' => $this->ckeditor5Profile,
        ]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $document->setBody($this->sanitizer->sanitize($document->getBody()));
            if (!LegalDocumentResolver::hasText($document->getBody())) {
                $this->flash($request, 'error', 'flash.empty_body');

                return $this->redirectToEdit($slug, $locale);
            }
            $this->documents->save($document);
            $this->scanner->reset();
            $this->dispatcher->dispatch(new LegalDocumentSavedEvent($slug, $locale));
            $this->flash($request, 'success', 'flash.saved');

            return $this->redirectToEdit($slug, $locale);
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
            'page'         => $page,
            'legal_editor' => $this->editor,
            'legal_slug'   => $slug,
            'legal_locale' => $locale,
            'is_stored'    => $isStored,
        ]), $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK);

        return $this->noStore($response);
    }

    /**
     * Deletes the operator copy (POST + CSRF): the seed is published again.
     *
     * @return RedirectResponse The result
     */
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
