<?php

declare(strict_types=1);

namespace App\Admin\Form;

use App\Entity\VpnPanel;
use App\Provisioning\Domain\SanaeiIntegrationMode;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Step 1 of the panel setup: name, URL, API token; proxy, TLS pin, forced mode and subscription settings are the
 * collapsed "advanced" part. No username/password: modern panels need only the token (old panels keep the classic
 * form).
 */
final class PanelConnectionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, ['label' => 'panel_connection.form.title', 'constraints' => [new NotBlank(), new Length(max: 255)]])
            ->add('baseUrl', TextType::class, [
                'label' => 'panel_connection.form.url',
                'help' => 'panel_connection.form.url_help',
                'constraints' => [new NotBlank(), new Length(max: 255)],
                'attr' => ['dir' => 'ltr', 'placeholder' => 'https://panel.example.com:2053/secret-path/'],
            ])
            ->add('plainApiToken', PasswordType::class, [
                'label' => 'panel_connection.form.token',
                'help' => 'panel_connection.form.token_help',
                'required' => false,
                'always_empty' => true,
                'attr' => ['dir' => 'ltr', 'autocomplete' => 'off'],
            ])
            ->add('useProxy', CheckboxType::class, ['label' => 'panel_connection.form.use_proxy', 'help' => 'panel_connection.form.use_proxy_help', 'required' => false])
            ->add('tlsPin', TextType::class, [
                'label' => 'panel_connection.form.tls_pin',
                'help' => 'panel_connection.form.tls_pin_help',
                'mapped' => false,
                'required' => false,
                'data' => $options['data'] instanceof VpnPanel ? $options['data']->getTlsPinSha256() : null,
                'attr' => ['dir' => 'ltr', 'placeholder' => 'sha256//…='],
            ])
            ->add('integrationModeValue', ChoiceType::class, [
                'label' => 'panel_connection.integration_mode',
                'help' => 'panel_connection.integration_mode_help',
                'choices' => [
                    'panel_connection.mode.auto' => SanaeiIntegrationMode::AUTO->value,
                    'panel_connection.mode.modern' => SanaeiIntegrationMode::MODERN->value,
                    'panel_connection.mode.legacy' => SanaeiIntegrationMode::LEGACY->value,
                ],
            ])
            ->add('subscriptionBaseUrl', TextType::class, ['label' => 'panel_connection.form.subscription_base_url', 'help' => 'panel_connection.form.subscription_base_url_help', 'required' => false, 'attr' => ['dir' => 'ltr']])
            ->add('subscriptionPathPrefix', TextType::class, ['label' => 'panel_connection.form.subscription_path_prefix', 'required' => false, 'attr' => ['dir' => 'ltr', 'placeholder' => '/sub']]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => VpnPanel::class,
            'translation_domain' => 'admin',
            'csrf_token_id' => 'panel_connection',
        ]);
    }
}
