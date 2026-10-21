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
 * The one panel form (create and edit): name, URL, API token, active; proxy, TLS pin, forced mode, subscription
 * settings and the fallback public host are the collapsed "advanced" part. The old username/password API settings
 * are added only for a panel that is on the legacy API ("legacy_credentials" option).
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
                'attr' => ['dir' => 'ltr', 'placeholder' => 'panel_connection.placeholder.url'],
            ])
            ->add('plainApiToken', PasswordType::class, [
                'label' => 'panel_connection.form.token',
                'help' => 'panel_connection.form.token_help',
                'required' => false,
                'always_empty' => true,
                'attr' => ['dir' => 'ltr', 'autocomplete' => 'off'],
            ])
            ->add('isActive', CheckboxType::class, ['label' => 'panel_connection.form.active', 'help' => 'panel_connection.form.active_help', 'required' => false])
            ->add('useProxy', CheckboxType::class, ['label' => 'panel_connection.form.use_proxy', 'help' => 'panel_connection.form.use_proxy_help', 'required' => false])
            ->add('tlsPin', TextType::class, [
                'label' => 'panel_connection.form.tls_pin',
                'help' => 'panel_connection.form.tls_pin_help',
                'mapped' => false,
                'required' => false,
                'data' => $options['data'] instanceof VpnPanel ? $options['data']->getTlsPinSha256() : null,
                'attr' => ['dir' => 'ltr', 'placeholder' => 'panel_connection.placeholder.tls_pin'],
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
            ->add('subscriptionPathPrefix', TextType::class, ['label' => 'panel_connection.form.subscription_path_prefix', 'required' => false, 'attr' => ['dir' => 'ltr', 'placeholder' => 'panel_connection.placeholder.sub_path']])
            ->add('publicHost', TextType::class, ['label' => 'panel_connection.form.public_host', 'help' => 'panel_connection.form.public_host_help', 'required' => false, 'attr' => ['dir' => 'ltr']]);

        if (true === $options['legacy_credentials']) {
            $builder
                ->add('username', TextType::class, ['label' => 'panel_connection.form.username', 'required' => false, 'attr' => ['dir' => 'ltr', 'autocomplete' => 'off']])
                ->add('plainPassword', PasswordType::class, [
                    'label' => 'panel_connection.form.password',
                    'help' => 'panel_connection.form.password_help',
                    'required' => false,
                    'always_empty' => true,
                    'attr' => ['dir' => 'ltr', 'autocomplete' => 'off'],
                ])
                ->add('apiVersion', ChoiceType::class, ['label' => 'panel_connection.form.api_version', 'choices' => ['legacy' => 'legacy', 'v3' => 'v3'], 'choice_translation_domain' => false])
                ->add('authMode', ChoiceType::class, ['label' => 'panel_connection.form.auth_mode', 'choices' => ['cookie' => 'cookie', 'bearer' => 'bearer'], 'choice_translation_domain' => false])
                ->add('clientApiMode', ChoiceType::class, ['label' => 'panel_connection.form.client_api_mode', 'choices' => ['inbound_legacy' => 'inbound_legacy', 'clients' => 'clients'], 'choice_translation_domain' => false])
                ->add('basePath', TextType::class, ['label' => 'panel_connection.form.base_path', 'required' => false, 'attr' => ['dir' => 'ltr', 'placeholder' => '/xui']]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => VpnPanel::class,
            'translation_domain' => 'admin',
            'csrf_token_id' => 'panel_connection',
            'legacy_credentials' => false,
        ]);
        $resolver->setAllowedTypes('legacy_credentials', 'bool');
    }
}
