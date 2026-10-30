<?php

declare(strict_types=1);

namespace MauticPlugin\MauticEasyBundle\Tests\Unit\EventListener;

use Mautic\CampaignBundle\CampaignEvents;
use Mautic\CampaignBundle\Event\CampaignBuilderEvent;
use MauticPlugin\MauticEasyBundle\Conditions\CheckEmailVerificationCondition;
use MauticPlugin\MauticEasyBundle\EventListener\CampaignBuilderSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

class CampaignBuilderSubscriberTest extends TestCase
{
    public function testRegistersTheConditionWithItsOwnEventName(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        $event = new CampaignBuilderEvent($translator);
        (new CampaignBuilderSubscriber())->onCampaignBuild($event);

        $condition = $event->getConditions()['email_verification'];
        $this->assertSame(CheckEmailVerificationCondition::ON_EVALUATE, $condition['eventName']);
        $this->assertSame('mautic.easybundle.condition.email_verification', $condition['label']);
        $this->assertSame('mautic.easybundle.condition.email_verification.descr', $condition['description']);
    }

    public function testListensToTheCampaignBuild(): void
    {
        $this->assertSame([CampaignEvents::CAMPAIGN_ON_BUILD => ['onCampaignBuild', 0]], CampaignBuilderSubscriber::getSubscribedEvents());
    }
}
