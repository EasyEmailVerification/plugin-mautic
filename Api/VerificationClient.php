<?php

declare(strict_types=1);

namespace MauticPlugin\MauticEasyBundle\Api;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * Verifies one address: with an API key through the REST API (GET /v1/verify), or with an OAuth access token
 * through the MCP endpoint, which accepts those tokens (verify_email tool, same answer as the REST API).
 */
class VerificationClient
{
    public const URL     = 'https://api.easyemailverification.com/v1/verify';
    public const MCP_URL = 'https://mcp.easyemailverification.com/mcp';

    /**
     * Seconds a verification may take (the API checks the mail server); the verification on save passes less.
     */
    public const TIMEOUT = 30;

    private const OPTIONS = [
        'connect_timeout' => 10,
        // API errors come as JSON with success=false and a message; read them instead of an exception
        'http_errors'     => false,
    ];

    /**
     * Why the last verification has no answer, for the Mautic user (no contact data, no credentials).
     */
    private ?string $lastError = null;

    /**
     * HTTP status of the last answer (0: no answer).
     */
    private int $lastStatus = 0;

    public function __construct(
        private ClientInterface $httpClient,
        private LoggerInterface $logger
    ) {
    }

    /**
     * @return array<string, mixed>|null the API answer, or null when there is none: request error, timeout,
     *                                   API error (invalid key, no credits, ...) or a response without a result
     */
    public function verify(string $email, string $apiKey, int $timeout = self::TIMEOUT): ?array
    {
        $response = $this->send('GET', self::URL.'?'.http_build_query(['email' => $email]), [
            // in a header, not in the query string, so the key stays out of URLs, proxy and server logs
            'headers' => ['Accept' => 'application/json', 'X-API-Key' => $apiKey],
            'timeout' => $timeout,
        ]);
        if (!$response) {
            return null;
        }

        $data = json_decode((string) $response->getBody(), true);

        return $this->answer($response->getStatusCode(), $data, is_array($data) ? ($data['message'] ?? null) : null);
    }

    /**
     * @param bool|null $tokenRejected set to true when the token was refused (revoked or expired)
     *
     * @return array<string, mixed>|null as verify()
     */
    public function verifyWithAccessToken(string $email, string $accessToken, ?bool &$tokenRejected = null, int $timeout = self::TIMEOUT): ?array
    {
        $tokenRejected = false;

        $response = $this->send('POST', self::MCP_URL, [
            'headers' => [
                'Accept'        => 'application/json, text/event-stream',
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer '.$accessToken,
            ],
            'timeout' => $timeout,
            'body'    => (string) json_encode([
                'jsonrpc' => '2.0',
                'id'      => 1,
                'method'  => 'tools/call',
                'params'  => ['name' => 'verify_email', 'arguments' => ['email' => $email]],
            ]),
        ]);
        if (!$response) {
            return null;
        }

        $body = json_decode((string) $response->getBody(), true);
        if (401 === $response->getStatusCode()) {
            $tokenRejected = true;
        }

        // The API answer is the tool's structured content; failures are a JSON-RPC error or a tool error (isError)
        $result  = is_array($body) ? ($body['result'] ?? null) : null;
        $message = is_array($body) ? ($body['error']['message'] ?? $result['content'][0]['text'] ?? null) : null;
        $data    = is_array($result) && empty($result['isError']) ? ($result['structuredContent'] ?? null) : null;

        return $this->answer($response->getStatusCode(), $data, $message);
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * The last verification was refused for its credential: API key invalid or deleted, OAuth token revoked or expired.
     */
    public function wasUnauthorized(): bool
    {
        return in_array($this->lastStatus, [401, 403], true);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function send(string $method, string $url, array $options): ?ResponseInterface
    {
        $this->lastError  = null;
        $this->lastStatus = 0;

        try {
            return $this->httpClient->request($method, $url, $options + self::OPTIONS);
        } catch (GuzzleException $e) {
            // Also timeouts and connection errors (ConnectException is not a RequestException in Guzzle 7).
            // Not $e->getMessage(): it can contain the request URL, with the email address.
            $this->lastError = 'request failed ('.(new \ReflectionClass($e))->getShortName().')';
            $this->logger->error('Easy Email Verification: request failed ('.get_class($e).')');

            return null;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function answer(int $status, mixed $data, mixed $message): ?array
    {
        $this->lastStatus = $status;

        if (!is_array($data) || true !== ($data['success'] ?? null) || !is_string($data['result'] ?? null)) {
            $this->lastError = sprintf('API error (HTTP %d): %s', $status, is_scalar($message) && '' !== (string) $message ? rtrim((string) $message, '. ') : 'invalid response');
            $this->logger->error('Easy Email Verification: '.$this->lastError);

            return null;
        }

        return $data;
    }
}
