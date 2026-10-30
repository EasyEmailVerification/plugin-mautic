<?php

declare(strict_types=1);

namespace MauticPlugin\MauticEasyBundle\Integration;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\IntegrationsBundle\Exception\IntegrationNotFoundException;
use Mautic\IntegrationsBundle\Facade\EncryptionService;
use Mautic\IntegrationsBundle\Helper\IntegrationsHelper;
use Mautic\PluginBundle\Entity\Integration;

/**
 * The plugin settings: authentication (API keys, encrypted by Mautic) and the decision mode (feature settings).
 */
class Config
{
    public const AUTH_API_KEY = 'apikey';
    public const AUTH_OAUTH   = 'oauth';

    public const DECIDE_SAFE_TO_SEND = 'safe_to_send';
    public const DECIDE_RESULT       = 'result';

    public function __construct(
        private IntegrationsHelper $integrationsHelper,
        private EntityManagerInterface $entityManager,
        private EncryptionService $encryption
    ) {
    }

    /**
     * The saved settings, with decrypted keys; null when the plugin is not installed.
     */
    public function getIntegrationConfiguration(): ?Integration
    {
        try {
            return $this->integrationsHelper->getIntegration(EasyIntegration::NAME)->getIntegrationConfiguration();
        } catch (IntegrationNotFoundException $e) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function getApiKeys(): array
    {
        $configuration = $this->getIntegrationConfiguration();

        return $configuration ? (array) $configuration->getApiKeys() : [];
    }

    /**
     * @param array<string, mixed> $keys
     */
    public function saveApiKeys(array $keys): void
    {
        $configuration = $this->getIntegrationConfiguration();
        if (!$configuration) {
            throw new \RuntimeException('Easy Email Verification is not installed.');
        }

        $configuration->setApiKeys($keys);
        $this->integrationsHelper->saveIntegrationConfiguration($configuration);
    }

    /**
     * Reads the keys again from the database: another process (cron, worker, web) may have refreshed the OAuth tokens.
     *
     * @return array<string, mixed>
     */
    public function reloadApiKeys(): array
    {
        $configuration = $this->getIntegrationConfiguration();
        if (!$configuration) {
            return [];
        }

        $this->entityManager->refresh($configuration);
        $keys = (array) $this->encryption->decrypt((array) $configuration->getApiKeys());
        $configuration->setApiKeys($keys);

        return $keys;
    }

    public function getAuthMethod(): string
    {
        return self::AUTH_OAUTH === ($this->getApiKeys()['auth_method'] ?? null) ? self::AUTH_OAUTH : self::AUTH_API_KEY;
    }

    public function getApiKey(): ?string
    {
        $key = $this->getApiKeys()['apikey'] ?? null;

        return is_string($key) && '' !== $key ? $key : null;
    }

    public function isPublished(): bool
    {
        $configuration = $this->getIntegrationConfiguration();

        return $configuration && $configuration->getIsPublished();
    }

    /**
     * Verify a contact saved in Mautic's contact form when it has no result yet or its email changed (default on).
     */
    public function verifyOnSave(): bool
    {
        return (bool) ($this->getFeatureSettings()['verify_on_save'] ?? true);
    }

    /**
     * Also verify contacts saved by imports, the API, forms, integrations and other automatic sources (default off:
     * each contact uses one credit).
     */
    public function verifyOtherSources(): bool
    {
        return (bool) ($this->getFeatureSettings()['verify_other_sources'] ?? false);
    }

    /**
     * @return array<string, mixed> the settings of the Features tab
     */
    private function getFeatureSettings(): array
    {
        $configuration = $this->getIntegrationConfiguration();
        $settings      = $configuration ? (array) $configuration->getFeatureSettings() : [];

        return is_array($settings['integration'] ?? null) ? $settings['integration'] : [];
    }

    /**
     * Which API fields decide the condition. Settings saved by 1.x (two yes/no switches at the top level of the
     * feature settings) are read as they are; nothing saved yet means "Safe To Send", the default of the form.
     *
     * @return array{safe_to_send: bool, result: bool}
     */
    public function getDecision(): array
    {
        $configuration = $this->getIntegrationConfiguration();
        $settings      = $configuration ? (array) $configuration->getFeatureSettings() : [];

        $decision = $settings['integration']['decision'] ?? null;
        if (self::DECIDE_RESULT === $decision) {
            return ['safe_to_send' => false, 'result' => true];
        }
        if (self::DECIDE_SAFE_TO_SEND === $decision) {
            return ['safe_to_send' => true, 'result' => false];
        }

        return [
            'safe_to_send' => (bool) ($settings['safe_to_send'] ?? true),
            'result'       => (bool) ($settings['result'] ?? false),
        ];
    }
}
