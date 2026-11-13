<?php

declare(strict_types=1);

namespace App\Form;

use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * REQ-DEMO-015: a FormKit form (profile `demo`) with CSRF. Demo login (database user admin / admin). Empty block
 * prefix keeps {@code _username} / {@code _password} / {@code _csrf_token} flat for {@code form_login}.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 *
 * @extends AbstractType<null>
 */
#[FormKitConfig('demo')]
final class LoginType extends AbstractType
{
    use FormOptionsTrait;

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $this->addText($builder, '_username', [
            'label' => 'demo.login.username',
            'data' => $options['last_username'],
            'placeholder' => false,
            'help' => false,
            'attr' => ['autocomplete' => 'username'],
        ]);
        $this->addPassword($builder, '_password', [
            'label' => 'demo.login.password',
            'data' => 'admin',
            'always_empty' => false,
            'placeholder' => false,
            'help' => false,
            'attr' => ['autocomplete' => 'current-password'],
        ]);
        $this->addWithDefaults($builder, '_target_path', HiddenType::class, [
            'data' => $options['target_path'],
        ]);
        $this->addWithDefaults($builder, 'submit', SubmitType::class, [
            'label' => 'demo.login.submit',
            'attr' => ['class' => 'nowo-ui-btn nowo-ui-btn--primary'],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_protection' => true,
            'csrf_field_name' => '_csrf_token',
            'csrf_token_id' => 'authenticate',
            'last_username' => 'admin',
            'target_path' => '/',
        ]);
        $resolver->setAllowedTypes('last_username', 'string');
        $resolver->setAllowedTypes('target_path', 'string');
    }

    public function getBlockPrefix(): string
    {
        return '';
    }
}
