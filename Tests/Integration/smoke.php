<?php

declare(strict_types=1);

/*
 * Smoke test inside an installed Mautic, after cache:clear and mautic:plugins:reload. Not part of the release zip.
 *
 *   php smoke.php /var/www/html            services, settings form, upgrade of 1.x settings
 *   php smoke.php /var/www/html --sandbox  also runs the campaign condition against the API with the sandbox key
 *                                          (eev_sandbox_key and sandbox addresses only: no credit is used)
 *
 * Exits 1 on the first failed check.
 */

use Mautic\CampaignBundle\Entity\Campaign;
use Mautic\CampaignBundle\Entity\Event;
use Mautic\CampaignBundle\Entity\LeadEventLog;
use Mautic\CampaignBundle\Event\ConditionEvent;
use Mautic\CampaignBundle\EventCollector\Accessor\Event\ConditionAccessor;
use Mautic\IntegrationsBundle\Form\Type\IntegrationConfigType;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Model\LeadModel;
use MauticPlugin\MauticEasyBundle\Conditions\CheckEmailVerificationCondition;
use MauticPlugin\MauticEasyBundle\Integration\Config;
use MauticPlugin\MauticEasyBundle\Integration\Support\ConfigSupport;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

$root    = rtrim($argv[1] ?? getcwd(), '/');
$sandbox = in_array('--sandbox', $argv, true);

require $root.'/vendor/autoload.php';

function check(bool $ok, string $what): void
{
    echo ($ok ? 'ok   ' : 'FAIL ').$what.PHP_EOL;
    if (!$ok) {
        exit(1);
    }
}

// The same bootstrap as bin/console (app/console-application.php): environment and parameters
$appDir = dirname((string) (new ReflectionClass(AppKernel::class))->getFileName());
define('IN_MAUTIC_CONSOLE', 1);
define('MAUTIC_ROOT_DIR', realpath($appDir.'/..'));
require $appDir.'/config/bootstrap.php';

// Mautic's kernel with the services this script uses made public, in a container of its own (Mautic's is not touched)
$kernel = new class($_SERVER['APP_ENV'], (bool) $_SERVER['APP_DEBUG']) extends AppKernel {
    protected function build(Symfony\Component\DependencyInjection\ContainerBuilder $container): void
    {
        parent::build($container);
        $container->addCompilerPass(new class implements Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface {
            public function process(Symfony\Component\DependencyInjection\ContainerBuilder $container): void
            {
                foreach (['form.factory', 'twig', 'event_dispatcher', 'router', 'request_stack', 'mautic.helper.integration', 'mautic.lead.model.lead'] as $id) {
                    $container->hasAlias($id) ? $container->getAlias($id)->setPublic(true) : $container->findDefinition($id)->setPublic(true);
                }
            }
        });
    }

    protected function getContainerClass(): string
    {
        return parent::getContainerClass().'EasySmoke';
    }

    // Mautic finds it from the file of the kernel class, which is this script here
    public function getProjectDir(): string
    {
        return $GLOBALS['root'];
    }
};
$kernel->boot();
$container = $kernel->getContainer();

// A request with a session, as in the browser (CSRF token, flash messages, absolute URLs)
$request = Request::create('https://mautic.example.com/s/plugins');
$request->setSession(new Session(new MockArraySessionStorage()));
$container->get('request_stack')->push($request);
$container->get('router')->getContext()->fromRequest($request);

// What Settings → Plugins does: list the integrations, which creates the settings row of a new one
$listed = $container->get('mautic.helper.integration')->getIntegrationObjects(null, null, true);
check(isset($listed['Easy']), 'listed in Settings → Plugins');

/** @var Config $config */
$config        = $container->get(Config::class);
$configuration = $config->getIntegrationConfiguration();
check(null !== $configuration, 'the "Easy" integration is installed (plugin_integration_settings)');

/** @var ConfigSupport $support */
$support = $container->get('mautic.integration.easy.configuration');
check('Easy Email Verification' === $support->getDisplayName(), 'settings class registered');
check('https://mautic.example.com/easy/callback' === $support->getRedirectUri(), 'OAuth callback URL: '.$support->getRedirectUri());
check('' === $support->getAuthorizationUrl(), 'saving the settings without "Authorize" does not start OAuth');

// The OAuth callback page, through Mautic's whole HTTP stack (firewall, API listener, ...): a forged request,
// refused without any network call
$callback = $kernel->handle(Request::create('https://mautic.example.com/easy/callback?code=eev_code_x&state=forged'));
check(400 === $callback->getStatusCode() && str_contains((string) $callback->getContent(), 'unknown or has expired'), 'OAuth callback page renders and refuses an unknown state');

// Settings saved by 1.x keep working: the same integration name, API key name and feature settings
$configuration->setApiKeys(['apikey' => 'eev_sandbox_key']);
$configuration->setFeatureSettings(['safe_to_send' => '0', 'result' => '1']);
$configuration->setIsPublished(true);
$config->saveApiKeys(['apikey' => 'eev_sandbox_key']);
check(['safe_to_send' => false, 'result' => true] === $config->getDecision(), '1.x feature settings are read (Result mode)');
check('eev_sandbox_key' === $config->getApiKey() && Config::AUTH_API_KEY === $config->getAuthMethod(), 'API key method with the saved key');

// The settings form renders with Mautic's own template (Twig) and keeps what is not one of its fields
$form = $container->get('form.factory')->create(IntegrationConfigType::class, $configuration, [
    'action'      => '/s/integration/Easy/config',
    'integration' => 'Easy',
]);
$html = $container->get('twig')->render('@Integrations/Config/form.html.twig', [
    'integrationObject'   => $support,
    'form'                => $form->createView(),
    'activeTab'           => null,
    'showFeaturesTab'     => true,
    'hasFeatureErrors'    => false,
    'hasAuthErrors'       => false,
    'useSyncFeatures'     => false,
    'useFeatureSettings'  => true,
    'useAuthorizationUrl' => true,
    'callbackUrl'         => $support->getRedirectUri(),
    'useConfigFormNotes'  => true,
]);
check(str_contains($html, 'integration_config[apiKeys][auth_method]'), 'form: authentication choice');
check(str_contains($html, 'integration_config[apiKeys][apikey]') && !str_contains($html, 'eev_sandbox_key'), 'form: API key field, value not sent to the browser');
check(str_contains($html, 'integration_config[featureSettings][integration][decision]'), 'form: decision choice');
check(str_contains($html, 'integration_details_authButton'), 'form: Authorize button');
check(str_contains($html, 'Each verification uses one credit'), 'form: note with the translations');

$keys = ['apikey' => 'eev_sandbox_key', 'oauth_refresh_token' => 'eev_rt_kept', 'oauth_client_id' => 'eev_dcr_kept'];
$configuration->setApiKeys($keys);
$form = $container->get('form.factory')->create(IntegrationConfigType::class, $configuration, ['integration' => 'Easy', 'csrf_protection' => false]);
$form->submit([
    'isPublished'     => '1',
    'apiKeys'         => ['auth_method' => 'apikey', 'apikey' => '', 'oauth_disconnect' => '0'],
    'featureSettings' => ['integration' => ['decision' => 'safe_to_send']],
], false);
$saved = $configuration->getApiKeys();
check($form->isValid(), 'form: submitted settings are valid');
check('eev_sandbox_key' === $saved['apikey'] && 'eev_rt_kept' === $saved['oauth_refresh_token'], 'form: blank API key and OAuth tokens kept on save');
check('safe_to_send' === ($configuration->getFeatureSettings()['integration']['decision'] ?? null), 'form: decision saved');

$configuration->setApiKeys(['apikey' => 'eev_sandbox_key']);
$config->saveApiKeys(['apikey' => 'eev_sandbox_key']);

// The condition is registered and listens to its own event
$dispatcher = $container->get('event_dispatcher');
$listeners  = array_map(fn ($l) => is_array($l) ? get_class($l[0]).'::'.$l[1] : 'closure', $dispatcher->getListeners(CheckEmailVerificationCondition::ON_EVALUATE));
check(in_array(CheckEmailVerificationCondition::class.'::onConditionValidate', $listeners, true), 'condition listener registered');

if (!$sandbox) {
    echo 'done (without --sandbox)'.PHP_EOL;
    exit(0);
}

/** @var LeadModel $leadModel */
$leadModel = $container->get('mautic.lead.model.lead');

$run = function (?string $email, string $decision, string $apiKey = 'eev_sandbox_key') use ($config, $configuration, $leadModel, $dispatcher): array {
    $configuration->setFeatureSettings(['integration' => ['decision' => $decision]]);
    $config->saveApiKeys(['apikey' => $apiKey]);

    $lead = new Lead();
    if ($email) {
        $lead->setEmail($email);
    }
    $leadModel->saveEntity($lead);

    $campaignEvent = new Event();
    $campaignEvent->setCampaign(new Campaign());
    $log = new LeadEventLog();
    $log->setLead($lead);
    $log->setEvent($campaignEvent);
    $event = new ConditionEvent(new ConditionAccessor(['eventName' => CheckEmailVerificationCondition::ON_EVALUATE]), $log);
    $dispatcher->dispatch($event, CheckEmailVerificationCondition::ON_EVALUATE);

    $fields = $leadModel->getEntity($lead->getId())->getProfileFields();

    return [$event->wasConditionSatisfied(), $fields['eev_result'] ?? null, $fields['eev_safe_to_send'] ?? null, $fields['eev_accept_all'] ?? null];
};

// address => [result, safe_to_send, accept_all, path in Safe To Send mode, path in Result mode]
$expected = [
    'valid@sandbox.easyemailverification.com'      => ['valid', 'true', 'false', true, true],
    'disposable@sandbox.easyemailverification.com' => ['valid', 'false', 'false', false, true],
    'catchall@sandbox.easyemailverification.com'   => ['valid', 'false', 'true', false, true],
    'invalid@sandbox.easyemailverification.com'    => ['invalid', 'false', 'false', false, false],
    'unknown@sandbox.easyemailverification.com'    => ['unknown', 'false', 'false', false, false],
];
foreach ($expected as $email => [$result, $safe, $acceptAll, $safeMode, $resultMode]) {
    foreach (['safe_to_send' => $safeMode, 'result' => $resultMode] as $decision => $path) {
        [$passed, $eevResult, $eevSafe, $eevAcceptAll] = $run($email, $decision);
        check($passed === $path && $eevResult === $result && $eevSafe === $safe && $eevAcceptAll === $acceptAll,
            sprintf('%-46s %-12s → %-3s eev_result=%s eev_safe_to_send=%s eev_accept_all=%s', $email, $decision, $passed ? 'yes' : 'no', $eevResult, $eevSafe, $eevAcceptAll));
    }
}

[$passed] = $run(null, 'safe_to_send');
check(false === $passed, 'contact without email → no');

[$passed, $eevResult] = $run('valid@sandbox.easyemailverification.com', 'safe_to_send', 'eev_wrong_key_for_smoke_test');
check(false === $passed && null === $eevResult, 'wrong API key → no, fields unchanged');

// Verification on save, through Mautic's real LeadModel (nested save of the result included)
$config->saveApiKeys(['apikey' => 'eev_sandbox_key']);
$configuration->setFeatureSettings(['integration' => ['decision' => 'safe_to_send']]);
$config->saveApiKeys(['apikey' => 'eev_sandbox_key']);

$formSave = function (?string $action) use ($request): void {
    $request->attributes->set('_route', $action ? 'mautic_contact_action' : 'mautic_plugin_index');
    $request->attributes->set('objectAction', $action);
};
$fieldsOf = fn (Lead $lead): array => $leadModel->getEntity($lead->getId())->getProfileFields();

$formSave('new');
$saved = new Lead();
$saved->setEmail('valid@sandbox.easyemailverification.com');
$leadModel->saveEntity($saved);
check('valid' === ($fieldsOf($saved)['eev_result'] ?? null), 'save in the contact form: new contact verified (eev_result=valid)');

// already verified with the same email: no new call (a stored result changed by hand stays as it is)
$saved = $leadModel->getEntity($saved->getId());
$saved->addUpdatedField('eev_result', 'kept');
$leadModel->saveEntity($saved);
$formSave('edit');
$saved = $leadModel->getEntity($saved->getId());
$saved->addUpdatedField('firstname', 'Smoke');
$leadModel->saveEntity($saved);
check('kept' === ($fieldsOf($saved)['eev_result'] ?? null), 'save in the contact form: same email, not verified again');

$saved = $leadModel->getEntity($saved->getId());
$saved->addUpdatedField('email', 'invalid@sandbox.easyemailverification.com');
$leadModel->saveEntity($saved);
check('invalid' === ($fieldsOf($saved)['eev_result'] ?? null), 'save in the contact form: changed email verified again (eev_result=invalid)');

$formSave(null);
$imported           = new Lead();
$imported->imported = true;
$imported->setEmail('valid@sandbox.easyemailverification.com');
$leadModel->saveEntity($imported);
check(empty($fieldsOf($imported)['eev_result']), 'import: not verified by default');

$other = new Lead();
$other->setEmail('valid@sandbox.easyemailverification.com');
$leadModel->saveEntity($other);
check(empty($fieldsOf($other)['eev_result']), 'save outside the contact form (command): not verified by default');

$configuration->setFeatureSettings(['integration' => ['decision' => 'safe_to_send', 'verify_other_sources' => 1]]);
$config->saveApiKeys(['apikey' => 'eev_sandbox_key']);
$imported           = new Lead();
$imported->imported = true;
$imported->setEmail('unknown@sandbox.easyemailverification.com');
$leadModel->saveEntity($imported);
check('unknown' === ($fieldsOf($imported)['eev_result'] ?? null), 'import with "Also verify on import/API": verified (eev_result=unknown)');

// "Verify email now": button on the contact page and the route behind it
// the event reads the route when it is created
$formSave('view');
$buttons = new Mautic\CoreBundle\Event\CustomButtonEvent(Mautic\CoreBundle\Twig\Helper\ButtonHelper::LOCATION_PAGE_ACTIONS, $request, [], $leadModel->getEntity($saved->getId()));
$dispatcher->dispatch($buttons, Mautic\CoreBundle\CoreEvents::VIEW_INJECT_CUSTOM_BUTTONS);
// no signed-in user in this script: the button is there, disabled, with the reason
$ours = array_values(array_filter($buttons->getButtons(), fn (array $b): bool => 'Verify email now' === ($b['btnText'] ?? null)));
if (1 !== count($ours)) {
    echo json_encode($buttons->getButtons()).PHP_EOL;
}
check(1 === count($ours) && str_contains((string) $ours[0]['attr']['class'], 'disabled') && 'You do not have permission to edit this contact.' === ($ours[0]['attr']['title'] ?? null),
    '"Verify email now" button on the contact page (disabled without permission)');
check(str_ends_with($container->get('router')->generate('mautic_easy_verify', ['objectId' => 5]), '/s/easy/verify/5'), '"Verify email now" route: /s/easy/verify/{id}');

echo 'done'.PHP_EOL;
