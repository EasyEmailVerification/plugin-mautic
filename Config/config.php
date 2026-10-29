<?php

use Mautic\CampaignBundle\CampaignEvents;

return [
    'name'        => 'Easy Email Verification Integration',
    'description' => 'Integrates Mautic with the Easy Email Verification service to verify email addresses and ensure their validity within campaigns.',
    'version'     => '1.0',
    'author'      => 'Easy Email Verification',

    'routes' => [
        'public' => [
            'mautic_plugin_easy_index' => [
                'path'       => '/easy/callback',
                'controller' => 'MauticEasyBundle:Public:callback',
            ],
        ],
        'main' => [
            'mautic_plugin_easy_action' => [
                'path'       => '/easy/{objectAction}/{objectId}',
                'controller' => 'MauticEasyBundle:Easy:execute',
            ],
        ],
    ],

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
                'arguments' => [
                    'event_dispatcher',
                    'translator',
                ],
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

    'parameters' => [
        'mautic_plugin_easy_apikey' => 'your_saved_api_key', // Replace this with the actual API key
    ],

    'campaign' => [
        'events' => [
            'email_verification' => [
                'label'       => 'mautic.easybundle.condition.email_verification',
                'description' => 'Checks if the email is verified',
                'eventName'   => CampaignEvents::ON_EVENT_CONDITION_EVALUATION,
            ],
        ],
    ],

    'menu' => [
        'main' => [
            'items' => [
                'plugin.easy' => [
                    'route'    => 'mautic_plugin_easy_index',
                    'access'   => ['plugin:easy:view'],
                    'parent'   => 'mautic.plugin',
                    'priority' => 10,
                ],
            ],
        ],
    ],

    'menuItems' => [
        'plugin.easy' => [
            'route'    => 'mautic_plugin_easy_index',
            'access'   => ['plugin:easy:view'],
            'parent'   => 'mautic.plugin',
            'priority' => 10,
        ],
    ],

    'settings' => [
        'global' => [
            'apikey' => [
                'type'        => 'text',
                'label'       => 'API Key',
                'required'    => true,
                'attr'        => [
                    'class'       => 'form-control',
                ],
                'tooltip'     => 'Enter your API key for Easy Email Verification.',
                'input_group' => [
                    'pre' => 'fa fa-key',
                ],
                'constraints' => [
                    'notblank' => true,
                ],
            ],
        ],
    ],
];
