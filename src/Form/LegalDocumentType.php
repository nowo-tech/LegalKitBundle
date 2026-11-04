<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Form;

use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormKitAbstractType;
use Nowo\LegalKitBundle\Catalog\LegalPage;
use Nowo\LegalKitBundle\Controller\Admin\LegalDocumentAdminController;
use Nowo\LegalKitBundle\Entity\LegalDocument;
use Nowo\LegalKitBundle\Versioning\Entity\LegalPageVersion;
use Override;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Title + body of one legal page in one locale (FormKit profile `legal_kit`). The body is a CKEditor 5 field
 * (`editor: ckeditor5`) or a plain textarea (`editor: textarea`); the bundle sanitises it before saving.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
#[FormKitConfig(self::PROFILE)]
final class LegalDocumentType extends FormKitAbstractType
{
    public const string PROFILE = 'legal_kit';

    public const string DOMAIN = 'NowoLegalKitBundle';

    /**
     * @param FormBuilderInterface<mixed> $builder
     * @param array<string, mixed> $options
     *
     * @return void Nothing (side effects only)
     */
    #[Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $this->withBuilder($builder, function () use ($builder, $options): void {
            $this->addTextField('title', [
                'label'       => 'admin.field.title',
                'empty_data'  => '',
                'help'        => false,
                'placeholder' => false,
                'constraints' => [new NotBlank(), new Length(max: LegalDocument::TITLE_MAX_LENGTH)],
                'attr'        => ['maxlength' => LegalDocument::TITLE_MAX_LENGTH],
            ]);

            $body = [
                'label'       => 'admin.field.body',
                'help'        => 'admin.field.body_help',
                'placeholder' => false,
                'required'    => false,
                'empty_data'  => '',
                'constraints' => [new Length(max: LegalDocument::BODY_MAX_LENGTH)],
            ];
            if ($options['editor'] === 'ckeditor5') {
                $this->addCkeditor5EditorField('body', $body + ['config' => $options['ckeditor5_profile']]);
            } else {
                $this->addTextareaField('body', $body + ['attr' => ['rows' => 24]]);
            }

            /** @var array{mode: string, can_publish: bool, summary: string, has_summary: bool, base_version: int, base_updated: string}|null $publication */
            $publication = $options['publication'];
            if ($publication !== null) {
                $this->addPublicationFields($builder, $publication);
            }
        });
    }

    /**
     * Versioning fields (unmapped): the minor / new version choice, re-acceptance, the change summary and the
     * optimistic-lock base.
     *
     * @param FormBuilderInterface<mixed> $builder The editor form builder
     * @param array{mode: string, can_publish: bool, summary: string, has_summary: bool, base_version: int, base_updated: string} $publication
     */
    private function addPublicationFields(FormBuilderInterface $builder, array $publication): void
    {
        $accept = $publication['mode'] !== LegalPage::ACCEPTANCE_NONE;
        if ($publication['can_publish']) {
            $this->addChoiceField('publication', [
                'label'       => 'admin.publication.label',
                'help'        => 'admin.publication.help',
                'placeholder' => false,
                'mapped'      => false,
                'expanded'    => true,
                'multiple'    => false,
                'required'    => true,
                'choices'     => [
                    'admin.publication.minor'       => LegalDocumentAdminController::PUBLICATION_MINOR,
                    'admin.publication.new_version' => LegalDocumentAdminController::PUBLICATION_NEW,
                ],
                // No preselection where users accept / acknowledge: the choice must be deliberate.
                'data'        => $accept ? null : LegalDocumentAdminController::PUBLICATION_MINOR,
                'constraints' => [new NotBlank(message: 'admin.publication.required')],
            ]);
            if ($accept) {
                $this->addCheckboxField('requires_acceptance', [
                    'label'       => 'admin.publication.requires_acceptance_' . $publication['mode'],
                    'help'        => false,
                    'placeholder' => false,
                    'mapped'      => false,
                    'required'    => false,
                    'data'        => true,
                ]);
            }
        }
        $this->addTextareaField('change_summary', [
            'label'       => 'admin.publication.summary',
            'help'        => 'admin.publication.summary_help',
            'placeholder' => false,
            'mapped'      => false,
            'required'    => false,
            'empty_data'  => '',
            'data'        => $publication['summary'],
            'constraints' => [new Length(max: LegalPageVersion::SUMMARY_MAX_LENGTH)],
            'attr'        => ['rows' => 4, 'maxlength' => LegalPageVersion::SUMMARY_MAX_LENGTH],
        ]);
        $builder->add('base_version', HiddenType::class, ['mapped' => false, 'data' => (string) $publication['base_version']]);
        $builder->add('base_updated', HiddenType::class, ['mapped' => false, 'data' => $publication['base_updated'], 'empty_data' => '']);
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'         => LegalDocument::class,
            'translation_domain' => self::DOMAIN,
            'editor'             => 'textarea',
            'ckeditor5_profile'  => 'legal',
            'publication'        => null,
        ]);
        $resolver->setAllowedTypes('publication', ['null', 'array']);
        $resolver->setAllowedValues('editor', ['ckeditor5', 'textarea']);
        $resolver->setAllowedTypes('ckeditor5_profile', 'string');
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function getBlockPrefix(): string
    {
        return 'nowo_legal_kit_document';
    }
}
