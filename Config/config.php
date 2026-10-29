<?php

return [
    'name'        => 'Easy Email Verification Integration',
    'description' => 'Integrates Mautic with the Easy Email Verification service to verify email addresses and ensure their validity within campaigns.',
    'version'     => '1.0.2',
    'author'      => 'Easy Email Verification',

    'services' => [
        'events' => [
            'mautic.plugin.easy.campaign.condition.email_verification' => [
                'class'     => \MauticPlugin\MauticEasyBundle\Conditions\CheckEmailVerificationCondition::class,
                'arguments' => [
                    'mautic.helper.integration',
                    'mautic.lead.model.lead',
                    'mautic.lead.model.field',
                    'logger', // Inject the logger
                ],
            ],
            'mautic.plugin.easy.campaign.builder.subscriber' => [
                'class'     => \MauticPlugin\MauticEasyBundle\EventListener\CampaignBuilderSubscriber::class,
            ],
        ],

        'integrations' => [
            'mautic.integration.easy' => [
                'class'     => \MauticPlugin\MauticEasyBundle\Integration\EasyIntegration::class,
                'arguments' => [
                    'event_dispatcher',
                    'mautic.helper.cache_storage',
                    'doctrine.orm.entity_manager',
                    'session',
                    'request_stack',
                    'router',
                    'translator',
                    'logger',
                    'mautic.helper.encryption',
                    'mautic.lead.model.lead',
                    'mautic.lead.model.company',
                    'mautic.helper.paths',
                    'mautic.core.model.notification',
                    'mautic.lead.model.field',
                    'mautic.plugin.model.integration_entity',
                    'mautic.lead.model.dnc',
                ],
            ],
        ],
    ],

];
