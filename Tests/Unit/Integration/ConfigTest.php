<?php

declare(strict_types=1);

namespace MauticPlugin\MauticEasyBundle\Tests\Unit\Integration;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\CoreBundle\Helper\EncryptionHelper;
use Mautic\IntegrationsBundle\Facade\EncryptionService;
use Mautic\IntegrationsBundle\Helper\IntegrationsHelper;
use Mautic\PluginBundle\Entity\Integration;
use MauticPlugin\MauticEasyBundle\Integration\Config;
use MauticPlugin\MauticEasyBundle\Integration\EasyIntegration;
use PHPUnit\Framework\TestCase;

class ConfigTest extends TestCase
{
    /**
     * @dataProvider decisions
     *
     * @param array<string, mixed> $features
     * @param array{safe_to_send: bool, result: bool} $expected
     */
    public function testDecision(array $features, array $expected): void
    {
        $this->assertSame($expected, $this->config($features)->getDecision());
    }

    /**
     * @return array<string, array{array<string, mixed>, array{safe_to_send: bool, result: bool}}>
     */
    public function decisions(): array
    {
        return [
            'never saved'                  => [[], ['safe_to_send' => true, 'result' => false]],
            '2.x safe to send'             => [['integration' => ['decision' => 'safe_to_send']], ['safe_to_send' => true, 'result' => false]],
            '2.x result'                   => [['integration' => ['decision' => 'result']], ['safe_to_send' => false, 'result' => true]],
            '1.x safe to send'             => [['safe_to_send' => '1', 'result' => '0'], ['safe_to_send' => true, 'result' => false]],
            '1.x result'                   => [['safe_to_send' => '0', 'result' => '1'], ['safe_to_send' => false, 'result' => true]],
            // combinations the 1.x form allowed keep their 1.x meaning until saved again
            '1.x both'                     => [['safe_to_send' => 1, 'result' => 1], ['safe_to_send' => true, 'result' => true]],
            '1.x neither'                  => [['safe_to_send' => 0, 'result' => 0], ['safe_to_send' => false, 'result' => false]],
            '2.x setting wins over 1.x'    => [['safe_to_send' => 0, 'result' => 1, 'integration' => ['decision' => 'safe_to_send']], ['safe_to_send' => true, 'result' => false]],
        ];
    }

    public function testApiKeyIsTheDefaultAuthentication(): void
    {
        $config = $this->config([], ['apikey' => 'k']);

        $this->assertSame(Config::AUTH_API_KEY, $config->getAuthMethod());
        $this->assertSame('k', $config->getApiKey());
    }

    public function testOAuthAuthentication(): void
    {
        $this->assertSame(Config::AUTH_OAUTH, $this->config([], ['auth_method' => 'oauth'])->getAuthMethod());
    }

    public function testEmptyApiKeyIsMissing(): void
    {
        $this->assertNull($this->config([], ['apikey' => ''])->getApiKey());
    }

    public function testReloadReadsTheDatabaseAndDecrypts(): void
    {
        $settings = $this->settings([], ['oauth_refresh_token' => 'old']);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())->method('refresh')->with($settings)
            ->willReturnCallback(fn (Integration $entity) => $entity->setApiKeys(['oauth_refresh_token' => 'encrypted-new']));

        $encryptionHelper = $this->createMock(EncryptionHelper::class);
        $encryptionHelper->method('decrypt')->willReturnMap([['encrypted-new', false, 'new']]);

        $config = new Config($this->helper($settings), $entityManager, new EncryptionService($encryptionHelper));

        $this->assertSame(['oauth_refresh_token' => 'new'], $config->reloadApiKeys());
        $this->assertSame(['oauth_refresh_token' => 'new'], $settings->getApiKeys());
    }

    /**
     * @param array<string, mixed> $features
     * @param array<string, mixed> $keys
     */
    private function config(array $features, array $keys = []): Config
    {
        return new Config(
            $this->helper($this->settings($features, $keys)),
            $this->createMock(EntityManagerInterface::class),
            new EncryptionService($this->createMock(EncryptionHelper::class))
        );
    }

    /**
     * @param array<string, mixed> $features
     * @param array<string, mixed> $keys
     */
    private function settings(array $features, array $keys): Integration
    {
        $settings = new Integration();
        $settings->setName(EasyIntegration::NAME);
        $settings->setFeatureSettings($features);
        $settings->setApiKeys($keys);

        return $settings;
    }

    private function helper(Integration $settings): IntegrationsHelper
    {
        $integration = new EasyIntegration();
        $integration->setIntegrationConfiguration($settings);

        $helper = $this->createMock(IntegrationsHelper::class);
        $helper->method('getIntegration')->willReturn($integration);

        return $helper;
    }
}
