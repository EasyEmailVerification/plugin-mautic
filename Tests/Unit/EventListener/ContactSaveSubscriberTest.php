<?php

declare(strict_types=1);

namespace MauticPlugin\MauticEasyBundle\Tests\Unit\EventListener;

use Doctrine\ORM\EntityManagerInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request as HttpRequest;
use GuzzleHttp\Psr7\Response as HttpResponse;
use Mautic\CoreBundle\Helper\EncryptionHelper;
use Mautic\IntegrationsBundle\Facade\EncryptionService;
use Mautic\IntegrationsBundle\Helper\IntegrationsHelper;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Entity\LeadField;
use Mautic\LeadBundle\Event\LeadEvent;
use Mautic\LeadBundle\LeadEvents;
use Mautic\LeadBundle\Model\FieldModel;
use Mautic\LeadBundle\Model\LeadModel;
use Mautic\PluginBundle\Entity\Integration;
use MauticPlugin\MauticEasyBundle\Api\VerificationClient;
use MauticPlugin\MauticEasyBundle\Api\Verifier;
use MauticPlugin\MauticEasyBundle\Auth\OAuthClient;
use MauticPlugin\MauticEasyBundle\EventListener\ContactSaveSubscriber;
use MauticPlugin\MauticEasyBundle\Integration\Config;
use MauticPlugin\MauticEasyBundle\Integration\EasyIntegration;
use MauticPlugin\MauticEasyBundle\Tests\Unit\ArrayLogger;
use MauticPlugin\MauticEasyBundle\Verification\ResultWriter;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

class ContactSaveSubscriberTest extends TestCase
{
    /**
     * @var \ArrayObject<int, array<mixed>>
     */
    private \ArrayObject $history;

    /**
     * @var LeadModel&MockObject
     */
    private $leadModel;

    private ArrayLogger $logger;

    /**
     * @var array<int, mixed>
     */
    private array $responses = [];

    /**
     * @var array<string, mixed>
     */
    private array $features = [];

    private bool $published = true;

    /**
     * @var array<string, string>
     */
    private array $keys = ['apikey' => 'test-api-key'];

    protected function setUp(): void
    {
        $this->history   = new \ArrayObject();
        $this->logger    = new ArrayLogger();
        $this->leadModel = $this->createMock(LeadModel::class);
        $this->responses = [$this->apiAnswer()];
        $this->features  = [];
        $this->published = true;
        $this->keys      = ['apikey' => 'test-api-key'];
    }

    // ------------------------------------------------------------------ trigger rules

    public function testNewContactFromTheContactFormIsVerified(): void
    {
        $lead = $this->contact('new@example.com');
        $this->leadModel->expects($this->once())->method('saveEntity')->with($lead);

        $this->save($lead, $this->formRequest('new'));

        $this->assertCount(1, $this->history);
        $this->assertSame('valid', $lead->getUpdatedFields()['eev_result']);
        $this->assertSame('true', $lead->getUpdatedFields()['eev_safe_to_send']);
        $this->assertSame(ContactSaveSubscriber::TIMEOUT, $this->sentOptions()['timeout']);
    }

    /**
     * @dataProvider formActions
     */
    public function testEveryContactFormActionCounts(string $action): void
    {
        $this->save($this->contact('a@example.com'), $this->formRequest($action));

        $this->assertCount(1, $this->history);
    }

    /**
     * @return array<string, array{string}>
     */
    public function formActions(): array
    {
        return ['new' => ['new'], 'edit' => ['edit'], 'quick add' => ['quickAdd']];
    }

    public function testUnverifiedContactIsVerified(): void
    {
        $this->save($this->contact('old@example.com', ['eev_result' => '']), $this->formRequest('edit'));

        $this->assertCount(1, $this->history);
    }

    public function testChangedEmailIsVerifiedAgain(): void
    {
        $lead = $this->contact('old@example.com', $this->stored('valid', 'example.com'));
        $lead->addUpdatedField('email', 'new@example.com', 'old@example.com');

        $this->save($lead, $this->formRequest('edit'));

        $this->assertCount(1, $this->history);
        $this->assertSame('email=new%40example.com', $this->sentRequest()->getUri()->getQuery());
    }

    public function testResultOfAnotherDomainIsVerifiedAgain(): void
    {
        // the email changed while the verification on save was off: no change recorded in this save
        $this->save($this->contact('someone@another.example', $this->stored('valid', 'example.com')), $this->formRequest('edit'));

        $this->assertCount(1, $this->history);
    }

    public function testAlreadyVerifiedWithTheSameEmailIsNotVerifiedAgain(): void
    {
        $lead = $this->contact('same@example.com', $this->stored('invalid', 'example.com'));
        $lead->addUpdatedField('firstname', 'Ana', '');
        $this->leadModel->expects($this->never())->method('saveEntity');

        $this->save($lead, $this->formRequest('edit'));

        $this->assertCount(0, $this->history);
    }

    public function testEmailChangedOnlyInLetterCaseIsNotVerifiedAgain(): void
    {
        $lead = $this->contact('Same@Example.com', $this->stored('valid', 'example.com'));
        $lead->addUpdatedField('email', 'Same@Example.com', 'same@example.com');

        $this->save($lead, $this->formRequest('edit'));

        $this->assertCount(0, $this->history);
    }

    public function testContactWithoutEmailIsNotVerified(): void
    {
        $this->save(new Lead(), $this->formRequest('new'));

        $this->assertCount(0, $this->history);
    }

    // ------------------------------------------------------------------ no loop

    public function testSavingTheResultDoesNotStartAnotherVerification(): void
    {
        $lead       = $this->contact('loop@example.com');
        $subscriber = $this->subscriber($this->formRequest('new'));

        // Mautic dispatches the post-save event again when the result is saved
        $this->leadModel->expects($this->once())->method('saveEntity')
            ->willReturnCallback(fn (Lead $saved) => $subscriber->onContactPostSave(new LeadEvent($saved, false)));

        $subscriber->onContactPostSave(new LeadEvent($lead, true));

        $this->assertCount(1, $this->history);
    }

    // ------------------------------------------------------------------ sources

    public function testImportIsSkippedByDefault(): void
    {
        $lead           = $this->contact('imported@example.com');
        $lead->imported = true;

        $this->save($lead, null);

        $this->assertCount(0, $this->history);
    }

    /**
     * @dataProvider otherSources
     */
    public function testOtherSourcesAreSkippedByDefault(?Request $request): void
    {
        $this->save($this->contact('a@example.com'), $request);

        $this->assertCount(0, $this->history);
    }

    /**
     * @return array<string, array{Request|null}>
     */
    public function otherSources(): array
    {
        $api = Request::create('/api/contacts/new', 'POST');
        $api->attributes->set('_route', 'mautic_api_contactsstandard_new');

        $form = Request::create('/form/submit', 'POST');
        $form->attributes->set('_route', 'mautic_form_postresults');

        $batch = Request::create('/s/contacts/batchOwners', 'POST');
        $batch->attributes->set('_route', 'mautic_contact_action');
        $batch->attributes->set('objectAction', 'batchOwners');

        return [
            'command, cron or worker' => [null],
            'API'                     => [$api],
            'form submission'         => [$form],
            'contact list batch'      => [$batch],
        ];
    }

    public function testOtherSourcesAreVerifiedWhenEnabled(): void
    {
        $this->features = ['verify_other_sources' => 1];
        $lead           = $this->contact('imported@example.com');
        $lead->imported = true;

        $this->save($lead, null);

        $this->assertCount(1, $this->history);
    }

    public function testImportInTheContactFormRouteIsStillAnImport(): void
    {
        $lead           = $this->contact('imported@example.com');
        $lead->imported = true;

        $this->save($lead, $this->formRequest('new'));

        $this->assertCount(0, $this->history);
    }

    // ------------------------------------------------------------------ settings

    public function testDisabledSettingDoesNothing(): void
    {
        $this->features = ['verify_on_save' => 0];

        $this->save($this->contact('a@example.com'), $this->formRequest('new'));

        $this->assertCount(0, $this->history);
    }

    public function testUnpublishedPluginDoesNothing(): void
    {
        $this->published = false;

        $this->save($this->contact('a@example.com'), $this->formRequest('new'));

        $this->assertCount(0, $this->history);
    }

    public function testMissingCredentialDoesNothingAndLogsNothing(): void
    {
        $this->keys = [];

        $this->save($this->contact('a@example.com'), $this->formRequest('new'));

        $this->assertCount(0, $this->history);
        $this->assertSame([], $this->logger->records);
    }

    // ------------------------------------------------------------------ failures never break the save

    public function testApiErrorKeepsTheFieldsAndLogs(): void
    {
        $this->responses = [new HttpResponse(402, [], (string) json_encode(['success' => false, 'message' => 'Running out of your credit limit']))];
        $lead            = $this->contact('a@example.com');
        $this->leadModel->expects($this->never())->method('saveEntity');

        $this->save($lead, $this->formRequest('new'));

        $this->assertSame([], $lead->getUpdatedFields());
        $this->assertTrue($this->logger->has('warning', 'saved without verification: API error (HTTP 402)'));
    }

    public function testTimeoutKeepsTheFieldsAndLogs(): void
    {
        $this->responses = [new ConnectException('timeout', new HttpRequest('GET', 'https://api.test'))];
        $lead            = $this->contact('secret.person@example.com');

        $this->save($lead, $this->formRequest('new'));

        $this->assertSame([], $lead->getUpdatedFields());
        $this->assertTrue($this->logger->has('warning', 'request failed (ConnectException)'));
        foreach ($this->logger->records as [, $message]) {
            $this->assertStringNotContainsString('secret.person', $message);
            $this->assertStringNotContainsString('test-api-key', $message);
        }
    }

    public function testUnexpectedErrorIsSwallowed(): void
    {
        $this->responses = [new \RuntimeException('boom')];

        $this->save($this->contact('a@example.com'), $this->formRequest('new'));

        $this->assertTrue($this->logger->has('error', 'verification on save failed (RuntimeException)'));
    }

    public function testListensAfterMauticsListeners(): void
    {
        $this->assertSame([LeadEvents::LEAD_POST_SAVE => ['onContactPostSave', -100]], ContactSaveSubscriber::getSubscribedEvents());
    }

    // ------------------------------------------------------------------ helpers

    private function save(Lead $lead, ?Request $request): void
    {
        $this->subscriber($request)->onContactPostSave(new LeadEvent($lead, true));
    }

    private function subscriber(?Request $request): ContactSaveSubscriber
    {
        $settings = new Integration();
        $settings->setName(EasyIntegration::NAME);
        $settings->setIsPublished($this->published);
        $settings->setFeatureSettings(['integration' => $this->features]);
        $settings->setApiKeys($this->keys);

        $integration = new EasyIntegration();
        $integration->setIntegrationConfiguration($settings);

        $integrationsHelper = $this->createMock(IntegrationsHelper::class);
        $integrationsHelper->method('getIntegration')->willReturn($integration);

        $config = new Config($integrationsHelper, $this->createMock(EntityManagerInterface::class), new EncryptionService($this->createMock(EncryptionHelper::class)));

        $stack = HandlerStack::create(new MockHandler($this->responses));
        // the same object: the middleware appends to it
        $history = $this->history;
        $stack->push(Middleware::history($history));

        $verifier = new Verifier($config, new VerificationClient(new Client(['handler' => $stack]), $this->logger), $this->createMock(OAuthClient::class), $this->logger);

        $fieldModel = $this->createMock(FieldModel::class);
        $fieldModel->method('getEntityByAlias')->willReturn(new LeadField());

        $requestStack = new RequestStack();
        if ($request) {
            $requestStack->push($request);
        }

        return new ContactSaveSubscriber($config, $verifier, new ResultWriter($this->leadModel, $fieldModel, $this->logger), $requestStack, $this->logger);
    }

    private function formRequest(string $action): Request
    {
        $request = Request::create('/s/contacts/'.$action, 'POST');
        $request->attributes->set('_route', 'mautic_contact_action');
        $request->attributes->set('objectAction', $action);

        return $request;
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
    private function stored(string $result, string $domain): array
    {
        return ['eev_result' => $result, 'eev_domain' => $domain, 'eev_validate_time' => date('Y-m-d H:i:s', (int) strtotime('-100 days'))];
    }

    private function apiAnswer(): HttpResponse
    {
        return new HttpResponse(200, ['Content-Type' => 'application/json'], (string) json_encode([
            'email'        => 'someone@example.com',
            'result'       => 'valid',
            'reason'       => 'accepted_email',
            'safe_to_send' => true,
            'domain'       => 'example.com',
            'success'      => true,
            'http_code'    => '200',
        ]));
    }

    private function sentRequest(): \Psr\Http\Message\RequestInterface
    {
        $request = $this->history[0]['request'] ?? null;
        $this->assertInstanceOf(\Psr\Http\Message\RequestInterface::class, $request);

        return $request;
    }

    /**
     * @return array<string, mixed>
     */
    private function sentOptions(): array
    {
        $options = $this->history[0]['options'] ?? null;
        $this->assertIsArray($options);

        return $options;
    }
}
