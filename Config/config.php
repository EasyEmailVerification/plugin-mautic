<?php

declare(strict_types=1);

// Services are in Config/services.php (autowired)
return [
    'name'        => 'Easy Email Verification Integration',
    'description' => 'Integrates Mautic with the Easy Email Verification service to verify email addresses and ensure their validity within campaigns.',
    'version'     => '2.0.0',
    'author'      => 'Easy Email Verification',

    'routes' => [
        // Under /s (signed-in users): "Verify email now" and "Verify selected"
        'main' => [
            'mautic_easy_verify' => [
                'path'       => '/easy/verify/{objectId}',
                'controller' => 'MauticPlugin\MauticEasyBundle\Controller\VerifyController::verifyAction',
                'defaults'   => ['objectId' => 0],
            ],
            'mautic_easy_verify_batch' => [
                'path'       => '/easy/verify-batch',
                'controller' => 'MauticPlugin\MauticEasyBundle\Controller\VerifyController::batchAction',
            ],
        ],
        // OAuth redirect URI: public, like the IntegrationsBundle callback; the state parameter protects it.
        // Not containing "/oauth" or "/api": Mautic handles those addresses as API requests.
        'public' => [
            'mautic_easy_oauth_callback' => [
                'path'       => '/easy/callback',
                'controller' => 'MauticPlugin\MauticEasyBundle\Controller\OAuthCallbackController::callbackAction',
            ],
        ],
    ],
];
