<?php

declare(strict_types=1);

namespace MauticPlugin\MauticEasyBundle\Tests\Unit\Form;

use Mautic\PluginBundle\Entity\Integration;
use MauticPlugin\MauticEasyBundle\Auth\OAuthClient;
use MauticPlugin\MauticEasyBundle\Form\Type\ConfigAuthType;
use MauticPlugin\MauticEasyBundle\Integration\EasyIntegration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\Forms;

/**
 * Saving the settings form must not lose what is not a field of it: the OAuth tokens, or the API key left blank.
 */
class ConfigAuthTypeTest extends TestCase
{
    private const CONNECTED = [
        'auth_method'         => 'oauth',
        'apikey'              => 'saved-key',
        'oauth_client_id'     => 'eev_dcr_abc',
        'oauth_access_token'  => 'eev_at_1',
        'oauth_refresh_token' => 'eev_rt_1',
        'oauth_expires_at'    => '1900000000',
    ];

    public function testSavingKeepsTheTokensAndTheBlankApiKey(): void
    {
        $settings = $this->submit(self::CONNECTED, ['auth_method' => 'oauth', 'apikey' => '', 'oauth_disconnect' => '0']);

        $keys = $settings->getApiKeys();
        $this->assertSame('saved-key', $keys['apikey']);
        $this->assertSame('eev_rt_1', $keys['oauth_refresh_token']);
        $this->assertSame('eev_at_1', $keys['oauth_access_token']);
        $this->assertSame('eev_dcr_abc', $keys['oauth_client_id']);
    }

    public function testNewApiKeyReplacesTheSavedOne(): void
    {
        $settings = $this->submit(['apikey' => 'saved-key'], ['auth_method' => 'apikey', 'apikey' => 'new-key']);

        $this->assertSame('new-key', $settings->getApiKeys()['apikey']);
        $this->assertSame('apikey', $settings->getApiKeys()['auth_method']);
    }

    public function testTheApiKeyIsNotSentBackToTheBrowser(): void
    {
        $view = $this->form(['apikey' => 'saved-key'])->createView();

        $this->assertSame('', $view['apiKeys']['apikey']->vars['value']);
    }

    public function testDisconnectIsOfferedOnlyWhenConnected(): void
    {
        $this->assertTrue($this->form(self::CONNECTED)->get('apiKeys')->has('oauth_disconnect'));
        $this->assertFalse($this->form(['apikey' => 'saved-key'])->get('apiKeys')->has('oauth_disconnect'));
    }

    /**
     * @param array<string, string> $keys
     * @param array<string, string> $submitted
     */
    private function submit(array $keys, array $submitted): Integration
    {
        $form = $this->form($keys);
        $form->submit(['apiKeys' => $submitted]);
        $this->assertTrue($form->isSynchronized());

        /** @var Integration $settings */
        $settings = $form->getData();

        return $settings;
    }

    /**
     * @param array<string, string> $keys
     *
     * @return FormInterface
     */
    private function form(array $keys): FormInterface
    {
        $settings = new Integration();
        $settings->setName(EasyIntegration::NAME);
        $settings->setApiKeys($keys);

        $integration = new EasyIntegration();
        $integration->setIntegrationConfiguration($settings);

        $oauth = $this->createMock(OAuthClient::class);
        $oauth->method('isConnected')->willReturnCallback(fn (array $keys): bool => !empty($keys['oauth_refresh_token']));

        $factory = Forms::createFormFactoryBuilder()->addType(new ConfigAuthType($oauth))->getFormFactory();

        // as Mautic's IntegrationConfigType adds it
        return $factory->createBuilder(FormType::class, $settings, ['data_class' => Integration::class])
            ->add('apiKeys', ConfigAuthType::class, ['label' => false, 'integration' => $integration])
            ->getForm();
    }
}
