<?php

/**
 * ISPConfig Connect for WHMCS - provisioning module entry file.
 *
 * Thin procedural WHMCS surface (spec 2.8): every function delegates to the
 * lib/ classes (autoloaded by WHMCS under WHMCS\Module\Server\Ispconfigconnect
 * from this module's lib/ directory), catches EVERY Throwable, and returns
 * the WHMCS-conventional 'success' or error string. Error strings carry the
 * exception message only - never a stack trace.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

use WHMCS\Module\Server\Ispconfigconnect\Api\Exception\ApiDisabledException;
use WHMCS\Module\Server\Ispconfigconnect\Api\Exception\IpNotAllowedException;
use WHMCS\Module\Server\Ispconfigconnect\Api\Exception\IspconfigException;
use WHMCS\Module\Server\Ispconfigconnect\Api\Exception\LockoutException;
use WHMCS\Module\Server\Ispconfigconnect\Api\Exception\LoginFailedException;
use WHMCS\Module\Server\Ispconfigconnect\Api\Exception\MaintenanceModeException;
use WHMCS\Module\Server\Ispconfigconnect\Api\Exception\MalformedResponseException;
use WHMCS\Module\Server\Ispconfigconnect\Api\Exception\PermissionDeniedException;
use WHMCS\Module\Server\Ispconfigconnect\Api\Exception\SessionExpiredException;
use WHMCS\Module\Server\Ispconfigconnect\Api\Exception\TransportException;
use WHMCS\Module\Server\Ispconfigconnect\Api\Permissions;
use WHMCS\Module\Server\Ispconfigconnect\Api\SessionManager;
use WHMCS\Module\Server\Ispconfigconnect\Metrics\Provider as MetricsProvider;
use WHMCS\Module\Server\Ispconfigconnect\Provision\ClientLockedException;
use WHMCS\Module\Server\Ispconfigconnect\Provision\CreateResult;
use WHMCS\Module\Server\Ispconfigconnect\Provision\Mapper;
use WHMCS\Module\Server\Ispconfigconnect\Provision\Recipe;
use WHMCS\Module\Server\Ispconfigconnect\Server\RemoteMetaData;
use WHMCS\Module\Server\Ispconfigconnect\Sso\CompanionClient;
use WHMCS\Module\Server\Ispconfigconnect\Sso\Exception\CompanionGrantMissingException;
use WHMCS\Module\Server\Ispconfigconnect\Sso\Exception\CompanionNotInstalledException;
use WHMCS\Module\Server\Ispconfigconnect\Sso\SsoAdvice;
use WHMCS\Module\Server\Ispconfigconnect\Support\Factory;
use WHMCS\Module\Server\Ispconfigconnect\Support\Params;
use WHMCS\Module\Server\Ispconfigconnect\Support\ServiceProperties;
use WHMCS\Module\Server\Ispconfigconnect\Sync\AccountLister;
use WHMCS\Module\Server\Ispconfigconnect\Sync\RemoteAccount;
use WHMCS\Module\Server\Ispconfigconnect\Usage\Collector;
use WHMCS\Module\Server\Ispconfigconnect\Usage\ServiceRef;
use WHMCS\Module\Server\Ispconfigconnect\Usage\Updater;
// WHMCS core classes (ionCube-encoded, absent from the test runtime): only the
// usage-billing surface, and only where the documentation shows them
// (developers.whmcs.com/provisioning-modules/usage-metrics/).
use WHMCS\UsageBilling\Contracts\Metrics\MetricInterface;
use WHMCS\UsageBilling\Contracts\Metrics\ProviderInterface;
use WHMCS\UsageBilling\Metrics\Metric;
use WHMCS\UsageBilling\Metrics\Units\Accounts;
use WHMCS\UsageBilling\Metrics\Units\Domains;
use WHMCS\UsageBilling\Metrics\Units\MegaBytes;
use WHMCS\UsageBilling\Metrics\Usage;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * Module capabilities (developers.whmcs.com/provisioning-modules/meta-data-params/).
 *
 * The three ListAccounts* keys are what makes WHMCS offer the Server Sync
 * ("Sync Accounts") button; they live next to the mapping that satisfies them,
 * in Sync\AccountLister.
 *
 * The single sign-on label is CONDITIONAL, and its DEFAULT IS ABSENT.
 * ISPConfig core has no one-time-login API at all, so SSO exists only on panels
 * where the operator has separately installed the ISPConfig SSO project
 * (repository ispconfig-sso - a standalone GPLv3 panel add-on this module does
 * not ship and does not require). A fresh install of this module therefore
 * advertises no label at all: advertising it unconditionally would render a
 * button that can only fail. MetaData() gets no $params and must not make API
 * calls, so the answer comes from the presence probe that GetRemoteMetaData
 * already cached in tblservers_remote.
 *
 * THIS LABEL IS FLEET-WIDE AND CANNOT BE MADE PER-SERVER: WHMCS asks whether the
 * MODULE supports single sign-on, with no server in scope, so the answer here is
 * "at least one ISPConfig server has the add-on". On a mixed fleet that is not a
 * per-customer answer, and the per-customer answer is given where server context
 * exists instead: ispconfigconnect_ClientArea() renders the login button only
 * for services whose OWN panel reported the add-on, and
 * ispconfigconnect_ServiceSingleSignOn() answers anyone who reaches the SSO path
 * anyway with a customer-safe sentence. Both halves are needed - remove this
 * label and WHMCS no longer advertises the capability at all; remove the gate
 * and customers on a panel without the add-on get a button that can only fail.
 *
 * AdminSingleSignOnLabel is deliberately never advertised: that button is
 * SERVER-level ("Login to Panel as Admin"), and the SSO project refuses to mint
 * tokens for administrator accounts by design (an admin token would be a full
 * panel takeover). ispconfigconnect_AdminSingleSignOn() still exists and works
 * wherever WHMCS calls it with a service in scope.
 *
 * @param mixed $ssoProbe test seam - a callable(): list<array<string, mixed>>.
 *        Typed `mixed` rather than `?callable` on purpose: WHMCS calls this
 *        function with no arguments, and a typed parameter would turn any
 *        future core change that passes one into a fatal error.
 * @return array<string, mixed>
 */
function ispconfigconnect_MetaData(mixed $ssoProbe = null): array
{
    $meta = [
        'DisplayName' => 'ISPConfig Connect',
        'APIVersion' => '1.1',
        'RequiresServer' => true,
        'DefaultSSLPort' => '8080',
        'DefaultNonSSLPort' => '8080',
    ];

    foreach (AccountLister::WHMCS_METADATA as $key => $value) {
        $meta[$key] = $value;
    }

    if (ispconfigconnect_ssoAvailable(is_callable($ssoProbe) ? $ssoProbe : null)) {
        $meta['ServiceSingleSignOnLabel'] = 'Log in to ISPConfig';
    }

    return $meta;
}

/**
 * Product configuration options. The ORDER IS FROZEN (spec 3.1): WHMCS stores
 * values by position (configoption1..14), so reordering after ship would
 * silently reassign every existing product's settings.
 *
 * @return array<string, array<string, mixed>>
 */
function ispconfigconnect_ConfigOptions(): array
{
    return [
        'Client Template' => [
            'Type' => 'dropdown',
            'Loader' => 'ispconfigconnect_ClientTemplateLoader',
            // SimpleMode is REQUIRED for Loader-backed fields: WHMCS only
            // invokes the loader "when simple mode is used" (developer docs,
            // provisioning-modules/loader-functions/ - the Loader example
            // ships 'SimpleMode' => true). Without it the dropdown stays
            // empty.
            'SimpleMode' => true,
            'Description' => 'ISPConfig master client template (limits) applied to new clients',
        ],
        'Web Server' => [
            'Type' => 'dropdown',
            'Loader' => 'ispconfigconnect_WebServerLoader',
            'SimpleMode' => true,
            'Description' => 'ISPConfig server that hosts the website, mail, DNS and database',
        ],
        'Create Website' => [
            'Type' => 'yesno',
            'Default' => 'on',
            'Description' => 'Create a website (vhost) for the service domain',
        ],
        'Create FTP User' => [
            'Type' => 'yesno',
            'Default' => 'on',
            'Description' => 'Create an FTP user for the website (requires Create Website)',
        ],
        'Create Mail Domain' => [
            'Type' => 'yesno',
            'Description' => 'Create a mail domain for the service domain',
        ],
        'Create DNS Zone' => [
            'Type' => 'yesno',
            'Description' => 'Create a DNS zone from the DNS template below',
        ],
        'DNS Template ID' => [
            'Type' => 'text',
            'Size' => '5',
            'Default' => '1',
            'Description' => 'ISPConfig DNS template id (1 = the stock Default template)',
        ],
        'NS1' => [
            'Type' => 'text',
            'Size' => '30',
            'Description' => 'Primary nameserver for created DNS zones',
        ],
        'NS2' => [
            'Type' => 'text',
            'Size' => '30',
            'Description' => 'Secondary nameserver for created DNS zones',
        ],
        'DNS Admin Email' => [
            'Type' => 'text',
            'Size' => '30',
            'Description' => 'SOA contact for created zones (empty = admin@<service domain>)',
        ],
        'Create Database' => [
            'Type' => 'yesno',
            'Description' => 'Create a MySQL database and database user',
        ],
        'PHP Mode' => [
            'Type' => 'dropdown',
            'Options' => [
                'php-fpm' => 'PHP-FPM',
                'fast-cgi' => 'FastCGI',
                'mod' => 'mod_php',
                'suphp' => 'suPHP',
                'cgi' => 'CGI',
                'no' => 'None (no PHP)',
            ],
            'Default' => 'php-fpm',
            'Description' => 'PHP handler for created websites',
        ],
        // Position 13 is frozen; only the label and semantics changed when
        // the option was reworked from "also disable website" to the
        // entire-client/website-only choice.
        'Suspend Entire Client' => [
            'FriendlyName' => 'Suspend Entire Client',
            'Type' => 'yesno',
            'Default' => 'on',
            // The blast radius must be spelled out: ISPConfig's client lock
            // (functions.inc.php func_client_lock) cascades over everything
            // the client owns.
            'Description' => 'Checked: suspension locks the ISPConfig client. ISPConfig\'s lock disables'
                . ' ALL of the client\'s objects - websites, mail domains, FTP users, databases, cron'
                . ' and shell - and unsuspension restores exactly what the lock disabled.'
                . ' Unchecked: website-only suspension - only this service\'s website is set inactive;'
                . ' mail, FTP and databases keep working. Website-only needs the service\'s ISPConfig'
                . ' website id (stored by Create Account, or by Link / Replace ISPConfig Link when the'
                . ' service domain matches one of the client\'s websites) and refuses to act without it -'
                . ' it never falls back to locking the whole client.',
        ],
        'Panel URL Override' => [
            'Type' => 'text',
            'Size' => '40',
            // The requirement is stated here because enforcement is silent:
            // the value is rendered as a link and used as an SSO redirect
            // target, so anything that is not an absolute http(s) URL is
            // ignored in favour of the server-derived address rather than
            // shown to a customer (ispconfigconnect_panelUrlOverride()).
            'Description' => 'Panel URL shown to clients (empty = https://<hostname>:<port>/).'
                . ' Must be an absolute http:// or https:// URL with no query string;'
                . ' anything else is ignored.',
        ],
    ];
}

/**
 * Dropdown loader for option 1: master client templates (template_type 'm').
 * Loader errors must PROPAGATE - WHMCS renders them inline (spec 2.8).
 *
 * @param array<string, mixed> $params
 * @return array<int, string> template id => template name
 */
function ispconfigconnect_ClientTemplateLoader(array $params): array
{
    $session = Factory::sessionManager($params);
    try {
        $templates = $session->call('client_templates_get_all', []);

        $options = [];
        foreach (is_array($templates) ? $templates : [] as $row) {
            if (is_array($row) && (string) ($row['template_type'] ?? '') === 'm') {
                $options[(int) $row['template_id']] = (string) $row['template_name'];
            }
        }

        return $options;
    } finally {
        $session->close();
    }
}

/**
 * Dropdown loader for option 2: ISPConfig servers.
 *
 * @param array<string, mixed> $params
 * @return array<int, string> server id => server name
 */
function ispconfigconnect_WebServerLoader(array $params): array
{
    $session = Factory::sessionManager($params);
    try {
        $servers = $session->call('server_get_all', []);

        $options = [];
        foreach (is_array($servers) ? $servers : [] as $row) {
            if (is_array($row)) {
                $options[(int) $row['server_id']] = (string) $row['server_name'];
            }
        }

        return $options;
    } finally {
        $session->close();
    }
}

/**
 * Test Connection: login, the read-grant probes, and the single sign-on state.
 *
 * WHY SSO IS REPORTED HERE. Pressing this button is the one moment an admin is
 * looking at ONE server and asking what it can do, which makes it the right
 * place to answer the question the module is otherwise silent about. The answer
 * costs one already-granted call on the session the grant probes just opened.
 *
 * SSO IS OPTIONAL, so its absence never fails the test and never reads as an
 * error: on success it is an informational line, and on failure the existing
 * actionable advice is left intact - only the one genuinely actionable SSO
 * finding (the probe grant is missing, which is a grant this module requires
 * anyway) is appended to it.
 *
 * The same line also goes to the module log, because how WHMCS renders the
 * message of a SUCCESSFUL test is core's business and not verifiable from here;
 * an admin who sees nothing in the UI can still read it in Configuration >
 * System Logs > Module Log.
 *
 * @param array<string, mixed> $params
 * @return array{success: bool, error: string}
 */
function ispconfigconnect_TestConnection(array $params): array
{
    try {
        $session = Factory::sessionManager($params);
        try {
            $missingGrants = ispconfigconnect_missingGrants($session);
            $ssoState = ispconfigconnect_ssoState($params, $session);
        } finally {
            $session->close();
        }

        if ($missingGrants !== []) {
            $error = 'Connected and logged in, but the remote user is missing remote_functions grants: '
                . implode(', ', $missingGrants)
                . '. Enable them on the remote user under System > Remote Users in ISPConfig'
                . ' (docs/REMOTE-USER.md documents the full grant list).';

            // Only the state that IS a missing grant is added here; "the panel
            // has no SSO add-on" is not a permission problem and would only
            // bury the list above.
            if ($ssoState === SsoAdvice::STATE_PROBE_DENIED) {
                $error .= ' Single sign-on: ' . SsoAdvice::admin($ssoState);
            }

            ispconfigconnect_logTestConnection($params, false, $ssoState);

            return ['success' => false, 'error' => $error];
        }

        ispconfigconnect_logTestConnection($params, true, $ssoState);

        return [
            'success' => true,
            'error' => 'Connected. Single sign-on: ' . SsoAdvice::admin($ssoState),
        ];
    } catch (\Throwable $failure) {
        // The panel never answered: nothing is known about single sign-on, and
        // saying anything about it would only compete with the real reason.
        return ['success' => false, 'error' => ispconfigconnect_connectionAdvice($failure)];
    }
}

/**
 * @param array<string, mixed> $params
 * @return string 'success' or error string
 */
function ispconfigconnect_CreateAccount(array $params): string
{
    try {
        $properties = ispconfigconnect_serviceProperties($params);

        // Double-provision guard: WHMCS retries and admin re-runs must never
        // create a second ISPConfig client for the same service (spec 2.8).
        $existingClientId = $properties->clientId();
        if ($existingClientId !== null) {
            return sprintf(
                'Create Account refused: this service is already linked to ISPConfig client #%d.'
                . ' Terminate the service (or clear its ispconfig_* service properties) before provisioning again.',
                $existingClientId
            );
        }

        // Empty-credential guard: ISPConfig's *_add forms treat an absent
        // PASSWORD field as "skip" (tform_base.inc.php _getSQL), so an empty
        // service password would silently provision panel/FTP/MySQL logins
        // with blank credentials. Refuse before any API call.
        if ((string) ($params['password'] ?? '') === '') {
            return 'Create Account refused: the service has no password.'
                . ' ISPConfig would create the client, FTP and database users with empty'
                . ' credentials - set a service password (or let WHMCS generate one) and retry.';
        }

        $generatedUsername = null;
        if (trim((string) ($params['username'] ?? '')) === '') {
            $generatedUsername = ispconfigconnect_generateUsername($params);
            $params['username'] = $generatedUsername;
        }

        $product = Params::productConfig($params);
        $service = Params::service($params);

        $session = Factory::sessionManager($params);
        try {
            $result = (new Recipe($session))->create($product, $service);

            // Persist every created id IMMEDIATELY - anything that runs
            // between provisioning and this save is a window in which a
            // failure orphans live (billable) ISPConfig objects that WHMCS
            // has no record of.
            try {
                $properties->setClientId($result->clientId);
                $properties->setClientUsername($result->username);
                if ($result->domainId !== null) {
                    $properties->setDomainId($result->domainId);
                }
                if ($result->ftpUserId !== null) {
                    $properties->setFtpUserId($result->ftpUserId);
                }
                if ($result->mailDomainId !== null) {
                    $properties->setMailDomainId($result->mailDomainId);
                }
                if ($result->dnsZoneId !== null) {
                    $properties->setDnsZoneId($result->dnsZoneId);
                }
                if ($result->databaseUserId !== null) {
                    $properties->setDatabaseUserId($result->databaseUserId);
                }
                if ($result->databaseId !== null) {
                    $properties->setDatabaseId($result->databaseId);
                }
            } catch (\Throwable $saveFailure) {
                // The ISPConfig side succeeded; only the WHMCS bookkeeping
                // failed. No rollback (the objects are live) - name every
                // created id so the operator can reconcile or terminate
                // manually.
                return sprintf(
                    'Create Account provisioned ISPConfig successfully but FAILED to store the'
                    . ' service properties (%s). Created ISPConfig objects: %s.'
                    . ' Reconcile manually: either set the ispconfig_* service properties to these'
                    . ' ids, or delete client #%d in ISPConfig before re-running Create.',
                    trim($saveFailure->getMessage()) !== ''
                        ? trim($saveFailure->getMessage())
                        : get_class($saveFailure),
                    ispconfigconnect_createdIdSummary($result),
                    $result->clientId
                );
            }
        } finally {
            $session->close();
        }

        if ($generatedUsername !== null) {
            // Cosmetic write-back of the generated username to the WHMCS
            // service record (admin/client area display). The authoritative
            // copy is already stored as a service property, so a failure
            // here must not fail the successful create.
            try {
                $model = $params['model'] ?? null;
                if (is_object($model)) {
                    $model->username = $generatedUsername;
                    if (method_exists($model, 'save')) {
                        $model->save();
                    }
                }
            } catch (\Throwable) {
                // Ignored: display-only convenience.
            }
        }

        return 'success';
    } catch (\Throwable $failure) {
        return ispconfigconnect_errorString('Create Account', $failure);
    }
}

/**
 * @param array<string, mixed> $params
 * @return string 'success' or error string
 */
function ispconfigconnect_SuspendAccount(array $params): string
{
    try {
        $properties = ispconfigconnect_serviceProperties($params);
        $clientId = $properties->clientId();
        if ($clientId === null) {
            // Rule 2: suspension WRITES panel state (it locks the client, or
            // sets the website inactive), so it refuses rather than resolving.
            return ispconfigconnect_unlinkedRefusal('Suspend', $params);
        }

        $product = Params::productConfig($params);
        $domainId = $properties->domainId();
        $recorded = $properties->suspendedAs();

        // Already suspended under the whole-client lock (the product may have
        // changed since): stay in that mode. The lock covers this service's
        // website too, and Recipe::suspend() re-locks only a client that is not
        // locked any more, so the lock's record of what to restore is kept.
        $entireClient = $recorded === ServiceProperties::SUSPENDED_AS_CLIENT || $product->suspendEntireClient;

        // Website-only mode acts on this service's website and nothing else,
        // so without the website's id there is nothing it can safely do. It
        // refuses here, before a session is opened, instead of either
        // "succeeding" while the site stays live or widening to the
        // whole-client lock the admin deliberately did not choose (which would
        // take down mail, FTP and databases - and every other service on a
        // shared client). Recipe::suspend() refuses the same case as a backstop.
        if (!$entireClient && $domainId === null) {
            return ispconfigconnect_noWebsiteIdRefusal('Suspend', 'suspend');
        }

        // Entire-client suspension locks the whole client, so refuse if another
        // live service shares it. Website-only mode touches just this service's
        // own site and is allowed even on a shared client. A guard that could
        // not run (null) has already logged why; suspension still proceeds.
        if ($entireClient) {
            $shared = ispconfigconnect_sharedClientRefusal(
                'Suspend',
                $clientId,
                ispconfigconnect_otherServicesLinkedToClient((int) ($params['serviceid'] ?? 0), $clientId) ?? []
            );
            if ($shared !== null) {
                return $shared;
            }
        }

        $session = Factory::sessionManager($params);
        try {
            $recipe = new Recipe($session);
            // Suspended website-only before, whole-client now (the product
            // changed in between): put the website back first, so the lock's
            // snapshot records it as active and the unlock brings it back.
            if ($entireClient && $recorded === ServiceProperties::SUSPENDED_AS_WEBSITE && $domainId !== null) {
                $recipe->unsuspend($clientId, $domainId, false);
            }
            $recipe->suspend($clientId, $domainId, $entireClient);
        } finally {
            $session->close();
        }

        ispconfigconnect_recordSuspension(
            $properties,
            $params,
            $entireClient ? ServiceProperties::SUSPENDED_AS_CLIENT : ServiceProperties::SUSPENDED_AS_WEBSITE
        );

        return 'success';
    } catch (\Throwable $failure) {
        return ispconfigconnect_errorString('Suspend', $failure);
    }
}

/**
 * @param array<string, mixed> $params
 * @return string 'success' or error string
 */
function ispconfigconnect_UnsuspendAccount(array $params): string
{
    try {
        $properties = ispconfigconnect_serviceProperties($params);
        $clientId = $properties->clientId();
        if ($clientId === null) {
            // Rule 2: unsuspension writes panel state exactly as suspension
            // does, and restoring the WRONG client is its own outage.
            return ispconfigconnect_unlinkedRefusal('Unsuspend', $params);
        }

        $product = Params::productConfig($params);
        $domainId = $properties->domainId();

        // REVERSE WHAT WAS DONE, NOT WHAT THE PRODUCT SAYS NOW. The "Suspend
        // Entire Client" box can change, and the service can move to another
        // product, while it is suspended. Unsuspending in the product's current
        // mode then undoes the wrong thing: a whole-client lock would stay on
        // (mail, FTP and databases down while WHMCS shows Active), or a
        // website-only suspension would stay in place behind a no-op unlock.
        // The mode Suspend recorded decides; the product's setting only stands
        // in for suspensions made before the mode was recorded.
        $recorded = $properties->suspendedAs();
        $entireClient = $recorded !== null
            ? $recorded === ServiceProperties::SUSPENDED_AS_CLIENT
            : $product->suspendEntireClient;

        // Symmetric with Suspend: website-only mode without the website's id
        // has nothing safe to act on.
        if (!$entireClient && $domainId === null) {
            return ispconfigconnect_noWebsiteIdRefusal('Unsuspend', 'unsuspend');
        }

        // THE UNLOCK ON A SHARED CLIENT. Suspend refuses a shared client
        // outright, but the unlock cannot be that strict: a client locked by an
        // older build (which had no shared-client guard) would then stay locked
        // forever, and WHMCS's automatic unsuspend-on-payment would leave a
        // paying customer offline. What the unlock must not do is restore a
        // DIFFERENT service that WHMCS still has suspended - so that, and only
        // that, is refused. When every other sharer is live, unlocking takes
        // nobody anywhere they are not entitled to be.
        if ($entireClient) {
            $serviceId = (int) ($params['serviceid'] ?? 0);
            // null = the guard could not run and has logged why; proceed.
            $others = ispconfigconnect_otherServicesLinkedToClient($serviceId, $clientId) ?? [];
            $suspended = ispconfigconnect_serviceStatus('SUSPENDED', 'Suspended');
            $suspendedOthers = array_values(array_filter(
                $others,
                static fn (array $other): bool => $other['status'] === $suspended
            ));
            if ($suspendedOthers !== []) {
                return ispconfigconnect_sharedUnsuspendRefusal($clientId, $suspendedOthers);
            }
            if ($others !== []) {
                // Allowed, but a shared client is never touched silently: the
                // log names who else was on it and why the unlock went ahead.
                ispconfigconnect_logModuleCall(
                    'UnsuspendAccount',
                    ['serviceid' => $serviceId, 'ispconfig_client_id' => $clientId],
                    [
                        'shared_client' => true,
                        'other_services' => ispconfigconnect_describeServices($others),
                        'decision' => 'unlock allowed: no other service on this ISPConfig client is Suspended',
                    ]
                );
            }
        }

        $session = Factory::sessionManager($params);
        try {
            $recipe = new Recipe($session);
            $unlocked = $recipe->unsuspend($clientId, $domainId, $entireClient);

            // No recorded mode, a whole-client product, and no lock to lift:
            // the one suspension that can still be in place is a website-only
            // one made before the product switched to the whole-client lock.
            // Reactivate that website - and only if it is actually off.
            if ($entireClient && !$unlocked && $recorded === null && $domainId !== null) {
                // Best effort on top of an unsuspend that has already done its
                // job: a panel refusal here (a missing grant, say) is logged,
                // not turned into a failed Unsuspend that keeps a paying
                // customer suspended in WHMCS. Connection-level failures still
                // propagate like everywhere else.
                try {
                    $reactivated = $recipe->reactivateWebsiteIfInactive($domainId);
                    $decision = $reactivated
                        ? 'the ISPConfig client was not locked, but this service\'s website was inactive - a'
                            . ' website-only suspension from before the product switched to "Suspend Entire'
                            . ' Client" - so the website was set active again'
                        : null;
                } catch (IspconfigException $failure) {
                    if (ispconfigconnect_isConnectionLevel($failure)) {
                        throw $failure;
                    }
                    $decision = 'could not check whether this service\'s website is still inactive: '
                        . ispconfigconnect_failureText($failure);
                }
                if ($decision !== null) {
                    ispconfigconnect_logModuleCall(
                        'UnsuspendAccount',
                        ['serviceid' => (int) ($params['serviceid'] ?? 0), 'ispconfig_domain_id' => $domainId],
                        ['decision' => $decision]
                    );
                }
            }
        } finally {
            $session->close();
        }

        ispconfigconnect_recordSuspension($properties, $params, null);

        return 'success';
    } catch (\Throwable $failure) {
        return ispconfigconnect_errorString('Unsuspend', $failure);
    }
}

/**
 * Record how the service is suspended on the panel (or that it no longer is),
 * for Unsuspend to reverse. The panel change it describes has already been
 * made, so a failure to store it must not turn that into an error: it is
 * logged, and Unsuspend falls back to the product's setting.
 *
 * @param array<string, mixed> $params
 * @param ServiceProperties::SUSPENDED_AS_*|null $mode
 */
function ispconfigconnect_recordSuspension(ServiceProperties $properties, array $params, ?string $mode): void
{
    try {
        $properties->setSuspendedAs($mode);
    } catch (\Throwable $failure) {
        ispconfigconnect_logModuleCall(
            'RecordSuspension',
            ['serviceid' => (int) ($params['serviceid'] ?? 0), 'suspended_as' => $mode ?? ''],
            ['error' => ispconfigconnect_failureText($failure)]
        );
    }
}

/**
 * @param array<string, mixed> $params
 * @return string 'success' or error string
 */
function ispconfigconnect_TerminateAccount(array $params): string
{
    try {
        $properties = ispconfigconnect_serviceProperties($params);
        $clientId = $properties->clientId();
        if ($clientId === null) {
            // RULE 2, AT ITS SHARPEST. This is the one operation whose mistake
            // cannot be undone: client_delete_everything cascades over every
            // website, mailbox, database and DNS zone the client owns. So
            // termination NEVER resolves an account - not even when exactly one
            // candidate exists and every other surface would happily link it.
            // An admin who sees this refusal links the service deliberately and
            // retries; that extra step is the whole point.
            return ispconfigconnect_unlinkedRefusal('Terminate', $params);
        }

        // One WHMCS service maps to one ISPConfig client. If another live
        // service also points at this client, client_delete_everything would
        // delete THEIR hosting too, so refuse before opening a session.
        $others = ispconfigconnect_otherServicesLinkedToClient((int) ($params['serviceid'] ?? 0), $clientId);
        if ($others === null) {
            // FAIL CLOSED. Suspend, Unsuspend, Change Password, Change Package
            // and Link proceed when the check cannot run, because refusing
            // them would strand a customer over a database hiccup; this one
            // deletes a client and everything it owns, and an unverified
            // "nobody else uses it" is not good enough for that.
            return sprintf(
                'Terminate refused: the module could not verify that no other WHMCS service shares'
                . ' ISPConfig client #%d - the check against the WHMCS database failed (the module log'
                . ' has the error under "SharedClientGuard"). Terminating deletes the client and'
                . ' everything it owns, so it is never done unverified. Nothing has been changed;'
                . ' retry once the WHMCS database answers normally.',
                $clientId
            );
        }
        $shared = ispconfigconnect_sharedClientRefusal('Terminate', $clientId, $others);
        if ($shared !== null) {
            return $shared;
        }

        $session = Factory::sessionManager($params);
        try {
            // RULE 5 in the destructive path. A stored id that no longer
            // resolves must not be deleted "through" - the module says stale
            // and stops. It also never looks for a replacement: re-pointing a
            // stale link inside a delete is how one customer's termination
            // removes another customer's hosting.
            if (!ispconfigconnect_clientExists($session, $clientId)) {
                return ispconfigconnect_staleRefusal('Terminate', $clientId);
            }

            (new Recipe($session))->terminate($clientId);
        } finally {
            $session->close();
        }

        // Only after the panel confirmed the delete: forget every stored id
        // so a later CreateAccount can provision fresh (spec 2.8).
        $properties->clear();

        return 'success';
    } catch (\Throwable $failure) {
        return ispconfigconnect_errorString('Terminate', $failure);
    }
}

/**
 * @param array<string, mixed> $params
 * @return string 'success' or error string
 */
function ispconfigconnect_ChangePassword(array $params): string
{
    try {
        $properties = ispconfigconnect_serviceProperties($params);
        $clientId = $properties->clientId();
        if ($clientId === null) {
            // Rule 2: this OVERWRITES panel credentials - the client's own
            // login plus the FTP and database users. Rotating the wrong
            // client's password locks a customer out of their hosting.
            return ispconfigconnect_unlinkedRefusal('Change Password', $params);
        }

        // Empty-credential guard, before any API call: an empty rotation
        // would no-op the FTP/DB updates (updateQueryPrepare drops empty
        // PASSWORD-type fields) while leaving the old credentials live.
        $newPassword = (string) ($params['password'] ?? '');
        if ($newPassword === '') {
            return 'Change Password failed: WHMCS passed an empty password.';
        }

        // client_change_password rotates the CLIENT's panel login, which is
        // shared by every service pointing at this client; refuse if more than
        // one does, so one customer's rotation cannot lock out another's.
        // A guard that could not run (null) has logged why; proceed.
        $shared = ispconfigconnect_sharedClientRefusal(
            'Change Password',
            $clientId,
            ispconfigconnect_otherServicesLinkedToClient((int) ($params['serviceid'] ?? 0), $clientId) ?? []
        );
        if ($shared !== null) {
            return $shared;
        }

        $session = Factory::sessionManager($params);
        try {
            // Rotate everything the module provisioned with the service
            // password: panel login always, plus the FTP user and database
            // user whose ids were stored at create time.
            (new Recipe($session))->changePassword(
                $clientId,
                $newPassword,
                $properties->ftpUserId(),
                $properties->databaseUserId()
            );
        } finally {
            $session->close();
        }

        return 'success';
    } catch (\Throwable $failure) {
        return ispconfigconnect_errorString('Change Password', $failure);
    }
}

/**
 * @param array<string, mixed> $params
 * @return string 'success' or error string
 */
function ispconfigconnect_ChangePackage(array $params): string
{
    try {
        $properties = ispconfigconnect_serviceProperties($params);
        $clientId = $properties->clientId();
        if ($clientId === null) {
            // Rule 2: this rewrites the client's limits and template
            // assignments - applying one customer's package to another's
            // account silently changes what they are allowed to use.
            return ispconfigconnect_unlinkedRefusal('Change Package', $params);
        }

        // A package change rewrites the client's template and limits for the
        // whole client; refuse if another live service shares it, so one
        // customer's upgrade cannot silently re-limit another's account. A
        // guard that could not run (null) has logged why; proceed.
        $shared = ispconfigconnect_sharedClientRefusal(
            'Change Package',
            $clientId,
            ispconfigconnect_otherServicesLinkedToClient((int) ($params['serviceid'] ?? 0), $clientId) ?? []
        );
        if ($shared !== null) {
            return $shared;
        }

        $product = Params::productConfig($params);
        $service = Params::service($params);

        // Read BEFORE the update, from WHMCS's own record: whether this service
        // is suspended, i.e. whether what the update re-activates has to go
        // offline again. It is put back in the NEW product's mode: a service
        // suspended website-only under the old product and moved to a
        // whole-client product would otherwise stay live (its client is not
        // locked, and the update re-activates the website). A locked client
        // never gets this far - Recipe refuses it below.
        $domainId = $properties->domainId();
        $suspended = ispconfigconnect_serviceStatusOf($params) === ispconfigconnect_serviceStatus('SUSPENDED', 'Suspended');
        $reapplyEntireClient = $suspended && $product->suspendEntireClient;
        $reapplyWebsite = $suspended && !$product->suspendEntireClient && $domainId !== null;

        $reSuspendFailure = null;
        $reSuspendedAs = null;
        $session = Factory::sessionManager($params);
        try {
            // Addon reconciliation needs the template list only when at least
            // one 'ISPConfig <Addon-Template-Name>' configurable option exists
            // (design §3.4); with none, the desired addon set is empty and
            // changePackage removes any stale assignments.
            $hasAddonOptions = false;
            foreach (array_keys($service->configOptions) as $optionName) {
                if (str_starts_with((string) $optionName, 'ISPConfig ')) {
                    $hasAddonOptions = true;
                    break;
                }
            }
            $templates = $hasAddonOptions ? $session->call('client_templates_get_all', []) : [];
            $addonTemplateIds = Mapper::addonTemplateIds($service, is_array($templates) ? $templates : []);

            $recipe = new Recipe($session);
            try {
                $recipe->changePackage($clientId, $product, $addonTemplateIds);
            } catch (ClientLockedException) {
                // Nothing was sent: Recipe refuses a locked client before any
                // client_update (see Provision\ClientLockedException for why a
                // template change on a locked client destroys the unlock).
                return 'Change Package refused: this service\'s ISPConfig client is locked (suspended).'
                    . ' Changing its template now would make ISPConfig re-take the lock snapshot, and a'
                    . ' later unsuspend could no longer restore the client\'s websites, mail and databases.'
                    . ' Unsuspend the service first (or, if the lock was set by hand in ISPConfig, remove it'
                    . ' there), then change the package. Nothing has been changed.';
            }

            // WHY THE WEBSITE IS SUSPENDED AGAIN. client_update merges the
            // stored record and re-runs func_client_lock() on its 'locked'
            // value (remote.d/client.inc.php client_update); for an unlocked
            // client that is func_client_lock('n'), which re-activates the
            // client's objects - the website a website-only suspension set
            // inactive included. So a Suspended service would come back online
            // merely because its package changed. Re-applying the suspension
            // in the same session puts it back.
            //
            // What remains, and is ISPConfig's own behaviour rather than this
            // module's: an object an admin disabled BY HAND on an ACTIVE client
            // is re-enabled by any client_update, this one included. The module
            // cannot tell such an object from one that was simply off, so it
            // does not try; the limitation predates this build and is documented.
            if ($reapplyWebsite || $reapplyEntireClient) {
                try {
                    $recipe->suspend($clientId, $domainId, $reapplyEntireClient);
                    $reSuspendedAs = $reapplyEntireClient
                        ? ServiceProperties::SUSPENDED_AS_CLIENT
                        : ServiceProperties::SUSPENDED_AS_WEBSITE;
                } catch (\Throwable $failure) {
                    $reSuspendFailure = $failure;
                }
            }
        } finally {
            $session->close();
        }

        // The old product's custom-field rows do not follow the service:
        // re-save migrates every stored id onto the NEW product's fields so
        // later operations read them without the fallback.
        $properties->resaveAll();
        if ($reSuspendedAs !== null) {
            ispconfigconnect_recordSuspension($properties, $params, $reSuspendedAs);
        }

        if ($reSuspendFailure !== null) {
            // The package DID change, so this is not a plain failure - but the
            // website of a service WHMCS has suspended is now live, and that
            // must not read as 'success'.
            return sprintf(
                'Change Package applied the new package, but FAILED to suspend this Suspended service\'s'
                . ' %s again afterwards: %s. ISPConfig re-activated it when the package changed, so it is'
                . ' LIVE now. Press Suspend on this service to take it offline again.',
                $reapplyEntireClient
                    ? sprintf('ISPConfig client #%d', $clientId)
                    : sprintf('website (ISPConfig website #%d)', (int) $domainId),
                ispconfigconnect_failureText($reSuspendFailure)
            );
        }

        return 'success';
    } catch (\Throwable $failure) {
        return ispconfigconnect_errorString('Change Package', $failure);
    }
}

/**
 * Read-only admin panel rows (spec 2.8). Must render even when the panel is
 * unreachable: every live figure degrades to 'n/a', nothing throws.
 *
 * All live values share ONE session (one login), and the probes stop early on
 * a connection-level failure - repeating a failed login per row would feed
 * ISPConfig's 10-failures-per-5-minutes IP lockout while rendering a page.
 *
 * ## It is also an adoption moment
 *
 * Opening a service's tab is READ-ONLY as far as the panel is concerned, and it
 * already opens a session to fill the live figures. So an unlinked service is
 * resolved here too: an admin who points a migrated service at the ISPConfig
 * server and opens it finds it already linked, with the evidence on the row and
 * in the module log. A STORED id is never re-resolved (rule 4) - it is only
 * checked for staleness (rule 5).
 *
 * @param array<string, mixed> $params
 * @param null|callable $adoptionResolver test seam - see ispconfigconnect_adoptionResolver()
 * @return array<string, string>
 */
function ispconfigconnect_AdminServicesTabFields(array $params, ?callable $adoptionResolver = null): array
{
    try {
        $properties = ispconfigconnect_serviceProperties($params);

        $live = ispconfigconnect_adminLiveFields($params, $properties, $adoptionResolver);

        // Read AFTER the live pass, which may have adopted this service.
        $clientId = $properties->clientId();
        $username = $properties->clientUsername() ?? (string) ($params['username'] ?? '');
        $domainId = $properties->domainId();

        $fields = [
            // The plain-English state comes FIRST: it is the row that answers
            // "is this service managed, and if not why not".
            'ISPConfig Link' => htmlspecialchars($live['link'], ENT_QUOTES),
            'ISPConfig Client ID' => $clientId === null ? 'not linked' : (string) $clientId,
            'ISPConfig Username' => htmlspecialchars($username, ENT_QUOTES),
            'ISPConfig Domain ID' => $domainId === null ? 'n/a' : (string) $domainId,
            'ISPConfig Job Queue' => $live['jobqueue'],
        ];

        if ($clientId !== null) {
            // Live panel figures, not WHMCS's nightly tblhosting import: this
            // tab is where an admin checks whether the import is current.
            $fields['ISPConfig Disk Usage'] = $live['disk'];
            $fields['ISPConfig Bandwidth (this month)'] = $live['bandwidth'];
        }

        // Deliberately a STATUS, not a login link: minting a token here would
        // spend the client's single live token on a page render (the SSO
        // project keeps exactly one, and a new one invalidates its predecessor). The
        // working button is WHMCS's own SSO control, wired to
        // ispconfigconnect_ServiceSingleSignOn().
        $fields['ISPConfig Single Sign-On'] = $live['sso'];

        // Its own try: the panel address comes from the WHMCS server record,
        // and ServerConfig refuses an empty or malformed Hostname/IP by
        // throwing (a bad host would retarget the credential POST). Left to
        // the outer catch, that one bad field would blank EVERY row of this
        // tab - the link state and stored ids an admin needs most exactly when
        // something is misconfigured. So the row explains itself instead.
        try {
            $panelUrl = ispconfigconnect_panelUrl($params);
            $fields['ISPConfig Panel'] = '<a href="' . htmlspecialchars($panelUrl, ENT_QUOTES) . '" target="_blank">'
                . htmlspecialchars($panelUrl, ENT_QUOTES) . '</a>';
        } catch (\Throwable $failure) {
            $fields['ISPConfig Panel'] = htmlspecialchars(
                'Not available: the panel address cannot be built from this service\'s WHMCS server ('
                . ispconfigconnect_failureText($failure) . '). Correct the server\'s Hostname / IP Address'
                . ' under Configuration > System Settings > Servers.',
                ENT_QUOTES
            );
        }

        return $fields;
    } catch (\Throwable) {
        // Sample-module convention: on error, contribute no extra fields.
        return [];
    }
}

/**
 * @return array<string, string>
 */
function ispconfigconnect_AdminCustomButtonArray(): array
{
    return [
        'Verify Permissions' => 'VerifyPermissions',
        // The manual override, for the cases automation must not guess.
        // All three change only what WHMCS has STORED - none of them touches
        // the panel, so none of them can damage a customer's hosting.
        'Link to ISPConfig Account' => 'LinkAccount',
        'Replace ISPConfig Link' => 'RelinkAccount',
        'Unlink from ISPConfig' => 'UnlinkAccount',
    ];
}

/**
 * Admin custom button: link THIS service to a named ISPConfig account.
 *
 * ## Why the name comes from the service's Username field
 *
 * A WHMCS custom button posts nothing - it calls this function with the
 * service's own $params and no form of its own. The account name therefore has
 * to be a field the admin already edits on the service, and the Username field
 * is exactly that: it is on the same screen as the button, and it is the field
 * that would hold the panel login anyway.
 *
 * That is also what makes this safe enough to be a manual override at all. The
 * rejected workaround was pasting ISPConfig's INTERNAL CLIENT ID into a custom
 * field: an id is opaque, unverifiable by eye, and one wrong digit silently
 * addresses a different customer's account. A username is checked against the
 * panel before anything is written, and a typo almost always resolves to
 * nothing rather than to somebody else.
 *
 * Rule 4: an id that is already stored is never overwritten here. Naming the
 * account already stored is a no-op success; naming a DIFFERENT one is refused
 * and points at Replace ISPConfig Link, which is the explicit, deliberate way
 * to change a link.
 *
 * @param array<string, mixed> $params
 * @return string 'success' or error string
 */
function ispconfigconnect_LinkAccount(array $params): string
{
    return ispconfigconnect_link($params, 'LinkAccount', false);
}

/**
 * Admin custom button: point this service at a DIFFERENT ISPConfig account.
 *
 * The same verification as Link, minus the already-linked guard - this is the
 * button whose whole purpose is to change a stored id, so it says so in its
 * name rather than hiding behind a flag. Both the old and the new id are
 * logged, because the log is the only record that the service ever pointed
 * anywhere else.
 *
 * @param array<string, mixed> $params
 * @return string 'success' or error string
 */
function ispconfigconnect_RelinkAccount(array $params): string
{
    return ispconfigconnect_link($params, 'RelinkAccount', true);
}

/**
 * Admin custom button: forget the stored ISPConfig ids for this service.
 *
 * NOTHING ON THE PANEL IS TOUCHED - not a session, not a call. This exists so
 * that a wrong link is reversible without hand-editing custom fields, which is
 * the manoeuvre this whole feature was built to abolish. After an unlink the
 * service is simply unmanaged again, and automatic adoption may re-link it on
 * the evidence of its domain.
 *
 * @param array<string, mixed> $params
 * @return string 'success' or error string
 */
function ispconfigconnect_UnlinkAccount(array $params): string
{
    try {
        $properties = ispconfigconnect_serviceProperties($params);
        $clientId = $properties->clientId();
        $username = $properties->clientUsername();

        if ($clientId === null && $username === null) {
            return 'Unlink: this service is not linked to an ISPConfig account, so there was'
                . ' nothing to clear. Nothing on the panel has been changed.';
        }

        $properties->clear();

        ispconfigconnect_logModuleCall(
            'UnlinkAccount',
            ['serviceid' => $params['serviceid'] ?? null],
            [
                'cleared_ispconfig_client_id' => $clientId,
                'cleared_ispconfig_client_username' => $username,
                'panel_touched' => false,
            ]
        );

        return 'success';
    } catch (\Throwable $failure) {
        return ispconfigconnect_errorString('Unlink', $failure);
    }
}

/**
 * Admin custom button: run the read probes and report which remote_functions
 * grants the remote user is missing (spec 2.8), single sign-on included.
 *
 * WHAT MAY BE RETURNED HERE, AND WHY IT IS NOT EVERYTHING. WHMCS renders any
 * return other than the literal 'success' as a Module Command Error to staff
 * (developers.whmcs.com/provisioning-modules/custom-functions/: "the return
 * should be a user understandable error message, as it will display to staff
 * users"). There is no neutral channel. So this button reports FINDINGS - the
 * things an operator must act on - and an optional add-on that is simply not
 * installed is not one: dressing it as a Module Command Error would make a
 * correct setup look broken. A REFUSED SSO PROBE is a finding, because
 * get_function_list is a grant this module requires and without it the panel
 * cannot be asked at all.
 *
 * The full SSO state, findings or not, still reaches the module log, and the
 * admin service tab renders it on every service.
 *
 * @param array<string, mixed> $params
 * @return string 'success' or error string
 */
function ispconfigconnect_VerifyPermissions(array $params): string
{
    try {
        $session = Factory::sessionManager($params);
        try {
            $missingGrants = ispconfigconnect_missingGrants($session);
            $ssoState = ispconfigconnect_ssoState($params, $session);
        } finally {
            $session->close();
        }

        $findings = [];
        if ($missingGrants !== []) {
            $findings[] = 'The remote user is missing remote_functions grants: '
                . implode(', ', $missingGrants)
                . '. Enable them under System > Remote Users in ISPConfig;'
                . ' docs/REMOTE-USER.md documents the full grant list.';
        }
        if ($ssoState === SsoAdvice::STATE_PROBE_DENIED) {
            $findings[] = 'Single sign-on: ' . SsoAdvice::admin($ssoState);
        }

        ispconfigconnect_logModuleCall(
            'VerifyPermissions',
            ['serverid' => $params['serverid'] ?? null],
            [
                'missing_grants' => $missingGrants,
                'sso_state' => $ssoState,
                'sso' => SsoAdvice::admin($ssoState),
            ]
        );

        return $findings === [] ? 'success' : implode(' ', $findings);
    } catch (\Throwable $failure) {
        return ispconfigconnect_errorString('Verify Permissions', $failure);
    }
}

/**
 * Client-area overview: panel link + ISPConfig username, and the single sign-on
 * button for the services that can actually use one. Read-only - no API calls,
 * and NEVER a password or any other credential in the template variables
 * (design §3.6.3).
 *
 * ## Why the SSO button is decided HERE and not in MetaData()
 *
 * WHMCS asks MetaData() whether the MODULE supports single sign-on with no
 * server in scope at all, so ServiceSingleSignOnLabel is one answer for the
 * whole fleet. On a mixed fleet - some panels carrying the optional ispconfig-sso
 * project, some not - that answer is wrong for somebody no matter which way it
 * goes, and a customer whose panel lacks the add-on would be shown a login
 * button that can only fail.
 *
 * This function is the opposite: it runs per SERVICE, and a service has exactly
 * one server. So the button lives in the module's own client area output - one
 * of the two placements WHMCS documents for it
 * (developers.whmcs.com/provisioning-modules/single-sign-on/) - and is offered
 * only when this service's OWN panel reported the add-on and the service is
 * actually linked to an ISPConfig client. Everyone else sees exactly what they
 * saw before: the panel address and their login name, with no broken
 * affordance.
 *
 * The answer comes from the per-server metadata GetRemoteMetaData already
 * cached; nothing here touches the panel while a customer's page renders.
 *
 * @param array<string, mixed> $params
 * @param null|callable(int): ?array<string, mixed> $ssoStateReader test seam for
 *        the per-server tblservers_remote lookup; WHMCS passes $params alone
 * @return array<string, mixed>
 */
function ispconfigconnect_ClientArea(array $params, ?callable $ssoStateReader = null): array
{
    $username = (string) ($params['username'] ?? '');
    $clientId = null;
    try {
        $properties = ispconfigconnect_serviceProperties($params);
        $storedUsername = $properties->clientUsername();
        if ($storedUsername !== null) {
            $username = $storedUsername;
        }
        $clientId = $properties->clientId();
    } catch (\Throwable) {
        // No service model in scope: fall back to the WHMCS service username,
        // and offer no login button (there is no client id to log in as).
    }

    $serviceId = (int) ($params['serviceid'] ?? 0);
    // Never offer a panel login for a service that is not active: a suspended,
    // cancelled or fraud service must not be able to reach the panel. The
    // status comes from ispconfigconnect_serviceStatusOf(), which knows where
    // WHMCS really puts it; when nothing carries a status at all the gate is
    // not applied (WHMCS core itself refuses SSO for a non-Active service).
    $status = ispconfigconnect_serviceStatusOf($params);
    $statusAllowsSso = $status === '' || $status === ispconfigconnect_serviceStatus('ACTIVE', 'Active');

    // THIS PAGE MUST RENDER WHATEVER THE SERVER RECORD HOLDS. ServerConfig
    // refuses an empty or malformed Hostname/IP by throwing - correctly, since
    // the same value addresses the credential POST - and a customer's product
    // page is the wrong place for that exception to surface. A service whose
    // server was removed or never set gets the page without the panel link and
    // without the login button: the button's login URL is built from the same
    // server record (Factory::sessionManager), so it could only fail. A valid
    // Panel URL Override still shows, because it does not depend on the host.
    $serverUsable = true;
    try {
        Params::serverConfig($params);
    } catch (\Throwable) {
        $serverUsable = false;
    }
    $panelUrl = '';
    try {
        $panelUrl = ispconfigconnect_panelUrl($params);
    } catch (\Throwable) {
        // Rendered blank; the template has nothing else to fall back to.
    }

    $ssoAvailable = $clientId !== null
        && $serviceId > 0
        && $statusAllowsSso
        && $serverUsable
        && ispconfigconnect_serverSsoState($params, $ssoStateReader) === CompanionClient::STATUS_INSTALLED;

    return [
        'tabOverviewReplacementTemplate' => 'templates/overview.tpl',
        'templateVariables' => [
            'panelUrl' => $panelUrl,
            'ispconfigUsername' => $username,
            // The template builds WHMCS's own SSO URL from this
            // (clientarea.php?action=productdetails&id=N&dosinglesignon=1), so
            // no panel address is ever assembled for the button.
            'ssoAvailable' => $ssoAvailable,
            'ssoServiceId' => $serviceId,
            'ssoLabel' => 'Log in to ISPConfig',
        ],
    ];
}

/**
 * Server Sync: every hosting account the panel knows about, for WHMCS to
 * compare against its own service records
 * (developers.whmcs.com/provisioning-modules/server-sync/).
 *
 * Returns the documented shape - ['success' => bool, 'accounts' => [...]] or
 * ['success' => false, 'error' => '...'] - and never throws: the sync screen
 * renders the error string.
 *
 * @param array<string, mixed> $params
 * @return array{success: bool, accounts?: list<array<string, string>>, error?: string}
 */
function ispconfigconnect_ListAccounts(array $params): array
{
    try {
        $session = Factory::sessionManager($params);
        try {
            // ISPConfig stores '*' on vhosts rather than an address, so the
            // WHMCS server's own IP is the only meaningful primary IP.
            $accounts = (new AccountLister($session, (string) ($params['serverip'] ?? '')))->listAccounts();
        } finally {
            $session->close();
        }

        return [
            'success' => true,
            'accounts' => AccountLister::toWhmcsAccounts(
                $accounts,
                ispconfigconnect_serviceStatus('ACTIVE', RemoteAccount::STATUS_ACTIVE),
                ispconfigconnect_serviceStatus('SUSPENDED', RemoteAccount::STATUS_SUSPENDED)
            ),
        ];
    } catch (\Throwable $failure) {
        return ['success' => false, 'error' => ispconfigconnect_connectionAdvice($failure)];
    }
}

/**
 * WHMCS's daily per-server disk and bandwidth import
 * (developers.whmcs.com/provisioning-modules/usage-update/). Runs from cron,
 * per server rather than per product, and must therefore never throw: an
 * exception here would abort the whole cron task for every other server too.
 *
 * One login covers the entire sweep, and a single unreadable service costs
 * only its own row - the reason is written to the module log so an operator
 * can see what was skipped and why.
 *
 * @param array<string, mixed> $params
 * ## It is also where pre-existing accounts get adopted
 *
 * This is the right moment for it, and the reasoning is worth keeping: the
 * sweep already runs per server, already holds ONE open session, already
 * enumerates every service on that server, and the worst a wrong link can do
 * here is import a wrong usage row that the next sweep corrects. Nothing is
 * created, changed or deleted on the panel. Adoption happens BEFORE the usage
 * collection so that a service linked tonight is also billed tonight.
 *
 * @param array<string, mixed> $params
 * @param null|callable(int): list<array<string, mixed>> $rowProvider test seam
 *        for the tblhosting lookup; WHMCS passes $params alone
 * @param null|callable(int, array<string, int>): void $writer test seam for the
 *        tblhosting write
 * @param null|callable $adoptionResolver test seam - see ispconfigconnect_adoptionResolver()
 * @param null|callable(int, array<string, string>): void $propertyWriter test
 *        seam for the service-property write an adoption performs
 */
function ispconfigconnect_UsageUpdate(
    array $params,
    ?callable $rowProvider = null,
    ?callable $writer = null,
    ?callable $adoptionResolver = null,
    ?callable $propertyWriter = null
): void {
    $serverId = (int) ($params['serverid'] ?? 0);

    // One read of the server's services, three views of it.
    $rows = ispconfigconnect_serviceRowsOnce($serverId, $rowProvider);
    $rowsOnce = static fn (int $id): array => $rows;

    $services = ispconfigconnect_serviceRefs($serverId, $rowsOnce);
    $write = $writer ?? ispconfigconnect_usageWriter();

    // What WHMCS already holds for each service's month-to-date bandwidth, so
    // a later, lower reading (a suspended site stops accruing traffic on the
    // panel) cannot erase it - see Usage\ServiceUsage::toHostingColumnsGiven().
    $storedBandwidth = ispconfigconnect_storedBandwidth($serverId, $rowsOnce);
    // Alongside it, when each stored figure was written, so a month rollover
    // resets bwusage instead of billing every month at the highest month seen.
    $storedLastUpdate = ispconfigconnect_storedLastUpdate($serverId, $rowsOnce);
    $domains = ispconfigconnect_serviceDomains($rows);

    $updated = 0;
    /** @var array<int, string> $skipped WHMCS service id => reason */
    $skipped = [];
    /** @var array<int, string> $adopted WHMCS service id => the evidence that justified the link */
    $adopted = [];
    /** @var array<int, string> $unlinked WHMCS service id => why it is still unmanaged */
    $unlinked = [];
    $adoptionNote = '';
    $error = '';

    if ($services !== []) {
        try {
            $session = Factory::sessionManager($params);
            try {
                // Reachability probe, once. Without it an unreachable or
                // mis-credentialed panel would be re-attempted per service,
                // and ten failed logins lock this IP out for five minutes
                // (remoting.inc.php, spec 1.4) - punishing every later run.
                $session->call('monitor_jobqueue_count', ['server_id' => 0]);

                $adoption = ispconfigconnect_adoptUnlinked(
                    $session,
                    $services,
                    $domains,
                    $adoptionResolver,
                    $propertyWriter
                );
                $services = $adoption['services'];
                $adopted = $adoption['adopted'];
                $unlinked = $adoption['unlinked'];
                $adoptionNote = $adoption['note'];

                $report = (new Updater($session))->collect($services);
            } finally {
                $session->close();
            }

            $skipped = $report->failures;
            foreach ($report->usage as $usage) {
                try {
                    $write(
                        $usage->serviceId,
                        $usage->toHostingColumnsGiven(
                            $storedBandwidth[$usage->serviceId] ?? null,
                            $storedLastUpdate[$usage->serviceId] ?? null
                        )
                    );
                    $updated++;
                } catch (\Throwable $writeFailure) {
                    $skipped[$usage->serviceId] = 'tblhosting update failed: '
                        . ispconfigconnect_failureText($writeFailure);
                }
            }
        } catch (\Throwable $failure) {
            // Panel unreachable, credentials rejected, or the panel locked
            // this IP out mid-sweep: stop, and say so once.
            $error = ispconfigconnect_connectionAdvice($failure);
        }
    }

    $summary = ['updated' => $updated];
    if ($skipped !== []) {
        $summary['skipped'] = $skipped;
    }
    // Rule 3, in the sweep's own summary: what was linked and on what
    // evidence, and what was left unmanaged and why. Each link ALSO has its
    // own 'Adopt' entry (ispconfigconnect_logAdoption); this is the roll-up an
    // operator reads the morning after.
    if ($adopted !== []) {
        $summary['adopted'] = $adopted;
    }
    if ($unlinked !== []) {
        $summary['unlinked'] = $unlinked;
    }
    if ($adoptionNote !== '') {
        $summary['adoption'] = $adoptionNote;
    }
    if ($error !== '') {
        $summary['error'] = $error;
    }

    ispconfigconnect_logModuleCall(
        'UsageUpdate',
        ['serverid' => $serverId, 'services' => count($services)],
        $summary
    );
}

/**
 * Usage-billing metrics provider
 * (developers.whmcs.com/provisioning-modules/usage-metrics/).
 *
 * WHMCS wants an object implementing
 * \WHMCS\UsageBilling\Contracts\Metrics\ProviderInterface, built from its own
 * Metric/Usage/Units classes. Those are ionCube-encoded core, so lib code
 * cannot reference them (Metrics\Provider deals in plain data instead) and the
 * adaptation happens here, in the only file that is allowed to know about
 * WHMCS types. The class is anonymous on purpose: an interface that does not
 * exist outside a WHMCS install must only be resolved when this function is
 * actually called.
 *
 * @param array<string, mixed> $params
 * @param null|callable(int): list<array<string, mixed>> $rowProvider test seam
 *        for the tblhosting lookup
 */
function ispconfigconnect_MetricProvider(array $params, ?callable $rowProvider = null): ProviderInterface
{
    return new class ($params, $rowProvider === null ? null : $rowProvider(...)) implements ProviderInterface {
        /**
         * @param array<string, mixed> $params
         * @param null|\Closure(int): list<array<string, mixed>> $rowProvider
         */
        public function __construct(
            private readonly array $params,
            private readonly ?\Closure $rowProvider,
        ) {
        }

        /**
         * The catalogue, with no usage attached. WHMCS invokes this in PRODUCT
         * contexts where no server is in scope, so it must never touch the
         * wire and must answer identically for every server.
         *
         * @return list<Metric>
         */
        public function metrics()
        {
            return ispconfigconnect_metricObjects();
        }

        /**
         * Usage for every service on this server, keyed by the account
         * username (the documented tenant key).
         *
         * @return array<string, list<Metric>>
         */
        public function usage()
        {
            $services = ispconfigconnect_serviceRefs(
                (int) ($this->params['serverid'] ?? 0),
                $this->rowProvider
            );
            if ($services === []) {
                return [];
            }

            try {
                $session = Factory::sessionManager($this->params);
                try {
                    $provider = new MetricsProvider($session);
                    $values = $provider->usage($services);
                    $skipped = $provider->failures();
                } finally {
                    $session->close();
                }
            } catch (\Throwable $failure) {
                ispconfigconnect_logModuleCall(
                    'MetricProvider',
                    ['serverid' => $this->params['serverid'] ?? null, 'services' => count($services)],
                    ['error' => ispconfigconnect_connectionAdvice($failure)]
                );

                return [];
            }

            if ($skipped !== []) {
                ispconfigconnect_logModuleCall(
                    'MetricProvider',
                    ['serverid' => $this->params['serverid'] ?? null, 'services' => count($services)],
                    ['collected' => count($values), 'skipped' => $skipped]
                );
            }

            $usage = [];
            foreach ($values as $tenant => $tenantValues) {
                $usage[$tenant] = ispconfigconnect_metricObjects($tenantValues);
            }

            return $usage;
        }

        /**
         * @param mixed $tenant a service model, a service id, or the account
         *        username - WHMCS's own type here is not documented, so all
         *        three are accepted
         * @return list<Metric>
         */
        public function tenantUsage($tenant)
        {
            $service = $this->resolve($tenant);
            if ($service === null) {
                // Unknown tenant: the catalogue without usage, which is what
                // the documented sample produces for a metric it has no value
                // for. Never an exception - WHMCS is rendering a page.
                return ispconfigconnect_metricObjects();
            }

            try {
                $session = Factory::sessionManager($this->params);
                try {
                    return ispconfigconnect_metricObjects(
                        (new MetricsProvider($session))->tenantUsage($service)
                    );
                } finally {
                    $session->close();
                }
            } catch (\Throwable $failure) {
                ispconfigconnect_logModuleCall(
                    'MetricProvider',
                    ['serviceid' => $service->serviceId, 'ispconfig_client_id' => $service->clientId],
                    ['error' => ispconfigconnect_connectionAdvice($failure)]
                );

                return ispconfigconnect_metricObjects();
            }
        }

        private function resolve(mixed $tenant): ?ServiceRef
        {
            $serviceId = 0;
            $username = '';

            if (is_object($tenant)) {
                // A WHMCS service model exposes id/username through accessors,
                // which get_object_vars() cannot see (they are guarded Eloquent
                // attributes, not public properties); read them directly. The
                // null-coalescing also covers a plain stdClass tenant double.
                $id = $tenant->id ?? null;
                $serviceId = is_numeric($id) ? (int) $id : 0;
                $name = $tenant->username ?? null;
                $username = is_scalar($name) ? trim((string) $name) : '';
            } elseif (is_int($tenant)) {
                $serviceId = $tenant;
            } elseif (is_string($tenant)) {
                // usage() keys tenants by USERNAME, so a string tenant is a
                // username first; an all-digit username is only treated as a
                // service id below if no username matches (a numeric username
                // must not be mistaken for a service id).
                $username = trim($tenant);
            }

            if ($serviceId <= 0 && $username === '') {
                return null;
            }

            $services = ispconfigconnect_serviceRefs(
                (int) ($this->params['serverid'] ?? 0),
                $this->rowProvider
            );

            if ($username !== '') {
                foreach ($services as $service) {
                    if ($service->username === $username) {
                        return $service;
                    }
                }
            }

            $numericId = $serviceId > 0
                ? $serviceId
                : ($username !== '' && ctype_digit($username) ? (int) $username : 0);
            if ($numericId > 0) {
                foreach ($services as $service) {
                    if ($service->serviceId === $numericId) {
                        return $service;
                    }
                }
            }

            return null;
        }
    };
}

/**
 * The panel facts WHMCS collects while rendering Configuration > Servers.
 *
 * LIVE-VERIFIED CONTRACT (WHMCS 9.0.6): the returned array is stored VERBATIM
 * as JSON in tblservers_remote.meta_data, so it must be FLAT - a
 * success/metaData wrapper is not unwrapped - and core renders the
 * 'service_count' key as "Services: N" in the Remote Usage Stats cell.
 *
 * Never throws: this runs while an admin page is being rendered, and a server
 * whose panel is down must still list.
 *
 * @param array<string, mixed> $params
 * @return array<string, scalar>
 */
function ispconfigconnect_GetRemoteMetaData(array $params): array
{
    try {
        // Factory::sessionManager() can itself throw on a broken server row,
        // so it belongs inside the try.
        $session = Factory::sessionManager($params);
        try {
            $meta = (new RemoteMetaData($session))->gather();

            // The SSO presence probe is cached HERE because the surfaces that
            // decide whether a login button is offered - MetaData(), which gets
            // no server parameters at all, and the client area, which renders
            // while a customer waits - must not make API calls. One
            // get_function_list call, already granted. "The panel does not have
            // the ispconfig-sso project" is the normal answer, not a fault.
            //
            // TWO KEYS, on purpose. `sso_available` is the boolean every
            // existing reader knows, and it is written ONLY when the probe
            // actually established the answer: a refused probe means "we were
            // not allowed to look", and caching that as false would publish
            // "this panel has no single sign-on" to every reader of this row on
            // the strength of a permission we were denied. `sso_state` carries
            // the three-way truth for readers that can use it.
            $abortedOnConnection = ($meta['ispconfig_status'] ?? '') === RemoteMetaData::STATUS_UNAVAILABLE
                || str_contains((string) ($meta['ispconfig_error'] ?? ''), 'remaining probes skipped');

            if ($abortedOnConnection) {
                // gather() already stopped on a connection-level failure. A
                // second login here for the SSO probe would repeat it and spend
                // another failed login against the panel's 10-per-5-minutes
                // lockout budget (spec 1.4), so skip it and record UNKNOWN.
                $meta['sso_state'] = CompanionClient::STATUS_UNKNOWN;
            } else {
                try {
                    $state = ispconfigconnect_companionClient($params, $session)->status();
                    $meta['sso_state'] = $state;
                    if ($state !== CompanionClient::STATUS_UNKNOWN) {
                        $meta['sso_available'] = $state === CompanionClient::STATUS_INSTALLED;
                    }
                } catch (\Throwable) {
                    // WHMCS stores meta_data WHOLESALE, so omitting the key would
                    // DELETE a previously known state, not preserve it. Record
                    // UNKNOWN explicitly - readers treat it as "neither offer nor
                    // withhold" (ispconfigconnect_ssoStateOfRow).
                    $meta['sso_state'] = CompanionClient::STATUS_UNKNOWN;
                }
            }

            return $meta;
        } finally {
            $session->close();
        }
    } catch (\Throwable $failure) {
        // Still flat, still renderable: the servers page shows the reason
        // instead of an empty cell.
        return [
            'checked_at' => gmdate('Y-m-d H:i:s') . ' UTC',
            'ispconfig_status' => RemoteMetaData::STATUS_UNAVAILABLE,
            'ispconfig_error' => ispconfigconnect_failureText($failure),
        ];
    }
}

/**
 * Single sign-on for one service - the customer's "Log in to ISPConfig"
 * button (developers.whmcs.com/provisioning-modules/single-sign-on/).
 *
 * EVERYTHING THIS RETURNS IS READ BY A CUSTOMER, so its errorMsg is the one
 * customer sentence and nothing else: no hostname, no grant name, no service
 * property, no panel diagnosis. The operator-grade reason is written to the
 * module log by ispconfigconnect_singleSignOn().
 *
 * @param array<string, mixed> $params
 * @param null|callable(int): ?array<string, mixed> $ssoStateReader test seam for
 *        the per-server tblservers_remote lookup; WHMCS passes $params alone
 * @return array{success: bool, redirectTo?: string, errorMsg?: string}
 */
function ispconfigconnect_ServiceSingleSignOn(
    array $params,
    ?callable $ssoStateReader = null,
    ?bool $adminArea = null
): array {
    // WHMCS runs this for the customer's button in the client area AND for
    // the login button on the admin's service page. Only the first is
    // customer-facing: an admin supporting a suspended customer may still log
    // in (ISPConfig itself refuses a LOCKED client), and gets the operator's
    // diagnosis instead of "please contact support". $adminArea is a test seam.
    $adminArea ??= defined('ADMINAREA');

    return ispconfigconnect_singleSignOn(
        $params,
        $adminArea ? 'AdminSingleSignOn' : 'ServiceSingleSignOn',
        $ssoStateReader
    );
}

/**
 * Single sign-on triggered from the admin area.
 *
 * WHMCS documents this as the SERVER-level counterpart of
 * ServiceSingleSignOn. ISPConfig has no administrator SSO to offer - the
 * remote user is not a panel user, and the SSO project refuses to mint tokens
 * for administrator accounts because such a token would be a full panel
 * takeover - so a server-only call is answered with that explanation rather
 * than a cryptic failure, which is also why AdminSingleSignOnLabel is never
 * advertised. Where WHMCS does call this with a service in scope, it logs the
 * admin into THAT client's panel, exactly like the customer-facing button.
 *
 * @param array<string, mixed> $params
 * @return array{success: bool, redirectTo?: string, errorMsg?: string}
 */
function ispconfigconnect_AdminSingleSignOn(array $params): array
{
    // WHMCS renders "Login to Control Panel" on the Servers page for any
    // module that defines this function, so it must do something useful.
    //
    // With a service in scope, mint a real one-time login for THAT service's
    // ISPConfig client (the SSO-project path). Without one - the server-level
    // button - there is no client to log in as, and minting an ADMIN panel
    // session is deliberately refused by the SSO project: such a token would be
    // a full panel takeover, and the API remote user is not a panel user at
    // all. Rather than fail, hand the admin the panel's own login page.
    if ((int) ($params['serviceid'] ?? 0) > 0) {
        $serviceSso = ispconfigconnect_singleSignOn($params, 'AdminSingleSignOn');
        if (($serviceSso['success'] ?? false) === true) {
            return $serviceSso;
        }
    }

    try {
        // WHMCS sends the browser straight to this value, so it goes through
        // the same validation as every other rendering of the panel URL - a
        // configoption14 of `javascript:...` or an off-site host must not
        // become a redirect target (ispconfigconnect_panelUrlOverride()).
        return [
            'success' => true,
            'redirectTo' => ispconfigconnect_panelUrl($params),
        ];
    } catch (\Throwable $failure) {
        return [
            'success' => false,
            'errorMsg' => ispconfigconnect_errorString('Admin Single Sign-On', $failure),
        ];
    }
}

// ---------------------------------------------------------------------------
// Internal helpers (module-prefixed to keep WHMCS's global function space
// collision-free; never invoked by WHMCS itself).
// ---------------------------------------------------------------------------

/**
 * Service-properties accessor with a product-agnostic fallback: WHMCS scopes
 * the backing custom fields to the service's CURRENT product, so after a
 * package change $model->serviceProperties reads miss. The fallback queries
 * by field name + service id directly (any product), keeping stored ids
 * readable across product switches.
 *
 * @param array<string, mixed> $params
 */
function ispconfigconnect_serviceProperties(array $params): \WHMCS\Module\Server\Ispconfigconnect\Support\ServiceProperties
{
    // PRODUCT ADDONS ARE NOT SUPPORTED. For an addon, WHMCS passes the PARENT
    // service's id as serviceid (and the addon's own id as addonId), so every
    // lookup keyed on serviceid below - the property reader, the cross-product
    // clearer, the shared-client check - would read and act on the PARENT
    // service's ISPConfig client: an addon Terminate would delete the parent's
    // hosting. Every lifecycle, button and page path reads properties through
    // here first, so refusing here stops all of them before any panel call.
    if ((int) ($params['addonId'] ?? 0) > 0) {
        throw new \DomainException(
            'ISPConfig Connect cannot run on a product addon. For an addon, WHMCS passes the parent'
            . ' service\'s id, so every operation would act on the parent service\'s ISPConfig client.'
            . ' Set this addon\'s module to none, and use ISPConfig Connect on products only. Nothing has'
            . ' been changed.'
        );
    }

    $properties = Params::serviceProperties($params);
    $serviceId = (int) ($params['serviceid'] ?? 0);
    if ($serviceId > 0 && class_exists('\\WHMCS\\Database\\Capsule')) {
        // The service's current product, so the reader can prefer its rows
        // when more than one product carries a value for a key (e.g. right
        // after a package change, before resaveAll migrates them).
        $currentProductId = ispconfigconnect_currentPackageId($serviceId);

        $properties->withFallbackReader(
            static function (string $key) use ($serviceId, $currentProductId): ?string {
                // Prefer the current product's row, then any product's, newest
                // row first - so the answer is deterministic rather than
                // "whichever row the database happened to return first". When
                // the current product is unknown, the null pass alone runs.
                $passes = $currentProductId !== null ? [$currentProductId, null] : [null];
                foreach ($passes as $productId) {
                    try {
                        $query = \WHMCS\Database\Capsule::table('tblcustomfieldsvalues')
                            ->join('tblcustomfields', 'tblcustomfields.id', '=', 'tblcustomfieldsvalues.fieldid')
                            ->where('tblcustomfields.type', 'product')
                            ->where('tblcustomfields.fieldname', $key)
                            ->where('tblcustomfieldsvalues.relid', $serviceId);
                        if ($productId !== null) {
                            $query->where('tblcustomfields.relid', $productId);
                        }
                        $value = $query
                            ->orderByDesc('tblcustomfieldsvalues.id')
                            ->value('tblcustomfieldsvalues.value');
                    } catch (\Throwable) {
                        return null;
                    }
                    if ($value !== null && (string) $value !== '') {
                        return (string) $value;
                    }
                }

                return null;
            }
        );

        // Blank the managed ids across EVERY product's rows for this service,
        // so clear() (Unlink/Terminate/Replace) truly forgets the link instead
        // of leaving an old product's rows for the reader to resurrect.
        $properties->withFallbackClearer(static function () use ($serviceId): void {
            try {
                $fieldIds = \WHMCS\Database\Capsule::table('tblcustomfields')
                    ->where('type', 'product')
                    ->whereIn('fieldname', ServiceProperties::KEYS)
                    ->pluck('id');
                $ids = [];
                foreach (is_iterable($fieldIds) ? $fieldIds : [] as $fieldId) {
                    if (is_numeric($fieldId)) {
                        $ids[] = (int) $fieldId;
                    }
                }
                if ($ids === []) {
                    return;
                }
                \WHMCS\Database\Capsule::table('tblcustomfieldsvalues')
                    ->where('relid', $serviceId)
                    ->whereIn('fieldid', $ids)
                    ->update(['value' => '']);
            } catch (\Throwable) {
                // A schema difference must not turn clear() into a fatal; the
                // model save already blanked the current product's rows.
            }
        });
    }

    return $properties;
}

/**
 * The service's current product id (tblhosting.packageid), or null when it
 * cannot be read. Used to disambiguate cross-product custom-field rows.
 */
function ispconfigconnect_currentPackageId(int $serviceId): ?int
{
    if ($serviceId <= 0 || !class_exists('\\WHMCS\\Database\\Capsule')) {
        return null;
    }

    try {
        $packageId = \WHMCS\Database\Capsule::table('tblhosting')
            ->where('id', $serviceId)
            ->value('packageid');

        return is_numeric($packageId) && (int) $packageId > 0 ? (int) $packageId : null;
    } catch (\Throwable) {
        return null;
    }
}

/**
 * The safe read-only probes TestConnection / VerifyPermissions run, keyed by
 * method name. Api\Permissions is the single source of truth for grant names
 * (source-verified checkPerm() aliases included).
 *
 * @return array<string, array{grant: string, params: array<string, mixed>}>
 */
function ispconfigconnect_readProbes(): array
{
    return Permissions::readProbes();
}

/**
 * Runs every read probe, collecting the missing grants instead of stopping at
 * the first: one round trip tells the operator everything to fix.
 *
 * ONLY A PANEL ANSWER IS A GRANT VERDICT. A probe proves its grant is present
 * when the panel itself answered with something other than "permission
 * denied" - and a panel answer is, by construction, an IspconfigException
 * (RemoteFaultException and friends) or a normal return. Anything else is a
 * failure on THIS side: a missing PHP function (\Error), a TypeError, a bug.
 * Counting that as "grant present" would let Test Connection report
 * "Connected" on a module that cannot actually parse a single reply, so it
 * propagates to the caller's error path instead.
 *
 * @return list<string> e.g. "'server_get' (needed by server_get_all)"
 */
function ispconfigconnect_missingGrants(SessionManager $session): array
{
    $missing = [];
    foreach (ispconfigconnect_readProbes() as $method => $probe) {
        try {
            $session->call($method, $probe['params']);
        } catch (PermissionDeniedException) {
            // The grant is missing: checkPerm() refused the call.
            $missing[] = sprintf("'%s' (needed by %s)", $probe['grant'], $method);
        } catch (IspconfigException $failure) {
            // A connection-level failure (unreachable panel, lockout, bad
            // credentials) is not a grant verdict - it means we could not
            // check at all, so let it abort TestConnection's usual way.
            if (ispconfigconnect_isConnectionLevel($failure)) {
                throw $failure;
            }
            // Any other remote fault ("no such client/record", an empty-result
            // validation error) means the call REACHED the panel and was not
            // refused, so the grant is present. The probes use ids/names that
            // match nothing precisely so this is the normal answer.
        }
        // Any other \Throwable is deliberately NOT caught - see above.
    }

    return $missing;
}

/**
 * Actionable TestConnection error text per verified failure mode (spec 2.8).
 * The original fault message is appended in parentheses - it is operator-safe
 * (remoting.inc.php sentinels are fixed English, spec §1.3).
 */
function ispconfigconnect_connectionAdvice(\Throwable $failure): string
{
    $message = trim($failure->getMessage());

    // Order matters: IpNotAllowedException extends LoginFailedException.
    if ($failure instanceof IpNotAllowedException) {
        // remoting.inc.php appends $_SERVER['REMOTE_ADDR'] to the fault text.
        $ip = trim((string) preg_replace('/^The login is not allowed from\s*/', '', $message));

        return sprintf(
            'ISPConfig rejected the connection: this WHMCS server\'s address is not on the remote'
            . ' user\'s IP allowlist. Add %s to the "Remote Access IPs" field of the remote user'
            . ' (System > Remote Users), or clear that field to allow any address. (%s)',
            $ip !== '' ? $ip : "this server's IP",
            $message
        );
    }
    if ($failure instanceof LockoutException) {
        return 'The panel has locked this IP out (10 failed attempts within 5 minutes, and failed'
            . ' attempts include expired sessions). Wait at least 5 minutes WITHOUT retrying - every'
            . ' further attempt extends the lockout - then correct the credentials and try again.'
            . ' (' . $message . ')';
    }
    if ($failure instanceof MaintenanceModeException) {
        return 'The ISPConfig panel is in maintenance mode; try again once maintenance is finished.'
            . ' (' . $message . ')';
    }
    if ($failure instanceof LoginFailedException) {
        return 'ISPConfig rejected the login. Check the Username and Password fields of this WHMCS'
            . ' server: they must hold an ISPConfig REMOTE user (System > Remote Users), not a panel'
            . ' administrator login. (' . $message . ')';
    }
    if ($failure instanceof PermissionDeniedException) {
        return 'The ISPConfig remote user is missing a remote_functions grant for one of the'
            . ' calls this operation makes. Run Verify Permissions on the service, or compare the'
            . ' user\'s Functions list against docs/REMOTE-USER.md. (' . $message . ')';
    }
    if ($failure instanceof ApiDisabledException) {
        return 'The panel\'s remote API is switched off. Set remote_api_allowed=yes in the'
            . ' [permissions] section of /usr/local/ispconfig/security/security_settings.ini on the'
            . ' ISPConfig server, then retry. (' . $message . ')';
    }
    if ($failure instanceof TransportException) {
        // TLS hostname mismatch while connecting to a bare IP: no client can
        // verify a hostname certificate against an IP, so the fix is always
        // to fill the WHMCS Hostname field - say so instead of raw curl text.
        if (str_contains($message, 'no alternative certificate subject name matches')
            && preg_match('~//(\d{1,3}(?:\.\d{1,3}){3}|\[[0-9a-fA-F:]+\]):~', $message)
        ) {
            return 'TLS verification failed because the connection used the server\'s IP address,'
                . ' but the panel\'s certificate is issued for its hostname. Enter the panel'
                . ' hostname (e.g. panel.example.com) in this server\'s Hostname field - the'
                . ' module always prefers it over the IP. (' . $message . ')';
        }

        return 'Could not reach the ISPConfig API endpoint. Check the hostname, port (default 8080),'
            . ' the Secure checkbox, and the TLS/CA settings in the Access Hash field. (' . $message . ')';
    }

    return $message !== '' ? $message : get_class($failure);
}

/**
 * Human-readable list of every ISPConfig id a create produced - used in the
 * property-save-failure error so the operator can reconcile the orphaned
 * objects (the client id is always present; the rest depend on the product's
 * Create * options).
 */
function ispconfigconnect_createdIdSummary(CreateResult $result): string
{
    $ids = ['client #' . $result->clientId . ' (username ' . $result->username . ')'];
    foreach (
        [
            'web domain' => $result->domainId,
            'FTP user' => $result->ftpUserId,
            'mail domain' => $result->mailDomainId,
            'DNS zone' => $result->dnsZoneId,
            'database user' => $result->databaseUserId,
            'database' => $result->databaseId,
        ] as $label => $id
    ) {
        if ($id !== null) {
            $ids[] = $label . ' #' . $id;
        }
    }

    return implode(', ', $ids);
}

/**
 * WHMCS error-string convention: name the operation, keep the exception
 * MESSAGE only - never a stack trace (spec 2.8).
 */
function ispconfigconnect_errorString(string $operation, \Throwable $failure): string
{
    $message = trim($failure->getMessage());
    if ($message === '') {
        $message = get_class($failure);
    }

    return $operation . ' failed: ' . $message;
}

/**
 * Panel URL shown to humans: the Panel URL Override product option when it is
 * usable, else derived from the server config (spec 2.8).
 *
 * Every caller hands the result to a browser - as an href in the client area
 * and the admin service tab, and as an SSO redirect target - so the override is
 * validated first; see ispconfigconnect_panelUrlOverride().
 *
 * @param array<string, mixed> $params
 */
function ispconfigconnect_panelUrl(array $params): string
{
    return ispconfigconnect_panelUrlOverride($params)
        ?? Params::serverConfig($params)->panelUrl();
}

/**
 * The Panel URL Override (configoption14) when it is a URL that is safe to put
 * in front of a browser, otherwise null - meaning "fall back to the URL derived
 * from the WHMCS server row".
 *
 * WHY THIS EXISTS. The option is a free-text product setting, and its value is
 * consumed in three places that all hand it to a browser: the "Open Control
 * Panel" button every customer of the product sees, the admin service tab's
 * panel link, and the single sign-on redirect target (directly for
 * AdminSingleSignOn, as the base of the SSO login URL for
 * ServiceSingleSignOn). HTML escaping does not make any of those safe -
 * `javascript:alert(1)` passes through htmlspecialchars() and Smarty's |escape
 * unchanged and still executes on click - so a mistyped or hostile value is
 * stored XSS against customers, and an off-site absolute URL is an open
 * redirect. An allowlist at the point of consumption is the only defence that
 * holds regardless of how the value got stored.
 *
 * THE RULE. It is a superset of Sso\CompanionClient::normalizeBaseUrl(), so
 * anything accepted here is also a legal SSO base:
 *
 *   - absolute, with an http or https scheme (case-insensitive) and a host;
 *   - no credentials in the authority - `https://panel.example.net@evil.tld/`
 *     reads as the panel and resolves to evil.tld;
 *   - no query and no fragment, because the SSO path is appended to this base;
 *   - no whitespace, control characters or backslashes ANYWHERE.
 *
 * The character screen runs BEFORE parse_url() and is not cosmetic: every one
 * of those characters is a way to make parse_url() and a browser disagree about
 * the same string. Browsers strip tabs, newlines and returns out of a URL before
 * resolving it, so "java\nscript:alert(1)" is a live javascript: URL that
 * parse_url() reports as a harmless relative path; they read a backslash inside
 * an authority as '/', which parse_url() does not; and a bare trailing '?' or
 * '#' yields no query/fragment member at all, so isset() alone would not see it.
 *
 * What is deliberately NOT checked is WHICH host the operator names: a vanity
 * hostname that differs from the API host is the normal reason to set this
 * option at all. The scheme is the part the module can decide; the host is the
 * operator's, and the product config screen is already admin-only.
 *
 * Rejection is silent and non-fatal by design - this runs while a customer's
 * page is being rendered, and a broken option must degrade to the panel's real
 * address rather than blank the page or break login. The option's Description
 * states the requirement (see ispconfigconnect_ConfigOptions()).
 *
 * @param array<string, mixed> $params
 * @return string|null the override exactly as the operator typed it, or null
 */
function ispconfigconnect_panelUrlOverride(array $params): ?string
{
    $override = Params::productConfig($params)->panelUrlOverride; // trimmed; '' is null
    if ($override === null) {
        return null;
    }

    // Space (\x20) and DEL (\x7f) are inside the range endpoints on purpose.
    // Byte-based and encoding-independent, so it holds whatever the operator
    // pasted.
    if (preg_match('/[\x00-\x20\x7f?#\\\\]/', $override) === 1) {
        return null;
    }

    // The same rule, continued into non-ASCII. The screen above is bytewise,
    // so UNICODE whitespace (U+00A0, U+2028, U+3000) and the zero-width and
    // BiDi format characters (U+200B, U+FEFF, U+202E) walk straight through
    // it. None of them is a scheme bypass - what they buy is a HOMOGRAPH: an
    // invisible character in the authority makes a link read as the panel's
    // own hostname while resolving somewhere else, and it survives a paste out
    // of a document without ever showing up in the product config field. That
    // matters most on the SSO path, where the base URL is what a live login
    // token is handed to.
    //
    // \p{Z} is every separator, \p{Cf} every format character; \p{Cc} repeats
    // the ASCII controls harmlessly. Non-ASCII in general is NOT rejected - an
    // operator may legitimately type an IDN as raw UTF-8 instead of punycode.
    // A non-1 result also covers preg_match()'s FALSE on malformed UTF-8,
    // which is refused rather than waved through unexamined.
    if (preg_match('/[\p{Z}\p{Cf}\p{Cc}]/u', $override) !== 0) {
        return null;
    }

    $parts = parse_url($override);
    if (
        !is_array($parts)
        || !isset($parts['scheme'], $parts['host'])
        || !in_array(strtolower((string) $parts['scheme']), ['http', 'https'], true)
        || (string) $parts['host'] === ''
        || isset($parts['user'])
        || isset($parts['pass'])
    ) {
        return null;
    }

    return $override;
}

/**
 * Deterministic username for services ordered without one:
 * alnum(second-level domain) truncated to 10 chars + the WHMCS service id
 * (unique per service), forced to start with a letter for system/FTP-user
 * compatibility.
 *
 * @param array<string, mixed> $params
 */
function ispconfigconnect_generateUsername(array $params): string
{
    $secondLevel = explode('.', trim((string) ($params['domain'] ?? '')), 2)[0];
    $secondLevel = (string) preg_replace('/[^a-z0-9]/', '', strtolower($secondLevel));

    $base = $secondLevel === '' ? 'whmcs' : substr($secondLevel, 0, 10);
    if (preg_match('/^[a-z]/', $base) !== 1) {
        $base = 'w' . $base;
    }

    return $base . (int) ($params['serviceid'] ?? 0);
}

/**
 * Shared body of both single sign-on entry points.
 *
 * ## Two audiences, one code path
 *
 * ServiceSingleSignOn is a CUSTOMER surface: whatever it returns is rendered in
 * the client area. AdminSingleSignOn is not. The failure text is therefore
 * chosen by audience - customers get the single sentence from Sso\SsoAdvice,
 * operators get the diagnosis - and the diagnosis is ALWAYS written to the
 * module log regardless, so nothing is lost by protecting the customer from it.
 * The states are kept apart in that log too: "the add-on is not on this panel"
 * and "the add-on is there but its grant was withheld" send an operator to
 * completely different places, and only a real mint attempt can tell them apart
 * (the panel cannot be asked about grants - see Sso\SsoAdvice).
 *
 * @param array<string, mixed> $params
 * @param null|callable(int): ?array<string, mixed> $ssoStateReader test seam for
 *        the per-server tblservers_remote lookup
 * @return array{success: bool, redirectTo?: string, errorMsg?: string}
 */
function ispconfigconnect_singleSignOn(
    array $params,
    string $context,
    ?callable $ssoStateReader = null
): array {
    $serviceId = (int) ($params['serviceid'] ?? 0);
    $serviceInScope = $serviceId > 0 || isset($params['model']);
    // Only the service-level entry point renders into the client area.
    $customerFacing = $context === 'ServiceSingleSignOn';

    $clientId = null;
    try {
        $clientId = ispconfigconnect_serviceProperties($params)->clientId();
    } catch (\Throwable) {
        // No service model in scope (a server-level admin call).
    }

    if ($clientId === null) {
        if ($serviceInScope) {
            $detail = 'Single sign-on is not available for this service: it has no stored'
                . ' ispconfig_client_id service property, so it was not provisioned by'
                . ' ISPConfig Connect.';
        } else {
            // The panel address is only advice here; a server row whose
            // Hostname/IP ServerConfig rejects must not turn it into a throw.
            try {
                $signInAt = ispconfigconnect_panelUrl($params);
            } catch (\Throwable) {
                $signInAt = 'the panel\'s own login page';
            }
            $detail = sprintf(
                'ISPConfig has no administrator single sign-on. The remote API user is not a'
                . ' panel user, and the ISPConfig SSO project refuses to mint tokens for administrator'
                . ' accounts because such a token would be a full panel takeover. Sign in at %s'
                . ' instead, or use the per-service login button on a client\'s service.',
                $signInAt
            );
        }

        return ispconfigconnect_ssoFailure($context, $serviceId, null, null, $detail, $customerFacing);
    }

    // A non-active service must not reach the panel. WHMCS passes the service
    // status as $params['status'] (see ispconfigconnect_serviceStatusOf());
    // when it is known and not Active, refuse the customer path (a suspended,
    // cancelled or fraud service otherwise still logs in).
    $status = ispconfigconnect_serviceStatusOf($params);
    if (
        $customerFacing
        && $status !== ''
        && $status !== ispconfigconnect_serviceStatus('ACTIVE', 'Active')
    ) {
        return ispconfigconnect_ssoFailure(
            $context,
            $serviceId,
            $clientId,
            null,
            sprintf('Single sign-on is only available while the service is active; this service is %s.', $status),
            true
        );
    }

    // MIXED FLEET, the customer path. The button is only rendered for services
    // whose own panel reported the add-on, so a click from a service on a panel
    // cached as ABSENT can only come from a stale link or a hand-typed URL -
    // and the panel cannot answer any differently. Refuse from the cache rather
    // than open a session to be told the same thing. Only a DEFINITE "absent"
    // short-circuits: a server nobody has probed yet is still asked, so a cold
    // cache can never withhold a login the panel would grant.
    if (
        $customerFacing
        && ispconfigconnect_serverSsoState($params, $ssoStateReader) === CompanionClient::STATUS_ABSENT
    ) {
        return ispconfigconnect_ssoFailure(
            $context,
            $serviceId,
            $clientId,
            SsoAdvice::STATE_ABSENT,
            SsoAdvice::admin(SsoAdvice::STATE_ABSENT),
            true
        );
    }

    try {
        $session = Factory::sessionManager($params);
        try {
            // IP binding is left OFF: the token would then only be redeemable
            // from the address WHMCS saw, and any CDN, proxy or IPv4/IPv6
            // asymmetry between WHMCS and the panel would break every login
            // (the ispconfig-sso project's README documents the trade-off).
            $result = [
                'success' => true,
                'redirectTo' => ispconfigconnect_companionClient($params, $session)->loginUrl($clientId),
            ];
        } finally {
            $session->close();
        }
    } catch (CompanionNotInstalledException) {
        return ispconfigconnect_ssoFailure(
            $context,
            $serviceId,
            $clientId,
            SsoAdvice::STATE_ABSENT,
            SsoAdvice::admin(SsoAdvice::STATE_ABSENT),
            $customerFacing
        );
    } catch (CompanionGrantMissingException) {
        // The add-on IS on this panel; only the checkbox is missing. Its own
        // state, never folded into "not installed".
        return ispconfigconnect_ssoFailure(
            $context,
            $serviceId,
            $clientId,
            SsoAdvice::STATE_NOT_GRANTED,
            SsoAdvice::admin(SsoAdvice::STATE_NOT_GRANTED),
            $customerFacing
        );
    } catch (\Throwable $failure) {
        return ispconfigconnect_ssoFailure(
            $context,
            $serviceId,
            $clientId,
            null,
            ispconfigconnect_connectionAdvice($failure),
            $customerFacing
        );
    }

    // The redirect URL carries the one-time token in its query string, so only
    // the OUTCOME is logged - never the URL.
    ispconfigconnect_logModuleCall(
        $context,
        ['serviceid' => $serviceId, 'ispconfig_client_id' => $clientId],
        ['success' => true]
    );

    return $result;
}

/**
 * One refused single sign-on: log the operator's version, return the audience's.
 *
 * @param string|null $state an Sso\SsoAdvice::STATE_* value where one applies
 * @param string $detail the operator-grade reason, always logged
 * @return array{success: false, errorMsg: string}
 */
function ispconfigconnect_ssoFailure(
    string $context,
    int $serviceId,
    ?int $clientId,
    ?string $state,
    string $detail,
    bool $customerFacing
): array {
    $errorMsg = $customerFacing ? SsoAdvice::customer() : $detail;

    ispconfigconnect_logModuleCall(
        $context,
        ['serviceid' => $serviceId, 'ispconfig_client_id' => $clientId],
        ['success' => false, 'sso_state' => $state ?? 'error', 'detail' => $detail, 'errorMsg' => $errorMsg]
    );

    return ['success' => false, 'errorMsg' => $errorMsg];
}

/**
 * @param array<string, mixed> $params
 */
function ispconfigconnect_companionClient(array $params, SessionManager $session): CompanionClient
{
    return new CompanionClient(
        $session,
        Params::serverConfig($params),
        // Validated, not raw: this string becomes the base of the URL WHMCS
        // redirects the customer's browser to. CompanionClient rejects an
        // unusable base by throwing, which would turn a typo in a product
        // option into "single sign-on is broken"; screening it here degrades
        // to the panel's real address instead.
        ispconfigconnect_panelUrlOverride($params)
    );
}

/**
 * Has the ispconfig-sso project been seen on any ISPConfig server WHMCS knows about?
 *
 * Reads the metadata GetRemoteMetaData already cached in tblservers_remote -
 * MetaData() has no server in scope and must not make API calls. The answer is
 * memoised per request because WHMCS calls MetaData() repeatedly.
 *
 * @param null|callable(): list<array<string, mixed>> $reader test seam
 */
function ispconfigconnect_ssoAvailable(?callable $reader = null): bool
{
    static $cached = null;

    if ($reader === null && is_bool($cached)) {
        return $cached;
    }

    $available = false;
    try {
        $rows = $reader === null ? ispconfigconnect_cachedServerMetaData() : $reader();
        foreach ($rows as $row) {
            if (is_array($row) && ispconfigconnect_ssoStateOfRow($row) === CompanionClient::STATUS_INSTALLED) {
                $available = true;
                break;
            }
        }
    } catch (\Throwable) {
        // An unknown answer must not render a button.
        $available = false;
    }

    if ($reader === null) {
        $cached = $available;
    }

    return $available;
}

/**
 * What ONE server's cached metadata says about single sign-on.
 *
 * This is the per-service answer MetaData() cannot give: the client area and
 * the customer-facing SSO path both know which server the service sits on, and
 * this is what turns that into "offer the button" or "do not". It reads the row
 * GetRemoteMetaData already filled and makes NO API call - it runs while a
 * customer's page is being rendered.
 *
 * Returns a CompanionClient::STATUS_* value; STATUS_UNKNOWN means the cache
 * cannot say (never probed, no row, unreadable, or the probe was refused), and
 * unknown must neither render a button nor withhold a login.
 *
 * @param array<string, mixed> $params
 * @param null|callable(int): ?array<string, mixed> $reader test seam
 */
function ispconfigconnect_serverSsoState(array $params, ?callable $reader = null): string
{
    $serverId = (int) ($params['serverid'] ?? 0);

    try {
        $values = $reader === null
            ? ispconfigconnect_cachedServerMetaDataFor($serverId)
            : $reader($serverId);
    } catch (\Throwable) {
        return CompanionClient::STATUS_UNKNOWN;
    }

    return is_array($values)
        ? ispconfigconnect_ssoStateOfRow($values)
        : CompanionClient::STATUS_UNKNOWN;
}

/**
 * One decoded meta_data row read as an SSO state.
 *
 * `sso_state` is preferred because it distinguishes "the panel says no" from
 * "we were not allowed to ask"; `sso_available` is the older boolean and is
 * still understood, because rows written before this key existed are live in
 * tblservers_remote right now. JSON and the database each have their own idea
 * of true and false, hence the string forms.
 *
 * @param array<string, mixed> $values
 */
function ispconfigconnect_ssoStateOfRow(array $values): string
{
    $state = $values['sso_state'] ?? null;
    if (
        is_string($state)
        && in_array($state, [
            CompanionClient::STATUS_INSTALLED,
            CompanionClient::STATUS_ABSENT,
            CompanionClient::STATUS_UNKNOWN,
        ], true)
    ) {
        return $state;
    }

    $flag = $values['sso_available'] ?? null;
    if ($flag === true || $flag === 1 || $flag === '1') {
        return CompanionClient::STATUS_INSTALLED;
    }
    if ($flag === false || $flag === 0 || $flag === '0') {
        return CompanionClient::STATUS_ABSENT;
    }

    return CompanionClient::STATUS_UNKNOWN;
}

/**
 * The decoded tblservers_remote.meta_data of ONE server, or null when there is
 * nothing readable there (no row yet, a schema difference, corrupt JSON).
 *
 * @return array<string, mixed>|null
 */
function ispconfigconnect_cachedServerMetaDataFor(int $serverId): ?array
{
    if ($serverId <= 0 || !class_exists('\\WHMCS\\Database\\Capsule')) {
        return null;
    }

    try {
        $json = \WHMCS\Database\Capsule::table('tblservers_remote')
            ->where('server_id', $serverId)
            ->value('meta_data');

        if (!is_string($json) || $json === '') {
            return null;
        }

        $values = json_decode($json, true);
        if (!is_array($values) || array_is_list($values)) {
            return null;
        }

        /** @var array<string, mixed> $values */
        return $values;
    } catch (\Throwable) {
        // A WHMCS schema difference costs the button, not the page.
        return null;
    }
}

/**
 * The live single sign-on state of the panel this session is logged in to, as
 * an Sso\SsoAdvice::STATE_* value.
 *
 * One already-granted call (get_function_list). It can only ever report what a
 * PRESENCE probe can see - "installed but the grant was withheld" is invisible
 * here and is discovered by an actual login attempt (see Sso\SsoAdvice).
 *
 * @param array<string, mixed> $params
 */
function ispconfigconnect_ssoState(array $params, SessionManager $session): string
{
    try {
        return match (ispconfigconnect_companionClient($params, $session)->status()) {
            CompanionClient::STATUS_INSTALLED => SsoAdvice::STATE_INSTALLED,
            CompanionClient::STATUS_ABSENT => SsoAdvice::STATE_ABSENT,
            default => SsoAdvice::STATE_PROBE_DENIED,
        };
    } catch (\Throwable) {
        // The panel stopped answering mid-probe: that is a connection fact, not
        // an answer about single sign-on.
        return SsoAdvice::STATE_UNREACHABLE;
    }
}

/**
 * Test Connection's own module-log line. WHMCS's rendering of a SUCCESSFUL test
 * is core's and cannot be verified from here, so the SSO report is written
 * where an admin can always find it.
 *
 * @param array<string, mixed> $params
 */
function ispconfigconnect_logTestConnection(array $params, bool $success, string $ssoState): void
{
    ispconfigconnect_logModuleCall(
        'TestConnection',
        ['serverid' => $params['serverid'] ?? null],
        ['success' => $success, 'sso_state' => $ssoState, 'sso' => SsoAdvice::admin($ssoState)]
    );
}

/**
 * The decoded tblservers_remote.meta_data of every server running this module.
 *
 * @return list<array<string, mixed>>
 */
function ispconfigconnect_cachedServerMetaData(): array
{
    if (!class_exists('\\WHMCS\\Database\\Capsule')) {
        return [];
    }

    try {
        $rows = \WHMCS\Database\Capsule::table('tblservers_remote')
            ->join('tblservers', 'tblservers.id', '=', 'tblservers_remote.server_id')
            ->where('tblservers.type', Factory::MODULE_NAME)
            ->pluck('tblservers_remote.meta_data');

        $decoded = [];
        foreach (is_iterable($rows) ? $rows : [] as $json) {
            if (!is_string($json) || $json === '') {
                continue;
            }
            $values = json_decode($json, true);
            if (is_array($values)) {
                /** @var array<string, mixed> $values */
                $decoded[] = $values;
            }
        }

        return $decoded;
    } catch (\Throwable) {
        // A WHMCS schema difference costs the SSO label, not the page.
        return [];
    }
}

/**
 * A WHMCS\Service\Status constant when WHMCS is in scope, the module's own
 * copy of its value otherwise (lib code must not reference WHMCS classes).
 */
function ispconfigconnect_serviceStatus(string $constant, string $fallback): string
{
    try {
        $name = 'WHMCS\\Service\\Status::' . $constant;
        if (defined($name)) {
            $value = constant($name);
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }
    } catch (\Throwable) {
        // Fall through to the module's own copy of the value.
    }

    return $fallback;
}

/**
 * The WHMCS status of the service in scope ('Active', 'Suspended', ...), or ''
 * when nothing in $params says.
 *
 * WHERE WHMCS PUTS IT. Module parameters carry the service status as
 * $params['status']: core's parameter builder sets `$params["status"] =
 * $serviceData->domainStatus` and `$params["model"] = $serviceData`
 * (WHMCS\Module\Server::buildServiceParams(), read in decoded 8.12.1 core; the
 * same in 7.10.2). There is NO 'domainstatus' parameter - that is the
 * tblhosting COLUMN name - and a gate keyed on it never fired in production:
 * every suspended service kept its login button. 'domainstatus' is still read
 * second, for a caller that builds $params from the table row, and the service
 * model's own domainstatus attribute is the last resort.
 *
 * The model is an Eloquent object whose attributes are magic, so it is read
 * defensively and inside a try: Eloquent's getAttribute() - what its
 * __isset/__get resolve to anyway - where the object has it (it answers null
 * for a missing attribute, or throws when strict attribute access is on), and
 * isset() plus a property fetch for anything else. Either way a read that
 * throws costs the gate its answer, never the page.
 *
 * @param array<string, mixed> $params
 */
function ispconfigconnect_serviceStatusOf(array $params): string
{
    foreach (['status', 'domainstatus'] as $key) {
        $value = $params[$key] ?? null;
        if (is_scalar($value) && trim((string) $value) !== '') {
            return trim((string) $value);
        }
    }

    $model = $params['model'] ?? null;
    if (!is_object($model)) {
        return '';
    }

    // Named through a variable because the attribute is magic: no class
    // declares it, so a literal property fetch reads as undefined to static
    // analysis even though Eloquent resolves it at runtime.
    $attribute = 'domainstatus';
    try {
        if (method_exists($model, 'getAttribute')) {
            $value = $model->getAttribute($attribute);
        } else {
            $value = isset($model->{$attribute}) ? $model->{$attribute} : null;
        }
    } catch (\Throwable) {
        return '';
    }

    return is_scalar($value) ? trim((string) $value) : '';
}

/**
 * tblhosting.bwusage as it currently stands, per service, from the same rows
 * the sweep already reads. Missing or unreadable values map to null, which
 * makes the write unconditional (previous behaviour).
 *
 * @param null|callable(int): list<array<string, mixed>> $rowProvider
 * @return array<int, int>
 */
function ispconfigconnect_storedBandwidth(int $serverId, ?callable $rowProvider = null): array
{
    try {
        $rows = $rowProvider === null
            ? ispconfigconnect_serverServiceRows($serverId)
            : $rowProvider($serverId);
    } catch (\Throwable) {
        return [];
    }

    $stored = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $serviceId = (int) ($row['id'] ?? 0);
        $bandwidth = $row['bwusage'] ?? null;
        if ($serviceId > 0 && is_numeric($bandwidth)) {
            $stored[$serviceId] = (int) $bandwidth;
        }
    }

    return $stored;
}

/**
 * tblhosting.lastupdate per service, from the same rows the sweep already
 * reads. It lets Usage\ServiceUsage::toHostingColumnsGiven() tell this month's
 * accumulated bwusage (which a lower reading must not erase) from a stale peak
 * left over after a month rollover (which must be replaced). Missing values
 * map to null, which keeps the previous "always keep the higher figure"
 * behaviour for that service.
 *
 * @param null|callable(int): list<array<string, mixed>> $rowProvider
 * @return array<int, string>
 */
function ispconfigconnect_storedLastUpdate(int $serverId, ?callable $rowProvider = null): array
{
    try {
        $rows = $rowProvider === null
            ? ispconfigconnect_serverServiceRows($serverId)
            : $rowProvider($serverId);
    } catch (\Throwable) {
        return [];
    }

    $stored = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $serviceId = (int) ($row['id'] ?? 0);
        $lastUpdate = $row['lastupdate'] ?? null;
        if ($serviceId > 0 && is_string($lastUpdate) && $lastUpdate !== '') {
            $stored[$serviceId] = $lastUpdate;
        }
    }

    return $stored;
}

/**
 * Every service on one WHMCS server, as the usage code addresses them.
 *
 * @param null|callable(int): list<array<string, mixed>> $rowProvider
 * @return list<ServiceRef>
 */
function ispconfigconnect_serviceRefs(int $serverId, ?callable $rowProvider = null): array
{
    try {
        $rows = $rowProvider === null
            ? ispconfigconnect_serverServiceRows($serverId)
            : $rowProvider($serverId);
    } catch (\Throwable) {
        return [];
    }

    $refs = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $serviceId = (int) ($row['id'] ?? 0);
        if ($serviceId <= 0) {
            continue;
        }

        // The stored panel username is authoritative; tblhosting.username is
        // the fallback for services provisioned before it was recorded.
        $username = trim((string) ($row[ServiceProperties::KEY_CLIENT_USERNAME] ?? ''));
        if ($username === '') {
            $username = trim((string) ($row['username'] ?? ''));
        }

        // A service with no stored client id is kept on purpose: the Updater
        // reports it as skipped, which is how an operator learns that an
        // imported or hand-made service is not linked to the panel.
        $refs[] = new ServiceRef(
            $serviceId,
            (int) ($row[ServiceProperties::KEY_CLIENT_ID] ?? 0),
            $username
        );
    }

    return $refs;
}

/**
 * tblhosting rows for one server, each carrying the two ispconfig_* service
 * properties the usage code needs. Three queries, regardless of service count.
 *
 * @return list<array<string, mixed>>
 */
function ispconfigconnect_serverServiceRows(int $serverId): array
{
    if ($serverId <= 0 || !class_exists('\\WHMCS\\Database\\Capsule')) {
        return [];
    }

    try {
        $services = \WHMCS\Database\Capsule::table('tblhosting')
            ->where('server', $serverId)
            // Terminated and cancelled services have no panel client left to
            // read, and probing them would only manufacture failures.
            ->whereIn('domainstatus', [
                ispconfigconnect_serviceStatus('ACTIVE', 'Active'),
                ispconfigconnect_serviceStatus('SUSPENDED', 'Suspended'),
            ])
            // bwusage comes along so the sweep can refuse to overwrite an
            // accumulated month's traffic with a lower reading, and lastupdate
            // so it can tell this month's accumulated total from a stale peak
            // left over after a month rollover (see
            // Usage\ServiceUsage::toHostingColumnsGiven()); domain is the key
            // automatic adoption matches an unlinked service on.
            ->get(['id', 'username', 'domain', 'bwusage', 'lastupdate']);

        $rows = [];
        foreach (is_iterable($services) ? $services : [] as $service) {
            $columns = (array) $service;
            $id = (int) ($columns['id'] ?? 0);
            if ($id > 0) {
                $rows[$id] = [
                    'id' => $id,
                    'username' => (string) ($columns['username'] ?? ''),
                    'domain' => (string) ($columns['domain'] ?? ''),
                    'bwusage' => isset($columns['bwusage']) && is_numeric($columns['bwusage'])
                        ? (int) $columns['bwusage']
                        : null,
                    'lastupdate' => isset($columns['lastupdate']) ? (string) $columns['lastupdate'] : null,
                ];
            }
        }
        if ($rows === []) {
            return [];
        }

        // Service properties are ordinary product custom fields, scoped to the
        // service's CURRENT product, and one service can have rows on several
        // products (a package change leaves the old product's behind). The
        // sweep must read a service as linked exactly when every other surface
        // does - otherwise it bills an old client's usage to a service the
        // admin tab calls unlinked - so it uses the SAME resolver as the
        // shared-client guard, which mirrors the property reader in
        // ispconfigconnect_serviceProperties().
        foreach ([ServiceProperties::KEY_CLIENT_ID, ServiceProperties::KEY_CLIENT_USERNAME] as $key) {
            foreach (ispconfigconnect_effectivePropertyValues($key, array_keys($rows)) as $id => $value) {
                if (isset($rows[$id])) {
                    $rows[$id][$key] = $value;
                }
            }
        }

        return array_values($rows);
    } catch (\Throwable) {
        return [];
    }
}

/**
 * The default tblhosting writer for UsageUpdate. `lastupdate` is what WHMCS's
 * own sample sets alongside the four usage columns
 * (developers.whmcs.com/provisioning-modules/usage-update/).
 *
 * ONE CLOCK. The stamp is written from PHP's clock, not MySQL's now(), because
 * PHP's clock is what reads it back: Usage\ServiceUsage compares the stored
 * lastupdate with PHP's "now" to decide whether this month's bandwidth is being
 * accumulated or last month's peak must be reset. MySQL's now() follows the
 * database session's time zone, PHP's date() follows date.timezone, and WHMCS
 * aligns neither with the other. With MySQL ahead of PHP, the last run of a
 * month is stamped into the NEXT month, the rollover is never seen, and last
 * month's peak is billed on and on. Stamping and comparing with the same clock
 * removes the question.
 *
 * @return \Closure(int, array<string, int>): void
 */
function ispconfigconnect_usageWriter(): \Closure
{
    return static function (int $serviceId, array $columns): void {
        if (!class_exists('\\WHMCS\\Database\\Capsule')) {
            // Outside a WHMCS runtime there is nothing to write to; report it
            // as this service's skip reason rather than pretending success.
            throw new \RuntimeException('WHMCS\\Database\\Capsule is unavailable');
        }

        \WHMCS\Database\Capsule::table('tblhosting')
            ->where('id', $serviceId)
            ->update($columns + ['lastupdate' => date('Y-m-d H:i:s')]);
    };
}

/**
 * Metrics\Provider's plain definitions as WHMCS Metric objects, with usage
 * attached wherever a value was collected.
 *
 * A value of 0 IS attached: zero is a real reading, and omitting it would
 * leave WHMCS showing the previous collection forever.
 *
 * @param array<string, int> $values keyed by metric system name
 * @return list<Metric>
 */
function ispconfigconnect_metricObjects(array $values = []): array
{
    $metrics = [];
    foreach (MetricsProvider::metrics() as $definition) {
        $metric = new Metric(
            $definition->systemName,
            $definition->displayName,
            $definition->type === MetricsProvider::TYPE_PERIOD_MONTH
                ? MetricInterface::TYPE_PERIOD_MONTH
                : MetricInterface::TYPE_SNAPSHOT,
            match ($definition->unit) {
                MetricsProvider::UNIT_MEGABYTES => new MegaBytes($definition->unitLabel),
                MetricsProvider::UNIT_ACCOUNTS => new Accounts($definition->unitLabel),
                MetricsProvider::UNIT_DOMAINS => new Domains($definition->unitLabel),
                default => throw new \LogicException('Unmapped metric unit ' . $definition->unit),
            }
        );

        if (array_key_exists($definition->systemName, $values)) {
            $metric = $metric->withUsage(new Usage($values[$definition->systemName]));
        }

        $metrics[] = $metric;
    }

    return $metrics;
}

/**
 * The live panel figures on the admin service tab - and the link state, which
 * is the one row that can also CHANGE something (it may adopt an unlinked
 * service). All from ONE session, and all degrading to a placeholder instead
 * of throwing: a service whose panel is down must still render.
 *
 * @param array<string, mixed> $params
 * @param null|callable $adoptionResolver see ispconfigconnect_adoptionResolver()
 * @return array{jobqueue: string, disk: string, bandwidth: string, sso: string, link: string}
 */
function ispconfigconnect_adminLiveFields(
    array $params,
    ServiceProperties $properties,
    ?callable $adoptionResolver = null
): array {
    $clientId = $properties->clientId();

    $fields = [
        'jobqueue' => 'n/a',
        'disk' => 'n/a',
        'bandwidth' => 'n/a',
        // Until a probe answers, the honest value is "the panel was not asked",
        // which every early return below leaves in place.
        'sso' => SsoAdvice::admin(SsoAdvice::STATE_UNREACHABLE),
        'link' => $clientId === null
            ? 'Not linked to an ISPConfig account, and the panel could not be asked about it.'
            : sprintf('Linked to ISPConfig client #%d (not verified - the panel could not be asked).', $clientId),
    ];

    try {
        $session = Factory::sessionManager($params);
        try {
            try {
                $fields['jobqueue'] = (string) (new Recipe($session))->jobqueueCount();
            } catch (\Throwable $failure) {
                // A connection-level failure would repeat identically for every
                // probe below, and each retry is another failed login against
                // the panel's 10-per-5-minutes IP lockout.
                if (ispconfigconnect_isConnectionLevel($failure)) {
                    return $fields;
                }
            }

            $fields['link'] = ispconfigconnect_serviceLinkState(
                $session,
                $params,
                $properties,
                $adoptionResolver
            );
            // The usage rows below belong to whatever this service is linked to
            // NOW, which may be an account adopted a moment ago.
            $clientId = $properties->clientId();

            if ($clientId !== null) {
                try {
                    $usage = (new Collector($session))->usageFor($clientId);
                    $fields['disk'] = ispconfigconnect_usageText(
                        $usage->diskUsageMegabytes(),
                        $usage->diskLimitMegabytes(),
                        $usage->diskUnlimited()
                    );
                    $fields['bandwidth'] = ispconfigconnect_usageText(
                        $usage->bandwidthUsageMegabytes(),
                        $usage->bandwidthLimitMegabytes(),
                        $usage->bandwidthUnlimited()
                    );
                } catch (\Throwable $failure) {
                    if (ispconfigconnect_isConnectionLevel($failure)) {
                        return $fields;
                    }
                }
            }

            // Distinct answers with distinct instructions: "not installed",
            // "we were not allowed to look" and "the panel never answered"
            // send an operator to three different places, and none of them is
            // a status word an admin has to interpret (Sso\SsoAdvice).
            $fields['sso'] = SsoAdvice::admin(ispconfigconnect_ssoState($params, $session));
        } finally {
            $session->close();
        }
    } catch (\Throwable) {
        // No usable server configuration at all: keep every placeholder.
    }

    return $fields;
}

/** "512 MB of 1024 MB" / "512 MB of unlimited" - the panel's own vocabulary. */
function ispconfigconnect_usageText(int $usedMegabytes, int $limitMegabytes, bool $unlimited): string
{
    return sprintf(
        '%d MB of %s',
        $usedMegabytes,
        $unlimited ? 'unlimited' : $limitMegabytes . ' MB'
    );
}

/**
 * True for failures that would repeat identically on every further call in the
 * same request: the transport never connected, the API is off, the credentials
 * or source IP are rejected, the panel is in maintenance, or the session
 * cannot be kept. Mirrors Server\RemoteMetaData's classification, and exists
 * for the same reason - failed logins and dead-session lookups share the
 * counter that locks an IP out for five minutes (remoting.inc.php, spec 1.4).
 */
function ispconfigconnect_isConnectionLevel(\Throwable $failure): bool
{
    return $failure instanceof TransportException
        || $failure instanceof ApiDisabledException
        || $failure instanceof LockoutException
        || $failure instanceof LoginFailedException      // incl. IpNotAllowedException
        || $failure instanceof MaintenanceModeException
        || $failure instanceof MalformedResponseException
        || $failure instanceof SessionExpiredException;  // incl. SessionIpMismatchException
}

/** The exception message, or its class when the message is empty. */
function ispconfigconnect_failureText(\Throwable $failure): string
{
    $message = trim($failure->getMessage());

    return $message !== '' ? $message : get_class($failure);
}

// ---------------------------------------------------------------------------
// ADOPTION OF PRE-EXISTING ACCOUNTS
//
// Until this existed, the module recognised a service as its own only when
// that service carried a stored ispconfig_client_id - and that property was
// written in exactly ONE place, CreateAccount. A customer migrated onto
// ISPConfig from another panel therefore had a live WHMCS service and a live
// ISPConfig account with nothing joining them, and suspend, terminate, usage
// and single sign-on all refused with "no stored ispconfig_client_id".
//
// The module now links such a service ITSELF. Five rules keep that safe,
// because this code acts on real customers' hosting:
//
//   1. Link ONLY on an exact, unambiguous match. Zero candidates or several
//      means DO NOT LINK - record why and leave the service unmanaged.
//   2. NEVER auto-link inside a destructive action. Anything that deletes or
//      overwrites panel state refuses an unlinked service and says what to do;
//      it does not resolve-then-act.
//   3. Every link written is logged with the evidence that justified it.
//   4. Adoption is idempotent and never overwrites an id that is already
//      stored - a linked service is not even offered to the resolver.
//   5. A stored id that no longer resolves is reported STALE and is never
//      silently re-pointed at another client.
//
// WHERE THE MATCHING LIVES. Deciding WHICH panel client a service belongs to
// is Adopt\Resolver's job, in its own lane. This file consumes it through a
// plain-data seam (the same shape as the $rowProvider / $writer /
// $ssoStateReader seams elsewhere in this file) so that the module tests can
// drive every outcome without that class, and so an install that does not have
// it degrades to "automatic adoption unavailable" instead of failing.
// ---------------------------------------------------------------------------

/** The resolver said this service maps to exactly one panel client. */
const ISPCONFIGCONNECT_ADOPT_MATCHED = 'matched';
/** No panel client could be attributed to this service. */
const ISPCONFIGCONNECT_ADOPT_NO_MATCH = 'no_match';
/** More than one candidate - the one outcome that most needs a human. */
const ISPCONFIGCONNECT_ADOPT_AMBIGUOUS = 'ambiguous';
/** The panel could not be read, so nothing at all was established. */
const ISPCONFIGCONNECT_ADOPT_UNREADABLE = 'unreadable';

/**
 * The adoption resolver, or null when this install has none.
 *
 * THE SEAM. A callable
 *   fn (SessionManager $session, list<array{serviceid:int, domain:string, username:string}>)
 *       : array<int, mixed>   // keyed by WHMCS service id
 * whose values are read by ispconfigconnect_adoptionOutcome() below. It is
 * handed an ALREADY-OPEN session on purpose: the nightly sweep has one, and
 * adoption must not cost a second login (ISPConfig locks an IP out after ten
 * failed attempts in five minutes - remoting.inc.php, spec 1.4).
 *
 * The batch form is the only form. Resolving per service would read the panel
 * once per service; one call per sweep is what the resolver's bulk path is
 * for.
 *
 * NOTE FOR THE Adopt\Resolver LANE: the default binding below expects
 * `WHMCS\Module\Server\Ispconfigconnect\Adopt\Resolver::__construct(SessionManager)`
 * and `->resolveMany(array $services): array`. If the real class differs, this
 * one function is the only thing that has to change - nothing else in this
 * file knows the class exists.
 *
 * @param null|callable(SessionManager, list<array{serviceid: int, domain: string, username: string}>): array<int, mixed> $override test seam
 * @return null|\Closure(SessionManager, list<array{serviceid: int, domain: string, username: string}>): array<int, mixed>
 */
function ispconfigconnect_adoptionResolver(?callable $override = null): ?\Closure
{
    if ($override !== null) {
        return $override(...);
    }

    $class = 'WHMCS\\Module\\Server\\Ispconfigconnect\\Adopt\\Resolver';
    if (!class_exists($class)) {
        return null;
    }

    return static function (SessionManager $session, array $services) use ($class): array {
        $resolver = new $class($session);
        $resolveMany = [$resolver, 'resolveMany'];
        if (!is_callable($resolveMany)) {
            return [];
        }

        $answers = $resolveMany($services);

        return is_array($answers) ? $answers : [];
    };
}

/**
 * One resolver answer, reduced to the only shape this file acts on.
 *
 * THIS IS RULE 1's ENFORCEMENT POINT, and it is deliberately here rather than
 * in the resolver: a link is written against a real customer's hosting, so the
 * decision to write one is re-made locally from the answer's own contents. A
 * malformed answer, an unknown reason word, or a "match" carrying no usable
 * client id all collapse to UNREADABLE - never to a link.
 *
 * The positive-id check is not defensive dressing. ISPConfig does not reject a
 * falsy client id; trafficquota_get_by_user answers it with EVERY domain on
 * the server (quota_lib.inc.php:80, and see Usage\Collector), so a stored 0
 * would bill one service for the whole panel.
 *
 * @return array{reason: string, clientid: int|null, clientusername: string, domainid: int|null, dnszoneid: int|null, evidence: string, candidates: list<string>}
 */
function ispconfigconnect_adoptionOutcome(mixed $answer): array
{
    $outcome = [
        'reason' => ISPCONFIGCONNECT_ADOPT_UNREADABLE,
        'clientid' => null,
        'clientusername' => '',
        // Optional extras: the resolver supplies these only where it could
        // establish them, and the module stores what it is given. The website
        // id in particular is what website-only suspension needs: a service
        // adopted without one is refused website-only Suspend/Unsuspend (never
        // widened to the whole-client lock) until Link/Replace stores it.
        'domainid' => null,
        'dnszoneid' => null,
        'evidence' => '',
        'candidates' => [],
    ];

    if (!is_array($answer)) {
        $outcome['evidence'] = 'the resolver returned no usable answer for this service';

        return $outcome;
    }

    $reason = $answer['reason'] ?? null;
    if (
        is_string($reason)
        && in_array($reason, [
            ISPCONFIGCONNECT_ADOPT_MATCHED,
            ISPCONFIGCONNECT_ADOPT_NO_MATCH,
            ISPCONFIGCONNECT_ADOPT_AMBIGUOUS,
            ISPCONFIGCONNECT_ADOPT_UNREADABLE,
        ], true)
    ) {
        $outcome['reason'] = $reason;
    } else {
        $outcome['evidence'] = sprintf(
            'the resolver returned an unrecognised outcome (%s) and was not acted on',
            is_scalar($reason) ? (string) $reason : get_debug_type($reason)
        );
    }

    $clientId = $answer['clientid'] ?? null;
    if (is_numeric($clientId) && (int) $clientId > 0) {
        $outcome['clientid'] = (int) $clientId;
    }

    $username = $answer['clientusername'] ?? '';
    if (is_scalar($username)) {
        $outcome['clientusername'] = trim((string) $username);
    }

    foreach (['domainid', 'dnszoneid'] as $extra) {
        $value = $answer[$extra] ?? null;
        if (is_numeric($value) && (int) $value > 0) {
            $outcome[$extra] = (int) $value;
        }
    }

    $evidence = $answer['evidence'] ?? '';
    if (is_scalar($evidence) && trim((string) $evidence) !== '') {
        $outcome['evidence'] = trim((string) $evidence);
    }

    $candidates = $answer['candidates'] ?? [];
    if (is_array($candidates)) {
        foreach ($candidates as $candidate) {
            if (is_scalar($candidate) && trim((string) $candidate) !== '') {
                $outcome['candidates'][] = trim((string) $candidate);
            }
        }
    }

    // A "match" the module cannot act on safely is not a match.
    if ($outcome['reason'] === ISPCONFIGCONNECT_ADOPT_MATCHED && $outcome['clientid'] === null) {
        $outcome['reason'] = ISPCONFIGCONNECT_ADOPT_UNREADABLE;
        $outcome['evidence'] = 'the resolver reported a match but gave no usable ISPConfig client id,'
            . ' so nothing was linked';
    }

    return $outcome;
}

/**
 * One unlinked service's outcome as a sentence an operator can act on. The
 * candidates are NAMED for an ambiguous answer - "ambiguous" on its own leaves
 * an admin with nothing to do next.
 *
 * @param array{reason: string, clientid: int|null, clientusername: string, evidence: string, candidates: list<string>} $outcome
 */
function ispconfigconnect_adoptionReason(array $outcome): string
{
    $reason = $outcome['evidence'] !== ''
        ? $outcome['evidence']
        : match ($outcome['reason']) {
            ISPCONFIGCONNECT_ADOPT_NO_MATCH => 'no ISPConfig account could be attributed to this service',
            ISPCONFIGCONNECT_ADOPT_AMBIGUOUS => 'more than one ISPConfig account could be this service',
            default => 'the panel could not be read, so nothing was established',
        };

    if ($outcome['candidates'] !== []) {
        $reason .= ' Candidates: ' . implode(', ', $outcome['candidates']) . '.';
    }

    return $reason;
}

/**
 * Why an otherwise clean automatic match was not linked: the one-service-per-
 * client check could not run (ispconfigconnect_otherServicesLinkedToClient()
 * returned null and logged the error).
 */
function ispconfigconnect_unverifiedAdoptionReason(int $clientId): string
{
    return sprintf(
        'matched ISPConfig client #%d, but the check that no other WHMCS service is already linked to'
        . ' it could not run (the module log has the error under "SharedClientGuard"), so it was left'
        . ' unmanaged rather than linked unverified.',
        $clientId
    );
}

/**
 * The shared body of the Link and Replace buttons.
 *
 * ORDER MATTERS HERE. The panel is asked to resolve the name BEFORE the
 * already-linked guard runs, so that a refusal can name BOTH ids - the one
 * stored and the one the admin meant. "This service is already linked" without
 * saying what to leaves an admin unable to tell a mistake from a duplicate.
 *
 * Every panel call is a READ (client_get_by_username, then - when something is
 * about to be stored - client_get_sites_by_user and sites_web_domain_get).
 * Nothing here creates, updates or deletes anything on the panel: these buttons
 * change what WHMCS has stored, and that is all they can do.
 *
 * ## The website id comes with the link
 *
 * Website-only suspension acts on the service's ISPConfig website and refuses
 * without its id (ispconfigconnect_noWebsiteIdRefusal()), and a link used to
 * store the client alone - so every linked service on a website-only product
 * could not be suspended at all. The link therefore also resolves the website
 * whose domain is the service domain, and stores its id when exactly one of the
 * client's OWN websites carries that name (ispconfigconnect_linkWebsite()). Any
 * other answer stores nothing and says why in the log line below.
 *
 * Naming the account ALREADY stored is still a success that re-points nothing,
 * but it is no longer a pure no-op: a missing website id is filled in. That is
 * rule 4 intact - a gap is filled, a stored value is never overwritten - and it
 * is what makes "press Replace ISPConfig Link" a working answer for a service
 * linked before the website id was resolved.
 *
 * @param array<string, mixed> $params
 * @param bool $replace true for Replace ISPConfig Link - the deliberate
 *        overwrite; false for Link, which refuses to overwrite (rule 4)
 * @return string 'success' or error string
 */
function ispconfigconnect_link(array $params, string $context, bool $replace): string
{
    $label = $replace ? 'Replace ISPConfig Link' : 'Link';

    try {
        $properties = ispconfigconnect_serviceProperties($params);
        $storedId = $properties->clientId();
        $serviceId = (int) ($params['serviceid'] ?? 0);

        $name = trim((string) ($params['username'] ?? ''));
        if ($name === '') {
            // Refused before a session is opened: an empty name has nothing to
            // verify, and a wasted login is a wasted login.
            return $label . ' refused: this service\'s Username field is empty, and the module will'
                . ' not guess which ISPConfig account it means. Put the ISPConfig account name in the'
                . ' service\'s Username field and press the button again.'
                . ispconfigconnect_adoptionRouteClause(trim((string) ($params['domain'] ?? '')));
        }

        $session = Factory::sessionManager($params);
        try {
            // remote.d/client.inc.php:478 - a bare SELECT on sys_user, which
            // throws no_client_found when the name is unknown. A miss is the
            // NORMAL answer here, so it is turned into advice rather than an
            // error; a connection or permission failure is not a miss and is
            // re-thrown to the caller's usual advice path.
            try {
                $record = $session->call('client_get_by_username', ['username' => $name]);
            } catch (\Throwable $failure) {
                if (ispconfigconnect_isConnectionLevel($failure) || $failure instanceof PermissionDeniedException) {
                    throw $failure;
                }
                $record = null;
            }

            if (!is_array($record)) {
                return ispconfigconnect_noSuchAccount($label, $name, $params);
            }

            // sys_user rows exist for panel ADMINISTRATORS too, and those carry
            // no client id. Linking a service to one would point every later
            // operation at something that is not a hosting account.
            $resolvedId = isset($record['client_id']) && is_numeric($record['client_id'])
                ? (int) $record['client_id']
                : 0;
            if ($resolvedId <= 0) {
                return sprintf(
                    '%s refused: "%s" is a panel login but not an ISPConfig CLIENT account (it carries no'
                    . ' client id), so there is nothing for this service to be linked to. Name the client'
                    . ' account that owns this customer\'s hosting instead.',
                    $label,
                    $name
                );
            }

            // Rule 4, the idempotent case: already exactly this client.
            $alreadyLinked = $storedId !== null && $storedId === $resolvedId;
            if ($alreadyLinked && $properties->domainId() !== null) {
                // Nothing to re-point and no gap to fill: nothing is written,
                // and nothing is an error.
                return 'success';
            }

            if (!$alreadyLinked) {
                // One WHMCS service = one ISPConfig client. Never bind to a
                // client that another live service already holds: a typo or a
                // paste from the wrong ticket resolves to a real account, and
                // linking here would hand this customer another's panel over
                // SSO and delete it on terminate. A guard that could not run
                // (null) has logged why; the admin's explicit link proceeds.
                $shared = ispconfigconnect_sharedClientRefusal(
                    $label,
                    $resolvedId,
                    ispconfigconnect_otherServicesLinkedToClient(
                        $serviceId,
                        $resolvedId,
                        (string) ($record['username'] ?? $name)
                    ) ?? []
                );
                if ($shared !== null) {
                    return $shared;
                }

                if ($storedId !== null && !$replace) {
                    return sprintf(
                        'Link refused: this service is already linked to ISPConfig client #%d, and "%s" is'
                        . ' client #%d. A stored link is never silently re-pointed - the id on file may be'
                        . ' the correct one, and overwriting it would move every later suspend, terminate'
                        . ' and usage read onto a different account. If #%d really is correct, press'
                        . ' "Replace ISPConfig Link" instead, which runs the same check and then overwrites'
                        . ' deliberately. Nothing has been changed.',
                        $storedId,
                        $name,
                        $resolvedId,
                        $resolvedId
                    );
                }
            }

            // Only now, when something WILL be stored, is the panel asked which
            // of this client's websites is the service's - on the same session.
            $website = ispconfigconnect_linkWebsite($session, $record, Params::service($params)->domain);
        } finally {
            $session->close();
        }

        if ($alreadyLinked) {
            // The client link is untouched; only the MISSING website id is
            // filled in, when one could be established.
            if ($website['domainid'] !== null) {
                $properties->setDomainId($website['domainid']);
            }

            ispconfigconnect_logModuleCall(
                $context,
                ['serviceid' => $params['serviceid'] ?? null, 'username' => $name],
                [
                    'linked' => true,
                    'already_linked' => true,
                    'ispconfig_client_id' => $resolvedId,
                    'ispconfig_domain_id' => $website['domainid'],
                    'website' => $website['note'],
                    'panel_touched' => false,
                ]
            );

            if ($website['domainid'] === null && !Params::productConfig($params)->suspendEntireClient) {
                // On a website-only product, filling the website id was the
                // only thing this press could do, and it did not happen.
                // 'success' would send the admin straight back to a Suspend
                // refusal that tells them to press this button again.
                $reason = (string) preg_replace('/^website id not stored:\s*/', '', $website['note']);

                return sprintf(
                    '%s kept the existing link to ISPConfig client #%d, but could not store the website id this'
                    . ' product needs to suspend: %s. The service domain must match exactly one website of'
                    . ' this client. Nothing has been changed.',
                    $label,
                    $resolvedId,
                    rtrim($reason !== '' ? $reason : 'no matching website was found', '.')
                );
            }

            return 'success';
        }

        if ($storedId !== null) {
            // Replacing: the website, FTP, mail, DNS and database ids on file
            // belong to the OLD client and would be actively wrong against the
            // new one, so they go with it - BEFORE the new ids are written.
            $properties->clear();
        }

        $properties->setClientId($resolvedId);
        $properties->setClientUsername($name);
        if ($website['domainid'] !== null) {
            $properties->setDomainId($website['domainid']);
        }

        $evidence = sprintf(
            'an administrator named ISPConfig account "%s" on WHMCS service #%d;'
            . ' the panel resolved that name to client #%d (client_get_by_username)',
            $name,
            $serviceId,
            $resolvedId
        );

        ispconfigconnect_logModuleCall(
            $context,
            ['serviceid' => $params['serviceid'] ?? null, 'username' => $name],
            [
                'linked' => true,
                'ispconfig_client_id' => $resolvedId,
                'ispconfig_client_username' => $name,
                'ispconfig_domain_id' => $website['domainid'],
                'website' => $website['note'],
                'replaced' => $storedId,
                'evidence' => $evidence,
                'panel_touched' => false,
            ]
        );

        return 'success';
    } catch (\Throwable $failure) {
        return ispconfigconnect_errorString($label, $failure);
    }
}

/**
 * The ISPConfig website id of the service domain, looked up among the linked
 * client's OWN websites - or null, with the reason, when it cannot be pinned to
 * exactly one.
 *
 * WHY ONLY THE CLIENT'S OWN GROUP. client_get_sites_by_user
 * (remote.d/sites.inc.php) returns every vhost with `(sys_userid = ? AND
 * sys_perm_user LIKE '%r%') OR (sys_groupid IN (<list>) AND sys_perm_group LIKE
 * '%r%') OR sys_perm_other LIKE '%r%'`. sys_user.groups is a RESELLER's whole
 * group list, its sub-clients' groups included, so passing it would offer a
 * sub-client's website as this client's. Only default_group - the client's own
 * group, the one every object created for it is filed under
 * (remoting_lib.inc.php loadUserProfile / tform_base.inc.php INSERT) - is
 * passed.
 *
 * WHY THE MATCH IS READ BACK. The sys_userid clause matches the website's
 * CREATOR (tform_base.inc.php stamps the session user on INSERT), so a site a
 * reseller created for a sub-client in the panel still comes back for the
 * reseller's own user id, and so does any world-readable site. One
 * sites_web_domain_get of the single match confirms it is filed under this
 * client's own group before its id is trusted.
 *
 * A connection-level failure propagates - the link is not written on a panel
 * that stopped answering, exactly as for client_get_by_username. A refused or
 * failing lookup (a missing grant, a remote fault) only costs the website id:
 * the client link is still valid, and the reason goes to the log.
 *
 * @param array<mixed> $sysUser the client_get_by_username record (sys_user row)
 * @param string $domain the service domain, normalised by Params::service()
 * @return array{domainid: int|null, note: string}
 */
function ispconfigconnect_linkWebsite(SessionManager $session, array $sysUser, string $domain): array
{
    if ($domain === '') {
        return [
            'domainid' => null,
            'note' => 'website id not stored: this service has no domain to match a website on',
        ];
    }

    $userId = isset($sysUser['userid']) && is_numeric($sysUser['userid']) ? (int) $sysUser['userid'] : 0;
    $groupId = isset($sysUser['default_group']) && is_numeric($sysUser['default_group'])
        ? (int) $sysUser['default_group']
        : 0;
    if ($groupId <= 0) {
        return [
            'domainid' => null,
            'note' => 'website id not stored: the panel login record carries no default group, so the'
                . ' client\'s own websites cannot be listed',
        ];
    }

    try {
        $sites = $session->call('client_get_sites_by_user', [
            'sys_userid' => $userId,
            // sites.inc.php explode()s this - a string, never an array; and
            // the client's OWN group only, never sys_user.groups (see above).
            'sys_groupid' => (string) $groupId,
        ]);
    } catch (IspconfigException $failure) {
        if (ispconfigconnect_isConnectionLevel($failure)) {
            throw $failure;
        }

        return [
            'domainid' => null,
            'note' => 'website id not stored: the panel refused or failed the website lookup'
                . ' (client_get_sites_by_user: ' . ispconfigconnect_failureText($failure) . ')',
        ];
    }

    $matches = [];
    foreach (is_array($sites) ? $sites : [] as $site) {
        if (!is_array($site)) {
            continue;
        }
        $siteDomain = is_scalar($site['domain'] ?? null) ? strtolower(trim((string) $site['domain'])) : '';
        $siteId = isset($site['domain_id']) && is_numeric($site['domain_id']) ? (int) $site['domain_id'] : 0;
        if ($siteId > 0 && $siteDomain === $domain) {
            $matches[$siteId] = true;
        }
    }

    if (count($matches) !== 1) {
        return [
            'domainid' => null,
            'note' => $matches === []
                ? sprintf('website id not stored: none of this client\'s own websites is named %s', $domain)
                : sprintf(
                    'website id not stored: %d of this client\'s websites are named %s (#%s), so none was'
                    . ' picked',
                    count($matches),
                    $domain,
                    implode(', #', array_keys($matches))
                ),
        ];
    }

    $siteId = (int) array_key_first($matches);

    try {
        $siteRecord = $session->call('sites_web_domain_get', ['primary_id' => $siteId]);
    } catch (IspconfigException $failure) {
        if (ispconfigconnect_isConnectionLevel($failure)) {
            throw $failure;
        }

        return [
            'domainid' => null,
            'note' => sprintf(
                'website id not stored: website #%d (%s) could not be read back to confirm its owner'
                . ' (sites_web_domain_get: %s)',
                $siteId,
                $domain,
                ispconfigconnect_failureText($failure)
            ),
        ];
    }

    $ownerGroup = is_array($siteRecord) && isset($siteRecord['sys_groupid']) && is_numeric($siteRecord['sys_groupid'])
        ? (int) $siteRecord['sys_groupid']
        : 0;
    $recordId = is_array($siteRecord) && isset($siteRecord['domain_id']) && is_numeric($siteRecord['domain_id'])
        ? (int) $siteRecord['domain_id']
        : 0;
    if ($recordId !== $siteId || $ownerGroup !== $groupId) {
        return [
            'domainid' => null,
            'note' => sprintf(
                'website id not stored: website #%d (%s) is not filed under this client\'s own group'
                . ' (#%d), so it may belong to another client (a reseller\'s sub-client, for example)',
                $siteId,
                $domain,
                $groupId
            ),
        ];
    }

    return [
        'domainid' => $siteId,
        'note' => sprintf('website #%d (%s) stored: the only website of this client named so', $siteId, $domain),
    ];
}

/**
 * The "no such account" advice.
 *
 * THIS IS THE MESSAGE THE MIGRATION CASE ACTUALLY HITS, so it is written for
 * it. A service moved from another control panel still carries THAT panel's
 * username in WHMCS - the owner's live data has a service whose WHMCS username
 * is a random string while the ISPConfig account owning the same domain is
 * named after the customer. Searching ISPConfig for the old name finds nothing,
 * and a bare "not found" reads as "the module cannot see my panel" when the
 * truth is "you searched by the wrong key".
 *
 * So it names the key that failed, names the DOMAIN that automatic adoption
 * uses instead, and gives both ways forward.
 *
 * @param array<string, mixed> $params
 */
function ispconfigconnect_noSuchAccount(string $label, string $name, array $params): string
{
    $domain = trim((string) ($params['domain'] ?? ''));

    return sprintf(
        '%s refused: this ISPConfig panel has no account named "%s", so nothing was linked and'
        . ' nothing was changed. That is the usual result for a service migrated from another'
        . ' control panel: the WHMCS Username field still holds the OLD panel\'s login name, which'
        . ' ISPConfig has never seen. Set this service\'s Username field to the ISPConfig account'
        . ' name that owns %s (System > Clients in the panel lists them) and press the button'
        . ' again.%s',
        $label,
        $name,
        $domain !== '' ? $domain : 'this service (currently no domain is set)',
        ispconfigconnect_adoptionRouteClause($domain)
    );
}

/**
 * Adopt every unlinked service in one batch, and hand back the service list
 * with the newly linked ones filled in so the SAME sweep bills them.
 *
 * WHY HERE. The nightly sweep is the ideal adoption moment: it already runs
 * per server, it already holds an open session, it already enumerates every
 * service on that server, and a mistake costs a wrong usage row - which the
 * next sweep corrects - rather than a deleted account. Contrast
 * TerminateAccount, which must never do this at all.
 *
 * Rule 4 is enforced by construction: only services with no stored id are put
 * in the batch, so a linked service is never re-decided.
 *
 * @param list<ServiceRef> $services
 * @param array<int, string> $domains WHMCS service id => service domain
 * @param null|callable $adoptionResolver test seam - see ispconfigconnect_adoptionResolver()
 * @param null|callable(int, array<string, string>): void $propertyWriter test seam
 * @return array{services: list<ServiceRef>, adopted: array<int, string>, unlinked: array<int, string>, note: string}
 */
function ispconfigconnect_adoptUnlinked(
    SessionManager $session,
    array $services,
    array $domains,
    ?callable $adoptionResolver = null,
    ?callable $propertyWriter = null
): array {
    $batch = [];
    foreach ($services as $service) {
        if ($service->clientId <= 0) {
            $batch[] = [
                'serviceid' => $service->serviceId,
                // The DOMAIN is the key that matches. A migrated service's
                // username belongs to the panel it came from.
                'domain' => $domains[$service->serviceId] ?? '',
                'username' => $service->username,
            ];
        }
    }

    if ($batch === []) {
        return ['services' => $services, 'adopted' => [], 'unlinked' => [], 'note' => ''];
    }

    $resolve = ispconfigconnect_adoptionResolver($adoptionResolver);
    if ($resolve === null) {
        return [
            'services' => $services,
            'adopted' => [],
            'unlinked' => [],
            'note' => sprintf(
                'unavailable: %d service(s) on this server carry no ISPConfig client id, and this'
                . ' install has no adoption resolver, so they were left unmanaged. Link them with the'
                . ' "Link to ISPConfig Account" button on each service.',
                count($batch)
            ),
        ];
    }

    try {
        // ONE call for the whole server. Resolving per service would read the
        // panel once per service, which on a few hundred services is a sweep
        // that never finishes.
        $answers = $resolve($session, $batch);
    } catch (\Throwable $failure) {
        return [
            'services' => $services,
            'adopted' => [],
            'unlinked' => [],
            'note' => 'failed: ' . ispconfigconnect_failureText($failure)
                . ' - no service was linked, and none was changed.',
        ];
    }

    $write = $propertyWriter ?? ispconfigconnect_servicePropertyWriter();
    $adopted = [];
    $unlinked = [];
    $linkedIds = [];

    foreach ($batch as $candidate) {
        $serviceId = $candidate['serviceid'];
        $outcome = ispconfigconnect_adoptionOutcome($answers[$serviceId] ?? null);

        if ($outcome['reason'] !== ISPCONFIGCONNECT_ADOPT_MATCHED || $outcome['clientid'] === null) {
            // Rule 1: zero candidates, several candidates, an unreadable panel
            // or an answer that cannot be trusted all end here - unmanaged,
            // with the reason on the record.
            $unlinked[$serviceId] = ispconfigconnect_adoptionReason($outcome);

            continue;
        }

        $values = [
            ServiceProperties::KEY_CLIENT_ID => (string) $outcome['clientid'],
            ServiceProperties::KEY_CLIENT_USERNAME => $outcome['clientusername'],
        ];
        if ($outcome['domainid'] !== null) {
            $values[ServiceProperties::KEY_DOMAIN_ID] = (string) $outcome['domainid'];
        }
        if ($outcome['dnszoneid'] !== null) {
            $values[ServiceProperties::KEY_DNS_ZONE_ID] = (string) $outcome['dnszoneid'];
        }

        // Never adopt a client another live service already holds: matching on
        // a customer-controlled domain plus a shared client is the cross-tenant
        // takeover path. Leave it unmanaged with the reason on the record.
        $holders = ispconfigconnect_otherServicesLinkedToClient(
            $serviceId,
            $outcome['clientid'],
            $outcome['clientusername']
        );
        if ($holders === null) {
            // Rule 1 again: a check that could not run is not a clean match.
            // Nobody asked for this link, so an unverified one is not made.
            $unlinked[$serviceId] = ispconfigconnect_unverifiedAdoptionReason($outcome['clientid']);

            continue;
        }
        if ($holders !== []) {
            $unlinked[$serviceId] = sprintf(
                'matched ISPConfig client #%d, but another WHMCS service is already linked to it, so it'
                . ' was left unmanaged rather than shared.',
                $outcome['clientid']
            );

            continue;
        }

        try {
            $write($serviceId, $values);
        } catch (\Throwable $failure) {
            // The link could not be PERSISTED, so it did not happen. Reporting
            // it as adopted would claim a link no later operation can read.
            $unlinked[$serviceId] = 'matched, but the link could not be stored: '
                . ispconfigconnect_failureText($failure);

            continue;
        }

        $adopted[$serviceId] = $outcome['evidence'];
        $linkedIds[$serviceId] = ['id' => $outcome['clientid'], 'username' => $outcome['clientusername']];
        ispconfigconnect_logAdoption(
            $serviceId,
            $outcome['clientid'],
            $outcome['clientusername'],
            $outcome['evidence'],
            'UsageUpdate'
        );
    }

    // Rebuild the refs so a service linked a moment ago is billed by THIS
    // sweep - an adopted service that had to wait a day for its first usage
    // row would look like the feature had not worked.
    $updated = [];
    foreach ($services as $service) {
        $link = $linkedIds[$service->serviceId] ?? null;
        $updated[] = $link === null
            ? $service
            : new ServiceRef(
                $service->serviceId,
                $link['id'],
                $link['username'] !== '' ? $link['username'] : $service->username
            );
    }

    return ['services' => $updated, 'adopted' => $adopted, 'unlinked' => $unlinked, 'note' => ''];
}

/**
 * ONE service's link state, as a sentence for the admin service tab - and, for
 * an unlinked service, the moment it gets adopted.
 *
 * The three answers an admin needs, and what each one does:
 *
 *   LINKED   - the stored id still resolves. Names the account, so a
 *              mis-linked service is visible rather than merely "managed".
 *   STALE    - the stored id no longer exists on the panel (rule 5). It is
 *              REPORTED, never repaired: the module does not go looking for
 *              "the client that fits now", because that client could be
 *              somebody else's. The admin gets the two buttons that fix it.
 *   UNLINKED - resolved through the adoption seam. A confident match is
 *              written here and now; anything else is explained, with the
 *              candidates named when the answer was ambiguous.
 *
 * A stored id is never handed to the resolver at all, which is what makes rule
 * 4 (never overwrite) structural rather than a check that could be forgotten.
 *
 * @param array<string, mixed> $params
 * @param null|callable $adoptionResolver see ispconfigconnect_adoptionResolver()
 */
function ispconfigconnect_serviceLinkState(
    SessionManager $session,
    array $params,
    ServiceProperties $properties,
    ?callable $adoptionResolver = null
): string {
    $serviceId = (int) ($params['serviceid'] ?? 0);
    $domain = trim((string) ($params['domain'] ?? ''));
    $storedId = $properties->clientId();

    if ($storedId !== null) {
        try {
            if (!ispconfigconnect_clientExists($session, $storedId)) {
                return sprintf(
                    'STALE: the stored ISPConfig client id #%d no longer exists on this panel.'
                    . ' Nothing has been re-linked - the module will not pick a replacement account'
                    . ' by itself, because the one it picked could belong to another customer.'
                    . ' Use "Replace ISPConfig Link" (after setting the Username field to the correct'
                    . ' ISPConfig account name) or "Unlink from ISPConfig" to clear it.',
                    $storedId
                );
            }
        } catch (\Throwable $failure) {
            // A panel that cannot answer is not evidence of staleness.
            return sprintf(
                'Linked to ISPConfig client #%d. The panel could not confirm it just now (%s).',
                $storedId,
                ispconfigconnect_failureText($failure)
            );
        }

        $username = $properties->clientUsername();

        return sprintf(
            'Linked to ISPConfig client #%d%s.',
            $storedId,
            $username !== null && $username !== '' ? ' (' . $username . ')' : ''
        );
    }

    $resolve = ispconfigconnect_adoptionResolver($adoptionResolver);
    if ($resolve === null) {
        return 'Not linked to an ISPConfig account. Automatic adoption is unavailable on this'
            . ' install, so link it with the "Link to ISPConfig Account" button (set the service\'s'
            . ' Username field to the ISPConfig account name first).';
    }

    try {
        $answers = $resolve($session, [[
            'serviceid' => $serviceId,
            'domain' => $domain,
            'username' => trim((string) ($params['username'] ?? '')),
        ]]);
    } catch (\Throwable $failure) {
        return 'Not linked to an ISPConfig account - the panel could not be searched just now ('
            . ispconfigconnect_failureText($failure) . ').';
    }

    $outcome = ispconfigconnect_adoptionOutcome($answers[$serviceId] ?? null);

    if ($outcome['reason'] !== ISPCONFIGCONNECT_ADOPT_MATCHED || $outcome['clientid'] === null) {
        // Rule 1. Not a link, and the reason is spelled out rather than left
        // as a status word.
        return 'Not linked to an ISPConfig account: ' . ispconfigconnect_adoptionReason($outcome)
            . ' Use "Link to ISPConfig Account" to link it by name instead.';
    }

    // Never adopt a client another live service already holds (cross-tenant
    // takeover via a customer-controlled domain) - nor one the check could not
    // clear (rule 1: an unverified match is not a match).
    $holders = ispconfigconnect_otherServicesLinkedToClient($serviceId, $outcome['clientid'], $outcome['clientusername']);
    if ($holders === null) {
        return 'Not linked: ' . ispconfigconnect_unverifiedAdoptionReason($outcome['clientid'])
            . ' Use "Link to ISPConfig Account" to link it by name instead.';
    }
    if ($holders !== []) {
        return sprintf(
            'Not linked: this service\'s domain matches ISPConfig client #%d, but another WHMCS service'
            . ' is already linked to that client. Give each service its own ISPConfig client, or unlink'
            . ' the other, then link this one by name.',
            $outcome['clientid']
        );
    }

    try {
        $properties->setClientId($outcome['clientid']);
        $properties->setClientUsername($outcome['clientusername']);
        if ($outcome['domainid'] !== null) {
            $properties->setDomainId($outcome['domainid']);
        }
        if ($outcome['dnszoneid'] !== null) {
            $properties->setDnsZoneId($outcome['dnszoneid']);
        }
    } catch (\Throwable $failure) {
        return 'Not linked: an ISPConfig account was matched, but the link could not be stored ('
            . ispconfigconnect_failureText($failure) . ').';
    }

    ispconfigconnect_logAdoption(
        $serviceId,
        $outcome['clientid'],
        $outcome['clientusername'],
        $outcome['evidence'],
        'AdminServicesTabFields'
    );

    return sprintf(
        'Linked to ISPConfig client #%d%s - adopted automatically just now: %s',
        $outcome['clientid'],
        $outcome['clientusername'] !== '' ? ' (' . $outcome['clientusername'] . ')' : '',
        $outcome['evidence']
    );
}

/**
 * The default service-property writer for the sweep, which has no WHMCS
 * service model in scope (UsageUpdate is called per SERVER).
 *
 * It is the exact mirror of the fallback READER wired in
 * ispconfigconnect_serviceProperties(): service properties are ordinary
 * product custom fields, addressed by tblcustomfields.fieldname +
 * tblcustomfieldsvalues.relid.
 *
 * IT THROWS RATHER THAN NO-OPS when the product has no custom-field definition
 * for a key yet. WHMCS creates those rows through the service model, so a
 * product that this module has never provisioned on may not have them; a
 * silent no-op there would report a link that no later read can see. The
 * caller turns the throw into that service's unlinked reason, and the admin
 * service tab - which DOES have a model - creates the rows the first time it
 * adopts a service on that product.
 *
 * @return \Closure(int, array<string, string>): void
 */
function ispconfigconnect_servicePropertyWriter(): \Closure
{
    return static function (int $serviceId, array $values): void {
        if (!class_exists('\\WHMCS\\Database\\Capsule')) {
            throw new \RuntimeException('WHMCS\\Database\\Capsule is unavailable');
        }

        $productId = \WHMCS\Database\Capsule::table('tblhosting')
            ->where('id', $serviceId)
            ->value('packageid');
        if (!is_numeric($productId) || (int) $productId <= 0) {
            throw new \RuntimeException('the service has no product id in tblhosting');
        }

        foreach ($values as $key => $value) {
            $fieldId = \WHMCS\Database\Capsule::table('tblcustomfields')
                ->where('type', 'product')
                ->where('relid', (int) $productId)
                ->where('fieldname', $key)
                ->value('id');

            if (!is_numeric($fieldId) || (int) $fieldId <= 0) {
                throw new \RuntimeException(sprintf(
                    'this service\'s product has no "%s" custom field yet, so the link could not be'
                    . ' stored - open the service in the admin area once (its ISPConfig tab creates'
                    . ' the field) and it will be adopted on the next sweep',
                    $key
                ));
            }

            $existing = \WHMCS\Database\Capsule::table('tblcustomfieldsvalues')
                ->where('fieldid', (int) $fieldId)
                ->where('relid', $serviceId)
                ->exists();

            if ($existing) {
                \WHMCS\Database\Capsule::table('tblcustomfieldsvalues')
                    ->where('fieldid', (int) $fieldId)
                    ->where('relid', $serviceId)
                    ->update(['value' => $value]);
            } else {
                \WHMCS\Database\Capsule::table('tblcustomfieldsvalues')->insert([
                    'fieldid' => (int) $fieldId,
                    'relid' => $serviceId,
                    'value' => $value,
                ]);
            }
        }
    };
}

/**
 * Every service on one server as one read, so the sweep's three derived views
 * (refs, stored bandwidth, domains) cost one trip rather than three.
 *
 * @param null|callable(int): list<array<string, mixed>> $rowProvider
 * @return list<array<string, mixed>>
 */
function ispconfigconnect_serviceRowsOnce(int $serverId, ?callable $rowProvider = null): array
{
    try {
        $rows = $rowProvider === null
            ? ispconfigconnect_serverServiceRows($serverId)
            : $rowProvider($serverId);
    } catch (\Throwable) {
        return [];
    }

    // The provider is a seam, so its answer is treated as untrusted: a
    // non-array, or a list with junk in it, costs the sweep nothing.
    if (!is_array($rows)) {
        return [];
    }

    return array_values(array_filter($rows, 'is_array'));
}

/**
 * The service domain of each row - the key automatic adoption matches on.
 *
 * @param list<array<string, mixed>> $rows
 * @return array<int, string>
 */
function ispconfigconnect_serviceDomains(array $rows): array
{
    $domains = [];
    foreach ($rows as $row) {
        $serviceId = (int) ($row['id'] ?? 0);
        $domain = trim((string) ($row['domain'] ?? ''));
        if ($serviceId > 0 && $domain !== '') {
            $domains[$serviceId] = $domain;
        }
    }

    return $domains;
}

/**
 * Rule 3. Every automatic link gets its own module-log entry carrying the
 * evidence that justified it, so an operator can audit after the fact what the
 * module decided and why - not merely that something changed.
 */
function ispconfigconnect_logAdoption(
    int $serviceId,
    int $clientId,
    string $username,
    string $evidence,
    string $source
): void {
    ispconfigconnect_logModuleCall(
        'Adopt',
        ['serviceid' => $serviceId, 'source' => $source],
        [
            'linked' => true,
            'ispconfig_client_id' => $clientId,
            'ispconfig_client_username' => $username,
            'evidence' => $evidence,
        ]
    );
}

/**
 * Rule 2's message. Every operation that writes to or deletes panel state ends
 * up here when the service is not linked.
 *
 * WHY THE DOMAIN IS NAMED. Automatic adoption keys on the service DOMAIN, not
 * on the WHMCS username - a service migrated from another panel carries that
 * panel's username, which ISPConfig has never heard of. Printing the domain
 * lets the admin confirm the key the automatic path will actually use before
 * relying on it.
 *
 * @param array<string, mixed> $params
 */
function ispconfigconnect_unlinkedRefusal(string $operation, array $params): string
{
    $domain = trim((string) ($params['domain'] ?? ''));

    return sprintf(
        '%s refused: this service is not linked to an ISPConfig account.'
        . ' An operation that changes or deletes panel state never resolves an account by itself,'
        . ' because a wrong match would act on a different customer\'s hosting. Press "Link to'
        . ' ISPConfig Account" on this service (set the Username field to the ISPConfig account name'
        . ' that owns %s first), then retry.%s',
        $operation,
        $domain !== '' ? $domain : 'this service (currently no domain is set)',
        ispconfigconnect_adoptionRouteClause($domain)
    );
}

/**
 * The "second way forward" clause the refusal messages append. Automatic
 * adoption only exists when an Adopt\Resolver is installed; this build ships
 * none, so the honest message on a normal install is that linking by name is
 * the only route. Only when a resolver is actually present does the message
 * promise the nightly sweep - so the docs and the UI never tell an admin to
 * wait for a sweep that will never run.
 */
function ispconfigconnect_adoptionRouteClause(string $domain): string
{
    if (ispconfigconnect_adoptionResolver() !== null) {
        return sprintf(
            ' Or change nothing and let automatic adoption match on the service domain (%s) during the'
            . ' nightly usage sweep, which records in the module log what it matched and why.',
            $domain !== '' ? $domain : 'not set on this service - set it, or the automatic path cannot work either'
        );
    }

    return ' Automatic adoption is not installed on this WHMCS, so linking by name is the only route'
        . ' until it is.';
}

/**
 * Rule 5's message. The stored id no longer resolves on the panel; the module
 * says so and stops, rather than finding "the client that looks right now".
 */
function ispconfigconnect_staleRefusal(string $operation, int $clientId): string
{
    return sprintf(
        '%s refused: the stored ISPConfig client id #%d no longer exists on this panel, so this'
        . ' service\'s link is stale. The module will not re-point a stale link at another account'
        . ' by itself - whichever id it picked could belong to a different customer. Check the panel,'
        . ' then either press "Replace ISPConfig Link" on this service (having set the service\'s'
        . ' Username field to the correct ISPConfig account name) or press "Unlink from ISPConfig"'
        . ' to clear the stored ids. Nothing on the panel has been changed.',
        $operation,
        $clientId
    );
}

/**
 * Does the stored client id still resolve to a live panel client?
 *
 * client_get answers a miss with null rather than an error (queryOneRecord ->
 * remoting_lib getDataRecord), which is the same check Usage\Collector already
 * relies on to catch a hand-deleted client. A THROWN failure is not staleness -
 * it is a panel problem - so it propagates to the caller's normal error path.
 */
function ispconfigconnect_clientExists(SessionManager $session, int $clientId): bool
{
    // A non-positive id never names a real client. client_get(-1) in
    // particular returns the WHOLE client table (remoting_lib getDataRecord),
    // which would otherwise read back as "exists" for a hand-edited or blank
    // custom field and let a delete/terminate proceed on nothing.
    if ($clientId <= 0) {
        return false;
    }

    $record = $session->call('client_get', ['client_id' => $clientId]);

    // client_get answers a miss with null. When it returns a record, confirm
    // it is the one asked for rather than a list or a coincidental row.
    return is_array($record)
        && isset($record['client_id'])
        && (int) $record['client_id'] === $clientId;
}

/**
 * Other non-terminated WHMCS services that store the SAME ispconfig_client_id
 * as this one - the services a whole-client operation (terminate, lock, panel
 * password rotation, template change) on $clientId would also hit.
 *
 * One WHMCS service maps to one ISPConfig client (spec 3.3); sharing a client
 * across services means terminating one deletes the others' hosting, so the
 * write paths use this to refuse before acting. Terminated, Cancelled and
 * Fraud services are excluded: they hold no live claim on the client.
 *
 * ## null means "the check could not be performed" - never "no conflict"
 *
 * The query can fail (a schema difference, a dropped connection, a query
 * listener that throws). That used to come back as [], indistinguishable from
 * a client nobody else uses, so a broken guard was a SILENT open guard. Now it
 * writes a module-log line naming the error and returns null, and each caller
 * decides on the failure explicitly: Terminate - irreversible - refuses;
 * automatic adoption - which nobody asked for - leaves the service unlinked;
 * everything else proceeds, because refusing a suspend or a password change
 * over a database hiccup strands a customer, and the log line says the guard
 * did not run.
 *
 * ## Only services on the same ISPConfig panel count
 *
 * ISPConfig client ids are per-panel auto-increments: client #1 on panel A and
 * client #1 on panel B are different customers. But two WHMCS server records
 * can also reach ONE panel under different strings (an alias host name, a host
 * name on one and the IP on the other, IPv4 and IPv6), so addresses alone never
 * prove two panels differ. A candidate is dropped only on two independent
 * pieces of evidence: its server record shares no address with this service's
 * (ispconfigconnect_otherPanelServerIds), AND both services store an ISPConfig
 * client username and the two differ - the same client on one panel has one
 * username. Everything else still counts (the same record, no server, a server
 * record that no longer exists, a username missing on either side), because
 * missing a real sharer deletes someone's hosting, while a false match only
 * refuses. The one gap: a client RENAMED in ISPConfig between two links, on
 * alias server records, stores two usernames for one client.
 *
 * ## The id each candidate is judged by is the one the module would read
 *
 * A package change (before the fix round) could leave an old product's
 * custom-field row holding a client id the service no longer uses. Matching on
 * any row made that stale value a false "shared" refusal. Each candidate is
 * now judged by the same rule as the property reader in
 * ispconfigconnect_serviceProperties() (ispconfigconnect_effectivePropertyValues).
 *
 * @param string|null $clientUsername the ISPConfig username of $clientId when the
 *        caller knows it (Link, adoption); otherwise this service's stored one is read
 * @return list<array{serviceid: int, userid: int, domain: string, status: string}>|null
 */
function ispconfigconnect_otherServicesLinkedToClient(int $serviceId, int $clientId, ?string $clientUsername = null): ?array
{
    // No positive id names no real client, and with no Capsule class at all
    // there is no WHMCS database and so no other service to find - both are
    // answers, not failures.
    if ($clientId <= 0 || !class_exists('\\WHMCS\\Database\\Capsule')) {
        return [];
    }

    try {
        $query = \WHMCS\Database\Capsule::table('tblcustomfieldsvalues')
            ->join('tblcustomfields', 'tblcustomfields.id', '=', 'tblcustomfieldsvalues.fieldid')
            ->join('tblhosting', 'tblhosting.id', '=', 'tblcustomfieldsvalues.relid')
            ->where('tblcustomfields.type', 'product')
            ->where('tblcustomfields.fieldname', ServiceProperties::KEY_CLIENT_ID)
            ->where('tblcustomfieldsvalues.value', (string) $clientId)
            ->where('tblcustomfieldsvalues.relid', '!=', $serviceId)
            ->whereNotIn('tblhosting.domainstatus', [
                ispconfigconnect_serviceStatus('TERMINATED', 'Terminated'),
                ispconfigconnect_serviceStatus('CANCELLED', 'Cancelled'),
                ispconfigconnect_serviceStatus('FRAUD', 'Fraud'),
            ]);
        $rows = $query->get([
            'tblhosting.id as id',
            'tblhosting.userid as userid',
            'tblhosting.domain as domain',
            'tblhosting.domainstatus as status',
            'tblhosting.server as server',
        ]);

        $candidates = [];
        $servers = [];
        foreach (is_iterable($rows) ? $rows : [] as $row) {
            $columns = (array) $row;
            $otherId = (int) ($columns['id'] ?? 0);
            if ($otherId > 0 && !isset($candidates[$otherId])) {
                $candidates[$otherId] = [
                    'serviceid' => $otherId,
                    'userid' => (int) ($columns['userid'] ?? 0),
                    'domain' => (string) ($columns['domain'] ?? ''),
                    'status' => (string) ($columns['status'] ?? ''),
                ];
                $servers[$otherId] = (int) ($columns['server'] ?? 0);
            }
        }
        if ($candidates === []) {
            return [];
        }

        $effective = ispconfigconnect_effectivePropertyValues(ServiceProperties::KEY_CLIENT_ID, array_keys($candidates));
        $sharers = [];
        foreach ($candidates as $otherId => $candidate) {
            if (($effective[$otherId] ?? null) === (string) $clientId) {
                $sharers[$otherId] = $candidate;
            }
        }

        // Candidates on a server record with no address in common: dropped
        // only when the stored usernames prove a different client too.
        $otherPanels = $sharers === [] ? [] : ispconfigconnect_otherPanelServerIds($serviceId);
        $onOtherPanels = array_values(array_filter(
            array_keys($sharers),
            static fn (int $otherId): bool => in_array($servers[$otherId] ?? 0, $otherPanels, true)
        ));
        if ($onOtherPanels !== []) {
            $names = ispconfigconnect_effectivePropertyValues(
                ServiceProperties::KEY_CLIENT_USERNAME,
                $clientUsername === null ? array_merge([$serviceId], $onOtherPanels) : $onOtherPanels
            );
            $own = strtolower(trim($clientUsername ?? ($names[$serviceId] ?? '')));
            foreach ($onOtherPanels as $otherId) {
                $theirs = strtolower(trim($names[$otherId] ?? ''));
                if ($own !== '' && $theirs !== '' && $own !== $theirs) {
                    unset($sharers[$otherId]);
                }
            }
        }

        return array_values($sharers);
    } catch (\Throwable $failure) {
        // Not [] - see the docblock. The line is what lets an operator tell
        // "the guard ran and found nobody" from "the guard never ran".
        ispconfigconnect_logModuleCall(
            'SharedClientGuard',
            ['serviceid' => $serviceId, 'ispconfig_client_id' => $clientId],
            [
                'checked' => false,
                'error' => ispconfigconnect_failureText($failure),
                'effect' => 'Could not check whether another WHMCS service shares this ISPConfig client.'
                    . ' Terminate and automatic adoption refuse until the check works; other operations'
                    . ' proceed without it.',
            ]
        );

        return null;
    }
}

/**
 * WHMCS server ids whose Hostname/IP Address has nothing in common with the
 * record $serviceId's server points at. That alone does NOT make them another
 * panel - two records can reach one panel under different strings - so
 * ispconfigconnect_otherServicesLinkedToClient() also needs the stored client
 * usernames to differ before it drops a service on one of these records.
 *
 * Two server records share an address when any one matches (host name against
 * host name or IP, IP against either); a record with too little to compare
 * (at least one address comparable on both sides is required) is kept. Anything
 * unreadable - no server on the service, a missing record, a query failure -
 * returns [], so nothing is dropped.
 *
 * @return list<int>
 */
function ispconfigconnect_otherPanelServerIds(int $serviceId): array
{
    if ($serviceId <= 0) {
        return [];
    }

    try {
        $serverId = \WHMCS\Database\Capsule::table('tblhosting')->where('id', $serviceId)->value('server');
        if (!is_numeric($serverId) || (int) $serverId <= 0) {
            return [];
        }
        $serverId = (int) $serverId;

        $servers = [];
        $rows = \WHMCS\Database\Capsule::table('tblservers')->get(['id', 'hostname', 'ipaddress']);
        foreach (is_iterable($rows) ? $rows : [] as $row) {
            $columns = (array) $row;
            $id = (int) ($columns['id'] ?? 0);
            if ($id > 0) {
                $servers[$id] = [
                    'host' => ispconfigconnect_panelAddress((string) ($columns['hostname'] ?? '')),
                    'ip' => ispconfigconnect_panelAddress((string) ($columns['ipaddress'] ?? '')),
                ];
            }
        }
    } catch (\Throwable) {
        return [];
    }

    if (!isset($servers[$serverId])) {
        return [];
    }
    $own = $servers[$serverId];
    $ownAddresses = array_values(array_filter([$own['host'], $own['ip']], static fn (string $a): bool => $a !== ''));

    $different = [];
    foreach ($servers as $id => $server) {
        if ($id === $serverId) {
            continue;
        }
        $addresses = array_values(array_filter([$server['host'], $server['ip']], static fn (string $a): bool => $a !== ''));
        if (array_intersect($ownAddresses, $addresses) !== []) {
            continue;
        }
        $comparable = ($own['host'] !== '' && $server['host'] !== '') || ($own['ip'] !== '' && $server['ip'] !== '');
        if ($comparable) {
            $different[] = $id;
        }
    }

    return $different;
}

/** A server Hostname/IP Address in the one form two records are compared by. */
function ispconfigconnect_panelAddress(string $address): string
{
    return strtolower(rtrim(trim(trim($address), '[]'), '.'));
}

/**
 * The value of property $key each service would be read as, by the same rule
 * as the property reader in ispconfigconnect_serviceProperties(): the newest
 * row of the service's current product when it is not blank, otherwise the
 * newest row of any product when that is not blank, otherwise none.
 *
 * @param list<int> $serviceIds
 * @return array<int, string> service id => value; services with none are absent
 */
function ispconfigconnect_effectivePropertyValues(string $key, array $serviceIds): array
{
    if ($serviceIds === []) {
        return [];
    }

    $rows = \WHMCS\Database\Capsule::table('tblcustomfieldsvalues')
        ->join('tblcustomfields', 'tblcustomfields.id', '=', 'tblcustomfieldsvalues.fieldid')
        ->join('tblhosting', 'tblhosting.id', '=', 'tblcustomfieldsvalues.relid')
        ->where('tblcustomfields.type', 'product')
        ->where('tblcustomfields.fieldname', $key)
        ->whereIn('tblcustomfieldsvalues.relid', $serviceIds)
        ->orderByDesc('tblcustomfieldsvalues.id')
        ->get([
            'tblcustomfieldsvalues.relid as serviceid',
            'tblcustomfieldsvalues.value as value',
            'tblcustomfields.relid as productid',
            'tblhosting.packageid as packageid',
        ]);

    // Rows arrive newest first, so the first row seen per service and pass is
    // the one the reader's ->value() would return.
    $current = [];
    $any = [];
    foreach (is_iterable($rows) ? $rows : [] as $row) {
        $columns = (array) $row;
        $id = (int) ($columns['serviceid'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        $value = trim((string) ($columns['value'] ?? ''));
        $any[$id] ??= $value;
        $productId = (int) ($columns['productid'] ?? 0);
        if ($productId > 0 && $productId === (int) ($columns['packageid'] ?? 0)) {
            $current[$id] ??= $value;
        }
    }

    $effective = [];
    foreach ($serviceIds as $id) {
        $value = ($current[$id] ?? '') !== '' ? $current[$id] : ($any[$id] ?? '');
        if ($value !== '') {
            $effective[$id] = $value;
        }
    }

    return $effective;
}

/**
 * The shared-client refusal an operation returns when another service would be
 * caught by a whole-client action, or null when the client is not shared.
 *
 * @param list<array{serviceid: int, userid: int, domain: string, status: string}> $others
 */
function ispconfigconnect_sharedClientRefusal(string $operation, int $clientId, array $others): ?string
{
    if ($others === []) {
        return null;
    }

    return sprintf(
        '%s refused: this service\'s ISPConfig client #%d is also linked to %s. This module maps one'
        . ' WHMCS service to one ISPConfig client, and a whole-client action here would hit the other'
        . ' service(s) too - terminating would delete their hosting. Give each service its own'
        . ' ISPConfig client, or "Unlink from ISPConfig" the ones that should not point here, then'
        . ' retry. Nothing has been changed.',
        $operation,
        $clientId,
        ispconfigconnect_describeServices($others)
    );
}

/**
 * Unsuspend's own shared-client refusal: the one case the unlock refuses is
 * another service on the same client that WHMCS still has Suspended, because
 * the whole-client unlock would restore that service's hosting too.
 *
 * @param non-empty-list<array{serviceid: int, userid: int, domain: string, status: string}> $suspendedOthers
 */
function ispconfigconnect_sharedUnsuspendRefusal(int $clientId, array $suspendedOthers): string
{
    return sprintf(
        'Unsuspend refused: this service\'s ISPConfig client #%d is also linked to %s, which WHMCS has'
        . ' suspended. This product suspends by locking the whole ISPConfig client, so unlocking it now'
        . ' would bring that suspended service\'s websites, mail and databases back online as well.'
        . ' Unsuspend or terminate the other service first - or "Unlink from ISPConfig" whichever'
        . ' service should not point at this client - then retry. Nothing has been changed.',
        $clientId,
        ispconfigconnect_describeServices($suspendedOthers)
    );
}

/**
 * "service #99 (other.example, WHMCS client #88, Active), ..." - the sharers
 * named well enough that an admin can find each one without a database query.
 *
 * @param list<array{serviceid: int, userid: int, domain: string, status: string}> $services
 */
function ispconfigconnect_describeServices(array $services): string
{
    $descriptions = [];
    foreach ($services as $service) {
        $descriptions[] = sprintf(
            'service #%d (%s%s, %s)',
            $service['serviceid'],
            $service['domain'] !== '' ? $service['domain'] : 'no domain',
            $service['userid'] > 0 ? ', WHMCS client #' . $service['userid'] : '',
            $service['status'] !== '' ? $service['status'] : 'unknown status'
        );
    }

    return implode(', ', $descriptions);
}

/**
 * Website-only Suspend/Unsuspend with no stored website id.
 *
 * Website-only mode acts on exactly one ISPConfig website, and without its id
 * there is none to act on. The refusal names both ways out: store the id (the
 * Link/Replace buttons now resolve it from the service domain), or change the
 * product to the whole-client lock if that is really what is wanted.
 *
 * @param string $operation 'Suspend' / 'Unsuspend' - the label WHMCS shows
 * @param string $verb 'suspend' / 'unsuspend' - the same, mid-sentence
 */
function ispconfigconnect_noWebsiteIdRefusal(string $operation, string $verb): string
{
    return sprintf(
        '%s refused: this product suspends the website only, but no ISPConfig website id is stored for'
        . ' this service, so there is nothing safe to %s. Press "Replace ISPConfig Link" (it stores the'
        . ' website id when the service domain matches one of the client\'s websites), or set the product'
        . ' to "Suspend Entire Client". Nothing has been changed.',
        $operation,
        $verb
    );
}

/**
 * Module-log wrapper for the entry points that are not a single API call
 * (Api\RestClient logs those itself). Never lets logging break the operation
 * it is reporting on.
 *
 * @param array<string, mixed> $request
 */
function ispconfigconnect_logModuleCall(string $action, array $request, mixed $response): void
{
    if (!function_exists('logModuleCall')) {
        return;
    }

    try {
        \logModuleCall(Factory::MODULE_NAME, $action, $request, $response, '', []);
    } catch (\Throwable) {
        // Logging is never worth failing an operation for.
    }
}
