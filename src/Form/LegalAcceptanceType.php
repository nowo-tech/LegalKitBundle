<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Form;

use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormKitAbstractType;
use Nowo\LegalKitBundle\Acceptance\PendingLegalPage;
use Override;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\IsTrue;

/**
 * Re-acceptance form: one required, unticked checkbox per page to accept (terms), nothing for pages that are only
 * acknowledged (privacy), and the offer fingerprint (pages, versions, text hashes) the server compares on submit.
 * CSRF token id `nowo_legal_kit_accept`.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
#[FormKitConfig(LegalDocumentType::PROFILE)]
final class LegalAcceptanceType extends FormKitAbstractType
{
    /** CSRF token id. */
    public const string CSRF_TOKEN_ID = 'nowo_legal_kit_accept';

    /** Field name prefix of an accept checkbox. */
    public const string ACCEPT_PREFIX = 'accept_';

    /**
     * {@inheritDoc}
     *
     * @param array{pages: list<PendingLegalPage>, offer: string} $options
     */
    #[Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $this->withBuilder($builder, function () use ($builder, $options): void {
            foreach ($options['pages'] as $pending) {
                if (!$pending->isAccept()) {
                    continue;
                }
                $this->addCheckboxField(self::ACCEPT_PREFIX . $pending->page->slug, [
                    'label'                        => 'acceptance.accept_label',
                    'label_translation_parameters' => ['%title%' => $pending->title, '%version%' => $pending->currentVersion],
                    'required'                     => true,
                    'help'                         => false,
                    'placeholder'                  => false,
                    'data'                         => false,
                    'constraints'                  => [new IsTrue(message: 'acceptance.accept_required')],
                ]);
            }
            $builder->add('offer', HiddenType::class, ['data' => $options['offer']]);
        });
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'translation_domain' => LegalDocumentType::DOMAIN,
            'csrf_token_id'      => self::CSRF_TOKEN_ID,
            'pages'              => [],
            'offer'              => '',
        ]);
        $resolver->setAllowedTypes('pages', 'array');
        $resolver->setAllowedTypes('offer', 'string');
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function getBlockPrefix(): string
    {
        return 'nowo_legal_kit_acceptance';
    }
}
