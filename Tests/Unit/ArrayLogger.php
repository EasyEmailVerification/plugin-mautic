<?php

declare(strict_types=1);

namespace MauticPlugin\MauticEasyBundle\Tests\Unit;

use Psr\Log\AbstractLogger;

/**
 * Keeps the log records, to check what is logged and that nothing sensitive is.
 */
class ArrayLogger extends AbstractLogger
{
    /**
     * @var array<int, array{0: string, 1: string}>
     */
    public array $records = [];

    /**
     * @param mixed                $level
     * @param string|\Stringable   $message
     * @param array<string, mixed> $context
     */
    public function log($level, $message, array $context = []): void
    {
        $this->records[] = [(string) $level, (string) $message];
    }

    public function has(string $level, string $text): bool
    {
        foreach ($this->records as [$recordLevel, $message]) {
            if ($recordLevel === $level && str_contains($message, $text)) {
                return true;
            }
        }

        return false;
    }
}
