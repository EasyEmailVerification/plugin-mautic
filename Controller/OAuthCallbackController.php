<?php

declare(strict_types=1);

namespace MauticPlugin\MauticEasyBundle\Controller;

use Mautic\IntegrationsBundle\Exception\UnauthorizedException;
use Mautic\IntegrationsBundle\Integration\Interfaces\AuthenticationInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

/**
 * Where the browser comes back after "Authorize". The IntegrationsBundle has a callback route of its own, but its
 * result page fails on Mautic 7 (its layout expects a "preHeader" variable the controller does not pass), so the
 * plugin answers with a page of its own that renders on every Mautic version.
 */
class OAuthCallbackController
{
    public function __construct(
        private AuthenticationInterface $integration,
        private Environment $twig
    ) {
    }

    public function callbackAction(Request $request): Response
    {
        try {
            $message = $this->integration->authenticateIntegration($request);
            $error   = false;
        } catch (UnauthorizedException $e) {
            $message = $e->getMessage();
            $error   = true;
        }

        $response = new Response($this->twig->render('@MauticEasy/OAuth/callback.html.twig', [
            'message' => $message,
            'error'   => $error,
        ]), $error ? Response::HTTP_BAD_REQUEST : Response::HTTP_OK);
        // the address holds a one-time code: keep it out of caches and referrers
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }
}
