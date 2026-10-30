<?php

declare(strict_types=1);

namespace MauticPlugin\MauticEasyBundle\Tests\Unit\Auth;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use MauticPlugin\MauticEasyBundle\Auth\OAuthClient;
use MauticPlugin\MauticEasyBundle\Auth\OAuthException;
use MauticPlugin\MauticEasyBundle\Auth\RefreshLock;
use MauticPlugin\MauticEasyBundle\Integration\Config;
use MauticPlugin\MauticEasyBundle\Tests\Unit\ArrayLogger;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

class OAuthClientTest extends TestCase
{
    private const REDIRECT = 'https://mautic.example.com/easy/callback';

    /**
     * Requests sent, filled by Guzzle's history middleware.
     *
     * @var \ArrayObject<int, array<mixed>>
     */
    private \ArrayObject $history;

    /**
     * The keys "in the database".
     *
     * @var array<string, mixed>
     */
    private array $stored = [];

    private int $saves = 0;

    /**
     * @var RefreshLock&MockObject
     */
    private $lock;

    private ArrayLogger $logger;

    protected function setUp(): void
    {
        $this->history = new \ArrayObject();
        $this->stored  = [];
        $this->saves   = 0;
        $this->logger  = new ArrayLogger();
        $this->lock    = $this->createMock(RefreshLock::class);
        $this->lock->method('acquire')->willReturn(true);
    }

    // ------------------------------------------------------------------ authorize

    public function testFirstAuthorizationRegistersThisMauticAndUsesPkce(): void
    {
        $url = $this->client([$this->json(201, ['client_id' => 'eev_dcr_abc'])])->getAuthorizationUrl(self::REDIRECT);

        $register = $this->sent(0);
        $this->assertSame('https://mcp.easyemailverification.com/oauth/register', (string) $register->getUri());
        $this->assertSame([
            'client_name'                => 'Mautic (mautic.example.com)',
            'redirect_uris'              => [self::REDIRECT],
            'grant_types'                => ['authorization_code', 'refresh_token'],
            'response_types'             => ['code'],
            'token_endpoint_auth_method' => 'none',
            'scope'                      => 'verify',
        ], json_decode((string) $register->getBody(), true));

        $this->assertStringStartsWith('https://mcp.easyemailverification.com/oauth/authorize?', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame('code', $query['response_type']);
        $this->assertSame('eev_dcr_abc', $query['client_id']);
        $this->assertSame(self::REDIRECT, $query['redirect_uri']);
        $this->assertSame('verify', $query['scope']);
        $this->assertSame('https://mcp.easyemailverification.com/mcp', $query['resource']);
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertSame($this->stored['oauth_state'], $query['state']);

        // S256: the challenge is the base64url SHA-256 of the stored verifier
        $verifier = $this->stored['oauth_verifier'];
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $verifier);
        $this->assertSame(rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='), $query['code_challenge']);

        $this->assertSame('eev_dcr_abc', $this->stored['oauth_client_id']);
        $this->assertSame(self::REDIRECT, $this->stored['oauth_redirect_uri']);
    }

    public function testRegisteredClientIsReused(): void
    {
        $this->stored = ['oauth_client_id' => 'eev_dcr_abc', 'oauth_redirect_uri' => self::REDIRECT, 'apikey' => 'kept'];

        $url = $this->client([])->getAuthorizationUrl(self::REDIRECT);

        $this->assertCount(0, $this->history);
        $this->assertStringContainsString('client_id=eev_dcr_abc', $url);
        $this->assertSame('kept', $this->stored['apikey']);
    }

    public function testNewMauticAddressRegistersAgain(): void
    {
        $this->stored = ['oauth_client_id' => 'eev_dcr_old', 'oauth_redirect_uri' => 'https://old.example.com/easy/callback'];

        $this->client([$this->json(201, ['client_id' => 'eev_dcr_new'])])->getAuthorizationUrl(self::REDIRECT);

        $this->assertCount(1, $this->history);
        $this->assertSame('eev_dcr_new', $this->stored['oauth_client_id']);
    }

    public function testRegistrationRefusedThrows(): void
    {
        $this->expectException(OAuthException::class);

        try {
            $this->client([$this->json(400, ['error' => 'invalid_redirect_uri'])])->getAuthorizationUrl('http://mautic.example.com/easy/callback');
        } finally {
            $this->assertTrue($this->logger->has('error', 'registration failed (HTTP 400): invalid_redirect_uri'));
            $this->assertArrayNotHasKey('oauth_state', $this->stored);
        }
    }

    // ------------------------------------------------------------------ callback

    public function testCallbackExchangesTheCodeAndStoresTheTokens(): void
    {
        $this->pendingAuthorization();

        $this->client([$this->tokens('eev_at_1', 'eev_rt_1')])->handleCallback([
            'code' => 'eev_code_x', 'state' => 'the-state', 'iss' => 'https://mcp.easyemailverification.com',
        ]);

        $token = $this->sent(0);
        $this->assertSame('https://mcp.easyemailverification.com/oauth/token', (string) $token->getUri());
        parse_str((string) $token->getBody(), $form);
        $this->assertSame([
            'grant_type'    => 'authorization_code',
            'code'          => 'eev_code_x',
            'redirect_uri'  => self::REDIRECT,
            'client_id'     => 'eev_dcr_abc',
            'code_verifier' => 'the-verifier',
            'resource'      => 'https://mcp.easyemailverification.com/mcp',
        ], $form);

        $this->assertSame('eev_at_1', $this->stored['oauth_access_token']);
        $this->assertSame('eev_rt_1', $this->stored['oauth_refresh_token']);
        $this->assertSame('verify', $this->stored['oauth_scope']);
        $this->assertEqualsWithDelta(time() + 3600, (int) $this->stored['oauth_expires_at'], 5);
        $this->assertSame('oauth', $this->stored['auth_method']);
        $this->assertArrayNotHasKey('oauth_state', $this->stored);
        $this->assertArrayNotHasKey('oauth_verifier', $this->stored);
    }

    /**
     * @dataProvider badCallbacks
     *
     * @param array<string, string> $query
     */
    public function testBadCallbackIsRefusedWithoutTokenRequest(array $query, string $message): void
    {
        $this->pendingAuthorization();

        try {
            $this->client([])->handleCallback($query);
            $this->fail('expected an OAuthException');
        } catch (OAuthException $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }

        $this->assertCount(0, $this->history);
        $this->assertArrayNotHasKey('oauth_access_token', $this->stored);
        // the pending request is used once
        $this->assertArrayNotHasKey('oauth_state', $this->stored);
    }

    /**
     * @return array<string, array{array<string, string>, string}>
     */
    public function badCallbacks(): array
    {
        return [
            'denied'        => [['error' => 'access_denied', 'state' => 'the-state'], 'denied'],
            'wrong state'   => [['code' => 'eev_code_x', 'state' => 'forged'], 'unknown or has expired'],
            'no state'      => [['code' => 'eev_code_x'], 'unknown or has expired'],
            'other issuer'  => [['code' => 'eev_code_x', 'state' => 'the-state', 'iss' => 'https://evil.example'], 'unexpected server'],
            'no code'       => [['state' => 'the-state'], 'no code'],
        ];
    }

    public function testExpiredAuthorizationRequestIsRefused(): void
    {
        $this->pendingAuthorization(time() - 601);

        $this->expectExceptionMessage('unknown or has expired');
        $this->client([])->handleCallback(['code' => 'eev_code_x', 'state' => 'the-state']);
    }

    public function testRefusedCodeThrows(): void
    {
        $this->pendingAuthorization();

        try {
            $this->client([$this->json(400, ['error' => 'invalid_grant', 'error_description' => 'PKCE verification failed'])])
                ->handleCallback(['code' => 'eev_code_x', 'state' => 'the-state']);
            $this->fail('expected an OAuthException');
        } catch (OAuthException $e) {
            $this->assertStringContainsString('did not issue a token', $e->getMessage());
        }

        $this->assertArrayNotHasKey('oauth_access_token', $this->stored);
        $this->assertTrue($this->logger->has('error', 'token request failed (HTTP 400): invalid_grant'));
    }

    // ------------------------------------------------------------------ access token and refresh

    public function testValidAccessTokenIsUsedWithoutRequest(): void
    {
        $this->connected('eev_at_1', 'eev_rt_1', time() + 3000);

        $this->assertSame('eev_at_1', $this->client([])->getAccessToken());
        $this->assertCount(0, $this->history);
    }

    public function testExpiredAccessTokenIsRefreshedWithRotation(): void
    {
        $this->connected('eev_at_1', 'eev_rt_1', time() - 10);
        $this->lock->expects($this->once())->method('release');

        $token = $this->client([$this->tokens('eev_at_2', 'eev_rt_2')])->getAccessToken();

        $this->assertSame('eev_at_2', $token);
        parse_str((string) $this->sent(0)->getBody(), $form);
        $this->assertSame([
            'grant_type'    => 'refresh_token',
            'refresh_token' => 'eev_rt_1',
            'client_id'     => 'eev_dcr_abc',
            'resource'      => 'https://mcp.easyemailverification.com/mcp',
        ], $form);
        $this->assertSame('eev_rt_2', $this->stored['oauth_refresh_token']);
        $this->assertSame('eev_at_2', $this->stored['oauth_access_token']);
    }

    public function testTokenRefreshedByAnotherProcessIsNotRefreshedAgain(): void
    {
        $this->connected('eev_at_1', 'eev_rt_1', time() - 10);
        // what the database holds once the lock is acquired
        $refreshed = ['oauth_access_token' => 'eev_at_2', 'oauth_refresh_token' => 'eev_rt_2', 'oauth_expires_at' => (string) (time() + 3600)] + $this->stored;

        $config = $this->config();
        $config->method('reloadApiKeys')->willReturn($refreshed);

        $client = new OAuthClient(new Client(['handler' => HandlerStack::create(new MockHandler([]))]), $config, $this->lock, $this->logger);

        $this->assertSame('eev_at_2', $client->getAccessToken());
    }

    public function testRevokedOrExpiredGrantDisconnects(): void
    {
        $this->connected('eev_at_1', 'eev_rt_1', time() - 10);
        $this->stored['apikey'] = 'kept';

        $token = $this->client([$this->json(400, ['error' => 'invalid_grant'])])->getAccessToken();

        $this->assertNull($token);
        $this->assertArrayNotHasKey('oauth_refresh_token', $this->stored);
        $this->assertArrayNotHasKey('oauth_access_token', $this->stored);
        $this->assertSame('kept', $this->stored['apikey']);
        $this->assertTrue($this->logger->has('error', 'revoked or has expired'));
    }

    public function testRefreshNetworkErrorKeepsTheTokens(): void
    {
        $this->connected('eev_at_1', 'eev_rt_1', time() - 10);

        $token = $this->client([new ConnectException('timeout', new Request('POST', 'https://mcp.test'))])->getAccessToken();

        $this->assertNull($token);
        $this->assertSame('eev_rt_1', $this->stored['oauth_refresh_token']);
        $this->assertTrue($this->logger->has('error', 'token request failed (GuzzleHttp\Exception\ConnectException)'));
    }

    public function testLockNotAcquiredDoesNotRefresh(): void
    {
        $this->connected('eev_at_1', 'eev_rt_1', time() - 10);
        $lock = $this->createMock(RefreshLock::class);
        $lock->method('acquire')->willReturn(false);
        $lock->expects($this->never())->method('release');
        $this->lock = $lock;

        $this->assertNull($this->client([])->getAccessToken());
        $this->assertCount(0, $this->history);
    }

    public function testNotConnectedHasNoToken(): void
    {
        $this->assertNull($this->client([])->getAccessToken());
        $this->assertTrue($this->logger->has('error', 'not connected with OAuth'));
    }

    public function testRejectedTokenIsRefreshed(): void
    {
        $this->connected('eev_at_1', 'eev_rt_1', time() + 3000);
        $client = $this->client([$this->tokens('eev_at_2', 'eev_rt_2')]);

        $client->rejectAccessToken('eev_at_1');

        $this->assertSame('eev_at_2', $client->getAccessToken());
        $this->assertCount(1, $this->history);
    }

    public function testTokensAreNeverLogged(): void
    {
        $this->connected('eev_at_secret', 'eev_rt_secret', time() - 10);
        $this->client([$this->json(400, ['error' => 'invalid_grant', 'error_description' => 'token eev_rt_secret reused'])])->getAccessToken();

        foreach ($this->logger->records as [, $message]) {
            $this->assertStringNotContainsString('secret', $message);
        }
    }

    // ------------------------------------------------------------------ disconnect

    public function testDisconnectRevokesTheRefreshTokenAndForgetsTheTokens(): void
    {
        $this->connected('eev_at_1', 'eev_rt_1', time() + 3000);

        $keys = $this->client([new Response(200)])->disconnect($this->stored + ['apikey' => 'kept']);

        $revoke = $this->sent(0);
        $this->assertSame('https://mcp.easyemailverification.com/oauth/revoke', (string) $revoke->getUri());
        parse_str((string) $revoke->getBody(), $form);
        $this->assertSame(['token' => 'eev_rt_1', 'token_type_hint' => 'refresh_token', 'client_id' => 'eev_dcr_abc'], $form);
        $this->assertSame(['oauth_client_id' => 'eev_dcr_abc', 'apikey' => 'kept'], $keys);
    }

    public function testDisconnectForgetsTheTokensEvenWhenTheServerCannotBeReached(): void
    {
        $this->connected('eev_at_1', 'eev_rt_1', time() + 3000);

        $keys = $this->client([new ConnectException('down', new Request('POST', 'https://mcp.test'))])->disconnect($this->stored);

        $this->assertFalse($this->client([])->isConnected($keys));
        $this->assertTrue($this->logger->has('warning', 'Connected apps'));
    }

    // ------------------------------------------------------------------ helpers

    /**
     * @param array<int, mixed> $responses
     */
    private function client(array $responses): OAuthClient
    {
        $config = $this->config();
        $config->method('reloadApiKeys')->willReturnCallback(fn (): array => $this->stored);

        $stack = HandlerStack::create(new MockHandler($responses));
        // the same object: the middleware appends to it
        $history = $this->history;
        $stack->push(Middleware::history($history));

        return new OAuthClient(new Client(['handler' => $stack]), $config, $this->lock, $this->logger);
    }

    /**
     * @return Config&MockObject
     */
    private function config()
    {
        $config = $this->createMock(Config::class);
        $config->method('getApiKeys')->willReturnCallback(fn (): array => $this->stored);
        $config->method('saveApiKeys')->willReturnCallback(function (array $keys): void {
            $this->stored = $keys;
            ++$this->saves;
        });

        return $config;
    }

    private function pendingAuthorization(?int $requestedAt = null): void
    {
        $this->stored = [
            'oauth_client_id'    => 'eev_dcr_abc',
            'oauth_redirect_uri' => self::REDIRECT,
            'oauth_state'        => 'the-state',
            'oauth_verifier'     => 'the-verifier',
            'oauth_requested_at' => (string) ($requestedAt ?? time()),
        ];
    }

    private function connected(string $access, string $refresh, int $expiresAt): void
    {
        $this->stored = [
            'oauth_client_id'     => 'eev_dcr_abc',
            'oauth_access_token'  => $access,
            'oauth_refresh_token' => $refresh,
            'oauth_expires_at'    => (string) $expiresAt,
        ];
    }

    private function tokens(string $access, string $refresh): Response
    {
        return $this->json(200, ['access_token' => $access, 'token_type' => 'Bearer', 'expires_in' => 3600, 'refresh_token' => $refresh, 'scope' => 'verify']);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function json(int $status, array $body): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], (string) json_encode($body));
    }

    private function sent(int $index): RequestInterface
    {
        $request = $this->history[$index]['request'] ?? null;
        $this->assertInstanceOf(RequestInterface::class, $request);

        return $request;
    }
}
