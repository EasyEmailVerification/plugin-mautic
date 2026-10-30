<?php

declare(strict_types=1);

namespace MauticPlugin\MauticEasyBundle\Tests\Unit;

use MauticPlugin\MauticEasyBundle\Verification\ManualVerifier;
use PHPUnit\Framework\TestCase;

/**
 * Every translation key the plugin uses exists in messages.ini, including the ones Mautic builds itself
 * (the campaign builder shows an event's type as "mautic.campaign.<type>").
 */
class TranslationsTest extends TestCase
{
    public function testEveryUsedKeyIsTranslated(): void
    {
        $root         = dirname(__DIR__, 2);
        $translations = parse_ini_file($root.'/Translations/en_US/messages.ini');
        $this->assertIsArray($translations);

        // built at run time: by Mautic's campaign builder, and from ManualVerifier statuses
        $used = ['mautic.campaign.email_verification'];
        foreach ([ManualVerifier::NO_EMAIL, ManualVerifier::NOT_CONFIGURED, ManualVerifier::FORBIDDEN, ManualVerifier::NOT_FOUND] as $status) {
            $used[] = 'mautic.integration.easy.verify.'.$status;
        }
        foreach (['Api', 'Auth', 'Conditions', 'Controller', 'EventListener', 'Form', 'Integration', 'Resources', 'Verification'] as $dir) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root.'/'.$dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                preg_match_all("/'(mautic\\.(?:integration\\.easy|easybundle)\\.[a-z0-9_.]*[a-z0-9_])'/", (string) file_get_contents((string) $file), $matches);
                $used = array_merge($used, $matches[1]);
            }
        }

        $this->assertGreaterThan(15, count(array_unique($used)));
        foreach (array_unique($used) as $key) {
            $this->assertArrayHasKey($key, $translations, "missing translation: $key");
            $this->assertNotSame('', trim((string) $translations[$key]), "empty translation: $key");
        }
    }
}
