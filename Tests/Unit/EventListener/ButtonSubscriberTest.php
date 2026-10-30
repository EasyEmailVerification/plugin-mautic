<?php

declare(strict_types=1);

namespace MauticPlugin\MauticEasyBundle\Tests\Unit\EventListener;

use Mautic\CoreBundle\Event\CustomButtonEvent;
use Mautic\CoreBundle\Twig\Helper\ButtonHelper;
use Mautic\LeadBundle\Entity\Lead;
use MauticPlugin\MauticEasyBundle\EventListener\ButtonSubscriber;
use MauticPlugin\MauticEasyBundle\Verification\ManualVerifier;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class ButtonSubscriberTest extends TestCase
{
    public function testContactPageGetsAnEnabledButton(): void
    {
        $buttons = $this->buttons(ButtonHelper::LOCATION_PAGE_ACTIONS, 'mautic_contact_action', $this->lead('a@example.com'));

        $this->assertCount(1, $buttons);
        $button = array_values($buttons)[0];
        $this->assertSame('mautic.integration.easy.verify.button', $button['btnText']);
        $this->assertSame('/s/easy/verify/7', $button['attr']['href']);
        $this->assertSame('ajaxmodal', $button['attr']['data-toggle']);
        $this->assertStringNotContainsString('disabled', $button['attr']['class'] ?? '');
        // next to Edit, not in the Options menu
        $this->assertTrue($button['primary']);
    }

    /**
     * @dataProvider disabledCases
     */
    public function testButtonIsDisabledWithTheReason(?string $email, bool $configured, bool $allowed, string $reason): void
    {
        $buttons = $this->buttons(ButtonHelper::LOCATION_LIST_ACTIONS, 'mautic_contact_index', $this->lead($email), $configured, $allowed);

        $button = array_values($buttons)[0];
        $this->assertStringContainsString('disabled', $button['attr']['class']);
        $this->assertSame('javascript:void(0);', $button['attr']['href']);
        $this->assertSame($reason, $button['attr']['title']);
        $this->assertSame('tooltip', $button['attr']['data-toggle']);
        // the list rows keep it in their menu
        $this->assertArrayNotHasKey('primary', $button);
    }

    /**
     * @return array<string, array{string|null, bool, bool, string}>
     */
    public function disabledCases(): array
    {
        return [
            'no email'       => [null, true, true, 'mautic.integration.easy.verify.no_email'],
            'not configured' => ['a@example.com', false, true, 'mautic.integration.easy.verify.not_configured'],
            'no permission'  => ['a@example.com', true, false, 'mautic.integration.easy.verify.forbidden'],
        ];
    }

    public function testListMassActionWhenConfigured(): void
    {
        $buttons = $this->buttons($this->bulkLocation(), 'mautic_contact_index', null);

        $this->assertCount(1, $buttons);
        $button = array_values($buttons)[0];
        $this->assertSame('mautic.integration.easy.verify.batch_button', $button['btnText']);
        $this->assertStringContainsString('/s/easy/verify-batch', $button['attr']['onclick']);
    }

    public function testMassActionIsInTheSelectionBarWhenThereIsOne(): void
    {
        if (!defined(ButtonHelper::class.'::LOCATION_TOOLBAR_BULK_ACTIONS')) {
            $this->markTestSkipped('Mautic 5 has no selection bar');
        }

        // not also in the Options menu of that bar
        $this->assertSame([], $this->buttons(ButtonHelper::LOCATION_BULK_ACTIONS, 'mautic_contact_index', null));
    }

    public function testNoMassActionWhenNotConfigured(): void
    {
        $this->assertSame([], $this->buttons($this->bulkLocation(), 'mautic_contact_index', null, false));
    }

    private function bulkLocation(): string
    {
        return defined(ButtonHelper::class.'::LOCATION_TOOLBAR_BULK_ACTIONS')
            ? (string) constant(ButtonHelper::class.'::LOCATION_TOOLBAR_BULK_ACTIONS')
            : ButtonHelper::LOCATION_BULK_ACTIONS;
    }

    public function testOtherPagesAreLeftAlone(): void
    {
        $this->assertSame([], $this->buttons(ButtonHelper::LOCATION_PAGE_ACTIONS, 'mautic_email_action', $this->lead('a@example.com')));
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function buttons(string $location, string $route, ?Lead $lead, bool $configured = true, bool $allowed = true): array
    {
        $verifier = $this->createMock(ManualVerifier::class);
        $verifier->method('isConfigured')->willReturn($configured);
        $verifier->method('canEdit')->willReturn($allowed);

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        $router = $this->createMock(RouterInterface::class);
        $router->method('generate')->willReturnCallback(fn (string $name, array $params = []): string => 'mautic_easy_verify' === $name ? '/s/easy/verify/'.$params['objectId'] : '/s/easy/verify-batch');

        $request = Request::create('/s/contacts');
        $request->attributes->set('_route', $route);
        $request->attributes->set('_route_params', []);

        $event = new CustomButtonEvent($location, $request, [], $lead);
        (new ButtonSubscriber($verifier, $translator, $router))->injectViewButtons($event);

        return $event->getButtons();
    }

    private function lead(?string $email): Lead
    {
        $lead = new Lead();
        if ($email) {
            $lead->setEmail($email);
        }
        $property = new \ReflectionProperty(Lead::class, 'id');
        $property->setAccessible(true);
        $property->setValue($lead, 7);

        return $lead;
    }
}
