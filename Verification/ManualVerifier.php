<?php

declare(strict_types=1);

namespace MauticPlugin\MauticEasyBundle\Verification;

use Mautic\CoreBundle\Security\Permissions\CorePermissions;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Model\LeadModel;
use MauticPlugin\MauticEasyBundle\Api\Verifier;
use MauticPlugin\MauticEasyBundle\Integration\Config;

/**
 * "Verify email now" and "Verify selected": always calls the API, without the 30-day reuse of the campaign
 * condition, and stores the answer in the eev_ fields.
 */
class ManualVerifier
{
    public const OK             = 'ok';
    public const FAILED         = 'failed';
    public const NOT_AUTHORIZED = 'not_authorized';
    public const NO_EMAIL       = 'no_email';
    public const FORBIDDEN      = 'forbidden';
    public const NOT_FOUND      = 'not_found';
    public const NOT_CONFIGURED = 'not_configured';

    /**
     * Contacts verified at most by one "Verify selected" (each one waits for the API).
     */
    public const BATCH_LIMIT = 100;

    public function __construct(
        private LeadModel $leadModel,
        private CorePermissions $security,
        private Config $config,
        private Verifier $verifier,
        private ResultWriter $writer
    ) {
    }

    /**
     * Published, with an API key or an OAuth connection.
     */
    public function isConfigured(): bool
    {
        return $this->config->isPublished() && $this->verifier->isConfigured();
    }

    public function canEdit(Lead $lead): bool
    {
        return (bool) $this->security->hasEntityAccess('lead:leads:editown', 'lead:leads:editother', $lead->getPermissionUser());
    }

    public function getContact(int $contactId): ?Lead
    {
        $lead = $contactId > 0 ? $this->leadModel->getEntity($contactId) : null;

        return $lead instanceof Lead && $lead->getId() ? $lead : null;
    }

    /**
     * @return array{status: string, data?: array<string, mixed>, error?: string}
     */
    public function verifyContact(int $contactId): array
    {
        $lead = $this->getContact($contactId);
        if (!$lead) {
            return ['status' => self::NOT_FOUND];
        }

        return $this->verifyLead($lead);
    }

    /**
     * @param array<int, mixed> $contactIds
     *
     * @return array<string, int> number of contacts per status, plus "skipped" beyond BATCH_LIMIT
     */
    public function verifyContacts(array $contactIds): array
    {
        $ids    = array_values(array_unique(array_filter(array_map('intval', $contactIds))));
        $counts = [self::OK => 0, self::FAILED => 0, self::NOT_AUTHORIZED => 0, self::NO_EMAIL => 0, self::FORBIDDEN => 0, self::NOT_FOUND => 0, self::NOT_CONFIGURED => 0];

        foreach (array_slice($ids, 0, self::BATCH_LIMIT) as $id) {
            $status = $this->verifyContact($id)['status'];
            ++$counts[$status];
            // the credential does not work: the next contacts would fail the same way
            if (self::NOT_AUTHORIZED === $status) {
                break;
            }
        }
        $counts['skipped'] = max(0, count($ids) - self::BATCH_LIMIT);

        return $counts;
    }

    /**
     * @return array{status: string, data?: array<string, mixed>, error?: string}
     */
    public function verifyLead(Lead $lead): array
    {
        if (!$this->canEdit($lead)) {
            return ['status' => self::FORBIDDEN];
        }

        $email = $lead->getEmail();
        if (!$email) {
            return ['status' => self::NO_EMAIL];
        }

        if (!$this->isConfigured()) {
            return ['status' => self::NOT_CONFIGURED];
        }

        $data = $this->verifier->verify($email);
        if (null === $data) {
            return [
                'status' => $this->verifier->wasUnauthorized() ? self::NOT_AUTHORIZED : self::FAILED,
                'error'  => (string) $this->verifier->getLastError(),
            ];
        }

        $this->writer->write($lead, $data);

        return ['status' => self::OK, 'data' => $data];
    }
}
