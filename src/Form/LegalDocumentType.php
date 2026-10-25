<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Form;

use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormKitAbstractType;
use Nowo\LegalKitBundle\Entity\LegalDocument;
use Override;
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
        $this->withBuilder($builder, function () use ($options): void {
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
        });
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
        ]);
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
