<?php

namespace MauticPlugin\MauticEasyBundle\Integration;

use Mautic\CoreBundle\Form\Type\YesNoButtonGroupType;
use Mautic\PluginBundle\Integration\AbstractIntegration;
use Symfony\Component\Form\Form;
use Symfony\Component\Form\FormBuilder;

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

    /**
     * Not named getKeys(): that is AbstractIntegration's method returning all the decrypted keys as an array.
     */
    public function getApiKey(): ?string
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
                'parameters' => [],
            ];
        }

        return parent::getFormNotes($section);
    }
}
