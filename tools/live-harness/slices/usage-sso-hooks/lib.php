<?php

/**
 * Shared helpers for the "usage-sso-hooks" slice (plan steps 3, 19, 20, 21, 22, 24).
 * Instance: panel 6 on 127.0.0.1:8086 (dbispconfig6), WHMCS tables in whmcs6.
 * Requires the harness unchanged; everything extra lives here.
 */

declare(strict_types=1);

putenv('HARNESS_DB=whmcs6');
putenv('PANEL_PORT=8086');
putenv('PANEL_DB=dbispconfig6');

require_once '/opt/whmcs-harness/harness.php';

const LT_DIR = '/opt/livetest/usage-sso-hooks';
const LT_STATE = LT_DIR . '/state.json';
const LT_PANEL_DIR = '/opt/ispc/p6';
const LT_REQLOG = LT_PANEL_DIR . '/server.log';

$GLOBALS['lt_results'] = [];

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

function dump(string $label, mixed $value): void
{
    out($label . ': ' . (is_string($value) ? $value : json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR)));
}

/** Record a check. */
function check(string $id, bool $ok, string $what, mixed $evidence = null): bool
{
    $GLOBALS['lt_results'][] = ['id' => $id, 'ok' => $ok, 'what' => $what] + ($evidence === null ? [] : ['evidence' => $evidence]);
    out(($ok ? 'PASS ' : 'FAIL ') . "[$id] $what" . ($ok || $evidence === null ? '' : ' -- evidence: ' . json_encode($evidence, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR)));

    return $ok;
}

/** The PHP CLI this run uses, for subprocesses (LT_PHP=/opt/livetest/usage-sso-hooks/php82.sh for 8.2). */
function lt_php(): string
{
    return getenv('LT_PHP') ?: '/usr/bin/php8.3';
}

function lt_finish(string $name): void
{
    $r = $GLOBALS['lt_results'];
    $fail = count(array_filter($r, fn ($x) => !$x['ok']));
    $name .= '-php' . PHP_MAJOR_VERSION . PHP_MINOR_VERSION;
    file_put_contents(LT_DIR . "/evidence-$name.json", json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR));
    out();
    out(sprintf('SUMMARY %s: %d checks, %d failed', $name, count($r), $fail));
}

/** A writable (root) connection to the panel DB for seeding. */
function lt_paneldb(): mysqli
{
    static $db = null;
    if ($db === null) {
        $db = new mysqli('localhost', 'root', '', harness_panel_db(), 0, '/run/mysqld/mysqld.sock');
        $db->set_charset('utf8mb4');
    }

    return $db;
}

function pq(string $sql, array $params = []): array
{
    $st = lt_paneldb()->prepare($sql);
    if ($params !== []) {
        $st->bind_param(str_repeat('s', count($params)), ...array_map('strval', $params));
    }
    $st->execute();
    $r = $st->get_result();

    return $r === false ? [] : $r->fetch_all(MYSQLI_ASSOC);
}

/** Byte offset into the php -S request log of panel 6. */
function req_mark(): int
{
    clearstatcache(true, LT_REQLOG);

    return is_file(LT_REQLOG) ? (int) filesize(LT_REQLOG) : 0;
}

/** HTTP request lines the panel logged since $mark. */
function req_since(int $mark): array
{
    clearstatcache(true, LT_REQLOG);
    $fh = fopen(LT_REQLOG, 'rb');
    fseek($fh, $mark);
    $text = (string) stream_get_contents($fh);
    fclose($fh);
    $out = [];
    foreach (explode("\n", $text) as $line) {
        if (preg_match('/\[(\d{3})\]:? (\w+) (\S+)/', $line, $m)) {
            $out[] = "[{$m[1]}] {$m[2]} {$m[3]}";
        }
    }

    return $out;
}

function logins_in(array $reqs): int
{
    return count(array_filter($reqs, fn ($l) => str_ends_with($l, '?login')));
}

/** Module-log rows since $mark, compact. */
function mlog(int $mark, int $cut = 600): array
{
    $rows = [];
    foreach (harness_log_since($mark) as $r) {
        $rows[] = [
            'id' => (int) $r->id,
            'action' => $r->action,
            'request' => strlen($r->request) > $cut ? substr($r->request, 0, $cut) . '...[+' . (strlen($r->request) - $cut) . 'B]' : $r->request,
            'response' => strlen($r->response) > $cut ? substr($r->response, 0, $cut) . '...[+' . (strlen($r->response) - $cut) . 'B]' : $r->response,
        ];
    }

    return $rows;
}

function mlog_raw(int $mark): array
{
    return array_map(fn ($r) => (array) $r, harness_log_since($mark));
}

function mlog_actions(int $mark): array
{
    return array_map(fn ($r) => $r->action, harness_log_since($mark));
}

function print_mlog(int $mark, int $cut = 600): void
{
    foreach (mlog($mark, $cut) as $r) {
        out(sprintf('  log #%d %-28s req=%s', $r['id'], $r['action'], $r['request']));
        out(sprintf('  %s  resp=%s', str_repeat(' ', 33), $r['response']));
    }
}

/** Exactly what WHMCS's configservers.php stores after GetRemoteMetaData: flat JSON in tblservers_remote. */
function store_remote_meta(int $serverId, array $meta): void
{
    $now = date('Y-m-d H:i:s');
    $exists = \WHMCS\Database\Capsule::table('tblservers_remote')->where('server_id', $serverId)->exists();
    if ($exists) {
        // num_accounts is reset on every metadata refresh (docs/IMPLEMENTATION-NOTES.md); keep 0.
        \WHMCS\Database\Capsule::table('tblservers_remote')->where('server_id', $serverId)
            ->update(['meta_data' => json_encode($meta), 'updated_at' => $now, 'num_accounts' => 0]);
    } else {
        \WHMCS\Database\Capsule::table('tblservers_remote')->insert([
            'server_id' => $serverId, 'num_accounts' => 0, 'meta_data' => json_encode($meta),
            'created_at' => $now, 'updated_at' => $now,
        ]);
    }
}

/** "Open the Servers page once": GetRemoteMetaData per server, stored as WHMCS stores it. */
function open_servers_page(array $serverIds): array
{
    $metas = [];
    foreach ($serverIds as $sid) {
        $metas[$sid] = ispconfigconnect_GetRemoteMetaData(harness_server_params($sid));
        store_remote_meta($sid, $metas[$sid]);
    }

    return $metas;
}

function svc(int $sid): array
{
    return (array) \WHMCS\Database\Capsule::table('tblhosting')->where('id', $sid)
        ->first(['id', 'userid', 'packageid', 'server', 'domain', 'username', 'domainstatus', 'diskusage', 'disklimit', 'bwusage', 'bwlimit', 'lastupdate']);
}

function set_svc(int $sid, array $cols): void
{
    \WHMCS\Database\Capsule::table('tblhosting')->where('id', $sid)->update($cols);
}

function props(int $sid): array
{
    $p = ispconfigconnect_serviceProperties(harness_params($sid));

    return [
        'client_id' => $p->clientId(),
        'client_username' => $p->clientUsername(),
        'domain_id' => $p->domainId(),
        'suspended_as' => $p->suspendedAs(),
    ];
}

/** A raw remote-API session to the panel for admin-side actions (the panel UI's equivalent). */
function panel_api(): \WHMCS\Module\Server\Ispconfigconnect\Api\SessionManager
{
    return \WHMCS\Module\Server\Ispconfigconnect\Support\Factory::sessionManager(harness_server_params(1));
}

function panel_client(int $clientId): ?array
{
    return harness_panel_rows("SELECT client_id, username, locked, template_master FROM client WHERE client_id = $clientId")[0] ?? null;
}

function panel_group(int $clientId): int
{
    return (int) (harness_panel_rows("SELECT groupid FROM sys_group WHERE client_id = $clientId")[0]['groupid'] ?? 0);
}
