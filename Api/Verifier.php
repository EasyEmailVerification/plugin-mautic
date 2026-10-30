<?php

declare(strict_types=1);

namespace MauticPlugin\MauticEasyBundle\Api;

use MauticPlugin\MauticEasyBundle\Auth\OAuthClient;
use MauticPlugin\MauticEasyBundle\Integration\Config;
use Psr\Log\LoggerInterface;

/**
 * Sends a verification with the authentication chosen in the plugin settings: the API key or the OAuth connection.
 * Both share the same answer checks; every failure is logged and returns null.
 */
class Verifier
{
    private ?string $lastError = null;

    private bool $unauthorized = false;

    public function __construct(
        private Config $config,
        private VerificationClient $client,
        private OAuthClient $oauth,
        private LoggerInterface $logger
    ) {
    }

    /**
     * Whether a credential is saved, without logging (for checks that run on every contact save).
     */
    public function isConfigured(): bool
    {
        return Config::AUTH_OAUTH === $this->config->getAuthMethod()
            ? $this->oauth->isConnected($this->config->getApiKeys())
            : null !== $this->config->getApiKey();
    }

    /**
     * Whether a credential is saved; logs what is missing.
     */
    public function hasCredentials(): bool
    {
        if ($this->isConfigured()) {
            return true;
        }

        $this->logger->error('Easy Email Verification: '.$this->missingCredential());

        return false;
    }

    /**
     * @return array<string, mixed>|null the API answer, or null when there is none
     */
    public function verify(string $email, int $timeout = VerificationClient::TIMEOUT): ?array
    {
        $this->lastError    = null;
        $this->unauthorized = false;

        if (Config::AUTH_API_KEY === $this->config->getAuthMethod()) {
            $apiKey = $this->config->getApiKey();
            if (!$apiKey) {
                $this->lastError    = $this->missingCredential();
                $this->unauthorized = true;

                return null;
            }

            return $this->result($this->client->verify($email, $apiKey, $timeout));
        }

        $token = $this->oauth->getAccessToken();
        if (null === $token) {
            $this->lastError    = $this->missingCredential();
            $this->unauthorized = true;

            return null;
        }

        $data = $this->client->verifyWithAccessToken($email, $token, $rejected, $timeout);
        if ($rejected) {
            $this->logger->error('Easy Email Verification: the OAuth access token was rejected (revoked or expired).');
            $this->oauth->rejectAccessToken($token);
        }

        return $this->result($data);
    }

    /**
     * Why the last verify() has no answer, for the Mautic user (no contact data, no credentials).
     */
    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * The last verify() failed for its credential (missing, invalid, deleted, revoked or expired): something only
     * the person who manages the plugin settings can fix.
     */
    public function wasUnauthorized(): bool
    {
        return $this->unauthorized;
    }

    /**
     * @param array<string, mixed>|null $data
     *
     * @return array<string, mixed>|null
     */
    private function result(?array $data): ?array
    {
        if (null === $data) {
            $this->lastError    = $this->client->getLastError() ?? 'no answer';
            $this->unauthorized = $this->client->wasUnauthorized();
        }

        return $data;
    }

    private function missingCredential(): string
    {
        return Config::AUTH_OAUTH === $this->config->getAuthMethod()
            ? 'not connected with OAuth. Authorize again in the plugin settings.'
            : 'API key is missing.';
    }
}
