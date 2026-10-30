<?php

declare(strict_types=1);

namespace MauticPlugin\MauticEasyBundle\Tests\Unit\Controller;

use Mautic\IntegrationsBundle\Exception\UnauthorizedException;
use Mautic\IntegrationsBundle\Integration\Interfaces\AuthenticationInterface;
use MauticPlugin\MauticEasyBundle\Controller\OAuthCallbackController;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;

/**
 * Renders the plugin's real callback template, without Mautic's layout.
 */
class OAuthCallbackControllerTest extends TestCase
{
    public function testConnectedPage(): void
    {
        $integration = $this->integration();
        $integration->method('authenticateIntegration')->willReturn('Mautic is connected to Easy Email Verification.');

        $response = $this->controller($integration)->callbackAction(Request::create('/easy/callback?code=c&state=s'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('class="success">Mautic is connected to Easy Email Verification.', (string) $response->getContent());
        $this->assertStringContainsString('window.close()', (string) $response->getContent());
        $this->assertSame('no-store, private', $response->headers->get('Cache-Control'));
        $this->assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
    }

    public function testRefusedPage(): void
    {
        $integration = $this->integration();
        $integration->method('authenticateIntegration')->willThrowException(new UnauthorizedException('Authorization was denied.'));

        $response = $this->controller($integration)->callbackAction(Request::create('/easy/callback?error=access_denied'));

        $this->assertSame(400, $response->getStatusCode());
        $this->assertStringContainsString('class="error">Authorization was denied.', (string) $response->getContent());
    }

    public function testMessageIsEscaped(): void
    {
        $integration = $this->integration();
        $integration->method('authenticateIntegration')->willThrowException(new UnauthorizedException('<script>x</script>'));

        $content = (string) $this->controller($integration)->callbackAction(Request::create('/easy/callback'))->getContent();

        $this->assertStringNotContainsString('<script>x</script>', $content);
        $this->assertStringContainsString('&lt;script&gt;', $content);
    }

    /**
     * @return AuthenticationInterface&MockObject
     */
    private function integration()
    {
        return $this->createMock(AuthenticationInterface::class);
    }

    private function controller(AuthenticationInterface $integration): OAuthCallbackController
    {
        $loader = new FilesystemLoader();
        $loader->addPath(__DIR__.'/../../../Resources/views', 'MauticEasy');
        $twig = new Environment($loader, ['strict_variables' => true, 'autoescape' => 'html']);
        $twig->addFilter(new TwigFilter('trans', fn (string $key): string => $key));

        return new OAuthCallbackController($integration, $twig);
    }
}
