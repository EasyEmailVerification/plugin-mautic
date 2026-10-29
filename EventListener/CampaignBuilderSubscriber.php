<?php

namespace MauticPlugin\MauticEasyBundle\EventListener;

use Mautic\CampaignBundle\CampaignEvents;
use Mautic\CampaignBundle\Event\CampaignBuilderEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use MauticPlugin\MauticEasyBundle\Integration\EasyIntegration;

class CampaignBuilderSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents()
    {
        return [
            CampaignEvents::CAMPAIGN_ON_BUILD => ['onCampaignBuild', 0],
        ];
    }

    public function onCampaignBuild(CampaignBuilderEvent $event)
    {
        $event->addCondition(
            'email_verification', // The key of the condition
            [
                'label' => 'mautic.easybundle.condition.email_verification', // The label of the condition
                'description' => 'Checks if the email is verified', // Description of the condition
                'eventName' => CampaignEvents::ON_EVENT_CONDITION_EVALUATION,
            ]
        );
    }
}
