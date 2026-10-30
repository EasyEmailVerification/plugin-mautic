<?php

declare(strict_types=1);

namespace MauticPlugin\MauticEasyBundle\Auth;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use MauticPlugin\MauticEasyBundle\Integration\Config;
use Psr\Log\LoggerInterface;

/**
 * OAuth 2.0 client of the Easy Email Verification authorization server, the one the MCP connector uses for
 * ChatGPT and Claude: Dynamic Client Registration (RFC 7591) per Mautic instance, authorization code with PKCE S256,
 * the MCP resource (RFC 8707), refresh token rotation and revocation (RFC 7009).
 *
 * Tokens are stored with the plugin's other keys, which Mautic encrypts.
 */
class OAuthClient
{
    public const ISSUER   = 'https://mcp.easyemailverification.com';
    public const RESOURCE = 'https://mcp.easyemailverification.com/mcp';

    /**
     * The narrowest scope that allows verification ("account:read" is not needed).
     */
    public const SCOPE = 'verify';

    /**
     * Keys that hold the connection; removed on disconnect.
     */
    public const TOKEN_KEYS = ['oauth_access_token', 'oauth_refresh_token', 'oauth_expires_at', 'oauth_scope'];

    private const PENDING_KEYS = ['oauth_state', 'oauth_verifier', 'oauth_requested_at'];

    /**
     * Seconds an authorization request stays valid (the server's own codes last 5 minutes).
     */
    private const PENDING_TTL = 600;

    /**
     * An access token this close to its expiry is refreshed first.
     */
    private const EXPIRY_MARGIN = 60;

    /**
     * Access token the API rejected in this process (revoked in the dashboard): not used again.
     */
    private ?string $rejectedToken = null;

    public function __construct(
        private ClientInterface $httpClient,
        private Config $config,
        private RefreshLock $lock,
        private LoggerInterface $logger
    ) {
    }

    /**
     * @param array<string, mixed> $keys
     */
    public function isConnected(array $keys): bool
    {
        return !empty($keys['oauth_refresh_token']) || !empty($keys['oauth_access_token']);
    }

    /**
     * Registers this Mautic when needed and returns the address of the sign-in and consent page.
     *
     * @throws OAuthException
     */
    public function getAuthorizationUrl(string $redirectUri): string
    {
        $verifier = $this->randomToken();
        $state    = $this->randomToken();

        $keys = $this->updateKeys(function (array $keys) use ($redirectUri, $verifier, $state): array {
            // A client is registered for one redirect URI: register again when Mautic's address changed
            if (empty($keys['oauth_client_id']) || ($keys['oauth_redirect_uri'] ?? null) !== $redirectUri) {
                $keys['oauth_client_id']    = $this->register($redirectUri);
                $keys['oauth_redirect_uri'] = $redirectUri;
            }
            $keys['oauth_state']        = $state;
            $keys['oauth_verifier']     = $verifier;
            $keys['oauth_requested_at'] = (string) time();

            return $keys;
        });

        return self::ISSUER.'/oauth/authorize?'.http_build_query([
            'response_type'         => 'code',
            'client_id'             => $keys['oauth_client_id'],
            'redirect_uri'          => $redirectUri,
            'scope'                 => self::SCOPE,
            'state'                 => $state,
            'code_challenge'        => $this->base64Url(hash('sha256', $verifier, true)),
            'code_challenge_method' => 'S256',
            'resource'              => self::RESOURCE,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * The browser came back from the consent page: checks the answer and exchanges the code for tokens.
     *
     * @param array<string, mixed> $query query string of the callback
     *
     * @throws OAuthException
     */
    public function handleCallback(array $query): void
    {
        $this->updateKeys(function (array $keys) use ($query): array {
            $pending = [];
            foreach (self::PENDING_KEYS as $name) {
                $pending[$name] = (string) ($keys[$name] ?? '');
                // an authorization request is answered once, whatever the outcome
                unset($keys[$name]);
            }

            try {
                $this->checkCallback($query, $pending);

                $tokens = $this->tokenRequest([
                    'grant_type'    => 'authorization_code',
                    'code'          => (string) $query['code'],
                    'redirect_uri'  => (string) ($keys['oauth_redirect_uri'] ?? ''),
                    'client_id'     => (string) ($keys['oauth_client_id'] ?? ''),
                    'code_verifier' => $pending['oauth_verifier'],
                    'resource'      => self::RESOURCE,
                ]);
                if (null === $tokens) {
                    throw new OAuthException('Easy Email Verification did not issue a token. Please authorize again.');
                }
            } catch (OAuthException $e) {
                // keep the removal of the pending request
                $this->config->saveApiKeys($keys);

                throw $e;
            }

            $this->logger->info('Easy Email Verification: connected with OAuth.');

            // authorizing selects OAuth as the authentication method
            $keys['auth_method'] = Config::AUTH_OAUTH;

            return $this->withTokens($keys, $tokens);
        });
    }

    /**
     * A valid access token, refreshed when needed; null when there is none (logged).
     */
    public function getAccessToken(): ?string
    {
        $keys = $this->config->getApiKeys();
        if (null !== ($token = $this->freshAccessToken($keys))) {
            return $token;
        }
        if (empty($keys['oauth_refresh_token'])) {
            $this->logger->error('Easy Email Verification: not connected with OAuth. Authorize again in the plugin settings.');

            return null;
        }

        if (!$this->lock->acquire()) {
            $this->logger->error('Easy Email Verification: another process kept the OAuth token refresh locked.');

            return null;
        }

        try {
            // another process may have refreshed the token while this one waited
            $keys = $this->config->reloadApiKeys();
            if (null !== ($token = $this->freshAccessToken($keys))) {
                return $token;
            }
            if (empty($keys['oauth_refresh_token'])) {
                $this->logger->error('Easy Email Verification: not connected with OAuth. Authorize again in the plugin settings.');

                return null;
            }

            $invalidGrant = false;
            $tokens       = $this->tokenRequest([
                'grant_type'    => 'refresh_token',
                'refresh_token' => (string) $keys['oauth_refresh_token'],
                'client_id'     => (string) ($keys['oauth_client_id'] ?? ''),
                'resource'      => self::RESOURCE,
            ], $invalidGrant);

            if (null === $tokens) {
                if ($invalidGrant) {
                    // revoked in the dashboard, expired after 30 days without use, or refreshed twice
                    $this->config->saveApiKeys($this->withoutTokens($keys));
                    $this->logger->error('Easy Email Verification: the OAuth connection was revoked or has expired. Authorize again in the plugin settings.');
                }

                return null;
            }

            $keys = $this->withTokens($keys, $tokens);
            $this->config->saveApiKeys($keys);

            return (string) $keys['oauth_access_token'];
        } finally {
            $this->lock->release();
        }
    }

    /**
     * The API answered 401 to this token: the next call refreshes it, which also tells whether the grant was revoked.
     */
    public function rejectAccessToken(string $token): void
    {
        $this->rejectedToken = $token;
    }

    /**
     * Revokes the refresh token (with the access tokens issued from it) and returns the keys without the connection.
     * The keys are removed even when the server cannot be reached, as the Excel add-in does; the app then stays in
     * the dashboard's Connected apps until the user removes it there.
     *
     * @param array<string, mixed> $keys
     *
     * @return array<string, mixed>
     */
    public function disconnect(array $keys): array
    {
        $token = $keys['oauth_refresh_token'] ?? $keys['oauth_access_token'] ?? null;
        if (is_string($token) && '' !== $token) {
            try {
                $response = $this->httpClient->request('POST', self::ISSUER.'/oauth/revoke', [
                    'form_params' => [
                        'token'           => $token,
                        'token_type_hint' => isset($keys['oauth_refresh_token']) ? 'refresh_token' : 'access_token',
                        'client_id'       => (string) ($keys['oauth_client_id'] ?? ''),
                    ],
                    'headers'         => ['Accept' => 'application/json'],
                    'connect_timeout' => 10,
                    'timeout'         => 30,
                    'http_errors'     => false,
                ]);
                if ($response->getStatusCode() >= 400) {
                    $this->logger->warning(sprintf('Easy Email Verification: revoking the OAuth token failed (HTTP %d). Remove the app in the dashboard under Connected apps.', $response->getStatusCode()));
                }
            } catch (GuzzleException $e) {
                $this->logger->warning('Easy Email Verification: revoking the OAuth token failed ('.get_class($e).'). Remove the app in the dashboard under Connected apps.');
            }
        }

        $this->logger->info('Easy Email Verification: disconnected from OAuth.');

        return $this->withoutTokens($keys);
    }

    /**
     * @param array<string, mixed> $keys
     *
     * @return array<string, mixed>
     */
    public function withoutTokens(array $keys): array
    {
        foreach (array_merge(self::TOKEN_KEYS, self::PENDING_KEYS) as $name) {
            unset($keys[$name]);
        }

        return $keys;
    }

    /**
     * Changes the stored keys under the refresh lock, starting from the database: a token refreshed meanwhile by
     * another process must not be overwritten with the previous one (the server revokes a reused refresh token).
     *
     * @param callable(array<string, mixed>): array<string, mixed> $change
     *
     * @return array<string, mixed> the saved keys
     *
     * @throws OAuthException
     */
    private function updateKeys(callable $change): array
    {
        if (!$this->lock->acquire()) {
            throw new OAuthException('Another process is refreshing the connection. Please try again.');
        }

        try {
            $keys = $change($this->config->reloadApiKeys());
            $this->config->saveApiKeys($keys);

            return $keys;
        } finally {
            $this->lock->release();
        }
    }

    /**
     * @param array<string, mixed>  $query
     * @param array<string, string> $pending
     *
     * @throws OAuthException
     */
    private function checkCallback(array $query, array $pending): void
    {
        if (isset($query['error'])) {
            $this->logger->warning('Easy Email Verification: authorization not granted ('.(is_scalar($query['error']) ? $query['error'] : 'error').').');

            throw new OAuthException('access_denied' === $query['error'] ? 'Authorization was denied.' : 'Authorization failed. Please try again.');
        }

        $state = $query['state'] ?? null;
        if ('' === $pending['oauth_state'] || !is_string($state) || !hash_equals($pending['oauth_state'], $state)
            || time() - (int) $pending['oauth_requested_at'] > self::PENDING_TTL) {
            throw new OAuthException('The authorization request is unknown or has expired. Please authorize again.');
        }

        // RFC 9207: the answer must come from the server the request was sent to
        if (isset($query['iss']) && self::ISSUER !== $query['iss']) {
            throw new OAuthException('The authorization answer came from an unexpected server.');
        }

        if (!is_string($query['code'] ?? null) || '' === $query['code']) {
            throw new OAuthException('The authorization answer has no code. Please authorize again.');
        }
    }

    /**
     * @param array<string, mixed> $keys
     */
    private function freshAccessToken(array $keys): ?string
    {
        $token = $keys['oauth_access_token'] ?? null;
        if (!is_string($token) || '' === $token || $token === $this->rejectedToken) {
            return null;
        }

        return (int) ($keys['oauth_expires_at'] ?? 0) - self::EXPIRY_MARGIN > time() ? $token : null;
    }

    /**
     * @param array<string, mixed> $keys
     * @param array<string, mixed> $tokens token endpoint answer
     *
     * @return array<string, mixed>
     */
    private function withTokens(array $keys, array $tokens): array
    {
        $keys['oauth_access_token'] = (string) $tokens['access_token'];
        // rotation: each refresh returns a new refresh token and invalidates the previous one
        if (is_string($tokens['refresh_token'] ?? null) && '' !== $tokens['refresh_token']) {
            $keys['oauth_refresh_token'] = $tokens['refresh_token'];
        }
        $keys['oauth_expires_at'] = (string) (time() + (int) ($tokens['expires_in'] ?? 3600));
        $keys['oauth_scope']      = (string) ($tokens['scope'] ?? self::SCOPE);

        return $keys;
    }

    /**
     * @throws OAuthException
     */
    private function register(string $redirectUri): string
    {
        $host = (string) parse_url($redirectUri, PHP_URL_HOST);

        try {
            $response = $this->httpClient->request('POST', self::ISSUER.'/oauth/register', [
                'json' => [
                    'client_name'                => 'Mautic ('.$host.')',
                    'redirect_uris'              => [$redirectUri],
                    'grant_types'                => ['authorization_code', 'refresh_token'],
                    'response_types'             => ['code'],
                    'token_endpoint_auth_method' => 'none',
                    'scope'                      => self::SCOPE,
                ],
                'headers'         => ['Accept' => 'application/json'],
                'connect_timeout' => 10,
                'timeout'         => 30,
                'http_errors'     => false,
            ]);
        } catch (GuzzleException $e) {
            $this->logger->error('Easy Email Verification: OAuth client registration failed ('.get_class($e).')');

            throw new OAuthException('Easy Email Verification could not be reached. Please try again.');
        }

        $data = json_decode((string) $response->getBody(), true);
        if ($response->getStatusCode() >= 300 || !is_array($data) || !is_string($data['client_id'] ?? null)) {
            $this->logger->error(sprintf('Easy Email Verification: OAuth client registration failed (HTTP %d): %s', $response->getStatusCode(), $this->errorOf($data)));

            throw new OAuthException('This Mautic could not be registered with Easy Email Verification. Check that it is served over https.');
        }

        return $data['client_id'];
    }

    /**
     * @param array<string, string> $params
     *
     * @return array<string, mixed>|null the token answer, or null (logged)
     */
    private function tokenRequest(array $params, bool &$invalidGrant = false): ?array
    {
        $invalidGrant = false;

        try {
            $response = $this->httpClient->request('POST', self::ISSUER.'/oauth/token', [
                'form_params'     => $params,
                'headers'         => ['Accept' => 'application/json'],
                'connect_timeout' => 10,
                'timeout'         => 30,
                'http_errors'     => false,
            ]);
        } catch (GuzzleException $e) {
            $this->logger->error('Easy Email Verification: OAuth token request failed ('.get_class($e).')');

            return null;
        }

        $data = json_decode((string) $response->getBody(), true);
        if (200 !== $response->getStatusCode() || !is_array($data) || !is_string($data['access_token'] ?? null)) {
            $invalidGrant = is_array($data) && 'invalid_grant' === ($data['error'] ?? null);
            $this->logger->error(sprintf('Easy Email Verification: OAuth token request failed (HTTP %d): %s', $response->getStatusCode(), $this->errorOf($data)));

            return null;
        }

        return $data;
    }

    /**
     * The OAuth error code of an answer, for the log (never the description, which could echo a value).
     */
    private function errorOf(mixed $data): string
    {
        return is_array($data) && is_string($data['error'] ?? null) ? $data['error'] : 'invalid response';
    }

    private function randomToken(): string
    {
        return $this->base64Url(random_bytes(32));
    }

    private function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
