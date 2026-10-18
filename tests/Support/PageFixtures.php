<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Tests\Support;

use DateTimeImmutable;
use Nowo\FormKitBundle\Form\Constraint\ConstraintDefinitionFactory;
use Nowo\FormKitBundle\Form\FormOptionsMerger;
use Nowo\FormKitBundle\Form\FormTypeMap;
use Nowo\LegalKitBundle\Catalog\LegalPage;
use Nowo\LegalKitBundle\Document\ResolvedLegalDocument;
use Nowo\LegalKitBundle\Entity\LegalDocument;
use Nowo\LegalKitBundle\Form\LegalDocumentType;
use Nowo\UiKitBundle\View\Action;
use Nowo\UiKitBundle\View\AreaNav;
use Nowo\UiKitBundle\View\AreaNavItem;
use Nowo\UiKitBundle\View\Column;
use Nowo\UiKitBundle\View\Confirm;
use Nowo\UiKitBundle\View\EmptyState;
use Nowo\UiKitBundle\View\FormPage;
use Nowo\UiKitBundle\View\Label;
use Nowo\UiKitBundle\View\ListPage;
use Nowo\UiKitBundle\View\PageHeader;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Csrf\CsrfExtension;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\Forms;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Validator\Validation;

/**
 * Template contexts of every bundle page, built like the controllers build them (REQ-UI-003 contract test).
 */
final class PageFixtures
{
    private const string B = 'NowoLegalKitBundle';

    /**
     * @return array<string, mixed>
     */
    public static function index(bool $empty = false): array
    {
        $locales = ['en', 'es'];
        $columns = [new Column('page', self::t('admin.column.page'), required: true)];
        foreach ($locales as $locale) {
            $columns[] = new Column('locale_' . $locale, new Label('admin.column.locale', self::B, ['%locale%' => $locale]));
        }
        $rows = [];
        if (!$empty) {
            foreach (['notice', 'privacy'] as $i => $slug) {
                $rows[$slug] = ['page' => self::t('page.' . $slug), 'slug' => $slug, 'cells' => [
                    'en' => ['custom' => $i === 0, 'updated_at' => $i === 0 ? new DateTimeImmutable('2026-10-01') : null, 'updated_by' => $i === 0 ? 'admin' : null, 'hits' => 0, 'edit_href' => '/admin/legal/' . $slug . '/en'],
                    'es' => ['custom' => false, 'updated_at' => null, 'updated_by' => null, 'hits' => 3, 'edit_href' => '/admin/legal/' . $slug . '/es'],
                ]];
            }
        }

        return [
            'page' => new ListPage(
                new PageHeader(self::t('admin.title'), self::t('admin.intro')),
                $columns,
                $rows,
                empty: new EmptyState(self::t('admin.empty')),
                bundle: self::B,
                attributes: ['data-testid' => 'nowo-legal-kit-admin-index'],
                responsive: 'scroll',
            ),
            'locales' => $locales,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function edit(bool $stored = true): array
    {
        $document = new LegalDocument('privacy', 'es');
        $document->setTitle('Política de privacidad');
        $document->setBody('<h1>Política</h1><p>Texto</p>');
        $params = ['%page%' => 'Privacy policy', '%locale%' => 'es'];
        $forms  = self::forms();

        $restore = $stored ? new Action(
            'restore',
            self::t('admin.reset'),
            '/admin/legal/privacy/es/reset',
            method: 'POST',
            confirm: new Confirm(new Label('admin.reset_confirm_title', self::B, $params), new Label('admin.reset_confirm_message', self::B, $params), self::t('admin.reset_confirm')),
            variant: 'danger',
            form: $forms->createNamedBuilder('nowo_legal_kit_reset', FormType::class, null, ['csrf_token_id' => 'nowo_legal_kit_reset_privacy_es'])->getForm()->createView(),
        ) : null;

        return [
            'page' => new FormPage(
                new PageHeader(
                    new Label('admin.edit_title', self::B, $params),
                    self::t('admin.edit_intro'),
                    tabs: new AreaNav([
                        new AreaNavItem(new Label('admin.tab.locale', self::B, ['%locale%' => 'en']), '/admin/legal/privacy/en'),
                        new AreaNavItem(new Label('admin.tab.locale', self::B, ['%locale%' => 'es']), '/admin/legal/privacy/es', true),
                    ], self::t('admin.tab.label')),
                ),
                $forms->create(LegalDocumentType::class, $document)->createView(),
                submitLabel: self::t('admin.save'),
                cancelHref: '/admin/legal',
                cancelLabel: self::t('admin.back'),
                secondaryActions: [new Action('view', self::t('admin.view_public'), '/es/legal/privacy')],
                deleteAction: $restore,
                bundle: self::B,
                dangerZoneTitle: self::t('admin.reset_zone'),
                dangerZoneIntro: self::t('admin.reset_intro'),
            ),
            'legal_editor' => 'textarea',
            'legal_slug'   => 'privacy',
            'legal_locale' => 'es',
            'is_stored'    => $stored,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function publicPage(bool $stored = false): array
    {
        $document = new ResolvedLegalDocument(
            'privacy',
            'en',
            'Privacy policy',
            '<h1>Privacy policy</h1><p>Text</p>',
            $stored ? ResolvedLegalDocument::SOURCE_STORED : ResolvedLegalDocument::SOURCE_SEED,
            'en',
            $stored ? new DateTimeImmutable('2026-10-02') : null,
        );

        return [
            'page'            => new LegalPage('privacy', 'privacy', true, '@NowoLegalKitBundle/seed/privacy.html.twig'),
            'document'        => $document,
            'alternates'      => ['en' => 'http://localhost/legal/privacy'],
            'layout_template' => null,
            'legal_locale'    => 'en',
        ];
    }

    private static function t(string $key): Label
    {
        return new Label($key, self::B);
    }

    private static function forms(): FormFactoryInterface
    {
        $csrf = new class implements CsrfTokenManagerInterface {
            public function getToken(string $tokenId): CsrfToken
            {
                return new CsrfToken($tokenId, 'token-' . $tokenId);
            }

            public function refreshToken(string $tokenId): CsrfToken
            {
                return $this->getToken($tokenId);
            }

            public function removeToken(string $tokenId): ?string
            {
                return null;
            }

            public function isTokenValid(CsrfToken $token): bool
            {
                return true;
            }
        };
        $profile = ['translation_domain' => self::B, 'auto_help' => false, 'auto_placeholder' => false, 'defaults' => ['attr' => [], 'row_attr' => []], 'field_types' => []];
        $merger  = new FormOptionsMerger(['legal_kit' => $profile, 'default' => $profile], 'legal_kit', new ConstraintDefinitionFactory());

        return Forms::createFormFactoryBuilder()
            ->addExtension(new CsrfExtension($csrf))
            ->addExtension(new ValidatorExtension(Validation::createValidator()))
            ->addType(new LegalDocumentType($merger, new FormTypeMap()))
            ->getFormFactory();
    }
}
