<?php

declare(strict_types=1);

namespace MauticPlugin\MauticEasyBundle\EventListener;

use Mautic\CoreBundle\CoreEvents;
use Mautic\CoreBundle\Event\CustomButtonEvent;
use Mautic\CoreBundle\Twig\Helper\ButtonHelper;
use Mautic\LeadBundle\Entity\Lead;
use MauticPlugin\MauticEasyBundle\Verification\ManualVerifier;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "Verify email now" on the contact page and in the contact list's row actions; "Verify selected" for the selected contacts.
 */
class ButtonSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private ManualVerifier $verifier,
        private TranslatorInterface $translator,
        private RouterInterface $router
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            CoreEvents::VIEW_INJECT_CUSTOM_BUTTONS => ['injectViewButtons', 0],
        ];
    }

    public function injectViewButtons(CustomButtonEvent $event): void
    {
        if (!str_starts_with((string) $event->getRoute(), 'mautic_contact_')) {
            return;
        }

        $configured = $this->verifier->isConfigured();
        $label      = $this->translator->trans('mautic.integration.easy.verify.button');

        if ($configured) {
            // Mautic 6 and later: in the bar shown while contacts are selected, next to "Delete selected"; Mautic 5
            // only has the mass actions menu
            $toolbar = defined(ButtonHelper::class.'::LOCATION_TOOLBAR_BULK_ACTIONS');
            $event->addButton(
                [
                    'attr' => [
                        'class'       => $toolbar ? 'btn btn-primary bdr-rds-0 btn-nospin' : 'btn btn-default btn-sm btn-nospin',
                        'data-toggle' => 'ajaxmodal',
                        'data-target' => '#MauticSharedModal',
                        'data-header' => $this->translator->trans('mautic.integration.easy.verify.batch_button'),
                        'onclick'     => 'this.href=\''.$this->router->generate('mautic_easy_verify_batch').
                            '?\' + mQuery.param({\'easy_verify\':{\'ids\':Mautic.getCheckedListIds(false, true)}});return true;',
                    ],
                    'btnText'   => $this->translator->trans('mautic.integration.easy.verify.batch_button'),
                    'iconClass' => $this->icon(),
                ],
                $toolbar ? (string) constant(ButtonHelper::class.'::LOCATION_TOOLBAR_BULK_ACTIONS') : ButtonHelper::LOCATION_BULK_ACTIONS
            );
        }

        $lead = $event->getItem();
        if (!$lead instanceof Lead) {
            return;
        }

        $button = $this->contactButton($lead, $configured, $label);
        // page actions are only shown on the contact's page; "primary" keeps the button next to Edit instead of in
        // the Options menu (Mautic 7 moves the other buttons there)
        $event->addButton($button + ['primary' => true], ButtonHelper::LOCATION_PAGE_ACTIONS, 'mautic_contact_action');
        $event->addButton($button, ButtonHelper::LOCATION_LIST_ACTIONS, 'mautic_contact_index');
    }

    /**
     * @return array<string, mixed>
     */
    private function contactButton(Lead $lead, bool $configured, string $label): array
    {
        $reason = !$configured ? 'mautic.integration.easy.verify.not_configured'
            : (!$lead->getEmail() ? 'mautic.integration.easy.verify.no_email'
            : (!$this->verifier->canEdit($lead) ? 'mautic.integration.easy.verify.forbidden' : null));

        if (null !== $reason) {
            // shown but disabled, with the reason as tooltip; Mautic adds its own button classes, and the tooltip
            // toggle keeps it from turning the link into an ajax navigation
            return [
                'attr' => [
                    'class'         => 'disabled',
                    'href'          => 'javascript:void(0);',
                    'aria-disabled' => 'true',
                    'data-toggle'   => 'tooltip',
                    'title'         => $this->translator->trans($reason),
                ],
                'btnText'   => $label,
                'iconClass' => $this->icon(),
            ];
        }

        return [
            'attr' => [
                'data-toggle' => 'ajaxmodal',
                'data-target' => '#MauticSharedModal',
                'data-header' => $label,
                'href'        => $this->router->generate('mautic_easy_verify', ['objectId' => $lead->getId()]),
            ],
            'btnText'   => $label,
            'iconClass' => $this->icon(),
        ];
    }

    /**
     * Mautic 5 uses Font Awesome, Mautic 6 and later Remix Icon.
     */
    private function icon(): string
    {
        return defined('MAUTIC_VERSION') && version_compare((string) constant('MAUTIC_VERSION'), '6.0.0-dev', '<')
            ? 'fa fa-check-circle'
            : 'ri-mail-check-line';
    }
}
