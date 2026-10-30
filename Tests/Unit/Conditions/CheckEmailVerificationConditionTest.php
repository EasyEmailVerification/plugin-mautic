<?php

declare(strict_types=1);

namespace MauticPlugin\MauticEasyBundle\Tests\Unit\Conditions;

use Doctrine\ORM\EntityManagerInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Mautic\CampaignBundle\Entity\Campaign;
use Mautic\CampaignBundle\Entity\Event;
use Mautic\CampaignBundle\Entity\LeadEventLog;
use Mautic\CampaignBundle\Event\ConditionEvent;
use Mautic\CampaignBundle\EventCollector\Accessor\Event\ConditionAccessor;
use Mautic\CoreBundle\Helper\EncryptionHelper;
use Mautic\IntegrationsBundle\Exception\IntegrationNotFoundException;
use Mautic\IntegrationsBundle\Facade\EncryptionService;
use Mautic\IntegrationsBundle\Helper\IntegrationsHelper;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Entity\LeadField;
use Mautic\LeadBundle\Model\FieldModel;
use Mautic\LeadBundle\Model\LeadModel;
use Mautic\PluginBundle\Entity\Integration;
use MauticPlugin\MauticEasyBundle\Api\VerificationClient;
use MauticPlugin\MauticEasyBundle\Api\Verifier;
use MauticPlugin\MauticEasyBundle\Auth\OAuthClient;
use MauticPlugin\MauticEasyBundle\Conditions\CheckEmailVerificationCondition;
use MauticPlugin\MauticEasyBundle\Integration\Config;
use MauticPlugin\MauticEasyBundle\Integration\EasyIntegration;
use MauticPlugin\MauticEasyBundle\Tests\Unit\ArrayLogger;
use MauticPlugin\MauticEasyBundle\Verification\ResultWriter;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

/**
 * Ported from 1.x: the same cases and assertions. Only the construction of the condition changed. The default
 * feature settings are the ones 1.x saved, so these tests also cover an upgrade from 1.x.
 */
class CheckEmailVerificationConditionTest extends TestCase
{
    private const SAFE_TO_SEND_MODE = ['safe_to_send' => 1, 'result' => 0];
    private const RESULT_MODE       = ['safe_to_send' => 0, 'result' => 1];

    // Settings saved by 2.x
    private const SAFE_TO_SEND_DECISION = ['integration' => ['decision' => 'safe_to_send']];
    private const RESULT_DECISION       = ['integration' => ['decision' => 'result']];

    private const OAUTH_KEYS = [
        'auth_method'         => 'oauth',
        'oauth_client_id'     => 'eev_dcr_test',
        'oauth_access_token'  => 'eev_at_test',
        'oauth_refresh_token' => 'eev_rt_test',
    ];

    /**
     * Requests sent, filled by Guzzle's history middleware.
     *
     * @var \ArrayObject<int, array<mixed>>
     */
    private \ArrayObject $history;

    /**
     * @var LeadModel&MockObject
     */
    private $leadModel;

    /**
     * @var FieldModel&MockObject
     */
    private $fieldModel;

    private ArrayLogger $logger;

    protected function setUp(): void
    {
        $this->history    = new \ArrayObject();
        $this->logger     = new ArrayLogger();
        $this->leadModel  = $this->createMock(LeadModel::class);
        $this->fieldModel = $this->createMock(FieldModel::class);
        $this->fieldModel->method('getEntityByAlias')->willReturn(new LeadField());
    }

    public function testValidAddressPasses(): void
    {
        $event = $this->evaluate($this->contact('valid@example.com'), [$this->apiAnswer('valid', true)]);

        $this->assertTrue($event->wasConditionSatisfied());
    }

    public function testInvalidAddressFails(): void
    {
        $event = $this->evaluate($this->contact('invalid@example.com'), [$this->apiAnswer('invalid', false)]);

        $this->assertFalse($event->wasConditionSatisfied());
    }

    /**
     * "unknown" is not a reliable answer: it fails in both decision modes.
     *
     * @dataProvider modes
     *
     * @param array<string, mixed> $features
     */
    public function testUnknownResultFails(array $features): void
    {
        $event = $this->evaluate($this->contact('unknown@example.com'), [$this->apiAnswer('unknown', false)], $features);

        $this->assertFalse($event->wasConditionSatisfied());
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public function modes(): array
    {
        return [
            'safe to send mode (1.x settings)' => [self::SAFE_TO_SEND_MODE],
            'result mode (1.x settings)'       => [self::RESULT_MODE],
            'safe to send mode'                => [self::SAFE_TO_SEND_DECISION],
            'result mode'                      => [self::RESULT_DECISION],
        ];
    }

    public function testTimeoutFailsAndKeepsTheContactFields(): void
    {
        $lead = $this->contact('valid@example.com');
        $this->leadModel->expects($this->never())->method('saveEntity');

        $event = $this->evaluate($lead, [new ConnectException('timeout', new Request('GET', 'https://api.test'))]);

        $this->assertFalse($event->wasConditionSatisfied());
        $this->assertSame([], $lead->getUpdatedFields());
        $this->assertTrue($this->logger->has('error', 'request failed'));
    }

    public function testApiErrorFails(): void
    {
        $lead = $this->contact('valid@example.com');
        $this->leadModel->expects($this->never())->method('saveEntity');

        $event = $this->evaluate($lead, [$this->apiError(401, 'Server can not verify your authentication to use API.')]);

        $this->assertFalse($event->wasConditionSatisfied());
        $this->assertSame([], $lead->getUpdatedFields());
        $this->assertTrue($this->logger->has('error', 'API error (HTTP 401)'));
    }

    public function testNoCreditsFails(): void
    {
        $lead = $this->contact('valid@example.com');
        $this->leadModel->expects($this->never())->method('saveEntity');

        $event = $this->evaluate($lead, [$this->apiError(402, 'Running out of your credit limit')]);

        $this->assertFalse($event->wasConditionSatisfied());
        $this->assertSame([], $lead->getUpdatedFields());
        $this->assertTrue($this->logger->has('error', 'API error (HTTP 402)'));
    }

    public function testResponseThatIsNotJsonFails(): void
    {
        $event = $this->evaluate($this->contact('valid@example.com'), [new Response(502, [], '<html>Bad gateway</html>')]);

        $this->assertFalse($event->wasConditionSatisfied());
    }

    /**
     * @dataProvider resultModes
     *
     * @param array<string, mixed> $features
     */
    public function testAcceptAllCountsAsValidInResultMode(array $features): void
    {
        $answer = $this->apiAnswer('valid', false, ['accept_all' => true, 'reason' => 'low_quality']);
        $event  = $this->evaluate($this->contact('anyone@catch-all.example'), [$answer], $features);

        $this->assertTrue($event->wasConditionSatisfied());
    }

    /**
     * @dataProvider safeToSendModes
     *
     * @param array<string, mixed> $features
     */
    public function testAcceptAllFailsInSafeToSendMode(array $features): void
    {
        $answer = $this->apiAnswer('valid', false, ['accept_all' => true, 'reason' => 'low_quality']);
        $event  = $this->evaluate($this->contact('anyone@catch-all.example'), [$answer], $features);

        $this->assertFalse($event->wasConditionSatisfied());
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public function resultModes(): array
    {
        return ['1.x settings' => [self::RESULT_MODE], '2.x settings' => [self::RESULT_DECISION]];
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public function safeToSendModes(): array
    {
        return ['1.x settings' => [self::SAFE_TO_SEND_MODE], '2.x settings' => [self::SAFE_TO_SEND_DECISION]];
    }

    public function testSafeToSendModeUsesSafeToSend(): void
    {
        $disposable = $this->apiAnswer('valid', false, ['disposable' => true]);

        $this->assertFalse($this->evaluate($this->contact('a@disposable.example'), [$disposable], self::SAFE_TO_SEND_MODE)->wasConditionSatisfied());
        $this->assertTrue($this->evaluate($this->contact('a@disposable.example'), [$disposable], self::RESULT_MODE)->wasConditionSatisfied());
        $this->assertFalse($this->evaluate($this->contact('a@disposable.example'), [$disposable], self::SAFE_TO_SEND_DECISION)->wasConditionSatisfied());
        $this->assertTrue($this->evaluate($this->contact('a@disposable.example'), [$disposable], self::RESULT_DECISION)->wasConditionSatisfied());
    }

    public function testUnsavedSettingsUseSafeToSend(): void
    {
        $disposable = $this->apiAnswer('valid', false, ['disposable' => true]);

        $event = $this->evaluate($this->contact('a@disposable.example'), [$disposable], null);

        $this->assertFalse($event->wasConditionSatisfied());
    }

    public function testEevFieldsAreFilled(): void
    {
        $lead = $this->contact('valid@example.com');
        $this->leadModel->expects($this->once())->method('saveEntity')->with($lead);

        $this->evaluate($lead, [$this->apiAnswer('valid', true, ['domain' => 'example.com'])]);

        $updated = $lead->getUpdatedFields();
        $this->assertSame('valid', $updated['eev_result']);
        $this->assertSame('true', $updated['eev_safe_to_send']);
        $this->assertSame('false', $updated['eev_disposable']);
        $this->assertSame('example.com', $updated['eev_domain']);
        $this->assertSame('accepted_email', $updated['eev_reason']);
        $this->assertSame('true', $updated['eev_success']);
        $this->assertSame('200', $updated['eev_http_code']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', $updated['eev_validate_time']);
    }

    public function testRecentlyVerifiedContactIsNotVerifiedAgain(): void
    {
        $lead = $this->contact('valid@example.com', $this->storedResult('valid', 'true', 'example.com', '-1 day'));
        $this->leadModel->expects($this->never())->method('saveEntity');

        // no queued response: a request would fail the test
        $event = $this->evaluate($lead, []);

        $this->assertTrue($event->wasConditionSatisfied());
        $this->assertCount(0, $this->history);
    }

    public function testRecentlyVerifiedInvalidContactStillFails(): void
    {
        $lead  = $this->contact('invalid@example.com', $this->storedResult('invalid', 'false', 'example.com', '-1 day'));
        $event = $this->evaluate($lead, []);

        $this->assertFalse($event->wasConditionSatisfied());
        $this->assertCount(0, $this->history);
    }

    public function testOldVerificationIsRenewed(): void
    {
        $lead  = $this->contact('valid@example.com', $this->storedResult('invalid', 'false', 'example.com', '-31 days'));
        $event = $this->evaluate($lead, [$this->apiAnswer('valid', true)]);

        $this->assertTrue($event->wasConditionSatisfied());
        $this->assertCount(1, $this->history);
    }

    public function testVerificationOfAnotherDomainIsNotReused(): void
    {
        $lead  = $this->contact('new@another.example', $this->storedResult('invalid', 'false', 'example.com', '-1 day'));
        $event = $this->evaluate($lead, [$this->apiAnswer('valid', true)]);

        $this->assertTrue($event->wasConditionSatisfied());
        $this->assertCount(1, $this->history);
    }

    public function testContactWithoutEmailFailsWithoutCallingTheApi(): void
    {
        $event = $this->evaluate(new Lead(), []);

        $this->assertFalse($event->wasConditionSatisfied());
        $this->assertCount(0, $this->history);
    }

    public function testMissingApiKeyFailsWithoutCallingTheApi(): void
    {
        $event = $this->evaluate($this->contact('valid@example.com'), [], self::SAFE_TO_SEND_MODE, []);

        $this->assertFalse($event->wasConditionSatisfied());
        $this->assertCount(0, $this->history);
        $this->assertTrue($this->logger->has('error', 'API key is missing'));
    }

    public function testPluginNotInstalledFailsWithoutCallingTheApi(): void
    {
        $integrationsHelper = $this->createMock(IntegrationsHelper::class);
        $integrationsHelper->method('getIntegration')->willThrowException(new IntegrationNotFoundException());

        $event = $this->conditionEvent($this->contact('valid@example.com'));
        $this->condition([], $integrationsHelper)->onConditionValidate($event);

        $this->assertFalse($event->wasConditionSatisfied());
        $this->assertCount(0, $this->history);
        $this->assertTrue($this->logger->has('error', 'not installed'));
    }

    public function testApiKeyIsSentInHeaderNotInUrl(): void
    {
        $this->evaluate($this->contact('valid@example.com'), [$this->apiAnswer('valid', true)]);

        $request = $this->sent(0);
        $this->assertSame('test-api-key', $request->getHeaderLine('X-API-Key'));
        $this->assertStringNotContainsString('test-api-key', (string) $request->getUri());
        $this->assertSame('https://api.easyemailverification.com/v1/verify?email=valid%40example.com', (string) $request->getUri());
        $this->assertSame('', $request->getHeaderLine('Authorization'));
    }

    public function testFieldsAreCreatedOncePerProcess(): void
    {
        $fieldModel = $this->createMock(FieldModel::class);
        $fieldModel->method('getEntityByAlias')->willReturn(null);
        $fieldModel->expects($this->exactly(15))->method('saveEntity');
        $this->fieldModel = $fieldModel;

        $condition = $this->condition([$this->apiAnswer('valid', true), $this->apiAnswer('valid', true)]);
        $condition->onConditionValidate($this->conditionEvent($this->contact('a@example.com')));
        $condition->onConditionValidate($this->conditionEvent($this->contact('b@example.com')));
    }

    public function testExistingFieldsAreNotCreated(): void
    {
        $this->fieldModel->expects($this->never())->method('saveEntity');

        $this->evaluate($this->contact('valid@example.com'), [$this->apiAnswer('valid', true)]);
    }

    public function testListensOnlyToItsOwnEvent(): void
    {
        $this->assertSame(
            [CheckEmailVerificationCondition::ON_EVALUATE => ['onConditionValidate', 0]],
            CheckEmailVerificationCondition::getSubscribedEvents()
        );
    }

    public function testLogsHaveNoContactDataOrCredentials(): void
    {
        $this->evaluate($this->contact('secret.person@example.com'), [$this->apiError(401, 'Server can not verify your authentication to use API.')]);
        $this->evaluate($this->contact('secret.person@example.com'), [new ConnectException('cURL error for https://api.test/?email=secret.person%40example.com', new Request('GET', 'https://api.test'))]);

        $this->assertNotEmpty($this->logger->records);
        foreach ($this->logger->records as [, $message]) {
            $this->assertStringNotContainsString('secret.person', $message);
            $this->assertStringNotContainsString('test-api-key', $message);
        }
    }

    // ------------------------------------------------------------------ OAuth

    public function testOAuthVerifiesThroughTheMcpEndpointWithBearer(): void
    {
        $lead  = $this->contact('valid@example.com');
        $event = $this->evaluate($lead, [$this->mcpAnswer('valid', true)], self::SAFE_TO_SEND_DECISION, self::OAUTH_KEYS);

        $this->assertTrue($event->wasConditionSatisfied());
        $request = $this->sent(0);
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('https://mcp.easyemailverification.com/mcp', (string) $request->getUri());
        $this->assertSame('Bearer eev_at_test', $request->getHeaderLine('Authorization'));
        $this->assertSame('', $request->getHeaderLine('X-API-Key'));
        $this->assertSame(
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'verify_email', 'arguments' => ['email' => 'valid@example.com']]],
            json_decode((string) $request->getBody(), true)
        );
        $this->assertSame('valid', $lead->getUpdatedFields()['eev_result']);
    }

    /**
     * @dataProvider modes
     *
     * @param array<string, mixed> $features
     */
    public function testOAuthUsesTheSameDecision(array $features): void
    {
        $catchAll = $this->mcpAnswer('valid', false, ['accept_all' => true]);
        $event    = $this->evaluate($this->contact('anyone@catch-all.example'), [$catchAll], $features, self::OAUTH_KEYS);

        $this->assertSame(in_array($features, [self::RESULT_MODE, self::RESULT_DECISION], true), $event->wasConditionSatisfied());
    }

    public function testOAuthRevokedTokenFailsAndKeepsTheContactFields(): void
    {
        $lead = $this->contact('valid@example.com');
        $this->leadModel->expects($this->never())->method('saveEntity');

        $unauthorized = new Response(401, ['Content-Type' => 'application/json'], (string) json_encode([
            'jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32001, 'message' => 'Invalid or expired access token.'],
        ]));
        $event = $this->evaluate($lead, [$unauthorized], self::SAFE_TO_SEND_DECISION, self::OAUTH_KEYS);

        $this->assertFalse($event->wasConditionSatisfied());
        $this->assertSame([], $lead->getUpdatedFields());
        $this->assertTrue($this->logger->has('error', 'access token was rejected'));
    }

    public function testOAuthToolErrorFails(): void
    {
        $noCredits = $this->mcpToolError('No Easy Email Verification credits left. Buy credits or upgrade the plan.');
        $event     = $this->evaluate($this->contact('valid@example.com'), [$noCredits], self::SAFE_TO_SEND_DECISION, self::OAUTH_KEYS);

        $this->assertFalse($event->wasConditionSatisfied());
        $this->assertTrue($this->logger->has('error', 'No Easy Email Verification credits left'));
    }

    public function testOAuthNotConnectedFailsWithoutCallingTheApi(): void
    {
        $event = $this->evaluate($this->contact('valid@example.com'), [], self::SAFE_TO_SEND_DECISION, ['auth_method' => 'oauth', 'apikey' => 'test-api-key']);

        $this->assertFalse($event->wasConditionSatisfied());
        $this->assertCount(0, $this->history);
        $this->assertTrue($this->logger->has('error', 'not connected with OAuth'));
    }

    public function testOAuthContactWithoutEmailFailsWithoutCallingTheApi(): void
    {
        $event = $this->evaluate(new Lead(), [], self::SAFE_TO_SEND_DECISION, self::OAUTH_KEYS);

        $this->assertFalse($event->wasConditionSatisfied());
        $this->assertCount(0, $this->history);
    }

    // ------------------------------------------------------------------ helpers

    /**
     * @param array<int, mixed>         $responses responses or exceptions, in order
     * @param array<string, mixed>|null $features  feature settings; null = never saved
     * @param array<string, string>     $keys      saved keys
     */
    private function evaluate(Lead $lead, array $responses, ?array $features = self::SAFE_TO_SEND_MODE, array $keys = ['apikey' => 'test-api-key']): ConditionEvent
    {
        $event = $this->conditionEvent($lead);
        $this->condition($responses, null, $features, $keys)->onConditionValidate($event);

        return $event;
    }

    /**
     * @param array<int, mixed>         $responses
     * @param array<string, mixed>|null $features
     * @param array<string, string>     $keys
     */
    private function condition(array $responses, ?IntegrationsHelper $integrationsHelper = null, ?array $features = self::SAFE_TO_SEND_MODE, array $keys = ['apikey' => 'test-api-key']): CheckEmailVerificationCondition
    {
        if (!$integrationsHelper) {
            $settings = new Integration();
            $settings->setName(EasyIntegration::NAME);
            $settings->setFeatureSettings($features ?? []);
            $settings->setApiKeys($keys);

            $integration = new EasyIntegration();
            $integration->setIntegrationConfiguration($settings);

            $integrationsHelper = $this->createMock(IntegrationsHelper::class);
            $integrationsHelper->method('getIntegration')->with(EasyIntegration::NAME)->willReturn($integration);
        }

        $config = new Config(
            $integrationsHelper,
            $this->createMock(EntityManagerInterface::class),
            new EncryptionService($this->createMock(EncryptionHelper::class))
        );

        $stack = HandlerStack::create(new MockHandler($responses));
        // the same object: the middleware appends to it
        $history = $this->history;
        $stack->push(Middleware::history($history));
        $httpClient = new Client(['handler' => $stack]);

        // The token is valid: no refresh, so no request to the authorization server
        $oauth = $this->createMock(OAuthClient::class);
        $oauth->method('isConnected')->willReturnCallback(fn (array $keys): bool => !empty($keys['oauth_refresh_token']));
        $oauth->method('getAccessToken')->willReturn($keys['oauth_access_token'] ?? null);

        $verifier = new Verifier($config, new VerificationClient($httpClient, $this->logger), $oauth, $this->logger);

        return new CheckEmailVerificationCondition($config, $verifier, new ResultWriter($this->leadModel, $this->fieldModel, $this->logger), $this->logger);
    }

    private function conditionEvent(Lead $lead): ConditionEvent
    {
        $campaignEvent = new Event();
        $campaignEvent->setCampaign(new Campaign());

        $log = new LeadEventLog();
        $log->setLead($lead);
        $log->setEvent($campaignEvent);

        return new ConditionEvent(new ConditionAccessor(['eventName' => CheckEmailVerificationCondition::ON_EVALUATE]), $log);
    }

    /**
     * @param array<string, string> $stored stored eev_ field values
     */
    private function contact(string $email, array $stored = []): Lead
    {
        $lead = new Lead();
        $lead->setEmail($email);

        $core = ['email' => ['alias' => 'email', 'type' => 'email', 'value' => $email]];
        foreach ($stored as $alias => $value) {
            $core[$alias] = ['alias' => $alias, 'type' => 'text', 'value' => $value];
        }
        $lead->setFields(['core' => $core]);

        return $lead;
    }

    /**
     * @return array<string, string>
     */
    private function storedResult(string $result, string $safeToSend, string $domain, string $age): array
    {
        return [
            'eev_result'        => $result,
            'eev_safe_to_send'  => $safeToSend,
            'eev_domain'        => $domain,
            'eev_validate_time' => date('Y-m-d H:i:s', (int) strtotime($age)),
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function apiBody(string $result, bool $safeToSend, array $overrides = []): array
    {
        return $overrides + [
            'email'        => 'someone@example.com',
            'result'       => $result,
            'reason'       => 'accepted_email',
            'disposable'   => false,
            'accept_all'   => false,
            'role'         => false,
            'free'         => false,
            'domain'       => 'example.com',
            'mx_record'    => 'mx.example.com',
            'mx_domain'    => 'example.com',
            'safe_to_send' => $safeToSend,
            'did_you_mean' => '',
            'success'      => true,
            'message'      => '',
            'http_code'    => '200',
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function apiAnswer(string $result, bool $safeToSend, array $overrides = []): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode($this->apiBody($result, $safeToSend, $overrides)));
    }

    private function apiError(int $status, string $message): Response
    {
        return new Response($status, [], (string) json_encode([
            'result'       => null,
            'safe_to_send' => false,
            'success'      => false,
            'message'      => $message,
            'http_code'    => (string) $status,
        ]));
    }

    /**
     * The MCP answer of verify_email: the API answer as structured content.
     *
     * @param array<string, mixed> $overrides
     */
    private function mcpAnswer(string $result, bool $safeToSend, array $overrides = []): Response
    {
        $body = $this->apiBody($result, $safeToSend, $overrides);

        return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
            'jsonrpc' => '2.0',
            'id'      => 1,
            'result'  => [
                'content'           => [['type' => 'text', 'text' => 'summary'], ['type' => 'text', 'text' => json_encode($body)]],
                'structuredContent' => $body,
            ],
        ]));
    }

    private function mcpToolError(string $text): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
            'jsonrpc' => '2.0',
            'id'      => 1,
            'result'  => ['isError' => true, 'content' => [['type' => 'text', 'text' => $text]]],
        ]));
    }

    private function sent(int $index): RequestInterface
    {
        $request = $this->history[$index]['request'] ?? null;
        $this->assertInstanceOf(RequestInterface::class, $request);

        return $request;
    }
}
