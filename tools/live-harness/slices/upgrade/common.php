<?php
/**
 * Upgrade slice helpers. Requires the WHMCS harness with the module selected
 * by MODULE_DIR (old build or new build - never both in one process, the
 * module's functions are global).
 */
declare(strict_types=1);

putenv('HARNESS_DB=' . (getenv('HARNESS_DB') ?: 'whmcs7'));
putenv('PANEL_PORT=' . (getenv('PANEL_PORT') ?: '8087'));
putenv('PANEL_DB=' . (getenv('PANEL_DB') ?: 'dbispconfig7'));
if (!getenv('MODULE_DIR')) {
    fwrite(STDERR, "MODULE_DIR must be set explicitly for the upgrade slice\n");
    exit(2);
}

require '/opt/whmcs-harness/harness.php';

use WHMCS\Database\Capsule;

const UP_STATE = '/opt/livetest/upgrade/state.json';
const UP_REPO = '/home/user/ispconfig-connect-whmcs';

function up_build(): string
{
    return str_starts_with((string) getenv('MODULE_DIR'), '/opt/livetest/upgrade/old') ? 'OLD(1ed05b3)' : 'NEW(repo HEAD)';
}

/** @return array<string, mixed> */
function up_state(): array
{
    return is_file(UP_STATE) ? (json_decode((string) file_get_contents(UP_STATE), true) ?: []) : [];
}

function up_state_save(array $state): void
{
    file_put_contents(UP_STATE, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

function up_h(string $title): void
{
    echo "\n==================== ", $title, " [", up_build(), "] ====================\n";
}

function up_p(string $label, mixed $data = null): void
{
    if ($data === null) {
        echo $label, "\n";
        return;
    }
    echo $label, ": ", is_string($data) ? $data : json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), "\n";
}

/** Module-log rows since $mark, compacted: action + request/response excerpts. */
function up_log(int $mark, int $cut = 400): array
{
    $out = [];
    foreach (harness_log_since($mark) as $row) {
        $out[] = sprintf('#%d %s | req=%s | resp=%s', $row->id, $row->action, mb_substr((string) $row->request, 0, $cut), mb_substr((string) $row->response, 0, $cut));
    }

    return $out;
}

/** API function names called since $mark (RestClient logs the function name as the action). */
function up_actions(int $mark): array
{
    return array_map(static fn ($r) => (string) $r->action, harness_log_since($mark));
}

function up_datalog_max(): int
{
    return (int) (harness_panel_rows('SELECT COALESCE(MAX(datalog_id),0) m FROM sys_datalog')[0]['m'] ?? 0);
}

function up_datalog_since(int $mark): array
{
    return harness_panel_rows("SELECT datalog_id, dbtable, dbidx, action FROM sys_datalog WHERE datalog_id > $mark ORDER BY datalog_id");
}

/**
 * Call a module function the way WHMCS would, print the result, the module-log
 * rows it wrote and the datalog rows it queued.
 */
function up_call(string $fn, int $svc, array $extra = [], string $note = ''): array
{
    $mark = harness_log_mark();
    $dl = up_datalog_max();
    $before = (string) Capsule::table('tblhosting')->where('id', $svc)->value('domainstatus');
    $result = harness_call($fn, $svc, $extra);
    $after = (string) Capsule::table('tblhosting')->where('id', $svc)->value('domainstatus');
    $log = up_log($mark);
    $actions = up_actions($mark);
    echo "\n--> $fn(svc #$svc)", $note !== '' ? " [$note]" : '', "\n";
    echo "    result: ", is_string($result) ? $result : json_encode($result, JSON_UNESCAPED_SLASHES), "\n";
    echo "    WHMCS status: $before -> $after\n";
    echo "    module log (", count($log), " rows):\n";
    foreach ($log as $l) {
        echo "      ", $l, "\n";
    }
    $dlRows = up_datalog_since($dl);
    echo "    sys_datalog since call: ", json_encode(array_map(static fn ($r) => $r['dbtable'] . '#' . $r['dbidx'] . ':' . $r['action'], $dlRows)), "\n";

    return ['result' => $result, 'log' => $log, 'actions' => $actions, 'datalog' => $dlRows, 'status' => $after];
}

/** Every custom-field value row of a service, across products. */
function up_props(int $svc): array
{
    return array_map(static fn ($r) => (array) $r, Capsule::table('tblcustomfieldsvalues as v')
        ->join('tblcustomfields as f', 'f.id', '=', 'v.fieldid')
        ->where('v.relid', $svc)->where('f.type', 'product')
        ->orderBy('v.id')
        ->get(['v.id as vid', 'f.relid as product', 'f.fieldname', 'v.value'])->all());
}

function up_props_brief(int $svc): string
{
    $pkg = (int) Capsule::table('tblhosting')->where('id', $svc)->value('packageid');
    $parts = [];
    foreach (up_props($svc) as $r) {
        $parts[] = sprintf('P%d%s.%s=%s', $r['product'], $r['product'] === $pkg ? '*' : '', str_replace('ispconfig_', '', $r['fieldname']), $r['value'] === '' ? "''" : $r['value']);
    }

    return implode(' ', $parts);
}

function up_show_props(int $svc, string $label = ''): void
{
    echo "    props svc #$svc", $label !== '' ? " ($label)" : '', " [*=current product]: ", up_props_brief($svc), "\n";
}

function up_group_of(int $clientId): int
{
    return (int) (harness_panel_rows("SELECT groupid FROM sys_group WHERE client_id = $clientId")[0]['groupid'] ?? 0);
}

/** The panel client row plus the active flags of every object it owns. */
function up_client(int $clientId): array
{
    $c = harness_panel_rows("SELECT client_id, username, locked, template_master, limit_web_domain, tmp_data FROM client WHERE client_id = $clientId")[0] ?? null;
    if ($c === null) {
        return ['client_id' => $clientId, 'exists' => false];
    }
    $g = up_group_of($clientId);
    $c['prev_active'] = null;
    if ((string) $c['tmp_data'] !== '') {
        $t = @unserialize((string) $c['tmp_data']);
        $c['prev_active'] = is_array($t) ? ($t['prev_active'] ?? $t) : 'unparseable';
    }
    unset($c['tmp_data']);
    $c['web'] = harness_panel_rows("SELECT domain_id, domain, active FROM web_domain WHERE sys_groupid = $g AND type='vhost'");
    $c['ftp'] = harness_panel_rows("SELECT ftp_user_id, active FROM ftp_user WHERE sys_groupid = $g");
    $c['mail'] = harness_panel_rows("SELECT domain_id, domain, active FROM mail_domain WHERE sys_groupid = $g");
    $c['dns'] = harness_panel_rows("SELECT id, origin, active FROM dns_soa WHERE sys_groupid = $g");
    $c['db'] = harness_panel_rows("SELECT database_id, active FROM web_database WHERE sys_groupid = $g");

    return $c;
}

function up_client_brief(int $clientId): string
{
    $c = up_client($clientId);
    if (($c['exists'] ?? true) === false) {
        return "client #$clientId DOES NOT EXIST";
    }
    $flags = [];
    foreach (['web', 'ftp', 'mail', 'dns', 'db'] as $k) {
        $flags[] = $k . '=' . implode(',', array_map(static fn ($r) => (string) $r['active'], $c[$k]));
    }
    $prev = $c['prev_active'] === null ? 'none' : json_encode($c['prev_active']);

    return sprintf('client #%d %s locked=%s tmpl=%s [%s] prev_active=%s', $clientId, $c['username'], $c['locked'], $c['template_master'], implode(' ', $flags), $prev);
}

function up_show_client(int $clientId, string $label = ''): void
{
    echo "    panel ", $label !== '' ? "($label) " : '', up_client_brief($clientId), "\n";
}

function up_web_active(int $domainId): string
{
    return (string) (harness_panel_rows("SELECT active FROM web_domain WHERE domain_id = $domainId")[0]['active'] ?? 'missing');
}

function up_set_status(int $svc, string $status): void
{
    Capsule::table('tblhosting')->where('id', $svc)->update(['domainstatus' => $status]);
}

function up_set_option(int $product, int $n, string $value): void
{
    Capsule::table('tblproducts')->where('id', $product)->update(['configoption' . $n => $value]);
}

/** Run SQL through the mariadb CLI (real MySQL, as an admin would) and return its tabular output. */
function up_sql(string $db, string $sql): string
{
    $file = tempnam('/opt/livetest/upgrade', 'sql');
    file_put_contents($file, $sql);
    $out = shell_exec('mariadb --table ' . escapeshellarg($db) . ' < ' . escapeshellarg($file) . ' 2>&1');
    unlink($file);

    return (string) $out;
}

function up_show_sql(string $label, string $db, string $sql): string
{
    $out = up_sql($db, $sql);
    echo "\n[SQL $label on $db]\n", trim($sql), "\n--- result ---\n", $out === '' ? "(empty set)\n" : $out;

    return $out;
}
