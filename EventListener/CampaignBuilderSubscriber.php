<?php

namespace MauticPlugin\MauticEasyBundle\EventListener;

use Mautic\CampaignBundle\CampaignEvents;
use Mautic\CampaignBundle\Event\CampaignBuilderEvent;
use MauticPlugin\MauticEasyBundle\Conditions\CheckEmailVerificationCondition;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class CampaignBuilderSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents()
    {
        return [
            CampaignEvents::CAMPAIGN_ON_BUILD => ['onCampaignBuild', 0],
        ];
    }

    /**
     * @return void
     */
    public function onCampaignBuild(CampaignBuilderEvent $event)
    {
        $event->addCondition(
            'email_verification', // The key of the condition
            [
                'label' => 'mautic.easybundle.condition.email_verification', // The label of the condition
                'description' => 'mautic.easybundle.condition.email_verification.descr', // Description of the condition
                // Own event name: Mautic also dispatches the generic ON_EVENT_CONDITION_EVALUATION for every
                // condition of every campaign, so listening to it would change the result of other conditions.
                'eventName' => CheckEmailVerificationCondition::ON_EVALUATE,
            ]
        );
    }
}
