<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Ispconfigconnect\Server;

use WHMCS\Module\Server\Ispconfigconnect\Api\Exception\ApiDisabledException;
use WHMCS\Module\Server\Ispconfigconnect\Api\Exception\LockoutException;
use WHMCS\Module\Server\Ispconfigconnect\Api\Exception\LoginFailedException;
use WHMCS\Module\Server\Ispconfigconnect\Api\Exception\MaintenanceModeException;
use WHMCS\Module\Server\Ispconfigconnect\Api\Exception\MalformedResponseException;
use WHMCS\Module\Server\Ispconfigconnect\Api\Exception\SessionExpiredException;
use WHMCS\Module\Server\Ispconfigconnect\Api\Exception\TransportException;
use WHMCS\Module\Server\Ispconfigconnect\Api\SessionManager;

/**
 * The panel-side facts WHMCS shows on Configuration > Servers.
 *
 * WHMCS calls `<module>_GetRemoteMetaData(array $params)` while rendering that
 * page and stores whatever flat array comes back VERBATIM as JSON in
 * `tblservers_remote.meta_data` (live-verified on WHMCS 9.0.6 - a
 * success/metaData wrapper is NOT accepted). Core renders one key itself:
 * `service_count` becomes "Services: N" in the Remote Usage Stats cell. The
 * rest is read back by this module's own admin hook.
 *
 * ## Who consumes each key
 *
 * Nothing here is decorative - every key has exactly one reader, and renaming
 * one silently removes a line from the servers page (the round trip is locked
 * down in tests/Unit/Server/RemoteMetaDataTest):
 *
 *   ispconfig_version   -> panel line "ISPConfig Version"
 *   pending_jobs        -> panel line "Pending Jobs"
 *   client_count        -> panel line "Clients"
 *   server_count        -> panel line "ISPConfig Nodes"
 *   checked_at          -> panel line "Last Updated" (only when WHMCS's own
 *                          tblservers_remote.updated_at is empty)
 *   ispconfig_error     -> panel line "Probe Errors"
 *   service_count       -> WHMCS CORE renders it as "Services: N"
 *   ispconfig_status    -> machine-readable ok/degraded/unavailable summary for
 *                          anything that reads the cached row
 *   sso_available       -> added by the entry file, not here; MetaData() reads
 *                          it to decide whether to offer the SSO button
 *
 * `website_count` USED TO BE HERE and was removed deliberately - see "Why
 * there is no website count" below. Nothing else reads it: the operator-facing
 * block renders a fixed field list (hooks/ispconfigconnect.php
 * ISPCONFIGCONNECT_METAPANEL_FIELDS, mirrored by Server\MetaDataPanel::FIELDS)
 * and neither list ever contained it.
 *
 * What is NOT here, and why: the panel's own "Accounts: N" comes from
 * `tblservers_remote.num_accounts`, a COLUMN this function cannot write. No
 * returned key populates it (num_accounts, numAccounts, ownedAccounts and
 * accounts were all tried live and ignored) - it is filled by a Server Sync
 * run, i.e. by ListAccounts. `client_count` is this module's own, always-fresh
 * answer to the same question. See docs/IMPLEMENTATION-NOTES.md, "WHMCS servers
 * page".
 *
 * ## Why these probes and not others
 *
 * ISPConfig 3.3.1p1 exposes exactly one counting function over the remote API
 * - `monitor_jobqueue_count` (remote.d/monitor.inc.php is the whole monitor
 * surface; interface/web/monitor/lib/remote.conf.php grants that one name).
 * Every other total has to be derived from reading records, and
 * remoting_lib.inc.php getDataRecord() answers an array/-1 primary_id with
 * `SELECT *` - the whole row, secret columns included.
 *
 * Besides that one count, every probe here reads IDENTIFIERS or a single
 * version string - never a record - which is what makes them safe to repeat on
 * a page render:
 *
 *   - `server_get_app_version(0)` answers with the interface's own
 *     ISPC_APP_VERSION constant (remote.d/server.inc.php);
 *   - `client_get_all` is `SELECT client_id FROM client` (remote.d/client.inc.php);
 *   - `server_get_all` is `SELECT server_id, server_name FROM server`
 *     (remote.d/server.inc.php).
 *
 * None of them goes through getDataRecord(), so none can widen into a record
 * read the way a `*_get` function does.
 *
 * RULE for any probe added later: this function may only call remote functions
 * that answer with a count or with identifiers. A bulk record read is not made
 * acceptable by `Api\RestClient` masking or dropping its secret columns -
 * masking protects tblmodulelog, not the wire, WHMCS's memory or anything in
 * between. RemoteMetaDataTest enforces the allowed call list.
 *
 * ## Why there is no website count
 *
 * This class used to answer `website_count`/`service_count` with
 * `sites_web_domain_get(-1)`, i.e. `SELECT * FROM web_domain`: every vhost row
 * on the panel, each carrying `web_domain.ssl_key` - the site's TLS PRIVATE KEY
 * - and `ssl_request`, its CSR (install/sql/ispconfig3.sql:2036/:2033). Dragging
 * every site's private key across the network on every Configuration > Servers
 * render, to arrive at an integer, is indefensible on a panel with hundreds of
 * sites, and no masking of the log changes that.
 *
 * ISPConfig 3.3.1p1 offers no cheap, safe substitute:
 *
 *   - there is no `sites_web_domain_count` and no site total in the monitor
 *     module. `monitor_jobqueue_count` is the only remote function in remote.d
 *     that RETURNS a count at all (openvz.inc.php counts rows too, but only
 *     internally, to refuse a duplicate pending action);
 *   - `client_get_sites_by_user` IS safe (its own SQL selects exactly `domain,
 *     domain_id, document_root, active ... AND type = 'vhost'`, no key columns
 *     - remote.d/sites.inc.php), but it is scoped to ONE client's sys_user and
 *     group list, neither of which is on the client row. Getting a panel-wide
 *     total that way costs a call per client - `client_get_groupid`, which also
 *     needs the `client_get_id` grant this module does not otherwise require -
 *     plus the site call itself. On a page render that is N+1 remote calls to
 *     replace one integer, and every failure feeds ISPConfig's login lockout;
 *   - an array filter (`sites_web_domain_get(['type' => 'vhost'])`) still runs
 *     `SELECT *` (remoting_lib.inc.php getDataRecord()), so it fixes nothing.
 *
 * So the number is gone rather than approximated. `service_count` - the one key
 * WHMCS core itself renders - now reports the ISPConfig CLIENT count, which is
 * the truthful answer to what core is asking: this module provisions exactly
 * one ISPConfig client per WHMCS service (Provision\Recipe::create), so the
 * client population IS the service population, and it costs no extra call.
 * (A mail-domain counter is absent for the same reason plus one more:
 * `mail_domain_get([])` would return `mail_domain.dkim_private` and
 * `relay_pass`, and nothing on the page renders the number.)
 *
 * ## Failure policy
 *
 * The servers page must render even when the panel is down, so every probe is
 * isolated and a diagnostic key replaces the numbers instead of an exception
 * escaping. Failures are NOT all equal, though: an authentication or transport
 * failure would repeat identically for every remaining probe, and each retry
 * costs another failed login against ISPConfig's 10-failures-per-5-minutes IP
 * lockout (remoting.inc.php, spec 1.4). Those abort the run; a per-function
 * fault (missing grant, SQL error) only skips its own key.
 */
final class RemoteMetaData
{
    public const STATUS_OK = 'ok';
    public const STATUS_DEGRADED = 'degraded';
    public const STATUS_UNAVAILABLE = 'unavailable';

    /** tblservers_remote.meta_data is a text column - keep the diagnostic bounded. */
    private const ERROR_LIMIT = 500;

    /** @var \Closure(): int */
    private readonly \Closure $clock;

    /** @param null|\Closure(): int $clock unix-time source, injectable for tests */
    public function __construct(
        private readonly SessionManager $session,
        ?\Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): int => time();
    }

    /**
     * Runs every probe and returns the flat array WHMCS stores.
     *
     * Never throws. Does NOT close the session - the caller owns its lifecycle
     * and must call SessionManager::close() (entry-file finally block), exactly
     * as with Provision\Recipe.
     *
     * @return array<string, scalar>
     */
    public function gather(): array
    {
        /** @var array<string, \Closure(): array<string, scalar>> $probes keyed by human label */
        $probes = [
            'panel version' => fn (): array => ['ispconfig_version' => $this->panelVersion()],
            'pending jobs' => fn (): array => ['pending_jobs' => $this->pendingJobs()],
            'clients' => function (): array {
                // ONE call answers both keys. service_count is the key WHMCS
                // itself renders ("Services: N") beneath the account count, and
                // the client count is the honest value for it: this module
                // creates exactly one ISPConfig client per WHMCS service
                // (Provision\Recipe::create). It used to report the panel's
                // website total, which cost a bulk read of web_domain - see the
                // class docblock, "Why there is no website count".
                $clients = $this->clientCount();

                return ['client_count' => $clients, 'service_count' => $clients];
            },
            'ISPConfig nodes' => fn (): array => ['server_count' => $this->serverCount()],
        ];

        $meta = [];
        $errors = [];
        $answered = 0;
        foreach ($probes as $label => $probe) {
            try {
                $meta = array_merge($meta, $probe());
                $answered++;
            } catch (\Throwable $failure) {
                $errors[] = $label . ': ' . self::describe($failure);

                if (self::isConnectionLevel($failure)) {
                    $errors[] = 'remaining probes skipped - every retry would repeat the same'
                        . ' failed login and extend the panel lockout window';
                    break;
                }
            }
        }

        $meta['checked_at'] = gmdate('Y-m-d H:i:s', ($this->clock)()) . ' UTC';
        $meta['ispconfig_status'] = match (true) {
            $errors === [] => self::STATUS_OK,
            $answered === 0 => self::STATUS_UNAVAILABLE,
            default => self::STATUS_DEGRADED,
        };
        if ($errors !== []) {
            $meta['ispconfig_error'] = self::truncate(implode('; ', $errors), self::ERROR_LIMIT);
        }

        return $meta;
    }

    // ---- probes ----------------------------------------------------------

    /**
     * remote.d/server.inc.php server_get_app_version(): grant `server_get`,
     * response `{"ispc_app_version": "..."}`.
     *
     * server_id 0 returns the interface's own ISPC_APP_VERSION constant. Any
     * other id unserializes monitor_data type 'ispc_info' for that node, which
     * stays empty until the node's monitor cron has reported - the source then
     * evaluates false['version'] and answers null. 0 is therefore the only id
     * that reliably reports a version.
     */
    private function panelVersion(): string
    {
        $response = $this->session->call('server_get_app_version', ['server_id' => 0]);

        $version = is_array($response) ? ($response['ispc_app_version'] ?? null) : null;
        if (!is_string($version) || trim($version) === '') {
            throw new \UnexpectedValueException('server_get_app_version returned no ispc_app_version');
        }

        return trim($version);
    }

    /**
     * Pending datalog jobs - the ISPConfig-specific health signal, because
     * every mutation is asynchronous (datalog -> per-server cron) and a growing
     * queue means provisioning is accepted but not applied.
     *
     * server_id 0 counts across all nodes (remote.d/monitor.inc.php). The
     * source returns the raw mysqli column value, i.e. a STRING.
     */
    private function pendingJobs(): int
    {
        $response = $this->session->call('monitor_jobqueue_count', ['server_id' => 0]);

        if (!is_int($response) && !(is_string($response) && $response !== '' && ctype_digit($response))) {
            throw new \UnexpectedValueException('monitor_jobqueue_count returned a non-numeric count');
        }

        return (int) $response;
    }

    /**
     * remote.d/client.inc.php client_get_all(): `SELECT client_id FROM client`
     * flattened to a bare list - ids only, no record columns. It returns FALSE
     * (not []) when the client table is empty, via its `if(!$result)` exit.
     */
    private function clientCount(): int
    {
        return $this->countOf($this->session->call('client_get_all', []), 'client_get_all');
    }

    /**
     * remote.d/server.inc.php server_get_all(): `SELECT server_id, server_name
     * FROM server` - the ISPConfig node count, which tells an operator whether
     * this panel fronts a single box or a multi-server install.
     */
    private function serverCount(): int
    {
        return $this->countOf($this->session->call('server_get_all', []), 'server_get_all');
    }

    /** ISPConfig list functions answer an empty result with false, not []. */
    private function countOf(mixed $response, string $function): int
    {
        if ($response === false || $response === null) {
            return 0;
        }
        if (!is_array($response)) {
            throw new \UnexpectedValueException($function . ' returned no list');
        }

        return count($response);
    }

    // ---- failure classification -------------------------------------------

    /**
     * True for failures that would repeat identically on every further probe.
     *
     * All of these are settled before any per-function permission check: the
     * transport never connected, the API is switched off, the credentials or
     * the source IP are rejected, the panel is in maintenance, the endpoint is
     * not ISPConfig's JSON handler, or the session cannot be kept (an IP
     * mismatch survives SessionManager's one retry). Continuing would burn a
     * fresh failed login per probe - and failed logins AND dead-session
     * lookups share the counter that locks the IP out for five minutes
     * (remoting.inc.php, spec 1.4).
     *
     * A shape complaint raised by this class is deliberately an
     * \UnexpectedValueException, not a MalformedResponseException: it says
     * something about one function, not about the endpoint.
     */
    private static function isConnectionLevel(\Throwable $failure): bool
    {
        return $failure instanceof TransportException
            || $failure instanceof ApiDisabledException
            || $failure instanceof LockoutException
            || $failure instanceof LoginFailedException      // incl. IpNotAllowedException
            || $failure instanceof MaintenanceModeException
            || $failure instanceof MalformedResponseException
            || $failure instanceof SessionExpiredException;  // incl. SessionIpMismatchException
    }

    private static function describe(\Throwable $failure): string
    {
        $message = trim($failure->getMessage());

        return $message !== '' ? $message : get_class($failure);
    }

    private static function truncate(string $text, int $limit): string
    {
        return strlen($text) > $limit ? substr($text, 0, $limit - 3) . '...' : $text;
    }
}
