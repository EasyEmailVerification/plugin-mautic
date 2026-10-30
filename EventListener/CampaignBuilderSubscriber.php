<?php

declare(strict_types=1);

namespace MauticPlugin\MauticEasyBundle\EventListener;

use Mautic\CampaignBundle\CampaignEvents;
use Mautic\CampaignBundle\Event\CampaignBuilderEvent;
use MauticPlugin\MauticEasyBundle\Conditions\CheckEmailVerificationCondition;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class CampaignBuilderSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            CampaignEvents::CAMPAIGN_ON_BUILD => ['onCampaignBuild', 0],
        ];
    }

    public function onCampaignBuild(CampaignBuilderEvent $event): void
    {
        $event->addCondition(
            'email_verification',
            [
                'label'       => 'mautic.easybundle.condition.email_verification',
                'description' => 'mautic.easybundle.condition.email_verification.descr',
                // Own event name: Mautic also dispatches the generic ON_EVENT_CONDITION_EVALUATION for every
                // condition of every campaign, so listening to it would change the result of other conditions.
                'eventName'   => CheckEmailVerificationCondition::ON_EVALUATE,
            ]
        );
    }
}
