<?php

declare(strict_types=1);

namespace MauticPlugin\MauticEasyBundle\EventListener;

use Mautic\IntegrationsBundle\Event\ConfigSaveEvent;
use Mautic\IntegrationsBundle\IntegrationEvents;
use MauticPlugin\MauticEasyBundle\Auth\OAuthClient;
use MauticPlugin\MauticEasyBundle\Integration\Config;
use MauticPlugin\MauticEasyBundle\Integration\EasyIntegration;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * "Disconnect" in the plugin settings: revokes the OAuth tokens when the settings are saved.
 */
class OAuthDisconnectSubscriber implements EventSubscriberInterface
{
    public function __construct(private OAuthClient $oauth)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            IntegrationEvents::INTEGRATION_CONFIG_BEFORE_SAVE => ['onConfigBeforeSave', 0],
        ];
    }

    public function onConfigBeforeSave(ConfigSaveEvent $event): void
    {
        if (EasyIntegration::NAME !== $event->getIntegration()) {
            return;
        }

        $configuration = $event->getIntegrationConfiguration();
        $keys          = (array) $configuration->getApiKeys();

        // a form switch, not a setting
        $disconnect = !empty($keys['oauth_disconnect']);
        unset($keys['oauth_disconnect']);

        if ($disconnect) {
            $keys                = $this->oauth->disconnect($keys);
            $keys['auth_method'] = Config::AUTH_API_KEY;
        }

        $configuration->setApiKeys($keys);
    }
}
