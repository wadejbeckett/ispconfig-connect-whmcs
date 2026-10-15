<?php

/**
 * Shared helpers for the "connection" slice (plan steps 4-8).
 * Instance: panel 2 on 127.0.0.1:8082 (dbispconfig2), WHMCS tables in whmcs2.
 */

declare(strict_types=1);

foreach (['HARNESS_DB' => 'whmcs2', 'PANEL_PORT' => '8082', 'PANEL_DB' => 'dbispconfig2'] as $k => $v) {
    if (getenv($k) === false || getenv($k) === '') {
        putenv("$k=$v");
    }
}

require_once '/opt/whmcs-harness/harness.php';

const LT_DIR = '/opt/livetest/connection';
const LT_PANEL_DIR = '/opt/ispc/p2';
const LT_REQLOG = LT_PANEL_DIR . '/server.log';
const LT_NEG_USER = 'negative';
const LT_NEG_PASS = 'Negative-Pass-1';

/** A writable (root) connection to the panel DB for setup/teardown of grants and attempts_login. */
function lt_paneldb(): mysqli
{
    static $db = null;
    if ($db === null) {
        $db = new mysqli('localhost', 'root', '', harness_panel_db(), 0, '/run/mysqld/mysqld.sock');
        $db->set_charset('utf8mb4');
    }

    return $db;
}

function lt_q(string $sql): array
{
    $r = lt_paneldb()->query($sql);

    return $r === true ? [] : $r->fetch_all(MYSQLI_ASSOC);
}

/** Byte offset into a php -S request log. */
function lt_mark_file(string $file): int
{
    clearstatcache(true, $file);

    return is_file($file) ? (int) filesize($file) : 0;
}

/** The HTTP request lines ("[200]: POST /remote/json.php?login") a php -S server logged since $mark. */
function lt_requests_since(string $file, int $mark): array
{
    clearstatcache(true, $file);
    $fh = fopen($file, 'rb');
    fseek($fh, $mark);
    $text = stream_get_contents($fh);
    fclose($fh);
    $out = [];
    foreach (explode("\n", (string) $text) as $line) {
        if (preg_match('/\[(\d{3})\]:? (\w+) (\S+)/', $line, $m)) {
            $out[] = "[{$m[1]}] {$m[2]} {$m[3]}";
        }
    }

    return $out;
}

function lt_attempts(): array
{
    return lt_q('SELECT ip, times, login_time FROM attempts_login');
}

function lt_clear_attempts(): void
{
    lt_q('DELETE FROM attempts_login');
}

/** The remote function GROUPS exactly as the ISPConfig remote-user form stores them (label => stored key). */
function lt_groups(): array
{
    static $groups = null;
    if ($groups === null) {
        $groups = [];
        foreach (glob(LT_PANEL_DIR . '/interface/web/*/lib/remote.conf.php') as $f) {
            $function_list = [];
            include $f;
            foreach ($function_list as $key => $label) {
                $groups[$label] = $key;
            }
        }
    }

    return $groups;
}

/** The nine groups docs/REMOTE-USER.md and plan prerequisite 5 prescribe. */
const LT_NINE = [
    'Server functions', 'Client functions', 'Quota functions', 'Sites Domain functions',
    'Sites FTP-User functions', 'Sites database functions', 'Mail domain functions',
    'DNS zone functions', 'Monitor functions',
];

function lt_functions_value(array $labels): string
{
    $g = lt_groups();
    $keys = [];
    foreach ($labels as $l) {
        if (!isset($g[$l])) {
            throw new RuntimeException("unknown group $l");
        }
        $keys[] = $g[$l];
    }

    return implode(';', $keys);
}

function lt_set_grants(string $user, array $labels): void
{
    $st = lt_paneldb()->prepare('UPDATE remote_user SET remote_functions = ? WHERE remote_username = ?');
    $v = lt_functions_value($labels);
    $st->bind_param('ss', $v, $user);
    $st->execute();
}

function lt_set_grants_raw(string $user, string $value): void
{
    $st = lt_paneldb()->prepare('UPDATE remote_user SET remote_functions = ? WHERE remote_username = ?');
    $st->bind_param('ss', $value, $user);
    $st->execute();
}

/** Module-log rows since $mark, compact. */
function lt_log(int $mark, int $cut = 400): array
{
    $rows = [];
    foreach (harness_log_since($mark) as $r) {
        $rows[] = [
            'id' => (int) $r->id,
            'action' => $r->action,
            'request' => strlen($r->request) > $cut ? substr($r->request, 0, $cut) . '...[+' . (strlen($r->request) - $cut) . 'B]' : $r->request,
            'response' => strlen($r->response) > $cut ? substr($r->response, 0, $cut) . '...[+' . (strlen($r->response) - $cut) . 'B]' : $r->response,
            'response_bytes' => strlen($r->response),
        ];
    }

    return $rows;
}

function lt_log_raw(int $mark): array
{
    return harness_log_since($mark);
}

function lt_update_server(int $id, array $cols): void
{
    \WHMCS\Database\Capsule::table('tblservers')->where('id', $id)->update($cols);
}

/** Pretty print + collect evidence. */
$GLOBALS['lt_evidence'] = [];
function lt_out(string $label, mixed $data): void
{
    $GLOBALS['lt_evidence'][] = [$label, $data];
    echo "### $label\n";
    echo is_string($data) ? $data : json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    echo "\n";
}

function lt_save(string $name): void
{
    $tag = (string) getenv('LT_TAG');
    file_put_contents(LT_DIR . "/evidence-$name" . ($tag !== '' ? "-$tag" : '') . ".json", json_encode($GLOBALS['lt_evidence'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

/** Exactly what WHMCS's configservers.php stores after GetRemoteMetaData: flat JSON in tblservers_remote. */
function lt_store_remote_meta(int $serverId, array $meta): void
{
    $now = date('Y-m-d H:i:s');
    $exists = \WHMCS\Database\Capsule::table('tblservers_remote')->where('server_id', $serverId)->exists();
    if ($exists) {
        \WHMCS\Database\Capsule::table('tblservers_remote')->where('server_id', $serverId)
            ->update(['meta_data' => json_encode($meta), 'updated_at' => $now]);
    } else {
        \WHMCS\Database\Capsule::table('tblservers_remote')->insert([
            'server_id' => $serverId, 'num_accounts' => 0, 'meta_data' => json_encode($meta),
            'created_at' => $now, 'updated_at' => $now,
        ]);
    }
}

function lt_assert(bool $cond, string $what, array &$results, mixed $evidence = null): bool
{
    $results[] = ['ok' => $cond, 'what' => $what] + ($evidence === null ? [] : ['evidence' => $evidence]);
    echo ($cond ? 'PASS ' : 'FAIL ') . $what . "\n";

    return $cond;
}
