<?php

declare(strict_types=1);

namespace MauticPlugin\MauticEasyBundle\Tests\Unit\Verification;

use Doctrine\ORM\EntityManagerInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Mautic\CoreBundle\Helper\EncryptionHelper;
use Mautic\CoreBundle\Security\Permissions\CorePermissions;
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
use MauticPlugin\MauticEasyBundle\Integration\Config;
use MauticPlugin\MauticEasyBundle\Integration\EasyIntegration;
use MauticPlugin\MauticEasyBundle\Tests\Unit\ArrayLogger;
use MauticPlugin\MauticEasyBundle\Verification\ManualVerifier;
use MauticPlugin\MauticEasyBundle\Verification\ResultWriter;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ManualVerifierTest extends TestCase
{
    /**
     * @var \ArrayObject<int, array<mixed>>
     */
    private \ArrayObject $history;

    /**
     * @var LeadModel&MockObject
     */
    private $leadModel;

    /**
     * @var array<int, Lead>
     */
    private array $contacts = [];

    private bool $allowed = true;

    private bool $published = true;

    protected function setUp(): void
    {
        $this->history   = new \ArrayObject();
        $this->contacts  = [];
        $this->allowed   = true;
        $this->published = true;
        $this->leadModel = $this->createMock(LeadModel::class);
    }

    public function testSuccessIgnoresARecentResultAndUpdatesTheFields(): void
    {
        // verified yesterday: the campaign condition would reuse it, the button verifies again
        $lead = $this->contact(1, 'valid@example.com', ['eev_result' => 'invalid', 'eev_domain' => 'example.com', 'eev_validate_time' => date('Y-m-d H:i:s', (int) strtotime('-1 day'))]);
        $this->leadModel->expects($this->once())->method('saveEntity')->with($lead);

        $result = $this->verifier([$this->apiAnswer('valid', true)])->verifyContact(1);

        $this->assertSame(ManualVerifier::OK, $result['status']);
        $this->assertSame('valid', $result['data']['result'] ?? null);
        $this->assertCount(1, $this->history);
        $this->assertSame('valid', $lead->getUpdatedFields()['eev_result']);
        $this->assertSame('true', $lead->getUpdatedFields()['eev_safe_to_send']);
    }

    public function testApiErrorReturnsTheReasonAndKeepsTheFields(): void
    {
        $lead = $this->contact(1, 'valid@example.com');
        $this->leadModel->expects($this->never())->method('saveEntity');

        $result = $this->verifier([new Response(402, [], (string) json_encode(['success' => false, 'message' => 'Running out of your credit limit.']))])->verifyContact(1);

        $this->assertSame(ManualVerifier::FAILED, $result['status']);
        $this->assertSame('API error (HTTP 402): Running out of your credit limit', $result['error'] ?? null);
        $this->assertSame([], $lead->getUpdatedFields());
    }

    /**
     * Invalid or deleted API key, revoked or expired OAuth connection: only the administrator can fix it.
     *
     * @dataProvider refusedCredentials
     */
    public function testRefusedCredentialIsNotAuthorized(int $status): void
    {
        $lead = $this->contact(1, 'valid@example.com');

        $result = $this->verifier([new Response($status, [], (string) json_encode(['success' => false, 'message' => 'Server can not verify your authentication to use API.']))])->verifyContact(1);

        $this->assertSame(ManualVerifier::NOT_AUTHORIZED, $result['status']);
        $this->assertSame([], $lead->getUpdatedFields());
    }

    /**
     * @return array<string, array{int}>
     */
    public function refusedCredentials(): array
    {
        return ['HTTP 401' => [401], 'HTTP 403' => [403]];
    }

    public function testVerifySelectedStopsWhenTheCredentialIsRefused(): void
    {
        $this->contact(1, 'a@example.com');
        $this->contact(2, 'b@example.com');
        $this->contact(3, 'c@example.com');

        $counts = $this->verifier([$this->apiAnswer('valid', true), new Response(401, [], '{"success":false}')])->verifyContacts([1, 2, 3]);

        $this->assertSame(1, $counts[ManualVerifier::OK]);
        $this->assertSame(1, $counts[ManualVerifier::NOT_AUTHORIZED]);
        // the third contact is not sent
        $this->assertCount(2, $this->history);
    }

    public function testContactWithoutEmailIsNotSent(): void
    {
        $this->contacts[1] = $this->withId(new Lead(), 1);

        $result = $this->verifier([])->verifyContact(1);

        $this->assertSame(ManualVerifier::NO_EMAIL, $result['status']);
        $this->assertCount(0, $this->history);
    }

    public function testMissingPermissionIsRefusedBeforeAnyRequest(): void
    {
        $this->contact(1, 'valid@example.com');
        $this->allowed = false;
        $this->leadModel->expects($this->never())->method('saveEntity');

        $result = $this->verifier([])->verifyContact(1);

        $this->assertSame(ManualVerifier::FORBIDDEN, $result['status']);
        $this->assertCount(0, $this->history);
    }

    public function testNotConfiguredIsRefused(): void
    {
        $this->contact(1, 'valid@example.com');
        $this->published = false;

        $verifier = $this->verifier([]);

        $this->assertFalse($verifier->isConfigured());
        $this->assertSame(ManualVerifier::NOT_CONFIGURED, $verifier->verifyContact(1)['status']);
        $this->assertCount(0, $this->history);
    }

    public function testUnknownContact(): void
    {
        $this->assertSame(ManualVerifier::NOT_FOUND, $this->verifier([])->verifyContact(99)['status']);
    }

    public function testVerifySelectedCountsEachOutcome(): void
    {
        $this->contact(1, 'valid@example.com');
        $this->contact(2, 'broken@example.com');
        $this->contacts[3] = $this->withId(new Lead(), 3);

        $counts = $this->verifier([$this->apiAnswer('valid', true), new Response(502, [], 'Bad gateway')])->verifyContacts([1, 2, 3, 4, '2']);

        $this->assertSame(1, $counts[ManualVerifier::OK]);
        $this->assertSame(1, $counts[ManualVerifier::FAILED]);
        $this->assertSame(1, $counts[ManualVerifier::NO_EMAIL]);
        $this->assertSame(1, $counts[ManualVerifier::NOT_FOUND]);
        $this->assertSame(0, $counts['skipped']);
        $this->assertCount(2, $this->history);
    }

    public function testVerifySelectedStopsAtTheLimit(): void
    {
        $ids = range(1, ManualVerifier::BATCH_LIMIT + 5);

        $counts = $this->verifier([])->verifyContacts($ids);

        $this->assertSame(ManualVerifier::BATCH_LIMIT, $counts[ManualVerifier::NOT_FOUND]);
        $this->assertSame(5, $counts['skipped']);
    }

    /**
     * @param array<int, mixed> $responses
     */
    private function verifier(array $responses): ManualVerifier
    {
        $contacts = $this->contacts;
        $this->leadModel->method('getEntity')->willReturnCallback(fn ($id): ?Lead => $contacts[(int) $id] ?? null);

        $settings = new Integration();
        $settings->setName(EasyIntegration::NAME);
        $settings->setIsPublished($this->published);
        $settings->setApiKeys(['apikey' => 'test-api-key']);

        $integration = new EasyIntegration();
        $integration->setIntegrationConfiguration($settings);

        $integrationsHelper = $this->createMock(IntegrationsHelper::class);
        $integrationsHelper->method('getIntegration')->willReturn($integration);

        $config = new Config($integrationsHelper, $this->createMock(EntityManagerInterface::class), new EncryptionService($this->createMock(EncryptionHelper::class)));

        $stack = HandlerStack::create(new MockHandler($responses));
        // the same object: the middleware appends to it
        $history = $this->history;
        $stack->push(Middleware::history($history));

        $logger   = new ArrayLogger();
        $verifier = new Verifier($config, new VerificationClient(new Client(['handler' => $stack]), $logger), $this->createMock(OAuthClient::class), $logger);

        $fieldModel = $this->createMock(FieldModel::class);
        $fieldModel->method('getEntityByAlias')->willReturn(new LeadField());

        $security = $this->createMock(CorePermissions::class);
        $security->method('hasEntityAccess')->willReturnCallback(fn (): bool => $this->allowed);

        return new ManualVerifier($this->leadModel, $security, $config, $verifier, new ResultWriter($this->leadModel, $fieldModel, $logger));
    }

    /**
     * @param array<string, string> $stored
     */
    private function contact(int $id, string $email, array $stored = []): Lead
    {
        $lead = new Lead();
        $lead->setEmail($email);
        $core = ['email' => ['alias' => 'email', 'type' => 'email', 'value' => $email]];
        foreach ($stored as $alias => $value) {
            $core[$alias] = ['alias' => $alias, 'type' => 'text', 'value' => $value];
        }
        $lead->setFields(['core' => $core]);

        return $this->contacts[$id] = $this->withId($lead, $id);
    }

    private function withId(Lead $lead, int $id): Lead
    {
        $property = new \ReflectionProperty(Lead::class, 'id');
        $property->setAccessible(true);
        $property->setValue($lead, $id);

        return $lead;
    }

    private function apiAnswer(string $result, bool $safeToSend): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
            'result' => $result, 'safe_to_send' => $safeToSend, 'domain' => 'example.com', 'success' => true, 'http_code' => '200',
        ]));
    }
}
