<?php
// Shared helpers for the "lifecycle" slice (plan steps 9-16b).
// Requires the harness unchanged; everything extra lives here.
declare(strict_types=1);

putenv('HARNESS_DB=whmcs3');
putenv('PANEL_PORT=8083');
putenv('PANEL_DB=dbispconfig3');

require '/opt/whmcs-harness/harness.php';

const LT_DIR = '/opt/livetest/lifecycle';
const LT_STATE = LT_DIR . '/state.json';

function lt_state(): array
{
    return is_file(LT_STATE) ? json_decode((string) file_get_contents(LT_STATE), true) : [];
}

function lt_save_state(array $s): void
{
    file_put_contents(LT_STATE, json_encode($s, JSON_PRETTY_PRINT));
}

function out(string $line = ''): void
{
    echo $line, "\n";
}

function hr(string $title): void
{
    out();
    out('==== ' . $title . ' ' . str_repeat('=', max(0, 70 - strlen($title))));
}

/** Panel-side datalog high-water mark. */
function dl_mark(): int
{
    return (int) (harness_panel_rows('SELECT COALESCE(MAX(datalog_id),0) m FROM sys_datalog')[0]['m']);
}

function dl_since(int $mark): array
{
    return harness_panel_rows("SELECT datalog_id, dbtable, dbidx, action FROM sys_datalog WHERE datalog_id > $mark ORDER BY datalog_id");
}

/** Every ispconfig_* custom-field value row for a service, on every product. */
function props_rows(int $sid): array
{
    return \WHMCS\Database\Capsule::table('tblcustomfieldsvalues as v')
        ->join('tblcustomfields as f', 'f.id', '=', 'v.fieldid')
        ->where('f.type', 'product')->where('v.relid', $sid)->where('f.fieldname', 'like', 'ispconfig%')
        ->orderBy('f.relid')->orderBy('f.fieldname')
        ->get(['v.id as vid', 'f.id as fid', 'f.relid as product', 'f.fieldname', 'f.adminonly', 'v.value'])
        ->map(fn ($r) => (array) $r)->all();
}

/** The values the module itself would read (current product first, then fallback). */
function props_effective(int $sid): array
{
    $props = ispconfigconnect_serviceProperties(harness_params($sid));
    return [
        'client_id' => $props->clientId(),
        'client_username' => $props->clientUsername(),
        'domain_id' => $props->domainId(),
        'ftp_user_id' => $props->ftpUserId(),
        'mail_domain_id' => $props->mailDomainId(),
        'dns_zone_id' => $props->dnsZoneId(),
        'database_id' => $props->databaseId(),
        'database_user_id' => $props->databaseUserId(),
        'suspended_as' => $props->suspendedAs(),
    ];
}

/** Raw value of one key in the CURRENT product's row (what WHMCS's admin page shows). */
function prop_current(int $sid, string $key): ?string
{
    $pid = (int) \WHMCS\Database\Capsule::table('tblhosting')->where('id', $sid)->value('packageid');
    $v = \WHMCS\Database\Capsule::table('tblcustomfieldsvalues as v')
        ->join('tblcustomfields as f', 'f.id', '=', 'v.fieldid')
        ->where('f.type', 'product')->where('f.relid', $pid)->where('v.relid', $sid)->where('f.fieldname', $key)
        ->value('v.value');
    return $v === null ? null : (string) $v;
}

function print_props(int $sid): void
{
    out("  WHMCS custom-field rows for service #$sid:");
    foreach (props_rows($sid) as $r) {
        out(sprintf('    product %d  %-28s = %-10s (field %d, adminonly=%s, vid %d)', $r['product'], $r['fieldname'], var_export($r['value'], true), $r['fid'], $r['adminonly'], $r['vid']));
    }
    out('  module-effective: ' . json_encode(props_effective($sid)));
}

function svc(int $sid): array
{
    return (array) \WHMCS\Database\Capsule::table('tblhosting')->where('id', $sid)->first(['id', 'userid', 'packageid', 'domain', 'username', 'domainstatus']);
}

/** Panel snapshot of one client and everything it owns. */
function panel_snap(int $clientId): array
{
    $c = harness_panel_rows("SELECT client_id, username, locked, canceled, template_master, template_additional, limit_web_domain, limit_web_quota, limit_ftp_user, limit_maildomain, limit_mailbox, limit_database, notes, tmp_data FROM client WHERE client_id = $clientId")[0] ?? null;
    if ($c === null) {
        return ['client' => null];
    }
    $g = (int) (harness_panel_rows("SELECT groupid FROM sys_group WHERE client_id = $clientId")[0]['groupid'] ?? 0);
    $tmp = $c['tmp_data'] === '' || $c['tmp_data'] === null ? null : @unserialize((string) $c['tmp_data']);
    unset($c['tmp_data']);
    $c['tmp_data'] = $tmp;
    return [
        'client' => $c,
        'group' => $g,
        'web_domain' => harness_panel_rows("SELECT domain_id, domain, type, active FROM web_domain WHERE sys_groupid = $g ORDER BY domain_id"),
        'ftp_user' => harness_panel_rows("SELECT ftp_user_id, username, active FROM ftp_user WHERE sys_groupid = $g ORDER BY ftp_user_id"),
        'mail_domain' => harness_panel_rows("SELECT domain_id, domain, active FROM mail_domain WHERE sys_groupid = $g ORDER BY domain_id"),
        'mail_user' => harness_panel_rows("SELECT mailuser_id, email, postfix, disablesmtp FROM mail_user WHERE sys_groupid = $g ORDER BY mailuser_id"),
        'web_database' => harness_panel_rows("SELECT database_id, database_name, active FROM web_database WHERE sys_groupid = $g ORDER BY database_id"),
        'dns_soa' => harness_panel_rows("SELECT id, origin, active FROM dns_soa WHERE sys_groupid = $g ORDER BY id"),
    ];
}

function print_snap(int $clientId, string $label = ''): array
{
    $s = panel_snap($clientId);
    out("  PANEL client #$clientId $label");
    if ($s['client'] === null) {
        out('    (no such client)');
        return $s;
    }
    $c = $s['client'];
    out(sprintf('    client: locked=%s canceled=%s template_master=%s template_additional=%s limits(web_domain=%s web_quota=%s ftp=%s maildomain=%s mailbox=%s db=%s)',
        $c['locked'], $c['canceled'], $c['template_master'], var_export($c['template_additional'], true), $c['limit_web_domain'], $c['limit_web_quota'], $c['limit_ftp_user'], $c['limit_maildomain'], $c['limit_mailbox'], $c['limit_database']));
    out('    client.tmp_data: ' . json_encode($c['tmp_data']));
    foreach (['web_domain', 'ftp_user', 'mail_domain', 'mail_user', 'web_database', 'dns_soa'] as $t) {
        $parts = [];
        foreach ($s[$t] as $r) {
            $parts[] = json_encode($r, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        out(sprintf('    %-12s %s', $t . ':', $parts === [] ? '(none)' : implode(' ', $parts)));
    }
    return $s;
}

/** The module-log rows since $mark, one line each. */
function print_log(int $mark, int $width = 400): array
{
    $rows = harness_log_since($mark);
    out('  tblmodulelog rows since #' . $mark . ':');
    if ($rows === []) {
        out('    (none)');
    }
    foreach ($rows as $r) {
        out(sprintf('    #%d %-32s req=%s', $r->id, $r->action, mb_substr((string) $r->request, 0, $width)));
        out(sprintf('        %-32s resp=%s', '', mb_substr((string) $r->response, 0, $width)));
    }
    return $rows;
}

/** Action names of the log rows since $mark. */
function log_actions(array $rows): array
{
    return array_map(fn ($r) => (string) $r->action, $rows);
}

/** Call a lifecycle function like WHMCS; print result + log. */
function lt_call(string $fn, int $sid, array $extra = []): array
{
    $mark = harness_log_mark();
    $dl = dl_mark();
    $before = svc($sid)['domainstatus'];
    $result = harness_call($fn, $sid, $extra);
    $after = svc($sid)['domainstatus'];
    out(sprintf('  %s(#%d) => %s', $fn, $sid, is_string($result) ? $result : json_encode($result)));
    out("  WHMCS status: $before -> $after");
    $rows = print_log($mark);
    $d = dl_since($dl);
    out('  panel datalog since mark ' . $dl . ': ' . ($d === [] ? '(none)' : implode(', ', array_map(fn ($x) => $x['dbtable'] . '#' . $x['dbidx'] . ':' . $x['action'], $d))));
    return ['result' => $result, 'log' => $rows, 'actions' => log_actions($rows), 'datalog' => $d];
}

/** A direct JSON call to the panel's remote API (the "by hand in ISPConfig" admin actions). */
function panel_api(string $method, array $args)
{
    static $sid = null;
    $post = static function (string $m, array $a) {
        $ch = curl_init('http://127.0.0.1:8083/remote/json.php?' . $m);
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($a), CURLOPT_RETURNTRANSFER => true]);
        $r = json_decode((string) curl_exec($ch), true);
        return $r;
    };
    if ($sid === null) {
        $sid = $post('login', ['username' => 'whmcs', 'password' => 'Whmcs-Remote-Pass-1'])['response'];
    }
    return $post($method, ['session_id' => $sid] + $args);
}

/** Direct SQL on the panel DB (admin "by hand" edit that does not go through a form). */
function panel_sql(string $sql): void
{
    harness_panel()->query($sql);
}

function assert_line(string $step, bool $ok, string $what): bool
{
    out(sprintf('  [%s] %s: %s', $ok ? 'PASS' : 'FAIL', $step, $what));
    file_put_contents(LT_DIR . '/assertions.log', sprintf("%s\t%s\t%s\n", $ok ? 'PASS' : 'FAIL', $step, $what), FILE_APPEND);
    return $ok;
}

/** true when no module-log row since $mark contains $needle. */
function log_free_of(array $rows, string $needle): bool
{
    foreach ($rows as $r) {
        foreach (['request', 'response', 'arrdata'] as $k) {
            if (str_contains((string) $r->$k, $needle)) {
                return false;
            }
        }
    }
    return true;
}

function actives(array $snap, string $table, string $col = 'active'): array
{
    return array_map(fn ($r) => $r[$col], $snap[$table] ?? []);
}

function panel_admin(string $args): void
{
    out('  [by hand in ISPConfig] ' . trim((string) shell_exec('/usr/bin/php8.3 ' . LT_DIR . '/panel_admin.php ' . $args . ' 2>&1')));
}

/** Non-empty prev_active entries as table => ids; null when there is no prev_active key. */
function prev_active_entries(?array $tmp): ?array
{
    if (!is_array($tmp) || !array_key_exists('prev_active', $tmp)) {
        return null;
    }
    $out = [];
    foreach ($tmp['prev_active'] as $table => $rows) {
        if ($rows !== []) {
            $out[$table] = $rows;
        }
    }
    return $out;
}

/** Decoded requests of the log rows with this action. */
function req_of(array $rows, string $action): array
{
    return array_values(array_map(fn ($r) => json_decode((string) $r->request, true), array_filter($rows, fn ($r) => $r->action === $action)));
}

/** The API calls in a log slice, without login/logout. */
function api_calls(array $r): array
{
    return array_values(array_filter($r['actions'], fn ($a) => !in_array($a, ['login', 'logout'], true)));
}

function susp_as(int $sid): array
{
    return ['current_row' => prop_current($sid, 'ispconfig_suspended_as'), 'effective' => props_effective($sid)['suspended_as']];
}
