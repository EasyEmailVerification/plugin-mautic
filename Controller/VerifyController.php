<?php

declare(strict_types=1);

namespace MauticPlugin\MauticEasyBundle\Controller;

use Mautic\CoreBundle\Controller\CommonController;
use MauticPlugin\MauticEasyBundle\Form\Type\VerifyType;
use MauticPlugin\MauticEasyBundle\Verification\ManualVerifier;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * "Verify email now" (contact page and list rows) and "Verify selected" (list mass action). GET shows a confirmation
 * in Mautic's modal; POST, with the form's CSRF token, verifies and closes the modal with a flash message.
 */
class VerifyController extends CommonController
{
    public function verifyAction(Request $request, ManualVerifier $verifier, int $objectId = 0): Response
    {
        $lead = $verifier->getContact($objectId);
        if (!$lead) {
            return $this->show('mautic.integration.easy.verify.not_found', []);
        }
        if (!$verifier->canEdit($lead)) {
            return $this->show('mautic.integration.easy.verify.forbidden', []);
        }

        $form = $this->createForm(VerifyType::class, ['ids' => (string) $lead->getId()], [
            'action' => $this->generateUrl('mautic_easy_verify', ['objectId' => $lead->getId()]),
        ]);

        if ('POST' === $request->getMethod()) {
            $form->handleRequest($request);
            if (!$form->isSubmitted() || !$form->isValid()) {
                return $this->show('mautic.integration.easy.verify.invalid_request', []);
            }

            $result = $verifier->verifyLead($lead);
            switch ($result['status']) {
                case ManualVerifier::OK:
                    $data = $result['data'] ?? [];

                    return $this->close('mautic.integration.easy.verify.done', [
                        '%email%'  => $lead->getEmail(),
                        '%result%' => (string) ($data['result'] ?? ''),
                        '%safe%'   => ($data['safe_to_send'] ?? false) ? 'true' : 'false',
                    ]);
                case ManualVerifier::FAILED:
                    return $this->show('mautic.integration.easy.verify.failed', ['%error%' => rtrim($result['error'] ?? '', '. ')]);
                case ManualVerifier::NOT_AUTHORIZED:
                    return $this->show('mautic.integration.easy.verify.not_authorized', ['%error%' => rtrim($result['error'] ?? '', '. ')]);
                default:
                    return $this->show('mautic.integration.easy.verify.'.$result['status'], []);
            }
        }

        if (!$lead->getEmail()) {
            return $this->show('mautic.integration.easy.verify.no_email', []);
        }
        if (!$verifier->isConfigured()) {
            return $this->show('mautic.integration.easy.verify.not_configured', []);
        }

        return $this->modal($form->createView(), $this->translator->trans('mautic.integration.easy.verify.confirm', ['%email%' => $lead->getEmail()]));
    }

    public function batchAction(Request $request, ManualVerifier $verifier): Response
    {
        if (!$verifier->isConfigured()) {
            return $this->show('mautic.integration.easy.verify.not_configured', []);
        }

        // GET: ids of the checked rows, set by the button (JSON list); POST: the same list in the form
        $ids = 'POST' === $request->getMethod()
            ? ($request->request->all()['easy_verify']['ids'] ?? '')
            : ($request->query->all()['easy_verify']['ids'] ?? '');
        $ids = is_array($ids) ? $ids : (array) json_decode((string) $ids, true);
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!$ids) {
            return $this->show('mautic.integration.easy.verify.batch_none', []);
        }

        $form = $this->createForm(VerifyType::class, ['ids' => (string) json_encode($ids)], [
            'action' => $this->generateUrl('mautic_easy_verify_batch'),
        ]);

        if ('POST' === $request->getMethod()) {
            $form->handleRequest($request);
            if (!$form->isSubmitted() || !$form->isValid()) {
                return $this->show('mautic.integration.easy.verify.invalid_request', []);
            }

            $counts = $verifier->verifyContacts($ids);
            if ($counts[ManualVerifier::NOT_AUTHORIZED]) {
                return $this->show('mautic.integration.easy.verify.not_authorized_batch', ['%verified%' => $counts[ManualVerifier::OK]]);
            }

            return $this->show('mautic.integration.easy.verify.batch_done', [
                '%verified%'  => $counts[ManualVerifier::OK],
                '%failed%'    => $counts[ManualVerifier::FAILED],
                '%no_email%'  => $counts[ManualVerifier::NO_EMAIL],
                '%forbidden%' => $counts[ManualVerifier::FORBIDDEN] + $counts[ManualVerifier::NOT_FOUND],
                '%skipped%'   => $counts['skipped'],
            ], $counts[ManualVerifier::FAILED] ? 'warning' : 'info');
        }

        // Credits: one per selected contact with an email (at most BATCH_LIMIT)
        $withEmail = 0;
        foreach (array_slice($ids, 0, ManualVerifier::BATCH_LIMIT) as $id) {
            $lead = $verifier->getContact($id);
            if ($lead && $lead->getEmail() && $verifier->canEdit($lead)) {
                ++$withEmail;
            }
        }

        return $this->modal($form->createView(), $this->translator->trans('mautic.integration.easy.verify.batch_confirm', [
            '%count%'   => count($ids),
            '%credits%' => $withEmail,
            '%limit%'   => ManualVerifier::BATCH_LIMIT,
        ]));
    }

    private function modal(mixed $form, string $message): Response
    {
        return $this->delegateView([
            'viewParameters'  => ['form' => $form, 'message' => $message],
            'contentTemplate' => '@MauticEasy/Verify/modal.html.twig',
            'passthroughVars' => [
                'activeLink'    => '#mautic_contact_index',
                'mauticContent' => 'lead',
            ],
        ]);
    }

    /**
     * An error or a summary, in the modal until the user closes it: flash messages disappear after 4 seconds, too
     * fast for the longer explanations.
     *
     * @param array<string, mixed> $vars
     */
    private function show(string $message, array $vars = [], string $level = 'danger'): Response
    {
        return $this->delegateView([
            'viewParameters'  => ['message' => $this->translator->trans($message, $vars), 'level' => $level],
            'contentTemplate' => '@MauticEasy/Verify/message.html.twig',
            'passthroughVars' => [
                'activeLink'    => '#mautic_contact_index',
                'mauticContent' => 'lead',
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $vars
     */
    private function close(string $message, array $vars = [], string $level = 'notice'): JsonResponse
    {
        // the plugin's translations are in the "messages" domain, not in Mautic's "flashes"
        $this->addFlashMessage($message, $vars, $level, 'messages');

        return new JsonResponse([
            'closeModal' => true,
            'flashes'    => $this->getFlashContent(),
        ]);
    }
}
