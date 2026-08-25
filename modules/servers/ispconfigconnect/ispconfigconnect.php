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
use WHMCS\Module\Server\Ispconfigconnect\Api\Exception\PermissionDeniedException;
use WHMCS\Module\Server\Ispconfigconnect\Api\Exception\TransportException;
use WHMCS\Module\Server\Ispconfigconnect\Api\Permissions;
use WHMCS\Module\Server\Ispconfigconnect\Api\SessionManager;
use WHMCS\Module\Server\Ispconfigconnect\Provision\CreateResult;
use WHMCS\Module\Server\Ispconfigconnect\Provision\Mapper;
use WHMCS\Module\Server\Ispconfigconnect\Provision\Recipe;
use WHMCS\Module\Server\Ispconfigconnect\Support\Factory;
use WHMCS\Module\Server\Ispconfigconnect\Support\Params;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * @return array<string, mixed>
 */
function ispconfigconnect_MetaData(): array
{
    // No ServiceSingleSignOnLabel yet: SSO is Phase 2 (spec 3.2) and
    // advertising it now would render a dead login button.
    return [
        'DisplayName' => 'ISPConfig Connect',
        'APIVersion' => '1.1',
        'RequiresServer' => true,
        'DefaultSSLPort' => '8080',
        'DefaultNonSSLPort' => '8080',
    ];
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
 * unreachable: the live job-queue probe degrades to 'n/a', never throws.
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

        $jobqueueDepth = 'n/a';
        try {
            $session = Factory::sessionManager($params);
            try {
                $jobqueueDepth = (string) (new Recipe($session))->jobqueueCount();
            } finally {
                $session->close();
            }
        } catch (\Throwable) {
            // Exception-safe by spec: the panel row shows 'n/a'.
        }

        $panelUrl = ispconfigconnect_panelUrl($params);

        return [
            'ISPConfig Client ID' => $clientId === null ? 'not provisioned' : (string) $clientId,
            'ISPConfig Username' => htmlspecialchars($username, ENT_QUOTES),
            'ISPConfig Domain ID' => $domainId === null ? 'n/a' : (string) $domainId,
            'ISPConfig Job Queue' => $jobqueueDepth,
            'ISPConfig Panel' => '<a href="' . htmlspecialchars($panelUrl, ENT_QUOTES) . '" target="_blank">'
                . htmlspecialchars($panelUrl, ENT_QUOTES) . '</a>',
        ];
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
