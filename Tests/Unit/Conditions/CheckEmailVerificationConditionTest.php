<?php

namespace MauticPlugin\MauticEasyBundle\Tests\Unit\Conditions;

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
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Entity\LeadField;
use Mautic\LeadBundle\Model\FieldModel;
use Mautic\LeadBundle\Model\LeadModel;
use Mautic\PluginBundle\Entity\Integration;
use Mautic\PluginBundle\Helper\IntegrationHelper;
use MauticPlugin\MauticEasyBundle\Conditions\CheckEmailVerificationCondition;
use MauticPlugin\MauticEasyBundle\Integration\EasyIntegration;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class CheckEmailVerificationConditionTest extends TestCase
{
    private const SAFE_TO_SEND_MODE = ['safe_to_send' => 1, 'result' => 0];
    private const RESULT_MODE       = ['safe_to_send' => 0, 'result' => 1];

    /**
     * @var array<int, array{request: Request}>
     */
    private $history = [];

    /**
     * @var LeadModel&MockObject
     */
    private $leadModel;

    /**
     * @var FieldModel&MockObject
     */
    private $fieldModel;

    protected function setUp(): void
    {
        $this->history    = [];
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
     * @param array<string, int> $features
     */
    public function testUnknownResultFails(array $features): void
    {
        $event = $this->evaluate($this->contact('unknown@example.com'), [$this->apiAnswer('unknown', false)], $features);

        $this->assertFalse($event->wasConditionSatisfied());
    }

    /**
     * @return array<string, array{array<string, int>}>
     */
    public function modes(): array
    {
        return [
            'safe to send mode' => [self::SAFE_TO_SEND_MODE],
            'result mode'       => [self::RESULT_MODE],
        ];
    }

    public function testTimeoutFailsAndKeepsTheContactFields(): void
    {
        $lead = $this->contact('valid@example.com');
        $this->leadModel->expects($this->never())->method('saveEntity');

        $event = $this->evaluate($lead, [new ConnectException('timeout', new Request('GET', 'https://api.test'))]);

        $this->assertFalse($event->wasConditionSatisfied());
        $this->assertSame([], $lead->getUpdatedFields());
    }

    public function testApiErrorFails(): void
    {
        $lead = $this->contact('valid@example.com');
        $this->leadModel->expects($this->never())->method('saveEntity');

        $error = new Response(401, [], (string) json_encode([
            'result'       => null,
            'safe_to_send' => false,
            'success'      => false,
            'message'      => 'Server can not verify your authentication to use API.',
            'http_code'    => '401',
        ]));
        $event = $this->evaluate($lead, [$error]);

        $this->assertFalse($event->wasConditionSatisfied());
    }

    public function testResponseThatIsNotJsonFails(): void
    {
        $event = $this->evaluate($this->contact('valid@example.com'), [new Response(502, [], '<html>Bad gateway</html>')]);

        $this->assertFalse($event->wasConditionSatisfied());
    }

    public function testAcceptAllCountsAsValidInResultMode(): void
    {
        $answer = $this->apiAnswer('valid', false, ['accept_all' => true, 'reason' => 'low_quality']);
        $event  = $this->evaluate($this->contact('anyone@catch-all.example'), [$answer], self::RESULT_MODE);

        $this->assertTrue($event->wasConditionSatisfied());
    }

    public function testSafeToSendModeUsesSafeToSend(): void
    {
        $disposable = $this->apiAnswer('valid', false, ['disposable' => true]);

        $this->assertFalse($this->evaluate($this->contact('a@disposable.example'), [$disposable], self::SAFE_TO_SEND_MODE)->wasConditionSatisfied());
        $this->assertTrue($this->evaluate($this->contact('a@disposable.example'), [$disposable], self::RESULT_MODE)->wasConditionSatisfied());
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
        $this->assertArrayHasKey('eev_validate_time', $updated);
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
        $event = $this->evaluate($this->contact('valid@example.com'), [], self::SAFE_TO_SEND_MODE, null);

        $this->assertFalse($event->wasConditionSatisfied());
        $this->assertCount(0, $this->history);
    }

    public function testApiKeyIsSentInHeaderNotInUrl(): void
    {
        $this->evaluate($this->contact('valid@example.com'), [$this->apiAnswer('valid', true)]);

        $request = $this->history[0]['request'];
        $this->assertSame('test-api-key', $request->getHeaderLine('X-API-Key'));
        $this->assertStringNotContainsString('test-api-key', (string) $request->getUri());
        $this->assertSame('email=valid%40example.com', $request->getUri()->getQuery());
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

    public function testListensOnlyToItsOwnEvent(): void
    {
        $this->assertSame(
            [CheckEmailVerificationCondition::ON_EVALUATE => ['onConditionValidate', 0]],
            CheckEmailVerificationCondition::getSubscribedEvents()
        );
    }

    /**
     * @param array<int, mixed>          $responses responses or exceptions, in order
     * @param array<string, int>|null    $features  feature settings; null = never saved
     */
    private function evaluate(Lead $lead, array $responses, ?array $features = self::SAFE_TO_SEND_MODE, ?string $apiKey = 'test-api-key'): ConditionEvent
    {
        $event = $this->conditionEvent($lead);
        $this->condition($responses, $features, $apiKey)->onConditionValidate($event);

        return $event;
    }

    /**
     * @param array<int, mixed>       $responses
     * @param array<string, int>|null $features
     */
    private function condition(array $responses, ?array $features = self::SAFE_TO_SEND_MODE, ?string $apiKey = 'test-api-key'): CheckEmailVerificationCondition
    {
        $settings = new Integration();
        $settings->setFeatureSettings($features);

        $integration = $this->createMock(EasyIntegration::class);
        $integration->method('getApiKey')->willReturn($apiKey);
        $integration->method('getIntegrationSettings')->willReturn($settings);

        $integrationHelper = $this->createMock(IntegrationHelper::class);
        $integrationHelper->method('getIntegrationObject')->with('Easy')->willReturn($integration);

        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));

        return new CheckEmailVerificationCondition(
            $integrationHelper,
            $this->leadModel,
            $this->fieldModel,
            new NullLogger(),
            new Client(['handler' => $stack])
        );
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
     */
    private function apiAnswer(string $result, bool $safeToSend, array $overrides = []): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode($overrides + [
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
        ]));
    }
}
