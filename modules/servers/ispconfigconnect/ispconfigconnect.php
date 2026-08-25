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
use WHMCS\Module\Server\Ispconfigconnect\Provision\CreateResult;
use WHMCS\Module\Server\Ispconfigconnect\Provision\Mapper;
use WHMCS\Module\Server\Ispconfigconnect\Provision\Recipe;
use WHMCS\Module\Server\Ispconfigconnect\Server\RemoteMetaData;
use WHMCS\Module\Server\Ispconfigconnect\Sso\CompanionClient;
use WHMCS\Module\Server\Ispconfigconnect\Sso\Exception\CompanionGrantMissingException;
use WHMCS\Module\Server\Ispconfigconnect\Sso\Exception\CompanionNotInstalledException;
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
 * The single sign-on label is CONDITIONAL. ISPConfig core has no one-time-login
 * API at all, so SSO exists only where the optional companion
 * (companion/ispconfig-sso) is deployed - advertising the label unconditionally
 * would render a button that can only fail. MetaData() gets no $params and must
 * not make API calls, so the answer comes from the companion probe that
 * GetRemoteMetaData already cached in tblservers_remote.
 *
 * AdminSingleSignOnLabel is deliberately never advertised: that button is
 * SERVER-level ("Login to Panel as Admin"), and the companion refuses to mint
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
                . ' mail, FTP and databases keep working.',
        ],
        'Panel URL Override' => [
            'Type' => 'text',
            'Size' => '40',
            'Description' => 'Panel URL shown to clients (empty = https://<hostname>:<port>/)',
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
 * @param array<string, mixed> $params
 * @return array{success: bool, error: string}
 */
function ispconfigconnect_TestConnection(array $params): array
{
    try {
        $session = Factory::sessionManager($params);
        try {
            $missingGrants = ispconfigconnect_missingGrants($session);
        } finally {
            $session->close();
        }

        if ($missingGrants !== []) {
            return [
                'success' => false,
                'error' => 'Connected and logged in, but the remote user is missing remote_functions grants: '
                    . implode(', ', $missingGrants)
                    . '. Enable them on the remote user under System > Remote Users in ISPConfig'
                    . ' (docs/REMOTE-USER.md documents the full grant list).',
            ];
        }

        return ['success' => true, 'error' => ''];
    } catch (\Throwable $failure) {
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
            return 'Suspend failed: no stored ispconfig_client_id service property'
                . ' - this service was not provisioned by ISPConfig Connect.';
        }

        $product = Params::productConfig($params);
        $session = Factory::sessionManager($params);
        try {
            (new Recipe($session))->suspend(
                $clientId,
                $properties->domainId(),
                $product->suspendEntireClient
            );
        } finally {
            $session->close();
        }

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
            return 'Unsuspend failed: no stored ispconfig_client_id service property'
                . ' - this service was not provisioned by ISPConfig Connect.';
        }

        $product = Params::productConfig($params);
        $session = Factory::sessionManager($params);
        try {
            (new Recipe($session))->unsuspend(
                $clientId,
                $properties->domainId(),
                $product->suspendEntireClient
            );
        } finally {
            $session->close();
        }

        return 'success';
    } catch (\Throwable $failure) {
        return ispconfigconnect_errorString('Unsuspend', $failure);
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
            return 'Terminate failed: no stored ispconfig_client_id service property'
                . ' - this service was not provisioned by ISPConfig Connect.';
        }

        $session = Factory::sessionManager($params);
        try {
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
            return 'Change Password failed: no stored ispconfig_client_id service property'
                . ' - this service was not provisioned by ISPConfig Connect.';
        }

        // Empty-credential guard, before any API call: an empty rotation
        // would no-op the FTP/DB updates (updateQueryPrepare drops empty
        // PASSWORD-type fields) while leaving the old credentials live.
        $newPassword = (string) ($params['password'] ?? '');
        if ($newPassword === '') {
            return 'Change Password failed: WHMCS passed an empty password.';
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
            return 'Change Package failed: no stored ispconfig_client_id service property'
                . ' - this service was not provisioned by ISPConfig Connect.';
        }

        $product = Params::productConfig($params);
        $service = Params::service($params);

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

            (new Recipe($session))->changePackage($clientId, $product, $addonTemplateIds);
        } finally {
            $session->close();
        }

        // The old product's custom-field rows do not follow the service:
        // re-save migrates every stored id onto the NEW product's fields so
        // later operations read them without the fallback.
        $properties->resaveAll();

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
 * @param array<string, mixed> $params
 * @return array<string, string>
 */
function ispconfigconnect_AdminServicesTabFields(array $params): array
{
    try {
        $properties = ispconfigconnect_serviceProperties($params);
        $clientId = $properties->clientId();
        $username = $properties->clientUsername() ?? (string) ($params['username'] ?? '');
        $domainId = $properties->domainId();

        $live = ispconfigconnect_adminLiveFields($params, $clientId);
        $panelUrl = ispconfigconnect_panelUrl($params);

        $fields = [
            'ISPConfig Client ID' => $clientId === null ? 'not provisioned' : (string) $clientId,
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
        // spend the client's single live token on a page render (the companion
        // keeps exactly one, and a new one invalidates its predecessor). The
        // working button is WHMCS's own SSO control, wired to
        // ispconfigconnect_ServiceSingleSignOn().
        $fields['ISPConfig Single Sign-On'] = $live['sso'];

        $fields['ISPConfig Panel'] = '<a href="' . htmlspecialchars($panelUrl, ENT_QUOTES) . '" target="_blank">'
            . htmlspecialchars($panelUrl, ENT_QUOTES) . '</a>';

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
    return ['Verify Permissions' => 'VerifyPermissions'];
}

/**
 * Admin custom button: run the read probes and report which remote_functions
 * grants the remote user is missing (spec 2.8).
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
        } finally {
            $session->close();
        }

        if ($missingGrants !== []) {
            return 'The remote user is missing remote_functions grants: '
                . implode(', ', $missingGrants)
                . '. Enable them under System > Remote Users in ISPConfig;'
                . ' docs/REMOTE-USER.md documents the full grant list.';
        }

        return 'success';
    } catch (\Throwable $failure) {
        return ispconfigconnect_errorString('Verify Permissions', $failure);
    }
}

/**
 * Client-area overview: panel link + ISPConfig username. Read-only - no API
 * calls, and NEVER a password or any other credential in the template
 * variables (design §3.6.3).
 *
 * @param array<string, mixed> $params
 * @return array<string, mixed>
 */
function ispconfigconnect_ClientArea(array $params): array
{
    $username = (string) ($params['username'] ?? '');
    try {
        $storedUsername = ispconfigconnect_serviceProperties($params)->clientUsername();
        if ($storedUsername !== null) {
            $username = $storedUsername;
        }
    } catch (\Throwable) {
        // No service model in scope: fall back to the WHMCS service username.
    }

    return [
        'tabOverviewReplacementTemplate' => 'templates/overview.tpl',
        'templateVariables' => [
            'panelUrl' => ispconfigconnect_panelUrl($params),
            'ispconfigUsername' => $username,
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
 * @param null|callable(int): list<array<string, mixed>> $rowProvider test seam
 *        for the tblhosting lookup; WHMCS passes $params alone
 * @param null|callable(int, array<string, int>): void $writer test seam for the
 *        tblhosting write
 */
function ispconfigconnect_UsageUpdate(array $params, ?callable $rowProvider = null, ?callable $writer = null): void
{
    $serverId = (int) ($params['serverid'] ?? 0);
    $services = ispconfigconnect_serviceRefs($serverId, $rowProvider);
    $write = $writer ?? ispconfigconnect_usageWriter();

    $updated = 0;
    /** @var array<int, string> $skipped WHMCS service id => reason */
    $skipped = [];
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

                $report = (new Updater($session))->collect($services);
            } finally {
                $session->close();
            }

            $skipped = $report->failures;
            foreach ($report->usage as $usage) {
                try {
                    $write($usage->serviceId, $usage->toHostingColumns());
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
                $properties = get_object_vars($tenant);
                $serviceId = isset($properties['id']) && is_numeric($properties['id'])
                    ? (int) $properties['id']
                    : 0;
                $username = isset($properties['username']) && is_scalar($properties['username'])
                    ? trim((string) $properties['username'])
                    : '';
            } elseif (is_int($tenant) || (is_string($tenant) && ctype_digit($tenant))) {
                $serviceId = (int) $tenant;
            } elseif (is_string($tenant)) {
                $username = trim($tenant);
            }

            if ($serviceId <= 0 && $username === '') {
                return null;
            }

            $services = ispconfigconnect_serviceRefs(
                (int) ($this->params['serverid'] ?? 0),
                $this->rowProvider
            );
            foreach ($services as $service) {
                if ($serviceId > 0 && $service->serviceId === $serviceId) {
                    return $service;
                }
            }
            foreach ($services as $service) {
                if ($username !== '' && $service->username === $username) {
                    return $service;
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

            // The SSO companion probe is cached HERE because MetaData(), which
            // decides whether the SSO button is offered at all, gets no server
            // parameters and must not make API calls. One get_function_list
            // call, already granted.
            try {
                $meta['sso_available'] = ispconfigconnect_companionClient($params, $session)->isInstalled();
            } catch (\Throwable) {
                // Unknown beats a false negative: leave the key out so the
                // previous answer is not contradicted by a transient failure.
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
 * @param array<string, mixed> $params
 * @return array{success: bool, redirectTo?: string, errorMsg?: string}
 */
function ispconfigconnect_ServiceSingleSignOn(array $params): array
{
    return ispconfigconnect_singleSignOn($params, 'ServiceSingleSignOn');
}

/**
 * Single sign-on triggered from the admin area.
 *
 * WHMCS documents this as the SERVER-level counterpart of
 * ServiceSingleSignOn. ISPConfig has no administrator SSO to offer - the
 * remote user is not a panel user, and the companion refuses to mint tokens
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
    return ispconfigconnect_singleSignOn($params, 'AdminSingleSignOn');
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
    $properties = Params::serviceProperties($params);
    $serviceId = (int) ($params['serviceid'] ?? 0);
    if ($serviceId > 0 && class_exists('\\WHMCS\\Database\\Capsule')) {
        $properties->withFallbackReader(static function (string $key) use ($serviceId): ?string {
            try {
                $value = \WHMCS\Database\Capsule::table('tblcustomfieldsvalues')
                    ->join('tblcustomfields', 'tblcustomfields.id', '=', 'tblcustomfieldsvalues.fieldid')
                    ->where('tblcustomfields.type', 'product')
                    ->where('tblcustomfields.fieldname', $key)
                    ->where('tblcustomfieldsvalues.relid', $serviceId)
                    ->value('tblcustomfieldsvalues.value');

                return $value === null ? null : (string) $value;
            } catch (\Throwable) {
                return null;
            }
        });
    }

    return $properties;
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
 * @return list<string> e.g. "'server_get' (needed by server_get_all)"
 */
function ispconfigconnect_missingGrants(SessionManager $session): array
{
    $missing = [];
    foreach (ispconfigconnect_readProbes() as $method => $probe) {
        try {
            $session->call($method, $probe['params']);
        } catch (PermissionDeniedException) {
            $missing[] = sprintf("'%s' (needed by %s)", $probe['grant'], $method);
        }
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
 * Panel URL shown to humans: the Panel URL Override product option when set,
 * else derived from the server config (spec 2.8).
 *
 * @param array<string, mixed> $params
 */
function ispconfigconnect_panelUrl(array $params): string
{
    return Params::productConfig($params)->panelUrlOverride
        ?? Params::serverConfig($params)->panelUrl();
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
 * @param array<string, mixed> $params
 * @return array{success: bool, redirectTo?: string, errorMsg?: string}
 */
function ispconfigconnect_singleSignOn(array $params, string $context): array
{
    $serviceId = (int) ($params['serviceid'] ?? 0);
    $serviceInScope = $serviceId > 0 || isset($params['model']);

    $clientId = null;
    try {
        $clientId = ispconfigconnect_serviceProperties($params)->clientId();
    } catch (\Throwable) {
        // No service model in scope (a server-level admin call).
    }

    if ($clientId === null) {
        $result = ['success' => false, 'errorMsg' => $serviceInScope
            ? 'Single sign-on is not available for this service: it has no stored'
                . ' ispconfig_client_id service property, so it was not provisioned by'
                . ' ISPConfig Connect.'
            : sprintf(
                'ISPConfig has no administrator single sign-on. The remote API user is not a'
                . ' panel user, and the SSO companion refuses to mint tokens for administrator'
                . ' accounts because such a token would be a full panel takeover. Sign in at %s'
                . ' instead, or use the per-service login button on a client\'s service.',
                ispconfigconnect_panelUrl($params)
            )];

        ispconfigconnect_logModuleCall($context, ['serviceid' => $serviceId], $result);

        return $result;
    }

    $result = ['success' => false];
    try {
        $session = Factory::sessionManager($params);
        try {
            // IP binding is left OFF: the token would then only be redeemable
            // from the address WHMCS saw, and any CDN, proxy or IPv4/IPv6
            // asymmetry between WHMCS and the panel would break every login
            // (companion/ispconfig-sso/README.md).
            $result = [
                'success' => true,
                'redirectTo' => ispconfigconnect_companionClient($params, $session)->loginUrl($clientId),
            ];
        } finally {
            $session->close();
        }
    } catch (CompanionNotInstalledException) {
        $result['errorMsg'] = 'Single sign-on needs the optional ISPConfig companion, which this panel'
            . ' does not have: ISPConfig core provides no one-time-login API. Install'
            . ' companion/ispconfig-sso (see companion/ispconfig-sso/README.md) on the panel and'
            . ' grant the remote user its connect_sso_token_create function.';
    } catch (CompanionGrantMissingException $denied) {
        $result['errorMsg'] = $denied->getMessage()
            . ' Installation and grant instructions: companion/ispconfig-sso/README.md.';
    } catch (\Throwable $failure) {
        $result['errorMsg'] = ispconfigconnect_connectionAdvice($failure);
    }

    // The redirect URL carries the one-time token in its query string, so only
    // the OUTCOME is logged - never the URL.
    ispconfigconnect_logModuleCall(
        $context,
        ['serviceid' => $serviceId, 'ispconfig_client_id' => $clientId],
        ['success' => $result['success']]
            + (isset($result['errorMsg']) ? ['errorMsg' => $result['errorMsg']] : [])
    );

    return $result;
}

/**
 * @param array<string, mixed> $params
 */
function ispconfigconnect_companionClient(array $params, SessionManager $session): CompanionClient
{
    return new CompanionClient(
        $session,
        Params::serverConfig($params),
        Params::productConfig($params)->panelUrlOverride
    );
}

/**
 * Has the SSO companion been seen on any ISPConfig server WHMCS knows about?
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
            if (!is_array($row)) {
                continue;
            }
            $flag = $row['sso_available'] ?? null;
            if ($flag === true || $flag === 1 || $flag === '1') {
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
 * properties the usage code needs. Two queries, regardless of service count.
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
            ->get(['id', 'username']);

        $rows = [];
        foreach (is_iterable($services) ? $services : [] as $service) {
            $columns = (array) $service;
            $id = (int) ($columns['id'] ?? 0);
            if ($id > 0) {
                $rows[$id] = ['id' => $id, 'username' => (string) ($columns['username'] ?? '')];
            }
        }
        if ($rows === []) {
            return [];
        }

        // Service properties are ordinary product custom fields, scoped to the
        // service's CURRENT product - so they are looked up by field NAME and
        // service id, exactly like the entry file's fallback reader.
        $properties = \WHMCS\Database\Capsule::table('tblcustomfieldsvalues')
            ->join('tblcustomfields', 'tblcustomfields.id', '=', 'tblcustomfieldsvalues.fieldid')
            ->where('tblcustomfields.type', 'product')
            ->whereIn('tblcustomfields.fieldname', [
                ServiceProperties::KEY_CLIENT_ID,
                ServiceProperties::KEY_CLIENT_USERNAME,
            ])
            ->whereIn('tblcustomfieldsvalues.relid', array_keys($rows))
            ->get([
                'tblcustomfieldsvalues.relid as relid',
                'tblcustomfields.fieldname as fieldname',
                'tblcustomfieldsvalues.value as value',
            ]);

        foreach (is_iterable($properties) ? $properties : [] as $property) {
            $columns = (array) $property;
            $relid = (int) ($columns['relid'] ?? 0);
            $field = (string) ($columns['fieldname'] ?? '');
            if (isset($rows[$relid]) && $field !== '') {
                $rows[$relid][$field] = (string) ($columns['value'] ?? '');
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
            ->update($columns + ['lastupdate' => \WHMCS\Database\Capsule::raw('now()')]);
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
 * The live panel figures on the admin service tab, all from ONE session and
 * all degrading to a placeholder instead of throwing.
 *
 * @param array<string, mixed> $params
 * @return array{jobqueue: string, disk: string, bandwidth: string, sso: string}
 */
function ispconfigconnect_adminLiveFields(array $params, ?int $clientId): array
{
    $fields = ['jobqueue' => 'n/a', 'disk' => 'n/a', 'bandwidth' => 'n/a', 'sso' => 'unknown'];

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

            try {
                $fields['sso'] = ispconfigconnect_companionClient($params, $session)->isInstalled()
                    ? 'available'
                    : 'not installed - deploy the optional companion/ispconfig-sso on the panel'
                        . ' to enable the "Log in to ISPConfig" button';
            } catch (\Throwable) {
                // Stays 'unknown'.
            }
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
