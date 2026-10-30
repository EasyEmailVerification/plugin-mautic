<?php

declare(strict_types=1);

namespace MauticPlugin\MauticEasyBundle\Tests\Unit\EventListener;

use Mautic\IntegrationsBundle\Event\ConfigSaveEvent;
use Mautic\PluginBundle\Entity\Integration;
use MauticPlugin\MauticEasyBundle\Auth\OAuthClient;
use MauticPlugin\MauticEasyBundle\EventListener\OAuthDisconnectSubscriber;
use PHPUnit\Framework\TestCase;

class OAuthDisconnectSubscriberTest extends TestCase
{
    public function testDisconnectRevokesAndSwitchesBackToTheApiKey(): void
    {
        $oauth = $this->createMock(OAuthClient::class);
        $oauth->expects($this->once())->method('disconnect')
            ->with(['auth_method' => 'oauth', 'oauth_refresh_token' => 'eev_rt_1', 'apikey' => 'k'])
            ->willReturn(['apikey' => 'k']);

        $settings = $this->save($oauth, ['auth_method' => 'oauth', 'oauth_refresh_token' => 'eev_rt_1', 'apikey' => 'k', 'oauth_disconnect' => '1']);

        $this->assertSame(['apikey' => 'k', 'auth_method' => 'apikey'], $settings->getApiKeys());
    }

    public function testSwitchLeftOnNoKeepsTheConnection(): void
    {
        $oauth = $this->createMock(OAuthClient::class);
        $oauth->expects($this->never())->method('disconnect');

        $settings = $this->save($oauth, ['auth_method' => 'oauth', 'oauth_refresh_token' => 'eev_rt_1', 'oauth_disconnect' => '0']);

        $this->assertSame(['auth_method' => 'oauth', 'oauth_refresh_token' => 'eev_rt_1'], $settings->getApiKeys());
    }

    public function testOtherIntegrationsAreLeftAlone(): void
    {
        $oauth = $this->createMock(OAuthClient::class);
        $oauth->expects($this->never())->method('disconnect');

        $settings = new Integration();
        $settings->setName('Other');
        $settings->setApiKeys(['oauth_disconnect' => '1']);
        (new OAuthDisconnectSubscriber($oauth))->onConfigBeforeSave(new ConfigSaveEvent($settings));

        $this->assertSame(['oauth_disconnect' => '1'], $settings->getApiKeys());
    }

    /**
     * @param array<string, string> $keys
     */
    private function save(OAuthClient $oauth, array $keys): Integration
    {
        $settings = new Integration();
        $settings->setName('Easy');
        $settings->setApiKeys($keys);
        (new OAuthDisconnectSubscriber($oauth))->onConfigBeforeSave(new ConfigSaveEvent($settings));

        return $settings;
    }
}
