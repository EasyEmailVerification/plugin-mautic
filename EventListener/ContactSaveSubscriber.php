<?php

declare(strict_types=1);

namespace MauticPlugin\MauticEasyBundle\EventListener;

use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Event\LeadEvent;
use Mautic\LeadBundle\LeadEvents;
use MauticPlugin\MauticEasyBundle\Api\Verifier;
use MauticPlugin\MauticEasyBundle\Integration\Config;
use MauticPlugin\MauticEasyBundle\Verification\ResultWriter;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Verifies a contact after it is saved, when it has an email and no result yet, or its email changed.
 *
 * Saves from Mautic's contact form only, unless "Also verify on import/API" is on: imports, the API, forms,
 * integrations and campaign actions can save many contacts, and each verification uses a credit.
 * The contact is already saved: a failed verification is logged and leaves its eev_ fields unchanged.
 */
class ContactSaveSubscriber implements EventSubscriberInterface
{
    /**
     * Seconds the save waits for the API at most (the campaign condition waits longer).
     */
    public const TIMEOUT = 15;

    /**
     * Mautic's contact form: route mautic_contact_action with these actions.
     */
    private const FORM_ACTIONS = ['new', 'edit', 'quickAdd'];

    public function __construct(
        private Config $config,
        private Verifier $verifier,
        private ResultWriter $writer,
        private RequestStack $requestStack,
        private LoggerInterface $logger
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // after Mautic's own listeners
        return [
            LeadEvents::LEAD_POST_SAVE => ['onContactPostSave', -100],
        ];
    }

    public function onContactPostSave(LeadEvent $event): void
    {
        // the save of the result itself: no new verification
        if ($this->writer->isWriting()) {
            return;
        }

        try {
            $lead  = $event->getLead();
            $email = $lead->getEmail();
            if (!$email || !$this->needsVerification($lead, $email)) {
                return;
            }

            if (!$this->config->isPublished() || !$this->config->verifyOnSave() || !$this->verifier->isConfigured()) {
                return;
            }

            if (!$this->isContactFormSave($lead) && !$this->config->verifyOtherSources()) {
                return;
            }

            $data = $this->verifier->verify($email, self::TIMEOUT);
            if (null === $data) {
                $this->logger->warning('Easy Email Verification: contact '.$lead->getId().' was saved without verification: '.$this->verifier->getLastError());

                return;
            }

            $this->writer->write($lead, $data);
        } catch (\Throwable $e) {
            // never break the save of a contact
            $this->logger->error('Easy Email Verification: verification on save failed ('.get_class($e).')');
        }
    }

    /**
     * No result yet, or a result of another address. Already verified with this address: nothing to do.
     */
    public function needsVerification(Lead $lead, string $email): bool
    {
        $fields = $lead->getProfileFields();
        if (empty($fields['eev_result'])) {
            return true;
        }

        $changes = $lead->getChanges(true);
        if (isset($changes['fields']['email'])) {
            [$old, $new] = array_values((array) $changes['fields']['email']) + [null, null];
            if (0 !== strcasecmp(trim((string) $old), trim((string) $new))) {
                return true;
            }
        }

        // an address changed while this verification was off or skipped: the stored result is of another domain
        $domain = strtolower((string) substr(strrchr($email, '@') ?: '', 1));
        $stored = strtolower((string) ($fields['eev_domain'] ?? ''));

        return '' !== $stored && $stored !== $domain;
    }

    /**
     * Saved by a person in Mautic's contact form (new, edit, quick add), not by an import, the API, a form,
     * an integration, a campaign or a command.
     */
    public function isContactFormSave(Lead $lead): bool
    {
        if ($lead->imported) {
            return false;
        }

        $request = $this->requestStack->getMainRequest();

        return null !== $request
            && 'mautic_contact_action' === $request->attributes->get('_route')
            && in_array($request->attributes->get('objectAction'), self::FORM_ACTIONS, true);
    }
}
