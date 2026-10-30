<?php

declare(strict_types=1);

namespace MauticPlugin\MauticEasyBundle\Integration;

use Mautic\IntegrationsBundle\Integration\BasicIntegration;
use Mautic\IntegrationsBundle\Integration\ConfigurationTrait;
use Mautic\IntegrationsBundle\Integration\Interfaces\BasicInterface;

class EasyIntegration extends BasicIntegration implements BasicInterface
{
    use ConfigurationTrait;

    /**
     * Same name as in 1.x, so an upgrade keeps the saved settings and API key.
     */
    public const NAME = 'Easy';

    public const DISPLAY_NAME = 'Easy Email Verification';

    public function getName(): string
    {
        return self::NAME;
    }

    public function getDisplayName(): string
    {
        return self::DISPLAY_NAME;
    }

    public function getIcon(): string
    {
        return 'plugins/MauticEasyBundle/Assets/img/easy.png';
    }
}
