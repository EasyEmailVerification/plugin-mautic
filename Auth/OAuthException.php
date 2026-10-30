<?php

declare(strict_types=1);

namespace MauticPlugin\MauticEasyBundle\Auth;

/**
 * An OAuth step failed. The message is shown to the Mautic user; it never contains tokens.
 */
class OAuthException extends \RuntimeException
{
}
