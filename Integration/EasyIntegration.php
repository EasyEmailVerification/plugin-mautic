<?php

namespace MauticPlugin\MauticEasyBundle\Integration;

use Mautic\CoreBundle\Form\Type\YesNoButtonGroupType;
use Mautic\PluginBundle\Integration\AbstractIntegration;
use Symfony\Component\Form\Form;
use Symfony\Component\Form\FormBuilder;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class EasyIntegration extends AbstractIntegration
{
    public function getName()
    {
        return 'Easy';
    }

    /**
     * Return's authentication method such as oauth2, oauth1a, key, etc.
     *
     * @return string
     */
    public function getAuthenticationType()
    {
        return 'none';
    }

    /**
     * Return array of key => label elements that will be converted to inputs to
     * obtain from the user.
     *
     * @return array
     */
    public function getRequiredKeyFields()
    {
        // Do not rename field. easy.js depends on it
        return [
            'apikey' => 'mautic.integration.easy.apikey',
        ];
    }

    /**
     * @param FormBuilder|Form $builder
     * @param array            $data
     * @param string           $formArea
     */
    public function appendToForm(&$builder, $data, $formArea)
    {
        if ('features' === $formArea) {
            $builder->add('safe_to_send', YesNoButtonGroupType::class, [
                'label' => 'mautic.integration.easy.safe_to_send',
                'data'  => isset($data['safe_to_send']) ? $data['safe_to_send'] : true,
                'attr'  => [
                    'tooltip' => 'mautic.integration.easy.safe_to_send',
                    'onchange' => "toggleSafeToSend(this.value)"
                ],
            ]);

            $builder->add('result', YesNoButtonGroupType::class, [
                'label' => 'mautic.integration.easy.result',
                'data'  => isset($data['result']) ? $data['result'] : false,
                'attr'  => [
                    'tooltip' => 'mautic.integration.easy.result',
                    'onchange' => "toggleResult(this.value)"
                ],
            ]);
        }
    }

    public function shouldAutoUpdate()
    {
        $featureSettings = $this->getKeys();

        return (isset($featureSettings['auto_update'])) ? (bool) $featureSettings['auto_update'] : false;
    }

    public function getKeys(): ?string
    {
        $keys = $this->getDecryptedApiKeys();
        return $keys['apikey'] ?? null;
    }

    /**
     * {@inheritdoc}
     *
     * @param $section
     *
     * @return string|array
     */
    public function getFormNotes($section)
    {
        if ('custom' === $section) {
            return [
                'template'   => 'MauticEasyBundle:Integration:form.html.php',
                'parameters' => [
                    'mauticUrl' => $this->router->generate(
                        'mautic_plugin_easy_index',
                        [],
                        UrlGeneratorInterface::ABSOLUTE_URL
                    ),
                ],
            ];
        }

        return parent::getFormNotes($section);
    }

    public function saveConfig(array $config)
    {
        // Validate safe_to_send and result
        if (isset($config['safe_to_send']) && isset($config['result']) && $config['safe_to_send'] && $config['result']) {
            // If both are true, set 'result' to false
            $config['result'] = false;
        }

        // Save safe to send checkbox
        if (isset($config['safe_to_send'])) {
            $this->setIntegrationConfig('safe_to_send', $config['safe_to_send']);
        }

        // Save result checkbox
        if (isset($config['result'])) {
            $this->setIntegrationConfig('result', $config['result']);
        }
    }
}
