<?php

declare(strict_types=1);

namespace MauticPlugin\MauticEasyBundle\Integration\Support;

use Mautic\IntegrationsBundle\DTO\Note;
use Mautic\IntegrationsBundle\Exception\UnauthorizedException;
use Mautic\IntegrationsBundle\Integration\DefaultConfigFormTrait;
use Mautic\IntegrationsBundle\Integration\Interfaces\AuthenticationInterface;
use Mautic\IntegrationsBundle\Integration\Interfaces\ConfigFormAuthInterface;
use Mautic\IntegrationsBundle\Integration\Interfaces\ConfigFormAuthorizeButtonInterface;
use Mautic\IntegrationsBundle\Integration\Interfaces\ConfigFormCallbackInterface;
use Mautic\IntegrationsBundle\Integration\Interfaces\ConfigFormFeatureSettingsInterface;
use Mautic\IntegrationsBundle\Integration\Interfaces\ConfigFormInterface;
use Mautic\IntegrationsBundle\Integration\Interfaces\ConfigFormNotesInterface;
use MauticPlugin\MauticEasyBundle\Auth\OAuthClient;
use MauticPlugin\MauticEasyBundle\Auth\OAuthException;
use MauticPlugin\MauticEasyBundle\Form\Type\ConfigAuthType;
use MauticPlugin\MauticEasyBundle\Form\Type\ConfigFeaturesType;
use MauticPlugin\MauticEasyBundle\Integration\EasyIntegration;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The plugin settings (Settings → Plugins → Easy Email Verification): authentication on the "Details" tab, decision
 * mode on the "Features" tab, and the OAuth authorize button and callback of the IntegrationsBundle.
 */
class ConfigSupport extends EasyIntegration implements ConfigFormInterface, ConfigFormAuthInterface, ConfigFormFeatureSettingsInterface, ConfigFormNotesInterface, ConfigFormAuthorizeButtonInterface, ConfigFormCallbackInterface, AuthenticationInterface
{
    use DefaultConfigFormTrait;

    public function __construct(
        private OAuthClient $oauth,
        private UrlGeneratorInterface $router,
        private RequestStack $requestStack,
        private TranslatorInterface $translator
    ) {
    }

    public function getAuthConfigFormName(): string
    {
        return ConfigAuthType::class;
    }

    public function getFeatureSettingsConfigFormName(): string
    {
        return ConfigFeaturesType::class;
    }

    public function getAuthorizationNote(): ?Note
    {
        $note = $this->translator->trans('mautic.integration.easy.note.credits');
        $note .= ' '.$this->translator->trans($this->isAuthorized() ? 'mautic.integration.easy.note.oauth_connected' : 'mautic.integration.easy.note.oauth_how');

        return new Note($note, Note::TYPE_INFO);
    }

    public function getFeaturesNote(): ?Note
    {
        return null;
    }

    public function getFieldMappingNote(): ?Note
    {
        return null;
    }

    public function isAuthorized(): bool
    {
        return $this->hasIntegrationConfiguration()
            && $this->oauth->isConnected((array) $this->getIntegrationConfiguration()->getApiKeys());
    }

    public function isAuthenticated(): bool
    {
        return $this->isAuthorized();
    }

    /**
     * Mautic asks for this address every time the settings are saved; it only opens it after the Authorize button.
     * Registering and creating a PKCE request happens for that button only.
     */
    public function getAuthorizationUrl(): string
    {
        $request = $this->requestStack->getCurrentRequest();
        $details = $request ? ($request->request->all()['integration_details'] ?? []) : [];
        if (!$request || !is_array($details) || empty($details['in_auth'])) {
            return '';
        }

        try {
            return $this->oauth->getAuthorizationUrl($this->getRedirectUri());
        } catch (OAuthException $e) {
            $session = $request->hasSession() ? $request->getSession() : null;
            if ($session instanceof Session) {
                $session->getFlashBag()->add('error', $e->getMessage());
            }

            return '';
        }
    }

    public function getRedirectUri(): string
    {
        // the plugin's own callback page (see OAuthCallbackController), not the IntegrationsBundle one
        return $this->router->generate('mautic_easy_oauth_callback', [], UrlGeneratorInterface::ABSOLUTE_URL);
    }

    public function getCallbackHelpMessageTranslationKey(): string
    {
        return 'mautic.integration.easy.callback_help';
    }

    /**
     * The browser comes back from the consent page.
     *
     * @throws UnauthorizedException
     */
    public function authenticateIntegration(Request $request): string
    {
        try {
            $this->oauth->handleCallback($request->query->all());
        } catch (OAuthException $e) {
            throw new UnauthorizedException($e->getMessage());
        }

        return $this->translator->trans('mautic.integration.easy.oauth_connected');
    }
}
