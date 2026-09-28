<?php

/**
 * Sharing-slice helpers on top of /opt/whmcs-harness/harness.php (not edited).
 * Env: HARNESS_DB=whmcs4 PANEL_PORT=8084 PANEL_DB=dbispconfig4
 */

declare(strict_types=1);

putenv('HARNESS_DB=' . (getenv('HARNESS_DB') ?: 'whmcs4'));
putenv('PANEL_PORT=' . (getenv('PANEL_PORT') ?: '8084'));
putenv('PANEL_DB=' . (getenv('PANEL_DB') ?: 'dbispconfig4'));

(new PDO('mysql:unix_socket=/run/mysqld/mysqld.sock', 'root', ''))->exec('CREATE DATABASE IF NOT EXISTS `' . getenv('HARNESS_DB') . '` CHARACTER SET utf8 COLLATE utf8_unicode_ci');
require_once '/opt/whmcs-harness/harness.php';

use WHMCS\Database\Capsule;

const SH_DIR = '/opt/livetest/sharing';
const SH_PANEL_LOGS = [
    'p4' => '/opt/ispc/p4/server.log',
    'p5' => '/opt/ispc/p5/server.log',
    'p5alt' => '/opt/livetest/sharing/p5-alt-server.log',
];

Capsule::connection()->statement("SET SESSION sql_mode = ''");

$GLOBALS['sh_results'] = [];

function out(string $line = ''): void
{
    echo $line, "\n";
}

function hdr(string $title): void
{
    out();
    out(str_repeat('=', 100));
    out($title);
    out(str_repeat('=', 100));
}

/** Record a check. */
function check(string $id, bool $ok, string $what, string $detail = ''): bool
{
    $GLOBALS['sh_results'][] = [$id, $ok, $what];
    out(sprintf('  [%s] %s: %s%s', $ok ? 'OK  ' : 'FAIL', $id, $what, $detail !== '' ? ' -- ' . $detail : ''));

    return $ok;
}

function summary(): void
{
    $fails = array_filter($GLOBALS['sh_results'], static fn ($r) => !$r[1]);
    out();
    out(sprintf('SUMMARY: %d checks, %d failed', count($GLOBALS['sh_results']), count($fails)));
    foreach ($fails as $f) {
        out('  FAILED ' . $f[0] . ': ' . $f[2]);
    }
}

/** Number of API requests (and logins) seen by each panel's built-in server so far. */
function panel_requests(): array
{
    $counts = [];
    foreach (SH_PANEL_LOGS as $name => $file) {
        $text = is_file($file) ? (string) file_get_contents($file) : '';
        $counts[$name] = [
            'requests' => substr_count($text, 'POST /remote/json.php'),
            'logins' => substr_count($text, 'POST /remote/json.php?login'),
        ];
    }

    return $counts;
}

/** Difference in panel request counts. */
function panel_delta(array $before): array
{
    $after = panel_requests();
    $d = [];
    foreach ($after as $name => $c) {
        $d[$name] = [
            'requests' => $c['requests'] - ($before[$name]['requests'] ?? 0),
            'logins' => $c['logins'] - ($before[$name]['logins'] ?? 0),
        ];
    }

    return $d;
}

function no_panel_traffic(array $delta): bool
{
    foreach ($delta as $c) {
        if ($c['requests'] !== 0 || $c['logins'] !== 0) {
            return false;
        }
    }

    return true;
}

function fmt_delta(array $delta): string
{
    $parts = [];
    foreach ($delta as $n => $c) {
        $parts[] = "$n: {$c['logins']} login(s), {$c['requests']} request(s)";
    }

    return implode('; ', $parts);
}

/** Module-log rows since $mark, compact. @return list<object> */
function log_rows(int $mark): array
{
    return harness_log_since($mark);
}

function print_log(array $rows, int $max = 400): void
{
    foreach ($rows as $row) {
        $req = (string) $row->request;
        $resp = (string) $row->response;
        out(sprintf('    log#%d %s | req=%s | resp=%s', $row->id, $row->action, mb_substr($req, 0, $max), mb_substr($resp, 0, $max)));
    }
    if ($rows === []) {
        out('    (no tblmodulelog rows)');
    }
}

function log_actions(array $rows): array
{
    return array_map(static fn ($r) => (string) $r->action, $rows);
}

/** Every ispconfig_* custom-field value row of a service, on any product. */
function prop_rows(int $serviceId): array
{
    return Capsule::table('tblcustomfieldsvalues as v')
        ->join('tblcustomfields as f', 'f.id', '=', 'v.fieldid')
        ->where('v.relid', $serviceId)
        ->where('f.fieldname', 'like', 'ispconfig%')
        ->orderBy('f.relid')->orderBy('f.fieldname')->orderBy('v.id')
        ->get(['v.id as vid', 'f.type', 'f.relid as product', 'f.fieldname', 'v.value'])
        ->map(static fn ($r) => (array) $r)->all();
}

function print_props(int $serviceId, string $label = ''): void
{
    out("    ispconfig_* rows for service #$serviceId $label:");
    $rows = prop_rows($serviceId);
    if ($rows === []) {
        out('      (none)');
    }
    foreach ($rows as $r) {
        out(sprintf('      v#%d %s product=%d %s = %s', $r['vid'], $r['type'], $r['product'], $r['fieldname'], var_export($r['value'], true)));
    }
}

function nonblank_props(int $serviceId): array
{
    return array_values(array_filter(prop_rows($serviceId), static fn ($r) => trim((string) $r['value']) !== ''));
}

function field_id(int $productId, string $name, string $type = 'product'): ?int
{
    $id = Capsule::table('tblcustomfields')->where('type', $type)->where('relid', $productId)->where('fieldname', $name)->value('id');

    return $id === null ? null : (int) $id;
}

/** Ensure the admin-only custom field exists on a product (as WHMCS would after a first save). */
function ensure_field(int $productId, string $name, string $type = 'product'): int
{
    $id = field_id($productId, $name, $type);
    if ($id !== null) {
        return $id;
    }

    return (int) Capsule::table('tblcustomfields')->insertGetId([
        'type' => $type, 'relid' => $productId, 'fieldname' => $name, 'fieldtype' => 'text', 'description' => '',
        'fieldoptions' => '', 'regexpr' => '', 'adminonly' => 'on', 'required' => '', 'showorder' => '', 'showinvoice' => '',
    ]);
}

/** A raw INSERT into tblcustomfieldsvalues, exactly as the plan's SQL does. */
function insert_value(int $fieldId, int $serviceId, string $value): int
{
    return (int) Capsule::table('tblcustomfieldsvalues')->insertGetId(['fieldid' => $fieldId, 'relid' => $serviceId, 'value' => $value]);
}

function set_status(int $serviceId, string $status): void
{
    Capsule::table('tblhosting')->where('id', $serviceId)->update(['domainstatus' => $status]);
}

function svc(int $serviceId): object
{
    return Capsule::table('tblhosting')->where('id', $serviceId)->first();
}

/** Panel DB access for another instance. */
function panel_db(string $db): mysqli
{
    static $conns = [];
    if (!isset($conns[$db])) {
        $conns[$db] = new mysqli('localhost', 'root', '', $db, 0, '/run/mysqld/mysqld.sock');
        $conns[$db]->set_charset('utf8mb4');
    }

    return $conns[$db];
}

function prow(string $sql, string $db = 'dbispconfig4'): array
{
    return panel_db($db)->query($sql)->fetch_all(MYSQLI_ASSOC);
}

function pval(string $sql, string $db = 'dbispconfig4'): mixed
{
    $r = panel_db($db)->query($sql)->fetch_row();

    return $r[0] ?? null;
}

/** Snapshot of everything a client owns on the panel. */
function client_snapshot(int $clientId, string $db = 'dbispconfig4'): array
{
    $gid = (int) pval("SELECT groupid FROM sys_group WHERE client_id = $clientId", $db);
    $snap = [
        'client' => prow("SELECT client_id, username, locked, canceled, template_master, LENGTH(tmp_data) tmp_len, MD5(password) pw FROM client WHERE client_id = $clientId", $db),
        'sys_user' => prow("SELECT userid, username, active, MD5(passwort) pw FROM sys_user WHERE client_id = $clientId", $db),
        'groupid' => $gid,
    ];
    if ($gid > 0) {
        $snap['web_domain'] = prow("SELECT domain_id, domain, active FROM web_domain WHERE sys_groupid = $gid", $db);
        $snap['ftp_user'] = prow("SELECT ftp_user_id, username, active, MD5(password) pw FROM ftp_user WHERE sys_groupid = $gid", $db);
        $snap['mail_domain'] = prow("SELECT domain_id, domain, active FROM mail_domain WHERE sys_groupid = $gid", $db);
        $snap['dns_soa'] = prow("SELECT id, origin, active FROM dns_soa WHERE sys_groupid = $gid", $db);
        $snap['web_database'] = prow("SELECT database_id, database_name, active FROM web_database WHERE sys_groupid = $gid", $db);
        $snap['web_database_user'] = prow("SELECT database_user_id, database_user, MD5(database_password) pw FROM web_database_user WHERE sys_groupid = $gid", $db);
    }

    return $snap;
}

function max_datalog(string $db = 'dbispconfig4'): int
{
    return (int) pval('SELECT COALESCE(MAX(datalog_id),0) FROM sys_datalog', $db);
}

function datalog_since(int $mark, string $db = 'dbispconfig4'): array
{
    return prow("SELECT datalog_id, dbtable, dbidx, action FROM sys_datalog WHERE datalog_id > $mark ORDER BY datalog_id", $db);
}

/** A raw ISPConfig remote API call (for "by hand in ISPConfig" actions), NOT via the module. */
function raw_api(int $port, string $method, array $params, string $host = '127.0.0.1'): mixed
{
    $post = static function (string $m, array $p) use ($port, $host) {
        $ch = curl_init("http://$host:$port/remote/json.php?$m");
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($p), CURLOPT_RETURNTRANSFER => true]);
        $body = curl_exec($ch);
        curl_close($ch);

        return json_decode((string) $body, true);
    };
    $login = $post('login', ['username' => HARNESS_REMOTE_USER, 'password' => HARNESS_REMOTE_PASS]);
    $sid = $login['response'];
    $result = $post($method, ['session_id' => $sid] + $params);
    $post('logout', ['session_id' => $sid]);

    return $result;
}

/**
 * A product addon model as WHMCS passes it for an addon: $params['model'] is the
 * service ADDON (tblhostingaddons row), whose serviceProperties are custom fields
 * of type 'addon' on the addon DEFINITION (relid = tbladdons.id), with values keyed
 * by the hosting-addon id. (The harness's HarnessServiceModel keys addon values by
 * the parent service id, which WHMCS does not.)
 */
final class ShAddonProperties
{
    public function __construct(private readonly int $addonDefinitionId, private readonly int $hostingAddonId)
    {
    }

    private function fieldId(string $name): ?int
    {
        $id = Capsule::table('tblcustomfields')->where('type', 'addon')->where('relid', $this->addonDefinitionId)->where('fieldname', $name)->value('id');

        return $id === null ? null : (int) $id;
    }

    public function get(string $name): string
    {
        $fid = $this->fieldId($name);
        if ($fid === null) {
            return '';
        }
        $v = Capsule::table('tblcustomfieldsvalues')->where('fieldid', $fid)->where('relid', $this->hostingAddonId)->value('value');

        return $v === null ? '' : (string) $v;
    }

    public function save(array $values): bool
    {
        foreach ($values as $name => $value) {
            $fid = $this->fieldId((string) $name) ?? ensure_field($this->addonDefinitionId, (string) $name, 'addon');
            $q = Capsule::table('tblcustomfieldsvalues')->where('fieldid', $fid)->where('relid', $this->hostingAddonId);
            if ((clone $q)->exists()) {
                (clone $q)->update(['value' => (string) $value]);
            } else {
                insert_value($fid, $this->hostingAddonId, (string) $value);
            }
        }

        return true;
    }
}

final class ShAddonModel
{
    public ShAddonProperties $serviceProperties;

    public function __construct(public int $id, public int $addonDefinitionId, public string $status)
    {
        $this->serviceProperties = new ShAddonProperties($addonDefinitionId, $id);
    }

    public function isAddon(): bool
    {
        return true;
    }

    public function isService(): bool
    {
        return false;
    }

    public function getAttribute(string $key): mixed
    {
        return $key === 'status' ? $this->status : null;
    }
}

/**
 * $params for a product addon of $parentServiceId, as WHMCS builds them: serviceid is the
 * PARENT's id, addonId the hosting-addon id, model the addon, status the addon's status,
 * configoptionN the addon's own module settings.
 */
function addon_params(int $parentServiceId, int $hostingAddonId, int $addonDefinitionId, string $addonStatus, array $configOptions, array $extra = []): array
{
    $model = new ShAddonModel($hostingAddonId, $addonDefinitionId, $addonStatus);
    $p = harness_params($parentServiceId, ['addonId' => $hostingAddonId]);
    $p['model'] = $model;
    $p['status'] = $addonStatus;
    foreach ($configOptions as $k => $v) {
        $p[$k] = $v;
    }

    return array_replace($p, $extra);
}

/** Variant of the harness property store whose save() updates only the FIRST matching value row
 * (Eloquent firstOrNew semantics), for the duplicate-row analysis. */
final class ShFirstRowProperties
{
    public function __construct(private readonly int $serviceId, private readonly int $productId)
    {
    }

    private function fieldId(string $name): ?int
    {
        return field_id($this->productId, $name);
    }

    public function get(string $name): string
    {
        $fid = $this->fieldId($name);
        if ($fid === null) {
            return '';
        }
        $v = Capsule::table('tblcustomfieldsvalues')->where('fieldid', $fid)->where('relid', $this->serviceId)->orderBy('id')->value('value');

        return $v === null ? '' : (string) $v;
    }

    public function save(array $values): bool
    {
        foreach ($values as $name => $value) {
            $fid = $this->fieldId((string) $name) ?? ensure_field($this->productId, (string) $name);
            $first = Capsule::table('tblcustomfieldsvalues')->where('fieldid', $fid)->where('relid', $this->serviceId)->orderBy('id')->value('id');
            if ($first !== null) {
                Capsule::table('tblcustomfieldsvalues')->where('id', $first)->update(['value' => (string) $value]);
            } else {
                insert_value($fid, $this->serviceId, (string) $value);
            }
        }

        return true;
    }
}

final class ShFirstRowModel
{
    public ShFirstRowProperties $serviceProperties;

    public function __construct(public int $id, public int $packageId, public string $domainstatus)
    {
        $this->serviceProperties = new ShFirstRowProperties($id, $packageId);
    }

    public function isAddon(): bool
    {
        return false;
    }

    public function isService(): bool
    {
        return true;
    }

    public function getAttribute(string $key): mixed
    {
        return $key === 'domainstatus' ? $this->domainstatus : null;
    }
}

function first_row_params(int $serviceId, array $extra = []): array
{
    $s = svc($serviceId);
    $p = harness_params($serviceId);
    $p['model'] = new ShFirstRowModel((int) $s->id, (int) $s->packageid, (string) $s->domainstatus);

    return array_replace($p, $extra);
}

/** What the module itself would read for a service's client id / username. */
function module_reads(int $serviceId, ?array $params = null): array
{
    $p = $params ?? harness_params($serviceId);
    $props = ispconfigconnect_serviceProperties($p);

    return ['client_id' => $props->clientId(), 'username' => $props->clientUsername(), 'domain_id' => $props->domainId()];
}

function state_file(): string
{
    return SH_DIR . '/state.json';
}

function save_state(array $state): void
{
    file_put_contents(state_file(), json_encode($state, JSON_PRETTY_PRINT));
}

function load_state(): array
{
    return json_decode((string) file_get_contents(state_file()), true);
}
