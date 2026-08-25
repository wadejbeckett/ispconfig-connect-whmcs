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
 * rest is read back by this module's own hooks.php panel.
 *
 * ## Why these probes and not others
 *
 * ISPConfig 3.3.1p1 exposes exactly one counting function over the remote API
 * - `monitor_jobqueue_count` (remote.d/monitor.inc.php is the whole monitor
 * surface; interface/web/monitor/lib/remote.conf.php grants that one name).
 * Every other total would have to be derived from a bulk record read, and
 * remoting_lib.inc.php getDataRecord() answers an array/-1 primary_id with
 * `SELECT *`. That rules out the obvious candidates on security grounds, not
 * performance ones:
 *
 *   - `sites_web_domain_get(['type' => 'vhost'])` returns every vhost row
 *     INCLUDING `web_domain.ssl_key` - the site's TLS private key
 *     (install/sql/ispconfig3.sql);
 *   - `mail_domain_get([])` returns `mail_domain.dkim_private` and
 *     `relay_pass` (same file).
 *
 * Those responses reach WHMCS's module log, so a website/mail counter would
 * copy private keys into tblmodulelog on every metadata refresh. The probes
 * below return ids, counts and a version string only.
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
                $clients = $this->clientCount();

                // service_count is the key WHMCS itself renders ("Services: N").
                // One ISPConfig client per WHMCS service is this module's
                // provisioning model (Provision\Recipe::create), so the client
                // count is the number that belongs there.
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
