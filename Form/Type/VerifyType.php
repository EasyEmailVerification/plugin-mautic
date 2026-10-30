<?php

declare(strict_types=1);

namespace MauticPlugin\MauticEasyBundle\Form\Type;

use Mautic\CoreBundle\Form\Type\FormButtonsType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormBuilderInterface;

/**
 * Confirmation of "Verify email now" and "Verify selected": the contacts, the CSRF token and the buttons.
 */
class VerifyType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('ids', HiddenType::class);

        $builder->add('buttons', FormButtonsType::class, [
            'apply_text'     => false,
            'save_text'      => 'mautic.integration.easy.verify.submit',
            'cancel_onclick' => 'javascript:void(0);',
            'cancel_attr'    => ['data-dismiss' => 'modal'],
        ]);
    }

    public function getBlockPrefix(): string
    {
        return 'easy_verify';
    }
}
