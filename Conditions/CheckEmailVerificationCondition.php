<?php

namespace MauticPlugin\MauticEasyBundle\Conditions;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Mautic\CampaignBundle\Event\ConditionEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Mautic\PluginBundle\Helper\IntegrationHelper;
use Mautic\LeadBundle\Model\LeadModel;
use Mautic\LeadBundle\Model\FieldModel;
use Mautic\LeadBundle\Entity\LeadField;
use Psr\Log\LoggerInterface;

use MauticPlugin\MauticEasyBundle\Integration\EasyIntegration;


class CheckEmailVerificationCondition implements EventSubscriberInterface
{
    public const ON_EVALUATE = 'mautic.plugin.easy.on_email_verification_condition';

    private $integrationHelper;
    private $apikey;
    private $leadModel;
    private $fieldModel;
    private $safeToSend;
    private $result;
    private $logger;

    /**
     * @var bool the eev_ fields were checked in this process
     */
    private $fieldsChecked = false;


    private const CUSTOM_FIELDS = [
        'eev_result' => 'result',
        'eev_reason' => 'reason',
        'eev_disposable' => 'disposable',
        'eev_accept_all' => 'accept_all',
        'eev_role' => 'role',
        'eev_free' => 'free',
        'eev_domain' => 'domain',
        'eev_mx_record' => 'mx_record',
        'eev_mx_domain' => 'mx_domain',
        'eev_safe_to_send' => 'safe_to_send',
        'eev_did_you_mean' => 'did_you_mean',
        'eev_success' => 'success',
        'eev_message' => 'message',
        'eev_http_code' => 'http_code',
        'eev_validate_time' => 'validate_time',
    ];


    public function __construct(
        IntegrationHelper $integrationHelper,
        LeadModel $leadModel,
        FieldModel $fieldModel,
        LoggerInterface $logger
    ) {
        $this->integrationHelper = $integrationHelper;
        $this->leadModel         = $leadModel;
        $this->fieldModel        = $fieldModel;
        $this->logger            = $logger;
    }

    /**
     * Reads the API key and the decision settings. Done when the condition runs, not when the service is built,
     * so a long-running process sees settings saved after it started.
     */
    private function loadSettings(): bool
    {
        $integration = $this->integrationHelper->getIntegrationObject('Easy');
        if (!$integration instanceof EasyIntegration) {
            $this->logger->error('Easy Email Verification: the integration is not installed.');

            return false;
        }

        $this->apikey = $integration->getKeys();
        if (!$this->apikey) {
            $this->logger->error('Easy Email Verification: API key is missing.');

            return false;
        }

        // Defaults are the ones the settings form shows before it is first saved
        $settings         = $integration->getIntegrationSettings()->getFeatureSettings() ?: [];
        $this->safeToSend = $settings['safe_to_send'] ?? true;
        $this->result     = $settings['result'] ?? false;

        return true;
    }


    public static function getSubscribedEvents()
    {
        return [
            self::ON_EVALUATE => ['onConditionValidate', 0],
        ];
    }

    private function ensureCustomFieldsExist()
    {
        if ($this->fieldsChecked) {
            return;
        }
        $this->fieldsChecked = true;

        foreach (self::CUSTOM_FIELDS as $alias => $responseField) {
            if (!$this->fieldModel->getEntityByAlias($alias)) {
                $field = new LeadField();
                $field->setAlias($alias);
                $field->setLabel('EEV_' . $responseField);
                $field->setType('text');
                $field->setGroup('core');
                $field->setIsRequired(false);
                $field->setIsVisible(true);
                $field->setProperties([]);

                try {
                    $this->fieldModel->saveEntity($field);
                    $this->logger->info('Easy Email Verification: created field ' . $alias);
                } catch (\Exception $e) {
                    $this->logger->error('Easy Email Verification: failed to create field ' . $alias . ': ' . $e->getMessage());
                }
            }
        }
    }



    public function onConditionValidate(ConditionEvent $event)
    {
        if (!$this->loadSettings()) {
            $event->setResult(false);

            return false;
        }

        try {
            $this->ensureCustomFieldsExist();
        } catch (\Exception $e) {
            $this->logger->error('Easy Email Verification: failed to ensure custom fields exist: ' . $e->getMessage());
        }

        // Get the lead (contact) from the event
        $lead = $event->getLead();
        // Get the email address of the lead
        $email = $lead->getEmail();

        $fields = $lead->getProfileFields();

        // Check if the condition has already been evaluated within the last 2 minutes
        if (isset($fields['eev_validate_time']) && !empty($fields['eev_validate_time'])) {
            $lastValidationTime = strtotime($fields['eev_validate_time']);
            if (time() - $lastValidationTime < 120) {
                $this->logger->debug('Easy Email Verification: recently checked, contact ' . $lead->getId());
                return;
            }
        }

        // Perform your email verification logic here and set the result
        $verificationData = $this->checkEmailVerification($email);

        // Update the lead with verification data
        foreach (self::CUSTOM_FIELDS as $field => $responseField) {
            if (isset($verificationData[$responseField])) {
                // Check if the value is a boolean represented as 0 or 1 and convert it to true/false
                $value = $verificationData[$responseField];
                if ($value === false) {
                    $value = "false";
                } elseif ($value === true) {
                    $value = "true";
                }

                $lead->addUpdatedField($field, $value);
            }
        }

        // Mark the validation time
        $lead->addUpdatedField('eev_validate_time', date('Y-m-d H:i:s'));


        try {
            // Save the lead
            $this->leadModel->saveEntity($lead);
        } catch (\Exception $e) {
            $this->logger->error('Easy Email Verification: could not save the result of contact ' . $lead->getId() . ': ' . $e->getMessage());
        }

        $isVerified = true;

        if ($this->result) {
            if (isset($verificationData['result']) && ($verificationData['result'] === 'invalid' || $verificationData['result'] === 'unknown')) {
                $isVerified = false;
            }
        }

        if ($this->safeToSend) {
            if (isset($verificationData['safe_to_send']) && ($verificationData['safe_to_send'] === "false" || $verificationData['safe_to_send'] === false)) {
                $isVerified = false;
            }
        }



        // Set the result to the event
        $event->setResult($isVerified);

        $this->logger->debug('Easy Email Verification: contact ' . $lead->getId() . ' ' . ($isVerified ? 'passed' : 'failed'));

        return $isVerified;
    }


    private function checkEmailVerification($email)
    {
        $client = new Client();
        $url    = 'https://api.easyemailverification.com/v1/verify?' . http_build_query(['email' => $email]);

        try {
            $response = $client->request('GET', $url, [
                'headers' => [
                    'Accept'    => 'application/json',
                    // in a header, not in the query string, so the key stays out of URLs, proxy and server logs
                    'X-API-Key' => $this->apikey,
                ],
                'connect_timeout' => 10,
                'timeout'         => 30,
            ]);

            $body = $response->getBody();
            $data = json_decode($body, true);

            // Return the verification data
            return $data;
        } catch (RequestException $e) {
            // Not $e->getMessage(): it contains the request URL, with the email address
            $this->logger->error('Easy Email Verification: request failed (' . get_class($e) . ', HTTP ' . $e->getCode() . ')');
            return true;
        }
    }
}
