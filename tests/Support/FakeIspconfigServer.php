<?php

declare(strict_types=1);

namespace Ispconfigconnect\Tests\Support;

use WHMCS\Module\Server\Ispconfigconnect\Api\HttpTransportInterface;

/**
 * In-process behavioral double of ISPConfig 3.3.1p1's remote/json.php +
 * remoting.inc.php + remote.d/*.inc.php. Every envelope shape, fault message,
 * session/lockout rule, and grant alias below is copied from the extracted
 * source tree - cited per method. The whole test suite runs against this
 * class, so fidelity beats brevity.
 *
 * Not modeled (single-client-IP test setting): session IP binding, demo-mode
 * HTML error pages, client_login=true sessions.
 */
final class FakeIspconfigServer implements HttpTransportInterface
{
    private const SESSION_TTL = 1800;      // remoting.inc.php $session_timeout
    private const FAILURE_WINDOW = 300;    // attempts_login: NOW() - INTERVAL 5 MINUTE
    private const FAILURE_LIMIT = 10;      // remoting.inc.php: $alreadyfailed['times'] >= 10

    /** Settable unix clock; advance with `$fake->clock += n` to age sessions. */
    public int $clock = 1_700_000_000;

    /** false -> exact plain-text die() body from interface/web/remote/json.php. */
    public bool $apiEnabled = true;

    /** true -> login throws the maintenance fault (remoting.inc.php login()). */
    public bool $maintenanceMode = false;

    /** Returned by monitor_jobqueue_count (as a STRING, like mysqli does). */
    public int $jobqueueCount = 0;

    /**
     * ISPC_APP_VERSION as the INTERFACE reports it (interface/lib/config.inc.php);
     * server_get_app_version($sid, 0) returns exactly this.
     */
    public string $appVersion = '3.3.1p1';

    /**
     * Per-node versions read from monitor_data type 'ispc_info' by
     * server_get_app_version($sid, <id>); an id with no monitor row yields
     * null, exactly like the source's unserialize(false)['version'].
     *
     * @var array<int, string>
     */
    public array $nodeVersions = [];

    /**
     * Whether the OPTIONAL SSO companion (companion/ispconfig-sso) is deployed
     * on this panel.  Default false = a stock ISPConfig: its remote function is
     * absent from get_function_list and calling it yields the JSON handler's
     * invalid_method envelope, exactly as json_handler.inc.php does for any
     * unregistered method name.
     */
    public bool $companionInstalled = false;

    /** Endpoint path the companion reports for the consumer page. */
    public string $companionTokenPath = '/connectsso/';

    /** Token TTL the companion reports, in seconds (real one: <= 60). */
    public int $companionTokenTtl = 45;

    /**
     * When non-null, connect_sso_token_create returns this verbatim instead of
     * a well-formed payload - the seam for "companion speaks a shape we do not
     * trust" tests (version skew, hostile/compromised panel).
     */
    public mixed $companionResponseOverride = null;

    /**
     * Methods that exist only when the companion is installed.
     *
     * @var list<string>
     */
    private const COMPANION_METHODS = ['connect_sso_token_create'];

    /**
     * The fake's crypt marker: crypt_password($x) is modeled as 'crypt:' . $x,
     * so a double-crypt (the client_update pre-merge corruption) is visible as
     * 'crypt:crypt:...'.
     */
    private const CRYPT_PREFIX = 'crypt:';

    /**
     * Canned override for quota_get_by_user: when non-empty it is returned
     * verbatim instead of the values computed from seeded state.
     *
     * @var array<mixed>
     */
    public array $quotaData = [];

    /**
     * Canned override for trafficquota_get_by_user (see $quotaData).
     *
     * @var array<mixed>
     */
    public array $trafficQuotaData = [];

    /**
     * monitor_data type 'harddisk_quota', member data['user'][system_user].
     * repquota block counts, i.e. KILOBYTES, and stringly typed because they
     * come back from unserialize() of preg_split() output
     * (server/lib/classes/cron.d/100-monitor_hd_quota.inc.php).
     *
     * @var array<string, array{used: string, soft: string, hard: string, files: string}>
     */
    public array $diskQuotaMonitor = [];

    /**
     * monitor_data type 'email_quota', keyed by email address, in BYTES
     * (doveadm kB * 1024 / .quotausage storage value / du -s KB * 1024 -
     * server/lib/classes/cron.d/100-monitor_email_quota.inc.php).
     *
     * @var array<string, string>
     */
    public array $mailQuotaMonitor = [];

    /**
     * monitor_data type 'database_size', keyed by database_name, in BYTES
     * (SUM(data_length+index_length) from information_schema -
     * server/lib/classes/db_mysql.inc.php getDatabaseSize()).
     *
     * @var array<string, string>
     */
    public array $databaseSizeMonitor = [];

    /**
     * web_traffic SUM(traffic_bytes) per hostname and period, in BYTES and
     * stringly typed the way mysqli returns a SUM().
     *
     * @var array<string, array<string, string>>
     */
    public array $webTrafficMonitor = [];

    /** @var list<array{method: string, params: array<mixed>}> decoded body of every parsed call */
    public array $requestLog = [];

    /** @var list<string>|null grant names for future logins; null = every function granted */
    private ?array $grants = null;

    /** @var array<string, array{tstamp: int, remote_functions: list<string>|null}> */
    private array $sessions = [];

    /** @var array<string, list<string>> method name -> queued one-shot fault messages */
    private array $pendingFaults = [];

    private int $failTimes = 0;
    private int $lastFailTime = 0;
    private int $nextSessionSerial = 1;

    /** @var array<int, array<string, mixed>> */
    private array $clients = [];
    /** @var array<int, array<string, mixed>> */
    private array $servers = [];
    /** @var array<int, array<string, mixed>> */
    private array $clientTemplates = [];
    /** @var array<int, array<string, mixed>> */
    private array $templateAssignments = [];
    /** @var array<int, array<string, mixed>> */
    private array $sites = [];
    /** @var array<int, array<string, mixed>> */
    private array $ftpUsers = [];
    /** @var array<int, array<string, mixed>> */
    private array $mailDomains = [];
    /** @var array<int, array<string, mixed>> mail_user rows (mailquota_get_by_user) */
    private array $mailUsers = [];
    /** @var array<int, array<string, mixed>> */
    private array $dnsZones = [];
    /** @var array<int, array<string, mixed>> */
    private array $databases = [];
    /** @var array<int, array<string, mixed>> */
    private array $databaseUsers = [];
    /** @var array<int, true> dns_template ids known to the wizard */
    private array $dnsTemplates = [];

    /**
     * client.tmp_data, per client id (functions.inc.php func_client_lock):
     * ['prev_active' => [store => [record id => 'n']]] between lock and unlock.
     *
     * @var array<int, array<string, mixed>>
     */
    private array $clientTmpData = [];

    /** @var array<int, int> sys_user.active per client id (func_client_cancel) */
    private array $sysUserActive = [];

    /** Object stores swept by func_client_lock (subset of its $to_disable). */
    private const LOCKABLE_STORES = ['sites', 'ftpUsers', 'mailDomains', 'databases'];

    private int $nextClientId = 1;
    private int $nextServerId = 1;
    private int $nextClientTemplateId = 1;
    private int $nextAssignedTemplateId = 1;
    private int $nextDomainId = 1;
    private int $nextFtpUserId = 1;
    private int $nextMailDomainId = 1;
    private int $nextMailUserId = 1;
    private int $nextDnsZoneId = 1;
    private int $nextDatabaseId = 1;
    private int $nextDatabaseUserId = 1;

    /** @param list<string>|null $grants remote_functions grant names; null = all */
    public function __construct(
        private readonly string $username = 'remote',
        private readonly string $password = 'secret',
        ?array $grants = null,
    ) {
        $this->grants = $grants;
        // A stock install ships dns_template id 1 ('Default', origin={DOMAIN}.)
        // - install/sql/ispconfig3.sql.
        $this->dnsTemplates[1] = true;
    }

    // ---- test hooks -------------------------------------------------------

    /**
     * Restrict the remote_functions grant list applied to FUTURE logins.
     *
     * @param list<string> $grantNames
     */
    public function setGrants(array $grantNames): void
    {
        $this->grants = array_values($grantNames);
    }

    public function grantAll(): void
    {
        $this->grants = null;
    }

    /**
     * Deploy the optional SSO companion on this panel: its remote.d class is
     * auto-loaded by ISPConfigRemotingHandlerBase from
     * interface/web/*&#47;lib/classes/remote.d/*.inc.php, so its public methods
     * join the handler's method map and therefore get_function_list.
     */
    public function installCompanion(): void
    {
        $this->companionInstalled = true;
    }

    /** Queue a one-shot remote_fault for the next call of $method. */
    public function failNextWith(string $method, string $faultMessage): void
    {
        $this->pendingFaults[$method][] = $faultMessage;
    }

    /** Failed login/session attempts currently inside the 5-minute window. */
    public function failedAttempts(): int
    {
        return $this->failuresInWindow();
    }

    // ---- seeding ----------------------------------------------------------

    public function addServer(string $name): int
    {
        $id = $this->nextServerId++;
        $this->servers[$id] = ['server_id' => $id, 'server_name' => $name];

        return $id;
    }

    /**
     * @param 'm'|'a' $type master or additional template
     * @param array<string, int|string> $limits extra limit_* columns
     */
    public function addClientTemplate(string $name, string $type = 'm', array $limits = []): int
    {
        $id = $this->nextClientTemplateId++;
        $this->clientTemplates[$id] = [
            'template_id' => $id,
            'template_name' => $name,
            'template_type' => $type,
        ] + $limits;

        return $id;
    }

    public function addDnsTemplate(): int
    {
        $id = count($this->dnsTemplates) + 1;
        $this->dnsTemplates[$id] = true;

        return $id;
    }

    /**
     * Seed a client row directly, skipping client_add's validators - for tests
     * about reading existing panel state rather than about provisioning it.
     */
    public function addClient(string $username, string $language = 'en'): int
    {
        $id = $this->nextClientId++;
        $this->clients[$id] = [
            'client_id' => $id,
            'username' => $username,
            'contact_name' => $username,
            'email' => $username . '@example.test',
            'language' => $language,
            'password' => self::CRYPT_PREFIX . 'seeded',
            'template_master' => 0,
            'template_additional' => '',
            'locked' => 'n',
            'canceled' => 'n',
            'parent_client_id' => 0,
            'created_at' => $this->clock,
            // Every panel-created client carries an SSH KEYPAIR: klientadd ->
            // generate_ssh_key() writes id_rsa (PRIVATE) and ssh_rsa (public)
            // in the same UPDATE that stamps created_at
            // (functions.inc.php:518), and client_get is SELECT * - so the
            // private key rides along on every client read. Seeded so leak
            // tests exercise the real wire content.
            'id_rsa' => self::clientSshPrivateKey($id),
            'ssh_rsa' => 'ssh-rsa FAKE-PUBLIC-KEY-client' . $id,
        ];
        $this->sysUserActive[$id] = 1;

        return $id;
    }

    /** The fake SSH PRIVATE key every client row carries (client.id_rsa). */
    public static function clientSshPrivateKey(int $clientId): string
    {
        return "-----BEGIN RSA PRIVATE KEY-----\nFAKE-SSH-KEY-client" . $clientId
            . "\n-----END RSA PRIVATE KEY-----";
    }

    /** The fake TLS PRIVATE key every website row carries (web_domain.ssl_key). */
    public static function websiteTlsPrivateKey(int $domainId): string
    {
        return "-----BEGIN PRIVATE KEY-----\nFAKE-TLS-KEY-web" . $domainId
            . "\n-----END PRIVATE KEY-----";
    }

    /** The fake CSR every website row carries (web_domain.ssl_request). */
    public static function websiteCertificateRequest(int $domainId): string
    {
        return "-----BEGIN CERTIFICATE REQUEST-----\nFAKE-CSR-web" . $domainId
            . "\n-----END CERTIFICATE REQUEST-----";
    }

    /**
     * Seed a type='vhost' web_domain. hd_quota and traffic_quota are the panel
     * columns, both in MB with -1 meaning unlimited
     * (interface/web/sites/form/web_vhost_domain.tform.php).
     */
    public function addWebsite(
        int $clientId,
        string $domain,
        int $hdQuotaMegabytes = -1,
        int $trafficQuotaMegabytes = -1,
        string $type = 'vhost',
        string $active = 'y',
    ): int {
        $id = $this->nextDomainId++;
        $this->sites[$id] = [
            'domain_id' => $id,
            'client_id' => $clientId,
            'client_group_id' => $this->groupIdFor($clientId),
            'sys_groupid' => $this->groupIdFor($clientId),
            'server_id' => 1,
            'domain' => $domain,
            'type' => $type,
            'active' => $active,
            'ip_address' => '*',
            'parent_domain_id' => 0,
            'hd_quota' => $hdQuotaMegabytes,
            'traffic_quota' => $trafficQuotaMegabytes,
            'system_user' => 'web' . $id,
            'system_group' => 'client' . $clientId,
            'document_root' => '/var/www/clients/client' . $clientId . '/web' . $id,
            // web_domain carries the site's TLS key material as COLUMNS
            // (install/sql/ispconfig3.sql:2036 ssl_key, :2033 ssl_request),
            // and quota_get_by_user is SELECT * over web_domain
            // (quota_lib.inc.php get_quota_data) - the PRIVATE KEY is on the
            // wire in every quota row. Seeded so leak tests see it.
            'ssl_key' => self::websiteTlsPrivateKey($id),
            'ssl_request' => self::websiteCertificateRequest($id),
        ];

        return $id;
    }

    public function addFtpUser(int $clientId, int $parentDomainId, string $username): int
    {
        $id = $this->nextFtpUserId++;
        $this->ftpUsers[$id] = [
            'ftp_user_id' => $id,
            'client_id' => $clientId,
            'sys_groupid' => $this->groupIdFor($clientId),
            'server_id' => 1,
            'parent_domain_id' => $parentDomainId,
            'username' => $username,
            'quota_size' => -1,
            'active' => 'y',
        ];

        return $id;
    }

    /**
     * Seed a mail_user row plus its email_quota monitor entry. mail_user.quota
     * is in BYTES with 0 meaning unlimited (interface/web/mail/user_quota_stats.php
     * divides it by 1048576 to display MB).
     */
    public function addMailUser(int $clientId, string $email, int $quotaBytes = 0, int $usedBytes = 0): int
    {
        $id = $this->nextMailUserId++;
        $this->mailUsers[$id] = [
            'mailuser_id' => $id,
            'client_id' => $clientId,
            'sys_groupid' => $this->groupIdFor($clientId),
            'server_id' => 1,
            'email' => $email,
            'login' => $email,
            'name' => $email,
            'quota' => $quotaBytes,
            'maildir' => '/var/vmail/' . $email,
            'last_access' => 0,
        ];
        if ($usedBytes > 0) {
            $this->mailQuotaMonitor[$email] = (string) $usedBytes;
        }

        return $id;
    }

    /**
     * Seed a web_database row. database_quota is in MB with -1 meaning
     * unlimited (interface/web/sites/form/database.tform.php).
     */
    public function addDatabase(int $clientId, string $databaseName, int $quotaMegabytes = -1): int
    {
        $id = $this->nextDatabaseId++;
        $this->databases[$id] = [
            'database_id' => $id,
            'client_id' => $clientId,
            'sys_groupid' => $this->groupIdFor($clientId),
            'server_id' => 1,
            'database_name' => $databaseName,
            'database_quota' => $quotaMegabytes,
            'type' => 'mysql',
            'active' => 'y',
        ];

        return $id;
    }

    public function addDnsZone(int $clientId, string $origin): int
    {
        $id = $this->nextDnsZoneId++;
        $this->dnsZones[$id] = [
            'id' => $id,
            'client_id' => $clientId,
            'sys_groupid' => $this->groupIdFor($clientId),
            'server_id' => 1,
            'origin' => rtrim($origin, '.') . '.',
            'active' => 'Y',
        ];

        return $id;
    }

    /**
     * Seed the harddisk_quota monitor entry for a website's system_user. All
     * four values are repquota block/inode counts, i.e. KILOBYTES and files
     * (server/lib/classes/cron.d/100-monitor_hd_quota.inc.php).
     */
    public function setDiskUsage(
        string $systemUser,
        int $usedKilobytes,
        int $softKilobytes = 0,
        int $hardKilobytes = 0,
        int $files = 0,
    ): void {
        $this->diskQuotaMonitor[$systemUser] = [
            'used' => (string) $usedKilobytes,
            'soft' => (string) $softKilobytes,
            'hard' => (string) $hardKilobytes,
            'files' => (string) $files,
        ];
    }

    /** Seed web_traffic sums for a hostname, in BYTES (web_traffic.traffic_bytes). */
    public function setWebTraffic(
        string $domain,
        int $thisMonthBytes,
        ?int $thisYearBytes = null,
        int $lastMonthBytes = 0,
    ): void {
        $this->webTrafficMonitor[$domain] = [
            'this_month' => (string) $thisMonthBytes,
            'this_year' => (string) ($thisYearBytes ?? $thisMonthBytes),
            'last_month' => (string) $lastMonthBytes,
            'last_year' => (string) $lastMonthBytes,
        ];
    }

    /** Seed the database_size monitor entry for a database, in BYTES. */
    public function setDatabaseSize(string $databaseName, int $bytes): void
    {
        $this->databaseSizeMonitor[$databaseName] = (string) $bytes;
    }

    // ---- state inspectors -------------------------------------------------

    /** @return array<int, array<string, mixed>> */
    public function mailUsers(): array
    {
        return $this->mailUsers;
    }

    /** @return array<int, array<string, mixed>> */
    public function clients(): array
    {
        return $this->clients;
    }

    /** @return array<int, array<string, mixed>> */
    public function sites(): array
    {
        return $this->sites;
    }

    /** @return array<int, array<string, mixed>> */
    public function ftpUsers(): array
    {
        return $this->ftpUsers;
    }

    /** @return array<int, array<string, mixed>> */
    public function mailDomains(): array
    {
        return $this->mailDomains;
    }

    /** @return array<int, array<string, mixed>> */
    public function dnsZones(): array
    {
        return $this->dnsZones;
    }

    /** @return array<int, array<string, mixed>> */
    public function databases(): array
    {
        return $this->databases;
    }

    /** @return array<int, array<string, mixed>> */
    public function databaseUsers(): array
    {
        return $this->databaseUsers;
    }

    /** @return array<int, array<string, mixed>> */
    public function templateAssignments(): array
    {
        return $this->templateAssignments;
    }

    /**
     * client.tmp_data as func_client_lock left it (unserialized shape).
     *
     * @return array<string, mixed>
     */
    public function clientTmpData(int $clientId): array
    {
        return $this->clientTmpData[$clientId] ?? [];
    }

    public function activeSessionCount(): int
    {
        $count = 0;
        foreach ($this->sessions as $session) {
            if ($session['tstamp'] >= $this->clock) {
                $count++;
            }
        }

        return $count;
    }

    // ---- transport entry point (mirrors json.php + json_handler.inc.php) --

    public function post(string $method, string $jsonBody): string
    {
        if (!$this->apiEnabled) {
            // Exact die() body from interface/web/remote/json.php - plain text, not JSON.
            return 'Remote API is disabled in security settings.';
        }

        // json_handler.inc.php: the method is the FIRST key of the query string.
        parse_str($method, $query);
        $keys = array_keys($query);
        if ($keys === []) {
            return $this->envelope('invalid_method', 'Method not provided in json call');
        }
        $name = (string) reset($keys);

        $json = json_decode($jsonBody, true);
        if (!is_array($json)) {
            return $this->envelope('invalid_data', 'The JSON data sent to the api is invalid');
        }

        $this->requestLog[] = ['method' => $name, 'params' => $json];

        // Companion methods only exist once companion/ispconfig-sso is
        // deployed; on a stock panel the handler's method map has no such key.
        $unregistered = in_array($name, self::COMPANION_METHODS, true) && !$this->companionInstalled;
        if ($unregistered || !method_exists($this, 'api_' . $name)) {
            return $this->envelope('invalid_method', 'Method ' . $name . ' does not exist');
        }

        if (isset($this->pendingFaults[$name])) {
            $message = array_shift($this->pendingFaults[$name]);
            if ($this->pendingFaults[$name] === []) {
                unset($this->pendingFaults[$name]);
            }

            return $this->envelope('remote_fault', (string) $message);
        }

        // json_handler.inc.php reflection loop: every declared parameter gets an
        // explicit value - null when absent - so PHP defaults NEVER apply.
        $reflection = new \ReflectionMethod($this, 'api_' . $name);
        $args = [];
        foreach ($reflection->getParameters() as $parameter) {
            $args[] = $json[$parameter->getName()] ?? null; // isset() semantics, like the source
        }

        try {
            return $this->envelope('ok', '', $reflection->invokeArgs($this, $args));
        } catch (FakeSoapFault $fault) {
            return $this->envelope('remote_fault', $fault->getMessage());
        }
    }

    private function envelope(string $code, string $message, mixed $data = false): string
    {
        // Property order matches json_handler.inc.php _return_json().
        return json_encode(
            ['code' => $code, 'message' => $message, 'response' => $data],
            JSON_THROW_ON_ERROR
        );
    }

    // ---- session & lockout machinery (mirrors remoting.inc.php) -----------

    private function failuresInWindow(): int
    {
        // attempts_login WHERE login_time > NOW() - INTERVAL 5 MINUTE
        return $this->lastFailTime > $this->clock - self::FAILURE_WINDOW ? $this->failTimes : 0;
    }

    private function recordFailure(): void
    {
        $this->failTimes = $this->failuresInWindow() + 1;
        $this->lastFailTime = $this->clock;
    }

    /**
     * remoting.inc.php getSession(): failed lookups increment the SAME counter
     * as failed logins; the session-path lockout has its own message.
     *
     * @return array{tstamp: int, remote_functions: list<string>|null}
     */
    private function getSession(mixed $sessionId): array
    {
        if (empty($sessionId)) {
            throw new FakeSoapFault('The SessionID is empty.');
        }
        if (!is_string($sessionId)) {
            throw new FakeSoapFault('Wrong SessionID datatype.');
        }
        if ($this->failuresInWindow() >= self::FAILURE_LIMIT) {
            throw new FakeSoapFault('The Session failure limit has been reached.');
        }
        // remote_session WHERE remote_session = ? AND tstamp >= UNIX_TIMESTAMP()
        // - tstamp written once at login, never extended by use.
        if (isset($this->sessions[$sessionId]) && $this->sessions[$sessionId]['tstamp'] >= $this->clock) {
            return $this->sessions[$sessionId];
        }
        $this->recordFailure();

        throw new FakeSoapFault('The Session is expired or does not exist.');
    }

    /** checkPerm(): grant names differ from method names for some functions. */
    private function requireGrant(mixed $sessionId, string $grantName): void
    {
        $session = $this->getSession($sessionId);
        if ($session['remote_functions'] !== null
            && !in_array($grantName, $session['remote_functions'], true)
        ) {
            throw new FakeSoapFault('You do not have the permissions to access this function.');
        }
    }

    // ---- login / logout (remoting.inc.php lines 65-263) -------------------

    public function api_login(mixed $username, mixed $password, mixed $client_login): string
    {
        if ($this->maintenanceMode) {
            throw new FakeSoapFault('This ISPConfig installation is currently under maintenance. We should be back shortly. Thank you for your patience.');
        }
        if (empty($username)) {
            throw new FakeSoapFault('The login username is empty.');
        }
        if (empty($password)) {
            throw new FakeSoapFault('The login password is empty.');
        }

        // DELETE FROM remote_session WHERE tstamp < UNIX_TIMESTAMP()
        foreach ($this->sessions as $id => $session) {
            if ($session['tstamp'] < $this->clock) {
                unset($this->sessions[$id]);
            }
        }

        if ($this->failuresInWindow() >= self::FAILURE_LIMIT) {
            throw new FakeSoapFault('The login failure limit has been reached.');
        }

        if ($username !== $this->username || $password !== $this->password) {
            $this->recordFailure();

            throw new FakeSoapFault('The login failed. Username or password wrong.');
        }

        // Format: 1 lowercase letter + 40 hex chars (deterministic here).
        $sessionId = 's' . str_pad(dechex($this->nextSessionSerial++), 40, '0', STR_PAD_LEFT);
        $this->sessions[$sessionId] = [
            'tstamp' => $this->clock + self::SESSION_TTL,
            // remote_functions is snapshotted into the session row at login.
            'remote_functions' => $this->grants,
        ];

        // DELETE FROM attempts_login after successful login.
        $this->failTimes = 0;
        $this->lastFailTime = 0;

        return $sessionId;
    }

    public function api_logout(mixed $session_id): bool
    {
        if (empty($session_id)) {
            throw new FakeSoapFault('The SessionID is empty.');
        }
        if (is_string($session_id)) {
            unset($this->sessions[$session_id]);
        }

        // The source returns true whenever the DELETE query ran.
        return true;
    }

    // ---- misc (remoting.inc.php / monitor.inc.php) ------------------------

    /** @return list<string> the FULL registered method list, NOT per-user grants */
    public function api_get_function_list(mixed $session_id): array
    {
        $this->requireGrant($session_id, 'get_function_list');

        $methods = [];
        foreach (get_class_methods($this) as $method) {
            if (!str_starts_with($method, 'api_')) {
                continue;
            }
            $name = substr($method, 4);
            if (in_array($name, self::COMPANION_METHODS, true) && !$this->companionInstalled) {
                continue;
            }
            $methods[] = $name;
        }

        return $methods;
    }

    public function api_monitor_jobqueue_count(mixed $session_id, mixed $server_id): string
    {
        $this->requireGrant($session_id, 'monitor_jobqueue_count');

        // monitor.inc.php returns $tmp['jobqueue_count'] straight from mysqli
        // - a string column value, never an int.
        return (string) $this->jobqueueCount;
    }

    /** @return list<array<string, string>> grant name 'server_get' covers server_get_all */
    public function api_server_get_all(mixed $session_id): array
    {
        $this->requireGrant($session_id, 'server_get');

        return array_values(array_map($this->dbRecord(...), $this->servers));
    }

    /**
     * remote.d/server.inc.php server_get_app_version(): grant 'server_get'
     * (another alias). server_id 0 (or absent - the JSON handler passes null,
     * and null == 0) returns the INTERFACE constant ISPC_APP_VERSION; any
     * other id unserializes monitor_data type 'ispc_info' for that node, which
     * is empty until the node's monitor cron has reported - the source then
     * evaluates false['version'] and returns null.
     *
     * @return array{ispc_app_version: string|null}
     */
    public function api_server_get_app_version(mixed $session_id, mixed $server_id): array
    {
        $this->requireGrant($session_id, 'server_get');

        $id = is_numeric($server_id) ? (int) $server_id : 0;
        if ($id === 0) {
            return ['ispc_app_version' => $this->appVersion];
        }

        return ['ispc_app_version' => $this->nodeVersions[$id] ?? null];
    }

    // ---- client functions (remote.d/client.inc.php) -----------------------

    public function api_client_add(mixed $session_id, mixed $reseller_id, mixed $params): int
    {
        $this->requireGrant($session_id, 'client_add');
        $params = is_array($params) ? $params : [];

        if (!isset($params['usertheme'])) {
            $params['usertheme'] = 'default';
        }

        // remoting_lib validators run first, then klientadd() appends the
        // duplicate-username error; all errors are '<br />'-joined and thrown
        // as one data_processing_error fault whose text is localized -
        // consumers must never string-match it (Duplicate username aside).
        // 'language' is NOTEMPTY in client.tform.php; its tform default only
        // fills the panel SELECT - the remote API has NO server-side default.
        $error = '';
        foreach (['contact_name', 'username', 'email', 'language'] as $field) {
            if (($params[$field] ?? '') === '' || $params[$field] === null) {
                $error .= $field . '_error_empty<br />';
            }
        }
        // NOTEMPTY with no server default (client.tform.php :1135ff/:1272ff;
        // live-verified on 3.3.1p1) - mirror the exact errmsg names.
        foreach (['web_php_options' => 'web_php_options_notempty', 'ssh_chroot' => 'ssh_chroot_notempty'] as $field => $errmsg) {
            if (($params[$field] ?? '') === '' || $params[$field] === null) {
                $error .= $errmsg . '<br />';
            }
        }
        if (($params['username'] ?? '') !== '') {
            foreach ($this->clients as $existing) {
                if ($existing['username'] === $params['username']) {
                    $error .= 'Duplicate username<br />'; // verbatim, klientadd()
                    break;
                }
            }
        }
        if ($error !== '') {
            throw new FakeSoapFault($error);
        }

        $id = $this->nextClientId++;
        $record = array_merge(
            [
                'template_master' => 0,
                'template_additional' => '',
                'locked' => 'n',
                'canceled' => 'n',
                'parent_client_id' => 0,
            ],
            $params
        );
        // getSQL INSERT crypts non-empty PASSWORD fields (tform_base.inc.php)
        // unless _ispconfig_pw_crypted == 1; empty ones are skipped entirely.
        $record['password'] = $this->cryptField($params, 'password') ?? '';
        unset($record['_ispconfig_pw_crypted']);
        $record['client_id'] = $id;
        // klientadd() -> functions.inc.php generate_ssh_key() (:518):
        // "UPDATE client SET created_at = UNIX_TIMESTAMP(), id_rsa = ?,
        // ssh_rsa = ? ..." - the only place these columns are ever written, so
        // they stay NULL when ssh-keygen fails or the row predates the
        // feature. id_rsa is the client's SSH PRIVATE key, echoed by every
        // client_get (SELECT *).
        $record['created_at'] = $this->clock;
        $record['id_rsa'] = self::clientSshPrivateKey($id);
        $record['ssh_rsa'] = 'ssh-rsa FAKE-PUBLIC-KEY-client' . $id;
        $this->clients[$id] = $record;
        $this->sysUserActive[$id] = 1;

        return $id;
    }

    /**
     * A single record for a positive id, EVERY client row for -1
     * (remoting_lib.inc.php getDataRecord(): "$primary_id == -1 // Return a
     * array with all records", plain "SELECT * FROM client" with no auth
     * filter - the remote API always runs as admin, loadUserProfile(0)).
     * client_get() post-processes both shapes: the multi-record branch is
     * "elseif(isset($data[0]['client_id']))" in remote.d/client.inc.php.
     *
     * @return array<mixed>|null
     */
    public function api_client_get(mixed $session_id, mixed $client_id): ?array
    {
        $this->requireGrant($session_id, 'client_get');

        if (is_numeric($client_id) && (int) $client_id === -1) {
            $records = [];
            foreach ($this->clients as $id => $record) {
                $records[] = $this->dbRecord($this->backfillTemplateAdditional($id, $record));
            }

            // queryAllRecords() returns array() when nothing matches, so an
            // empty panel answers with [] - never false, never null.
            return $records;
        }

        $id = $this->assertRecordId($client_id);

        if (!isset($this->clients[$id])) {
            // getDataRecord -> queryOneRecord: a miss is NULL (db_mysql.inc.php),
            // serialized as response: null with code ok.
            return null;
        }

        return $this->dbRecord($this->backfillTemplateAdditional($id, $this->clients[$id]));
    }

    /**
     * client.inc.php client_get(): when template_additional is empty, it is
     * backfilled with 'assigned_template_id:client_template_id' pairs joined
     * by '/' from client_template_assigned.
     *
     * @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    private function backfillTemplateAdditional(int $clientId, array $record): array
    {
        if (($record['template_additional'] ?? '') !== '') {
            return $record;
        }

        $pairs = [];
        foreach ($this->templateAssignments as $assignment) {
            if ($assignment['client_id'] === $clientId) {
                $pairs[] = $assignment['assigned_template_id'] . ':' . $assignment['client_template_id'];
            }
        }
        $record['template_additional'] = implode('/', $pairs);

        return $record;
    }

    /** @return array<string, mixed> the sys_user row, not the client record */
    public function api_client_get_by_username(mixed $session_id, mixed $username): array
    {
        $this->requireGrant($session_id, 'client_get_by_username');

        // SELECT * FROM sys_user WHERE username = ? - returns the sys_user row.
        foreach ($this->clients as $id => $client) {
            if ($client['username'] === $username) {
                return $this->dbRecord([
                    'userid' => $id + 1,        // admin is userid 1
                    'username' => $client['username'],
                    'client_id' => $id,
                    'default_group' => $this->groupIdFor($id),
                    // sys_user.groups is a comma-separated group list; a client
                    // created through the API gets exactly its own group
                    // (remoting_lib.inc.php ispconfig_sysuser_add: $groups = $groupid).
                    'groups' => (string) $this->groupIdFor($id),
                    // sys_user.active - toggled by func_client_cancel.
                    'active' => $this->sysUserActive[$id] ?? 1,
                    'language' => $client['language'] ?? 'en',
                ]);
            }
        }

        throw new FakeSoapFault('There is no user account for this user name.');
    }

    public function api_client_update(mixed $session_id, mixed $client_id, mixed $reseller_id, mixed $params): int
    {
        $this->requireGrant($session_id, 'client_update');
        $id = $this->assertRecordId($client_id);
        $params = is_array($params) ? $params : [];

        if (!isset($this->clients[$id])) {
            return 0; // UPDATE with no matching row -> 0 affected rows
        }
        $stored = $this->clients[$id];

        // client.inc.php client_update(): the OLD RECORD is merged into
        // $params BEFORE updateQuery - unlike every other *_update function.
        // Everything below operates on the merged record, which is why partial
        // updates have side effects on password, templates, and lock state.
        $merged = array_merge($stored, $params);

        // Template resync: the merged record ALWAYS carries the
        // template_additional COLUMN ('' when assignments were made via the
        // API), so update_client_templates runs on EVERY update - and
        // explode('/', '') wipes all assigned rows (client_templates.inc.php).
        if (array_key_exists('template_additional', $merged)) {
            $this->updateClientTemplates($id, explode('/', (string) ($merged['template_additional'] ?? '')));
            $merged['template_additional'] = '';
        }

        // Password (remoting.inc.php updateQueryPrepare + tform_base _getSQL):
        // - empty merged password -> field skipped from the UPDATE, stored
        //   value survives;
        // - non-empty WITHOUT _ispconfig_pw_crypted == 1 -> re-crypted. After
        //   the pre-merge the stored HASH is what gets re-crypted, so every
        //   partial client_update corrupts the panel password (client.password
        //   AND sys_user.passwort via ispconfig_sysuser_update, modeled as one
        //   field here);
        // - with _ispconfig_pw_crypted == 1 -> stored verbatim.
        if (($merged['password'] ?? '') === '' || $merged['password'] === null) {
            $merged['password'] = $stored['password'] ?? '';
        } elseif ((int) ($merged['_ispconfig_pw_crypted'] ?? 0) !== 1) {
            $merged['password'] = self::CRYPT_PREFIX . $merged['password'];
        }
        unset($merged['_ispconfig_pw_crypted']); // not a column

        $merged['client_id'] = $id;
        $this->clients[$id] = $merged;

        // client.inc.php: if($params['canceled']) / if($params['locked']) on
        // the MERGED record - 'n' is a truthy string, so these run on every
        // update; the funcs themselves only act on 'y'/'n'.
        if (!empty($merged['canceled'])) {
            $this->funcClientCancel($id, $merged['canceled']);
        }
        if (!empty($merged['locked'])) {
            $this->funcClientLock($id, $merged['locked']);
        }

        return 1;
    }

    public function api_client_change_password(mixed $session_id, mixed $client_id, mixed $new_password): bool
    {
        $this->requireGrant($session_id, 'client_change_password');
        $id = is_numeric($client_id) ? (int) $client_id : 0;

        if (!isset($this->clients[$id])) {
            throw new FakeSoapFault('There is no user account for this client_id');
        }
        // client.inc.php client_change_password() always crypt_password()s the
        // new clear (client.password AND sys_user.passwort).
        $this->clients[$id]['password'] = self::CRYPT_PREFIX . (string) $new_password;

        return true;
    }

    public function api_client_delete_everything(mixed $session_id, mixed $client_id): int
    {
        $this->requireGrant($session_id, 'client_delete_everything');
        $id = is_numeric($client_id) ? (int) $client_id : 0;

        if ($id > 0) {
            // Cascade over every table owned by the client's sys_groupid
            // (client.inc.php client_delete_everything()).
            foreach (
                [
                    'sites', 'ftpUsers', 'mailDomains', 'dnsZones',
                    'databases', 'databaseUsers', 'templateAssignments',
                ] as $store
            ) {
                foreach ($this->{$store} as $recordId => $record) {
                    if (($record['client_id'] ?? null) === $id) {
                        unset($this->{$store}[$recordId]);
                    }
                }
            }
        }

        // The source re-checks checkPerm('client_delete') AFTER the cascade -
        // a remote user needs BOTH grants or the client row survives.
        $this->requireGrant($session_id, 'client_delete');
        unset($this->clients[$id], $this->clientTmpData[$id], $this->sysUserActive[$id]);

        return 1;
    }

    /**
     * client.inc.php client_template_additional_get(): grant 'client_get' (an
     * alias), returns the raw client_template_assigned rows.
     *
     * @return list<array<string, string>>
     */
    public function api_client_template_additional_get(mixed $session_id, mixed $client_id): array
    {
        $this->requireGrant($session_id, 'client_get');

        if (!is_numeric($client_id)) {
            throw new FakeSoapFault('The ID must be an integer.');
        }

        $rows = [];
        foreach ($this->templateAssignments as $assignment) {
            if ($assignment['client_id'] === (int) $client_id) {
                $rows[] = $this->dbRecord($assignment);
            }
        }

        return $rows;
    }

    /**
     * remote.d/client.inc.php client_get_all(): SELECT client_id FROM client,
     * collected into a bare list. Two source facts that matter to callers:
     * the ids arrive as mysqli STRINGS, and an empty client table returns
     * FALSE (the `if(!$result) return false;` early exit), never [].
     *
     * @return list<string>|false
     */
    public function api_client_get_all(mixed $session_id): array|false
    {
        $this->requireGrant($session_id, 'client_get_all');

        if ($this->clients === []) {
            return false;
        }

        return array_map(static fn (int $id): string => (string) $id, array_keys($this->clients));
    }

    /** @return list<array<string, string>> */
    public function api_client_templates_get_all(mixed $session_id): array
    {
        $this->requireGrant($session_id, 'client_templates_get_all');

        return array_values(array_map($this->dbRecord(...), $this->clientTemplates));
    }

    public function api_client_template_additional_add(mixed $session_id, mixed $client_id, mixed $template_id): int
    {
        // client.inc.php: this method checks the 'client_update' grant.
        $this->requireGrant($session_id, 'client_update');

        if (!is_numeric($client_id) || !is_numeric($template_id)) {
            throw new FakeSoapFault('The IDs must be of type integer.');
        }
        if (!isset($this->clients[(int) $client_id])) {
            throw new FakeSoapFault('Invalid client');
        }
        if (!isset($this->clientTemplates[(int) $template_id])) {
            throw new FakeSoapFault('Invalid template');
        }

        return $this->insertTemplateAssignment((int) $client_id, (int) $template_id);
    }

    public function api_client_template_additional_delete(mixed $session_id, mixed $client_id, mixed $assigned_template_id): int
    {
        // client.inc.php: this method checks the 'client_update' grant.
        $this->requireGrant($session_id, 'client_update');

        if (!is_numeric($client_id) || !is_numeric($assigned_template_id)) {
            throw new FakeSoapFault('The IDs must be of type integer.');
        }
        if (!isset($this->clients[(int) $client_id])) {
            throw new FakeSoapFault('Invalid client');
        }

        // Source quirk (client.inc.php): the third argument is matched against
        // client_template_id, NOT assigned_template_id:
        //   SELECT assigned_template_id FROM client_template_assigned
        //   WHERE client_id = ? AND client_template_id = ?
        foreach ($this->templateAssignments as $assignedId => $assignment) {
            if ($assignment['client_id'] === (int) $client_id
                && $assignment['client_template_id'] === (int) $assigned_template_id
            ) {
                unset($this->templateAssignments[$assignedId]);

                return 1;
            }
        }

        throw new FakeSoapFault('Invalid template');
    }

    // ---- sites functions (remote.d/sites.inc.php) -------------------------

    /**
     * sites.inc.php client_get_sites_by_user(): own-name grant. $sys_groupid
     * is a COMMA-SEPARATED STRING (the source explode()s it, so an array
     * argument would be a TypeError), each part intval()ed into an IN() list.
     * The query selects exactly four columns from vhost-type web domains:
     *   SELECT domain, domain_id, document_root, active FROM web_domain
     *   WHERE ((sys_userid = ? AND sys_perm_user LIKE '%r%')
     *          OR (sys_groupid IN (...) AND sys_perm_group LIKE '%r%')
     *          OR sys_perm_other LIKE '%r%') AND type = 'vhost'
     * Modeled here as "owned by that client's group, or by that client's
     * sys_user" - the fake's sites carry client_group_id and client_id, and
     * its sys_user ids are client_id + 1 (api_client_get_by_username).
     * queryAllRecords() never returns null, so the source's no_client_found
     * branch is dead code: an empty result is [].
     *
     * @return list<array<string, string>>
     */
    public function api_client_get_sites_by_user(mixed $session_id, mixed $sys_userid, mixed $sys_groupid): array
    {
        $this->requireGrant($session_id, 'client_get_sites_by_user');

        $userId = is_numeric($sys_userid) ? (int) $sys_userid : 0;
        $groupIds = [];
        foreach (explode(',', (string) $sys_groupid) as $part) {
            $groupIds[] = (int) $part;
        }

        $rows = [];
        foreach ($this->sites as $site) {
            if (($site['type'] ?? 'vhost') !== 'vhost') {
                continue;
            }
            $clientId = (int) ($site['client_id'] ?? 0);
            $ownedByGroup = in_array((int) ($site['client_group_id'] ?? 0), $groupIds, true);
            $ownedByUser = $userId > 0 && $clientId + 1 === $userId;
            if (!$ownedByGroup && !$ownedByUser) {
                continue;
            }

            $rows[] = $this->dbRecord([
                'domain' => $site['domain'] ?? '',
                'domain_id' => $site['domain_id'] ?? 0,
                'document_root' => $site['document_root'] ?? '',
                'active' => $site['active'] ?? 'y',
            ]);
        }

        return $rows;
    }

    public function api_sites_web_domain_add(mixed $session_id, mixed $client_id, mixed $params, mixed $readonly): int
    {
        $this->requireGrant($session_id, 'sites_web_domain_add');
        $params = is_array($params) ? $params : [];

        // web_vhost_domain.tform.php NOTEMPTY validators (vhostdomain type
        // 'domain'): domain, server_id, hd_quota, traffic_quota.
        $error = '';
        foreach (['domain', 'server_id', 'hd_quota', 'traffic_quota'] as $field) {
            if (($params[$field] ?? '') === '' || $params[$field] === null) {
                $error .= $field . '_error_empty<br />';
            }
        }
        // web_vhost_domain.tform.php REGEX validators with NO allowempty: an
        // empty/absent value fails preg_match, so these four fields are
        // effectively REQUIRED over the remote API (sites_web_domain_add sets
        // defaults for pm_max_children & co, but not for these). The stock
        // example remoting_client/examples/sites_web_domain_add.php ships
        // pm_process_idle_timeout=10, pm_max_requests=0, http_port='80',
        // https_port='443'.
        $regexValidators = [
            'pm_process_idle_timeout' => '/^([1-9][0-9]{0,10})$/',
            'pm_max_requests' => '/^([0-9]{1,11})$/',
            'http_port' => '/^([0-9]{1,5})$/',
            'https_port' => '/^([0-9]{1,5})$/',
        ];
        foreach ($regexValidators as $field => $regex) {
            if (!preg_match($regex, (string) ($params[$field] ?? ''))) {
                $error .= $field . '_error_regex<br />';
            }
        }
        if ($error !== '') {
            throw new FakeSoapFault($error);
        }

        $cid = is_numeric($client_id) ? (int) $client_id : 0;
        $id = $this->nextDomainId++;
        $record = array_merge(
            [
                'ip_address' => '*',
                'type' => 'vhost',
                'parent_domain_id' => 0,
                'vhost_type' => 'name',
                'subdomain' => 'www',
                'active' => 'y',
                'log_retention' => 30,
            ],
            $params
        );
        if (($record['ip_address'] ?? '') === '') {
            $record['ip_address'] = '*';
        }
        $record['domain_id'] = $id;
        $record['client_id'] = $cid;
        $record['client_group_id'] = $this->groupIdFor($cid);
        // Every ISPConfig table carries sys_groupid; a record created for a
        // client is owned by that client's own sys_group (the one
        // ispconfig_sysuser_add created - remoting_lib.inc.php).
        $record['sys_groupid'] = $this->groupIdFor($cid);
        // The real values are written asynchronously by the server plugin; the
        // fake stores the final shape directly (spec 2.9). That includes the
        // TLS key material the LE/SSL plugins write back into web_domain
        // (ssl_key/ssl_request columns, install/sql/ispconfig3.sql:2036/:2033)
        // - present on the wire in every later SELECT * read of the row.
        $record['system_user'] = 'web' . $id;
        $record['system_group'] = 'client' . $cid;
        $record['document_root'] = '/var/www/clients/client' . $cid . '/web' . $id;
        if (($record['ssl_key'] ?? '') === '') {
            $record['ssl_key'] = self::websiteTlsPrivateKey($id);
        }
        if (($record['ssl_request'] ?? '') === '') {
            $record['ssl_request'] = self::websiteCertificateRequest($id);
        }
        $this->sites[$id] = $record;

        return $id;
    }

    /** @return array<string, mixed>|null miss is null (queryOneRecord), not false */
    public function api_sites_web_domain_get(mixed $session_id, mixed $primary_id): ?array
    {
        $this->requireGrant($session_id, 'sites_web_domain_get');
        $id = $this->assertRecordId($primary_id);

        return isset($this->sites[$id]) ? $this->dbRecord($this->sites[$id]) : null;
    }

    public function api_sites_web_domain_set_status(mixed $session_id, mixed $primary_id, mixed $status): int
    {
        $this->requireGrant($session_id, 'sites_web_domain_set_status');

        if (!in_array($status, ['active', 'inactive'], true)) {
            throw new FakeSoapFault('The status is not available');
        }
        $id = is_numeric($primary_id) ? (int) $primary_id : 0;
        if (!isset($this->sites[$id])) {
            return 0;
        }
        $this->sites[$id]['active'] = $status === 'active' ? 'y' : 'n';

        return 1;
    }

    public function api_sites_ftp_user_add(mixed $session_id, mixed $client_id, mixed $params): int
    {
        $this->requireGrant($session_id, 'sites_ftp_user_add');
        $params = is_array($params) ? $params : [];

        // ftp_user.tform.php NOTEMPTY validators: quota_size, uid, gid, dir.
        $error = '';
        foreach (['quota_size', 'uid', 'gid', 'dir'] as $field) {
            if (($params[$field] ?? '') === '' || $params[$field] === null) {
                $error .= $field . '_error_empty<br />';
            }
        }
        if ($error !== '') {
            throw new FakeSoapFault($error);
        }

        $id = $this->nextFtpUserId++;
        $record = array_merge(['active' => 'y'], $params);
        // ftp_user.tform.php: password is PASSWORD/CRYPT - crypted on INSERT.
        $crypted = $this->cryptField($params, 'password');
        if ($crypted !== null) {
            $record['password'] = $crypted;
        }
        unset($record['_ispconfig_pw_crypted']);
        $record['ftp_user_id'] = $id;
        $record['client_id'] = is_numeric($client_id) ? (int) $client_id : 0;
        $record['sys_groupid'] = $this->groupIdFor($record['client_id']);
        $this->ftpUsers[$id] = $record;

        return $id;
    }

    /**
     * sites.inc.php sites_ftp_user_get() -> remoting_lib::getDataRecord():
     * a positive int returns ONE record, and an ARRAY is a key/value filter
     * turned into "WHERE k = v AND ..." plus getAuthSQL(), which is a plain
     * '1' HERE - unlike the quota_lib path, getDataRecord() calls
     * loadUserProfile(0) first (remoting_lib.inc.php:251), which sets
     * $_SESSION['s']['user']['typ'] = 'admin' (:143) and so takes the '1'
     * shortcut (tform_base.inc.php:1633). A filter is therefore unrestricted
     * and returns a LIST, never a single record.
     *
     * @return array<mixed>|null
     */
    public function api_sites_ftp_user_get(mixed $session_id, mixed $primary_id): ?array
    {
        $this->requireGrant($session_id, 'sites_ftp_user_get');

        return $this->getDataRecord('ftpUsers', $primary_id);
    }

    /**
     * sites.inc.php sites_ftp_user_update(): own-name grant, plain updateQuery
     * - NO client_update-style pre-merge. updateQueryPrepare drops empty
     * PASSWORD-type fields, so partial updates are SAFE here.
     */
    public function api_sites_ftp_user_update(mixed $session_id, mixed $client_id, mixed $primary_id, mixed $params): int
    {
        $this->requireGrant($session_id, 'sites_ftp_user_update');
        $id = $this->assertRecordId($primary_id);
        $params = is_array($params) ? $params : [];

        if (!isset($this->ftpUsers[$id])) {
            return 0;
        }

        if (($params['password'] ?? '') === '' || ($params['password'] ?? null) === null) {
            unset($params['password']); // dropped, stored hash survives
        } else {
            $params['password'] = $this->cryptField($params, 'password');
        }
        unset($params['_ispconfig_pw_crypted']);

        $record = array_merge($this->ftpUsers[$id], $params);
        $record['ftp_user_id'] = $id;
        $this->ftpUsers[$id] = $record;

        return 1;
    }

    public function api_sites_database_user_add(mixed $session_id, mixed $client_id, mixed $params): int
    {
        $this->requireGrant($session_id, 'sites_database_user_add');
        $params = is_array($params) ? $params : [];

        // database_user.tform.php NOTEMPTY validator: database_user.
        if (($params['database_user'] ?? '') === '' || $params['database_user'] === null) {
            throw new FakeSoapFault('database_user_error_empty<br />');
        }

        $id = $this->nextDatabaseUserId++;
        $record = $params;
        // database_user.tform.php: database_password is PASSWORD/MYSQL -
        // hashed on INSERT (modeled with the same crypt marker).
        $crypted = $this->cryptField($params, 'database_password');
        if ($crypted !== null) {
            $record['database_password'] = $crypted;
        }
        unset($record['_ispconfig_pw_crypted']);
        $record['database_user_id'] = $id;
        $record['client_id'] = is_numeric($client_id) ? (int) $client_id : 0;
        $this->databaseUsers[$id] = $record;

        return $id;
    }

    /**
     * sites.inc.php sites_database_user_update(): own-name grant, plain
     * updateQuery - partial updates are SAFE (empty PASSWORD-type fields such
     * as database_password are dropped by updateQueryPrepare).
     */
    public function api_sites_database_user_update(mixed $session_id, mixed $client_id, mixed $primary_id, mixed $params): int
    {
        $this->requireGrant($session_id, 'sites_database_user_update');
        $id = $this->assertRecordId($primary_id);
        $params = is_array($params) ? $params : [];

        if (!isset($this->databaseUsers[$id])) {
            return 0;
        }

        if (($params['database_password'] ?? '') === '' || ($params['database_password'] ?? null) === null) {
            unset($params['database_password']);
        } else {
            $params['database_password'] = $this->cryptField($params, 'database_password');
        }
        unset($params['_ispconfig_pw_crypted']);

        $record = array_merge($this->databaseUsers[$id], $params);
        $record['database_user_id'] = $id;
        $this->databaseUsers[$id] = $record;

        return 1;
    }

    public function api_sites_database_add(mixed $session_id, mixed $client_id, mixed $params): int
    {
        $this->requireGrant($session_id, 'sites_database_add');
        $params = is_array($params) ? $params : [];

        // sites.inc.php: the per-server uniqueness check runs BEFORE the form
        // validators.
        foreach ($this->databases as $database) {
            if ($database['database_name'] === ($params['database_name'] ?? null)
                && ($database['server_id'] ?? null) === ($params['server_id'] ?? null)
            ) {
                throw new FakeSoapFault('There is already a database with that name on the same server.');
            }
        }

        // database.tform.php NOTEMPTY validator: database_name.
        if (($params['database_name'] ?? '') === '' || $params['database_name'] === null) {
            throw new FakeSoapFault('database_name_error_empty<br />');
        }

        $id = $this->nextDatabaseId++;
        $record = array_merge(['type' => 'mysql', 'active' => 'y'], $params);
        $record['database_id'] = $id;
        $record['client_id'] = is_numeric($client_id) ? (int) $client_id : 0;
        $record['sys_groupid'] = $this->groupIdFor($record['client_id']);
        $this->databases[$id] = $record;

        return $id;
    }

    /** @return array<string, mixed>|null miss is null (queryOneRecord), not false */
    public function api_sites_database_get(mixed $session_id, mixed $primary_id): ?array
    {
        $this->requireGrant($session_id, 'sites_database_get');
        $id = $this->assertRecordId($primary_id);

        return isset($this->databases[$id]) ? $this->dbRecord($this->databases[$id]) : null;
    }

    /**
     * sites.inc.php quota_get_by_user() -> quota_lib::get_quota_data($client_id, false):
     * every type='vhost' web_domain row visible to the client's group list,
     * augmented with the harddisk_quota monitor values (used/soft/hard in
     * KILOBYTES, files as an inode count) and the *_raw fields quota_lib
     * derives from them IN BYTES - with 0/absent soft & hard mapped to -1.
     *
     * @return array<mixed>
     */
    public function api_quota_get_by_user(mixed $session_id, mixed $client_id): array
    {
        $this->requireGrant($session_id, 'quota_get_by_user');

        if ($this->quotaData !== []) {
            return $this->quotaData;
        }

        $rows = [];
        foreach ($this->sitesVisibleTo($client_id) as $site) {
            if (($site['type'] ?? 'vhost') !== 'vhost') {
                continue;
            }
            $monitor = $this->diskQuotaMonitor[(string) ($site['system_user'] ?? '')] ?? null;
            // A system_user with no monitor entry leaves used/soft/hard/files
            // NULL: quota_lib reads $monitor_data['user'][$username][...] on a
            // missing key, and PHP 8 yields null rather than raising.
            $used = $monitor['used'] ?? null;
            $soft = $monitor['soft'] ?? null;
            $hard = $monitor['hard'] ?? null;
            $files = $monitor['files'] ?? null;

            $row = $this->dbRecord($site);
            $row['used'] = $used;
            $row['soft'] = $soft;
            $row['hard'] = $hard;
            $row['files'] = $files;
            // "Convert from kb to bytes, and use -1 for instead of 0 for
            // Unlimited." - quota_lib.inc.php, verbatim comment. These are
            // computed in PHP, so unlike the web_domain columns they come back
            // as JSON numbers, not strings.
            $row['used_raw'] = (int) $used * 1024;
            $row['soft_raw'] = (int) $soft > 0 ? (int) $soft * 1024 : -1;
            $row['hard_raw'] = (int) $hard > 0 ? (int) $hard * 1024 : -1;
            $row['files_raw'] = $files;
            $row['used_percentage'] = (int) $soft > 0 && (int) $used > 0
                ? (int) round((int) $used * 100 / (int) $soft)
                : 0;
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * sites.inc.php trafficquota_get_by_user() -> quota_lib::get_trafficquota_data():
     * a map keyed by DOMAIN NAME (not id) over the client's active vhost,
     * vhostsubdomain and vhostalias records. Every period member is a
     * SUM(traffic_bytes) - bytes, and stringly typed the way mysqli returns a
     * SUM - and is ABSENT entirely when web_traffic holds no row for it.
     *
     * @return array<mixed>
     */
    public function api_trafficquota_get_by_user(mixed $session_id, mixed $client_id, mixed $lastdays): array
    {
        $this->requireGrant($session_id, 'trafficquota_get_by_user');

        if ($this->trafficQuotaData !== []) {
            return $this->trafficQuotaData;
        }

        // get_trafficquota_data() scopes with a subselect ("sys_groupid =
        // (SELECT default_group FROM sys_user WHERE client_id=?)",
        // quota_lib.inc.php:81), so an unknown client id yields NULL = no
        // match - zero rows, same outcome as the getAuthSQL degradation in
        // recordsVisibleTo(), reached by a different mechanism.
        $clientId = is_numeric($client_id) ? (int) $client_id : 0;
        $traffic = [];
        foreach ($this->sites as $site) {
            if ((int) ($site['client_id'] ?? 0) !== $clientId || ($site['active'] ?? 'y') !== 'y') {
                continue;
            }
            if (!in_array($site['type'] ?? 'vhost', ['vhost', 'vhostsubdomain', 'vhostalias'], true)) {
                continue;
            }
            $domain = (string) ($site['domain'] ?? '');
            $traffic[$domain] = array_merge(
                ['domain_id' => (string) ($site['domain_id'] ?? 0)],
                $this->webTrafficMonitor[$domain] ?? []
            );
        }

        return $traffic;
    }

    /**
     * sites.inc.php databasequota_get_by_user() -> quota_lib::get_databasequota_data():
     * the client's web_database rows plus database_quota_raw and used_raw.
     * NOTE the units: database_quota_raw is database_quota * 1000 * 1000
     * (DECIMAL megabytes - quota_lib.inc.php), while used_raw is the
     * information_schema data_length+index_length sum in bytes.
     *
     * @return array<mixed>
     */
    public function api_databasequota_get_by_user(mixed $session_id, mixed $client_id): array
    {
        $this->requireGrant($session_id, 'databasequota_get_by_user');

        $rows = [];
        foreach ($this->recordsVisibleTo('databases', $client_id) as $database) {
            $name = (string) ($database['database_name'] ?? '');
            $quota = (int) ($database['database_quota'] ?? -1);
            $size = $this->databaseSizeMonitor[$name] ?? 0;

            $row = $this->dbRecord($database);
            $row['database_quota_raw'] = $quota === -1 ? -1 : $quota * 1000 * 1000;
            $row['used_raw'] = $size;
            $row['used_percentage'] = $quota > 0 && (int) $size > 0
                ? (int) round((int) $size * 100 / ($quota * 1000 * 1000))
                : 0;
            $rows[] = $row;
        }

        return $rows;
    }

    // ---- mail functions (remote.d/mail.inc.php) ---------------------------

    /**
     * mail.inc.php mailquota_get_by_user() -> quota_lib::get_mailquota_data($client_id, false):
     * the client's mail_user rows plus used/used_raw/quota_raw. Both quota and
     * used are BYTES; quota == 0 means unlimited, and the $readable branch that
     * would rewrite it to -1 is never taken over the remote API. A mailbox with
     * no email_quota monitor entry gets used = 0 (quota_lib defaults it to
     * array(1 => 0) and then takes member 1).
     *
     * @return array<mixed>
     */
    public function api_mailquota_get_by_user(mixed $session_id, mixed $client_id): array
    {
        $this->requireGrant($session_id, 'mailquota_get_by_user');

        $rows = [];
        foreach ($this->recordsVisibleTo('mailUsers', $client_id) as $mailUser) {
            $email = (string) ($mailUser['email'] ?? '');
            $used = $this->mailQuotaMonitor[$email] ?? 0;

            $row = $this->dbRecord($mailUser);
            $row['used'] = $used;
            $row['quota_raw'] = $row['quota'] ?? '0';
            $row['used_raw'] = $used;
            $row['used_percentage'] = (int) ($mailUser['quota'] ?? 0) > 0 && (int) $used > 0
                ? (int) round((int) $used * 100 / (int) $mailUser['quota'])
                : 0;
            // get_mailquota_data() rewrites last_access even when $readable is
            // false: it becomes a localized string, never the raw timestamp.
            $row['last_access'] = 'never_accessed_txt';
            $rows[] = $row;
        }

        return $rows;
    }

    public function api_mail_domain_add(mixed $session_id, mixed $client_id, mixed $params): int
    {
        $this->requireGrant($session_id, 'mail_domain_add');
        $params = is_array($params) ? $params : [];

        // mail_domain.tform.php NOTEMPTY validator: domain.
        if (($params['domain'] ?? '') === '' || $params['domain'] === null) {
            throw new FakeSoapFault('domain_error_empty<br />');
        }

        $id = $this->nextMailDomainId++;
        $record = array_merge(['active' => 'y'], $params);
        $record['domain_id'] = $id;
        $record['client_id'] = is_numeric($client_id) ? (int) $client_id : 0;
        $record['sys_groupid'] = $this->groupIdFor($record['client_id']);
        $this->mailDomains[$id] = $record;

        return $id;
    }

    /** @return array<string, mixed>|null miss is null (queryOneRecord), not false */
    public function api_mail_domain_get(mixed $session_id, mixed $primary_id): ?array
    {
        $this->requireGrant($session_id, 'mail_domain_get');
        $id = $this->assertRecordId($primary_id);

        return isset($this->mailDomains[$id]) ? $this->dbRecord($this->mailDomains[$id]) : null;
    }

    // ---- dns functions (remote.d/dns.inc.php + dns_wizard.inc.php) --------

    public function api_dns_templatezone_add(
        mixed $session_id,
        mixed $client_id,
        mixed $template_id,
        mixed $domain,
        mixed $ip,
        mixed $ns1,
        mixed $ns2,
        mixed $email,
        mixed $ipv6
    ): bool {
        $this->requireGrant($session_id, 'dns_templatezone_add');

        $cid = is_numeric($client_id) ? (int) $client_id : 0;
        if (!isset($this->clients[$cid])) {
            throw new FakeSoapFault('There is no group for this client ID.');
        }

        // dns_wizard.inc.php create(): field errors are localized,
        // '<br />'-joined, and surface as SoapFault('dns_wizard_error', ...).
        // Consumers must never string-match this message.
        $error = '';
        if ($domain === null || $domain === '') {
            $error .= 'error_domain_empty<br />';
        } elseif (!preg_match('/^[\w\.\-]{1,64}\.[a-zA-Z0-9\-]{2,63}$/', (string) $domain)) {
            $error .= 'error_domain_regex<br />';
        }
        if ($ip === null || $ip === '') {
            $error .= 'error_ip_empty<br />';
        }
        foreach (['ns1' => $ns1, 'ns2' => $ns2] as $field => $value) {
            if ($value === null || $value === '') {
                $error .= 'error_' . $field . '_empty<br />';
            } elseif (!preg_match('/^[\w\.\-]{1,64}\.[a-zA-Z0-9]{2,63}$/', (string) $value)) {
                $error .= 'error_' . $field . '_regex<br />';
            }
        }
        if ($email === null || $email === '') {
            $error .= 'error_email_empty<br />';
        } elseif (filter_var((string) $email, FILTER_VALIDATE_EMAIL) === false) {
            $error .= 'error_email_regex<br />';
        }
        if (!is_numeric($template_id) || !isset($this->dnsTemplates[(int) $template_id])) {
            // Unknown template -> empty template body -> the wizard's
            // origin-empty error.
            $error .= 'error_origin_empty<br />';
        }
        if ($error !== '') {
            throw new FakeSoapFault($error);
        }

        $id = $this->nextDnsZoneId++;
        $this->dnsZones[$id] = [
            'id' => $id,
            // The stock template stores 'origin={DOMAIN}.' - trailing dot
            // (install/sql/ispconfig3.sql, dns_template id 1).
            'origin' => $domain . '.',
            'ns' => $ns1 . '.',
            'mbox' => str_replace('@', '.', (string) $email) . '.',
            'server_id' => 1,
            'client_id' => $cid,
            'sys_groupid' => $this->groupIdFor($cid),
            'active' => 'Y',
        ];

        return true; // dns_templatezone_add returns bool, never the zone id
    }

    /**
     * dns.inc.php dns_zone_get() -> remoting_lib::getDataRecord(): one record
     * for a positive int (a miss is null - queryOneRecord, not false), a LIST
     * for a key/value filter array (see api_sites_ftp_user_get).
     *
     * @return array<mixed>|null
     */
    public function api_dns_zone_get(mixed $session_id, mixed $primary_id): ?array
    {
        $this->requireGrant($session_id, 'dns_zone_get');

        return $this->getDataRecord('dnsZones', $primary_id);
    }

    public function api_dns_zone_get_id(mixed $session_id, mixed $origin): int
    {
        $this->requireGrant($session_id, 'dns_zone_get_id');

        // dns.inc.php dns_zone_get_id(): this regex REJECTS a trailing-dot
        // origin ('example.com.' -> 'Invalid domain name.').
        if (!preg_match('/^[\w\.\-]{1,64}\.[a-zA-Z0-9\-]{2,63}$/', (string) $origin)) {
            throw new FakeSoapFault('Invalid domain name.');
        }

        // ... WHERE origin = ? or origin = ? (origin, origin.'.') - a dotless
        // query matches zones stored either way.
        foreach ($this->dnsZones as $id => $zone) {
            if ($zone['origin'] === $origin || $zone['origin'] === $origin . '.') {
                return $id;
            }
        }

        throw new FakeSoapFault('There is no domain ID with informed domain name.');
    }

    // ---- optional SSO companion (companion/ispconfig-sso) -----------------

    /**
     * Mints a one-time panel-login token.  Provided by the OPTIONAL companion
     * module's remote.d class, not by ISPConfig core - guarded by
     * $companionInstalled above.
     *
     * The double models the wire contract only: grant check, argument
     * validation, payload shape.  It deliberately does NOT model panel-user
     * lookup (the real function faults with 'There is no panel user for this
     * client id.' for an unknown/inactive client) - tests reach that path with
     * failNextWith(), because modelling it here would require seeding sys_user
     * rows the rest of the suite has no use for.
     */
    public function api_connect_sso_token_create(mixed $session_id, mixed $client_id, mixed $bind_ip): mixed
    {
        $this->requireGrant($session_id, 'connect_sso_token_create');

        if (!is_numeric($client_id) || (int) $client_id <= 0) {
            throw new FakeSoapFault('The client id must be an integer greater than zero.');
        }
        if ($bind_ip !== null && $bind_ip !== '' && !filter_var((string) $bind_ip, FILTER_VALIDATE_IP)) {
            throw new FakeSoapFault('The bind IP address is invalid.');
        }

        if ($this->companionResponseOverride !== null) {
            return $this->companionResponseOverride;
        }

        return [
            // random_bytes(32) -> 64 hex chars, per the companion's contract.
            'token' => bin2hex(random_bytes(32)),
            'path' => $this->companionTokenPath,
            'expires_in' => $this->companionTokenTtl,
            'client_id' => (int) $client_id,
            'username' => 'client' . (int) $client_id,
        ];
    }

    // ---- helpers ----------------------------------------------------------

    private function insertTemplateAssignment(int $clientId, int $templateId): int
    {
        $assignedId = $this->nextAssignedTemplateId++;
        $this->templateAssignments[$assignedId] = [
            'assigned_template_id' => $assignedId,
            'client_id' => $clientId,
            'client_template_id' => $templateId,
        ];

        return $assignedId;
    }

    /**
     * client_templates.inc.php update_client_templates(): bare ids ("5/7") are
     * the OLD style - a per-template-id count diff against the assigned table;
     * "assignedId:templateId" pairs are the NEW style - keep listed assigned
     * ids, delete the rest, insert 'n'-prefixed/zero assigned ids. An empty
     * list ('' explodes to ['']) is old style with nothing needed: every
     * existing row counts to -1 and is DELETED.
     *
     * @param list<string> $templates
     */
    private function updateClientTemplates(int $clientId, array $templates): void
    {
        $newTemplates = [];
        $usedAssigned = [];
        $neededCounts = [];
        $oldStyle = true;
        foreach ($templates as $item) {
            $item = trim((string) $item);
            if ($item === '') {
                continue;
            }
            $assignedId = 0;
            if (!str_contains($item, ':')) {
                $templateId = (int) $item;
            } else {
                $oldStyle = false; // has new-style assigns
                [$assignedPart, $templatePart] = explode(':', $item, 2);
                $templateId = (int) $templatePart;
                // 'n...' marks newly inserted items in the panel UI.
                $assignedId = str_starts_with($assignedPart, 'n') ? 0 : (int) $assignedPart;
            }
            $neededCounts[$templateId] = ($neededCounts[$templateId] ?? 0) + 1;
            if ($assignedId > 0) {
                $usedAssigned[] = $assignedId;
            } else {
                $newTemplates[] = $templateId;
            }
        }

        if ($oldStyle) {
            foreach ($this->templateAssignments as $assignment) {
                if ($assignment['client_id'] !== $clientId) {
                    continue;
                }
                $templateId = $assignment['client_template_id'];
                $neededCounts[$templateId] = ($neededCounts[$templateId] ?? 0) - 1;
            }
            foreach ($neededCounts as $templateId => $count) {
                for ($i = $count; $i > 0; $i--) {
                    $this->insertTemplateAssignment($clientId, (int) $templateId);
                }
                for ($i = $count; $i < 0; $i++) {
                    // DELETE ... WHERE client_id = ? AND client_template_id = ? LIMIT 1
                    foreach ($this->templateAssignments as $rowId => $assignment) {
                        if ($assignment['client_id'] === $clientId
                            && $assignment['client_template_id'] === (int) $templateId
                        ) {
                            unset($this->templateAssignments[$rowId]);
                            break;
                        }
                    }
                }
            }
        } else {
            foreach ($this->templateAssignments as $rowId => $assignment) {
                if ($assignment['client_id'] === $clientId
                    && !in_array($assignment['assigned_template_id'], $usedAssigned, true)
                ) {
                    unset($this->templateAssignments[$rowId]);
                }
            }
            foreach ($newTemplates as $templateId) {
                $this->insertTemplateAssignment($clientId, $templateId);
            }
        }
    }

    /**
     * functions.inc.php func_client_lock(): lock disables every client object
     * and remembers the ALREADY-inactive ones in client.tmp_data (OVERWRITING
     * whatever tmp_data held - locking an already-locked client therefore
     * remembers everything as previously-inactive and a later unlock restores
     * nothing); unlock re-enables everything except the remembered ids.
     */
    private function funcClientLock(int $clientId, mixed $locked): void
    {
        $tmpData = $this->clientTmpData[$clientId] ?? [];

        if ($locked === 'y') {
            $prevActive = [];
            foreach (self::LOCKABLE_STORES as $store) {
                foreach ($this->{$store} as $recordId => $record) {
                    if (($record['client_id'] ?? null) !== $clientId) {
                        continue;
                    }
                    if (($record['active'] ?? 'y') !== 'y') {
                        $prevActive[$store][$recordId] = 'n';
                    }
                    $this->{$store}[$recordId]['active'] = 'n';
                }
            }
            $tmpData['prev_active'] = $prevActive; // overwrite, never merged
            $this->clientTmpData[$clientId] = $tmpData;
        } elseif ($locked === 'n') {
            foreach (self::LOCKABLE_STORES as $store) {
                foreach ($this->{$store} as $recordId => $record) {
                    if (($record['client_id'] ?? null) !== $clientId) {
                        continue;
                    }
                    $remembered = ($tmpData['prev_active'][$store][$recordId] ?? '') === 'n';
                    $this->{$store}[$recordId]['active'] = $remembered ? 'n' : 'y';
                }
            }
            unset($tmpData['prev_active']);
            $this->clientTmpData[$clientId] = $tmpData;
        }
    }

    /** functions.inc.php func_client_cancel(): toggles sys_user.active only. */
    private function funcClientCancel(int $clientId, mixed $canceled): void
    {
        if ($canceled === 'y') {
            $this->sysUserActive[$clientId] = 0;
        } elseif ($canceled === 'n') {
            $this->sysUserActive[$clientId] = 1;
        }
    }

    /**
     * INSERT-path PASSWORD handling (tform_base.inc.php _getSQL): a non-empty
     * value is crypted unless _ispconfig_pw_crypted == 1; an empty/absent one
     * is skipped. Returns the value to store, or null when the field would be
     * skipped.
     *
     * @param array<string, mixed> $params
     */
    private function cryptField(array $params, string $field): ?string
    {
        $value = $params[$field] ?? null;
        if ($value === '' || $value === null) {
            return null;
        }
        if ((int) ($params['_ispconfig_pw_crypted'] ?? 0) === 1) {
            return (string) $value;
        }

        return self::CRYPT_PREFIX . $value;
    }

    /**
     * remoting_lib.inc.php getDataRecord(): a primary id must be numeric and
     * > 0 (or -1 for "all", which no module code uses - not modeled).
     */
    private function assertRecordId(mixed $id): int
    {
        if (!is_numeric($id) || (int) $id <= 0) {
            throw new FakeSoapFault('The ID has to be > 0 or -1.');
        }

        return (int) $id;
    }

    /** sys_group rows: groupid 1 is admin, clients get client_id + 1. */
    private function groupIdFor(int $clientId): int
    {
        return $clientId + 1;
    }

    /**
     * remoting_lib::getDataRecord(): a positive int is a single record (a miss
     * is null - queryOneRecord), an array is a "WHERE k = v AND ..." filter
     * returning a LIST, and anything else is rejected. getAuthSQL() adds no
     * restriction on THIS path: getDataRecord() calls loadUserProfile(0)
     * (remoting_lib.inc.php:251), which sets typ='admin' (:143), so the '1'
     * shortcut applies (tform_base.inc.php:1633) and the filter alone decides
     * what comes back. Contrast recordsVisibleTo(): the quota_lib path never
     * loads a user profile and gets the fallback clause instead.
     *
     * @param 'databases'|'dnsZones'|'ftpUsers'|'mailDomains'|'mailUsers'|'sites' $store
     * @return array<mixed>|null
     */
    private function getDataRecord(string $store, mixed $filter): ?array
    {
        if (is_array($filter)) {
            $rows = [];
            foreach ($this->{$store} as $record) {
                foreach ($filter as $column => $value) {
                    // The source casts both sides to string for the prepared
                    // statement, and MySQL then compares loosely.
                    if ((string) ($record[$column] ?? '') !== (string) $value) {
                        continue 2;
                    }
                }
                $rows[] = $this->dbRecord($record);
            }

            return $rows;
        }

        $id = $this->assertRecordId($filter);

        return isset($this->{$store}[$id]) ? $this->dbRecord($this->{$store}[$id]) : null;
    }

    /**
     * quota_lib scopes get_quota_data/get_mailquota_data/get_databasequota_data
     * with getAuthSQL('r', '', '', clientid_to_groups_list($clientid))
     * (quota_lib.inc.php:18/:224/:290). clientid_to_groups_list() returns NULL
     * when the client id matches no client row (functions.inc.php:687,
     * GROUP_CONCAT over an empty set) - and getAuthSQL() then falls to its
     * FALLBACK clause, NOT to '1': the '1' shortcut requires
     * $_SESSION['s']['user']['typ'] == 'admin' (tform_base.inc.php:1633), and
     * the only writes of typ='admin' live in loadUserProfile
     * (remoting_lib.inc.php:143/:155), which the quota remote path never
     * calls. With no session user and NULL groups the WHERE collapses to
     * "sys_perm_other like '%r%'", and these tables carry sys_perm_other = ''
     * (e.g. web_vhost_domain.tform.php:76 auth_preset perm_other = ''), so a
     * STALE CLIENT ID SILENTLY RETURNS ZERO ROWS with code ok. Modeled
     * faithfully because consumers must not mistake that for "no usage".
     *
     * For an EXISTING client the group list is real ("3", or "3,5,..." for a
     * reseller whose sub-client groups are concatenated in - the
     * parent_client_id branch of the same query), so its own records match.
     *
     * @param 'databases'|'mailUsers'|'sites' $store
     * @return list<array<string, mixed>>
     */
    private function recordsVisibleTo(string $store, mixed $clientId): array
    {
        $id = is_numeric($clientId) ? (int) $clientId : 0;
        if (!isset($this->clients[$id])) {
            return [];
        }

        $rows = [];
        foreach ($this->{$store} as $record) {
            if ((int) ($record['client_id'] ?? 0) === $id) {
                $rows[] = $record;
            }
        }

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     * @see recordsVisibleTo()
     */
    private function sitesVisibleTo(mixed $clientId): array
    {
        return $this->recordsVisibleTo('sites', $clientId);
    }

    /**
     * mysqli returns every column as a string by default, so real json.php
     * record responses are stringly typed. Mirror that so sloppy strict
     * comparisons in module code fail against the fake, too.
     *
     * @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    private function dbRecord(array $record): array
    {
        return array_map(
            static fn (mixed $value): mixed => is_int($value) || is_float($value) ? (string) $value : $value,
            $record
        );
    }
}
