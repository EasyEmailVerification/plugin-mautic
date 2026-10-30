<?php

declare(strict_types=1);

namespace MauticPlugin\MauticEasyBundle\Auth;

use Doctrine\DBAL\Connection;

/**
 * Database lock around the token refresh. The authorization server rotates refresh tokens and revokes the whole grant
 * when one is used twice, so two processes (cron, worker, web) must never refresh at the same time. A MySQL named
 * lock works across containers that share the database.
 */
class RefreshLock
{
    private const NAME = 'mautic_easy_oauth_refresh';

    public function __construct(private Connection $connection)
    {
    }

    public function acquire(int $timeoutSeconds = 15): bool
    {
        return 1 === (int) $this->connection->fetchOne('SELECT GET_LOCK(?, ?)', [self::NAME, $timeoutSeconds]);
    }

    public function release(): void
    {
        $this->connection->fetchOne('SELECT RELEASE_LOCK(?)', [self::NAME]);
    }
}
