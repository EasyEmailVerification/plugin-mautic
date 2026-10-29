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

use Monolog\Logger;
use Monolog\Handler\StreamHandler;

use MauticPlugin\MauticEasyBundle\Integration\EasyIntegration;


class CheckEmailVerificationCondition implements EventSubscriberInterface
{
    public const ON_EVALUATE = 'mautic.plugin.easy.on_email_verification_condition';

    private $apikey;
    private $leadModel;
    private $fieldModel;
    private $safeToSend;
    private $result;
    private $logger;


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
        $this->initializeLogger(); // Initialize the logger

        $integration = $integrationHelper->getIntegrationObject('Easy');

        if ($integration === null) {
            $this->logger->error('Easy integration is not initialized correctly.');
            return;
        }

        $settings = $integration->getIntegrationSettings()->getFeatureSettings();

        // get the key
        $this->apikey = $integration->getKeys();
        if (!$this->apikey) {
            $this->logger->error('API key is missing.');
        }

        $this->leadModel = $leadModel;
        $this->fieldModel = $fieldModel;
        $this->logger = $logger;

        $this->safeToSend = $settings['safe_to_send'];
        $this->result = $settings['result'];

        try {
            $this->ensureCustomFieldsExist();
        } catch (\Exception $e) {
            $this->logger->error('Failed to ensure custom fields exist: ' . $e->getMessage());
        }
    }


    private function initializeLogger()
    {
        $this->logger = new Logger('mautic_test_logger');
        $this->logger->pushHandler(new StreamHandler('/var/www/html/app/logs/mautic_test.log', Logger::DEBUG));
    }

    public static function getSubscribedEvents()
    {
        return [
            self::ON_EVALUATE => ['onConditionValidate', 0],
        ];
    }

    private function ensureCustomFieldsExist()
    {
        $this->initializeLogger();
        $this->logger->info('ensureCustomFieldsExist started.');

        $fields = $this->fieldModel->getFieldList();
        $existingFields = array_column($fields, 'alias');

        foreach (self::CUSTOM_FIELDS as $alias => $responseField) {
            if (in_array($alias, $existingFields)) {
                $this->logger->info('Field already exists: ' . $alias);
            } else {
                $this->logger->info('Creating field: ' . $alias);
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
                    $this->logger->info('Created field: ' . $alias);
                } catch (\Exception $e) {
                    $this->logger->error('Failed to create field: ' . $alias . '. Error: ' . $e->getMessage());
                }
            }
        }

        $this->logger->info('ensureCustomFieldsExist completed.');
    }



    public function onConditionValidate(ConditionEvent $event)
    {
        $this->initializeLogger();

        // Log that the method was called
        $this->logger->info('onConditionValidate called.');

        // Get the lead (contact) from the event
        $lead = $event->getLead();
        // Get the email address of the lead
        $email = $lead->getEmail();

        $fields = $lead->getProfileFields();

        // Check if the condition has already been evaluated within the last 2 minutes
        if (isset($fields['eev_validate_time']) && !empty($fields['eev_validate_time'])) {
            $lastValidationTime = strtotime($fields['eev_validate_time']);
            if (time() - $lastValidationTime < 120) {
                $this->logger->info('Email verification recently checked for lead ID: ' . $lead->getId());
                return;
            }
        }

        $this->logger->info('Updated field', ['field' => $field, 'value' => $value]);

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
                $this->logger->info('Updated field', ['field' => $field, 'value' => $value]);
            }
        }

        // Mark the validation time
        $lead->addUpdatedField('eev_validate_time', date('Y-m-d H:i:s'));


        try {
            // Save the lead
            $this->leadModel->saveEntity($lead);
        } catch (\Exception $e) {
            $this->logger->error('Error to save the EEV data');
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

        $this->logger->info('Email verification result: ' . $isVerified);

        return $isVerified;
    }


    private function checkEmailVerification($email)
    {
        $client = new Client();
        $query = [
            'apikey' => $this->apikey,
            'email' => $email,
        ];

        $url = 'https://api.easyemailverification.com/v1/verify?' . http_build_query($query);

        try {
            $response = $client->request('GET', $url, [
                'headers' => [
                    'Accept' => 'application/json',
                ],
                'timeout' => 30,
            ]);

            $body = $response->getBody();
            $data = json_decode($body, true);

            $this->logger->info('API Response: ' . $body);

            // Return the verification data
            return $data;
        } catch (RequestException $e) {
            $this->logger->error('Email verification failed: ' . $e->getMessage());
            return true;
        }
    }
}
