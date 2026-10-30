<?php

declare(strict_types=1);

namespace MauticPlugin\MauticEasyBundle\Verification;

use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Entity\LeadField;
use Mautic\LeadBundle\Model\FieldModel;
use Mautic\LeadBundle\Model\LeadModel;
use Psr\Log\LoggerInterface;

/**
 * Stores an API answer in the contact's eev_ fields, creating the fields the first time. Shared by the campaign
 * condition, the verification on save and the "Verify email now" action.
 */
class ResultWriter
{
    /**
     * Contact field => key of the API answer.
     */
    public const FIELDS = [
        'eev_result'        => 'result',
        'eev_reason'        => 'reason',
        'eev_disposable'    => 'disposable',
        'eev_accept_all'    => 'accept_all',
        'eev_role'          => 'role',
        'eev_free'          => 'free',
        'eev_domain'        => 'domain',
        'eev_mx_record'     => 'mx_record',
        'eev_mx_domain'     => 'mx_domain',
        'eev_safe_to_send'  => 'safe_to_send',
        'eev_did_you_mean'  => 'did_you_mean',
        'eev_success'       => 'success',
        'eev_message'       => 'message',
        'eev_http_code'     => 'http_code',
        'eev_validate_time' => 'validate_time',
    ];

    /**
     * The eev_ fields were checked in this process.
     */
    private bool $fieldsChecked = false;

    /**
     * A contact is being saved by this class: its save must not start another verification.
     */
    private bool $writing = false;

    public function __construct(
        private LeadModel $leadModel,
        private FieldModel $fieldModel,
        private LoggerInterface $logger
    ) {
    }

    public function isWriting(): bool
    {
        return $this->writing;
    }

    public function ensureFieldsExist(): void
    {
        if ($this->fieldsChecked) {
            return;
        }
        $this->fieldsChecked = true;

        foreach (self::FIELDS as $alias => $responseField) {
            if (!$this->fieldModel->getEntityByAlias($alias)) {
                $field = new LeadField();
                $field->setAlias($alias);
                $field->setLabel('EEV_'.$responseField);
                $field->setType('text');
                $field->setGroup('core');
                $field->setIsRequired(false);
                $field->setIsVisible(true);
                $field->setProperties([]);

                try {
                    $this->fieldModel->saveEntity($field);
                    $this->logger->info('Easy Email Verification: created field '.$alias);
                } catch (\Exception $e) {
                    $this->logger->error('Easy Email Verification: failed to create field '.$alias.': '.$e->getMessage());
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $verificationData API answer
     */
    public function write(Lead $lead, array $verificationData): void
    {
        try {
            $this->ensureFieldsExist();
        } catch (\Exception $e) {
            $this->logger->error('Easy Email Verification: failed to ensure custom fields exist: '.$e->getMessage());
        }

        foreach (self::FIELDS as $field => $responseField) {
            if (isset($verificationData[$responseField])) {
                // booleans are stored as "true"/"false"
                $value = $verificationData[$responseField];
                if (false === $value) {
                    $value = 'false';
                } elseif (true === $value) {
                    $value = 'true';
                }

                $lead->addUpdatedField($field, $value);
            }
        }

        $lead->addUpdatedField('eev_validate_time', date('Y-m-d H:i:s'));

        $this->writing = true;
        try {
            $this->leadModel->saveEntity($lead);
        } catch (\Exception $e) {
            $this->logger->error('Easy Email Verification: could not save the result of contact '.$lead->getId().': '.$e->getMessage());
        } finally {
            $this->writing = false;
        }
    }

    /**
     * The stored result, as API keys.
     *
     * @param array<string, mixed> $fields contact profile fields
     *
     * @return array<string, mixed>
     */
    public static function storedResult(array $fields): array
    {
        $stored = [];
        foreach (self::FIELDS as $field => $responseField) {
            if (isset($fields[$field])) {
                $stored[$responseField] = $fields[$field];
            }
        }

        return $stored;
    }
}
