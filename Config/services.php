<?php

declare(strict_types=1);

use Mautic\CoreBundle\DependencyInjection\MauticCoreExtension;
use MauticPlugin\MauticEasyBundle\Api\VerificationClient;
use MauticPlugin\MauticEasyBundle\Auth\OAuthClient;
use MauticPlugin\MauticEasyBundle\Controller\OAuthCallbackController;
use MauticPlugin\MauticEasyBundle\Integration\Config;
use MauticPlugin\MauticEasyBundle\Integration\EasyIntegration;
use MauticPlugin\MauticEasyBundle\Integration\Support\ConfigSupport;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return function (ContainerConfigurator $configurator): void {
    $services = $configurator->services()
        ->defaults()
        ->autowire()
        ->autoconfigure()
        ->public();

    // The integration classes are registered below with explicit tags, which Mautic 5.0 needs (it does not
    // autoconfigure them) and which keep one service per role on every version.
    $excludes = ['Integration', 'Assets', 'Resources', 'Translations', 'docs', 'vendor'];

    $services->load('MauticPlugin\\MauticEasyBundle\\', '../')
        ->exclude('../{'.implode(',', array_merge(MauticCoreExtension::DEFAULT_EXCLUDES, $excludes)).'}');

    $services->set(VerificationClient::class)
        ->arg('$httpClient', service('mautic.http.client'));
    $services->set(OAuthClient::class)
        ->arg('$httpClient', service('mautic.http.client'));
    $services->set(Config::class);

    // Service id and file name are the ones Mautic's plugin list looks for (mautic.integration.<name>, <Name>Integration.php)
    $services->set('mautic.integration.easy', EasyIntegration::class)
        ->autoconfigure(false)
        ->tag('mautic.integration')
        ->tag('mautic.basic_integration');

    $services->set('mautic.integration.easy.configuration', ConfigSupport::class)
        ->autoconfigure(false)
        ->tag('mautic.config_integration')
        ->tag('mautic.authentication_integration');

    $services->set(OAuthCallbackController::class)
        ->arg('$integration', service('mautic.integration.easy.configuration'));
};
