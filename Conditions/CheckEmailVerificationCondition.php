<?php

declare(strict_types=1);

namespace MauticPlugin\MauticEasyBundle\Conditions;

use Mautic\CampaignBundle\Event\ConditionEvent;
use MauticPlugin\MauticEasyBundle\Api\Verifier;
use MauticPlugin\MauticEasyBundle\Integration\Config;
use MauticPlugin\MauticEasyBundle\Verification\ResultWriter;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class CheckEmailVerificationCondition implements EventSubscriberInterface
{
    public const ON_EVALUATE = 'mautic.plugin.easy.on_email_verification_condition';

    /**
     * A stored result younger than this is reused instead of calling the API again (30 days).
     */
    public const REUSE_SECONDS = 2592000;

    /**
     * "Safe to send" decides (feature setting).
     */
    private bool $safeToSend = true;

    /**
     * "Result" decides (feature setting).
     */
    private bool $result = false;

    public function __construct(
        private Config $config,
        private Verifier $verifier,
        private ResultWriter $writer,
        private LoggerInterface $logger
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            self::ON_EVALUATE => ['onConditionValidate', 0],
        ];
    }

    /**
     * Reads the credentials and the decision settings. Done when the condition runs, not when the service is built,
     * so a long-running process sees settings saved after it started.
     */
    private function loadSettings(): bool
    {
        if (!$this->config->getIntegrationConfiguration()) {
            $this->logger->error('Easy Email Verification: the integration is not installed.');

            return false;
        }

        if (!$this->verifier->hasCredentials()) {
            return false;
        }

        $decision         = $this->config->getDecision();
        $this->safeToSend = $decision['safe_to_send'];
        $this->result     = $decision['result'];

        return true;
    }

    /**
     * @return bool the condition result, also set on the event
     */
    public function onConditionValidate(ConditionEvent $event): bool
    {
        if (!$this->loadSettings()) {
            return $this->decide($event, false);
        }

        try {
            $this->writer->ensureFieldsExist();
        } catch (\Exception $e) {
            $this->logger->error('Easy Email Verification: failed to ensure custom fields exist: '.$e->getMessage());
        }

        $lead  = $event->getLead();
        $email = $lead->getEmail();

        if (!$email) {
            $this->logger->debug('Easy Email Verification: contact '.$lead->getId().' has no email address');

            return $this->decide($event, false);
        }

        // A recent result of this contact is reused: each verification spends a credit
        $stored = $this->getRecentVerification($lead->getProfileFields(), $email);
        if (null !== $stored) {
            $this->logger->debug('Easy Email Verification: reusing the recent result of contact '.$lead->getId());

            return $this->decide($event, $this->isVerified($stored));
        }

        $verificationData = $this->verifier->verify($email);

        // No answer from the API: the contact fails and its eev_ fields are kept, so it is verified again next time
        if (null === $verificationData) {
            return $this->decide($event, false);
        }

        $this->writer->write($lead, $verificationData);

        $isVerified = $this->isVerified($verificationData);

        $this->logger->debug('Easy Email Verification: contact '.$lead->getId().' '.($isVerified ? 'passed' : 'failed'));

        return $this->decide($event, $isVerified);
    }

    private function decide(ConditionEvent $event, bool $passed): bool
    {
        $passed ? $event->pass() : $event->fail();

        return $passed;
    }

    /**
     * @param array<string, mixed> $verificationData API answer (or the stored eev_ fields, as API keys)
     */
    private function isVerified(array $verificationData): bool
    {
        $isVerified = true;

        if ($this->result) {
            if (isset($verificationData['result']) && ('invalid' === $verificationData['result'] || 'unknown' === $verificationData['result'])) {
                $isVerified = false;
            }
        }

        if ($this->safeToSend) {
            if (isset($verificationData['safe_to_send']) && ('false' === $verificationData['safe_to_send'] || false === $verificationData['safe_to_send'])) {
                $isVerified = false;
            }
        }

        return $isVerified;
    }

    /**
     * The contact's stored result, as API keys, when it was verified less than REUSE_SECONDS ago.
     * The verified address itself is not stored, so the stored domain must match the current address:
     * a contact whose address moved to another domain is verified again.
     *
     * @param array<string, mixed> $fields contact profile fields
     *
     * @return array<string, mixed>|null
     */
    private function getRecentVerification(array $fields, string $email): ?array
    {
        if (empty($fields['eev_result']) || empty($fields['eev_validate_time'])) {
            return null;
        }

        $verifiedAt = strtotime((string) $fields['eev_validate_time']);
        if (false === $verifiedAt || time() - $verifiedAt >= self::REUSE_SECONDS) {
            return null;
        }

        $domain = strtolower((string) substr(strrchr($email, '@') ?: '', 1));
        if (strtolower((string) ($fields['eev_domain'] ?? '')) !== $domain) {
            return null;
        }

        return ResultWriter::storedResult($fields);
    }
}
