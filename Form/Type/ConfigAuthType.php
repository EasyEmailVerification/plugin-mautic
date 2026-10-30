<?php

declare(strict_types=1);

namespace MauticPlugin\MauticEasyBundle\Form\Type;

use Mautic\CoreBundle\Form\Type\YesNoButtonGroupType;
use Mautic\IntegrationsBundle\Integration\Interfaces\IntegrationInterface;
use MauticPlugin\MauticEasyBundle\Auth\OAuthClient;
use MauticPlugin\MauticEasyBundle\Integration\Config;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * "Details" tab: how the plugin authenticates. The OAuth tokens are stored with these keys but are not fields of the
 * form, so saving the form keeps them.
 */
class ConfigAuthType extends AbstractType
{
    public function __construct(private OAuthClient $oauth)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var IntegrationInterface $integration */
        $integration = $options['integration'];
        $keys        = $integration->hasIntegrationConfiguration() ? (array) $integration->getIntegrationConfiguration()->getApiKeys() : [];

        $builder->add('auth_method', ChoiceType::class, [
            'label'       => 'mautic.integration.easy.auth_method',
            'label_attr'  => ['class' => 'control-label'],
            'choices'     => [
                'mautic.integration.easy.auth_method.apikey' => Config::AUTH_API_KEY,
                'mautic.integration.easy.auth_method.oauth'  => Config::AUTH_OAUTH,
            ],
            'expanded'    => true,
            'multiple'    => false,
            'required'    => true,
            'placeholder' => false,
            'data'        => $keys['auth_method'] ?? Config::AUTH_API_KEY,
        ]);

        // Never sent back to the browser; left blank, the saved key is kept
        $builder->add('apikey', PasswordType::class, [
            'label'      => 'mautic.integration.easy.apikey',
            'label_attr' => ['class' => 'control-label'],
            'required'   => false,
            'attr'       => [
                'class'        => 'form-control',
                'autocomplete' => 'off',
                'placeholder'  => empty($keys['apikey']) ? '' : 'mautic.integration.easy.apikey.saved',
                'tooltip'      => 'mautic.integration.easy.apikey.tooltip',
            ],
        ]);

        if ($this->oauth->isConnected($keys)) {
            $builder->add('oauth_disconnect', YesNoButtonGroupType::class, [
                'label' => 'mautic.integration.easy.oauth_disconnect',
                'data'  => false,
                'attr'  => ['tooltip' => 'mautic.integration.easy.oauth_disconnect.tooltip'],
            ]);
        }

        $builder->addEventListener(FormEvents::PRE_SUBMIT, function (FormEvent $event) use ($keys): void {
            $data = $event->getData();
            if (is_array($data) && '' === trim((string) ($data['apikey'] ?? '')) && !empty($keys['apikey'])) {
                $data['apikey'] = $keys['apikey'];
                $event->setData($data);
            }
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired(['integration']);
    }
}
