<?php

declare(strict_types=1);

namespace MauticPlugin\MauticEasyBundle\Form\Type;

use Mautic\CoreBundle\Form\Type\YesNoButtonGroupType;
use MauticPlugin\MauticEasyBundle\Integration\Config;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;

/**
 * "Features" tab: which API field decides the condition (one choice instead of the two yes/no switches of 1.x, which
 * only made sense one at a time) and when contacts are verified as they are saved.
 */
class ConfigFeaturesType extends AbstractType
{
    public function __construct(private Config $config)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        // Until saved again, settings of 1.x show as the mode they select
        $decision = $this->config->getDecision();
        $current  = $decision['result'] && !$decision['safe_to_send'] ? Config::DECIDE_RESULT : Config::DECIDE_SAFE_TO_SEND;

        $builder->add('decision', ChoiceType::class, [
            'label'       => 'mautic.integration.easy.decision',
            'label_attr'  => ['class' => 'control-label'],
            'choices'     => [
                'mautic.integration.easy.safe_to_send' => Config::DECIDE_SAFE_TO_SEND,
                'mautic.integration.easy.result'       => Config::DECIDE_RESULT,
            ],
            'expanded'    => true,
            'multiple'    => false,
            'required'    => true,
            'placeholder' => false,
            'data'        => $current,
        ]);

        $builder->add('verify_on_save', YesNoButtonGroupType::class, [
            'label' => 'mautic.integration.easy.verify_on_save',
            'data'  => $this->config->verifyOnSave(),
            'attr'  => ['tooltip' => 'mautic.integration.easy.verify_on_save.tooltip'],
        ]);

        $builder->add('verify_other_sources', YesNoButtonGroupType::class, [
            'label' => 'mautic.integration.easy.verify_other_sources',
            'data'  => $this->config->verifyOtherSources(),
            'attr'  => ['tooltip' => 'mautic.integration.easy.verify_other_sources.tooltip'],
        ]);
    }
}
