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
     * The fake's crypt marker: crypt_password($x) is modeled as 'crypt:' . $x,
     * so a double-crypt (the client_update pre-merge corruption) is visible as
     * 'crypt:crypt:...'.
     */
    private const CRYPT_PREFIX = 'crypt:';

    /**
     * Returned verbatim by quota_get_by_user.
     *
     * @var array<mixed>
     */
    public array $quotaData = [];

    /**
     * Returned verbatim by trafficquota_get_by_user.
     *
     * @var array<mixed>
     */
    public array $trafficQuotaData = [];

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

    // ---- state inspectors -------------------------------------------------

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

        if (!method_exists($this, 'api_' . $name)) {
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
            if (str_starts_with($method, 'api_')) {
                $methods[] = substr($method, 4);
            }
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
        $this->clients[$id] = $record;
        $this->sysUserActive[$id] = 1;

        return $id;
    }

    /** @return array<string, mixed>|null */
    public function api_client_get(mixed $session_id, mixed $client_id): ?array
    {
        $this->requireGrant($session_id, 'client_get');
        $id = $this->assertRecordId($client_id);

        if (!isset($this->clients[$id])) {
            // getDataRecord -> queryOneRecord: a miss is NULL (db_mysql.inc.php),
            // serialized as response: null with code ok.
            return null;
        }
        $record = $this->clients[$id];

        // client.inc.php client_get(): when template_additional is empty, it is
        // backfilled with 'assigned_template_id:client_template_id' pairs
        // joined by '/' from client_template_assigned.
        if (($record['template_additional'] ?? '') === '') {
            $pairs = [];
            foreach ($this->templateAssignments as $assignment) {
                if ($assignment['client_id'] === $id) {
                    $pairs[] = $assignment['assigned_template_id'] . ':' . $assignment['client_template_id'];
                }
            }
            $record['template_additional'] = implode('/', $pairs);
        }

        return $this->dbRecord($record);
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
        // The real values are written asynchronously by the server plugin; the
        // fake stores the final shape directly (spec 2.9).
        $record['system_user'] = 'web' . $id;
        $record['system_group'] = 'client' . $cid;
        $record['document_root'] = '/var/www/clients/client' . $cid . '/web' . $id;
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
        $this->ftpUsers[$id] = $record;

        return $id;
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

    /** @return array<mixed> */
    public function api_quota_get_by_user(mixed $session_id, mixed $client_id): array
    {
        $this->requireGrant($session_id, 'quota_get_by_user');

        return $this->quotaData;
    }

    /** @return array<mixed> */
    public function api_trafficquota_get_by_user(mixed $session_id, mixed $client_id, mixed $lastdays): array
    {
        $this->requireGrant($session_id, 'trafficquota_get_by_user');

        return $this->trafficQuotaData;
    }

    // ---- mail functions (remote.d/mail.inc.php) ---------------------------

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
            'active' => 'Y',
        ];

        return true; // dns_templatezone_add returns bool, never the zone id
    }

    /** @return array<string, mixed>|null miss is null (queryOneRecord), not false */
    public function api_dns_zone_get(mixed $session_id, mixed $primary_id): ?array
    {
        $this->requireGrant($session_id, 'dns_zone_get');
        $id = $this->assertRecordId($primary_id);

        return isset($this->dnsZones[$id]) ? $this->dbRecord($this->dnsZones[$id]) : null;
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
