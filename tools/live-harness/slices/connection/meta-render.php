<?php

/**
 * GetRemoteMetaData + RenderRemoteMetaData for the good server and the bad ones.
 *
 * WHMCS (decoded configservers.php, 8.1.2 - see harness_notes) stores GetRemoteMetaData's array as JSON
 * in tblservers_remote.meta_data and, per server row, renders <div class="remote-meta-data"> ONLY when
 * the module defines <module>_RenderRemoteMetaData(['remoteData' => ServerRemote]). This module does not
 * define it; its block comes from hooks/ispconfigconnect.php (AdminAreaFooterOutput). Both halves are
 * exercised here: function_exists() for the core path, and the hook's real output for the module path.
 */

declare(strict_types=1);

require __DIR__ . '/common.php';
require_once '/home/user/ispconfig-connect-whmcs/modules/servers/ispconfigconnect/hooks/ispconfigconnect.php';

$test = 1;
$neg = 2;
$results = [];
lt_set_grants('whmcs', LT_NINE);
lt_clear_attempts();
\WHMCS\Database\Capsule::table('tblservers_remote')->delete();

lt_out('core path: ispconfigconnect_RenderRemoteMetaData defined?', function_exists('ispconfigconnect_RenderRemoteMetaData') ? 'yes' : 'no');
lt_assert(!function_exists('ispconfigconnect_RenderRemoteMetaData'), 'INFO module defines no RenderRemoteMetaData: WHMCS core renders no remote-meta-data div for it', $results);

$scenarios = [
    'good' => [$test, [], fn () => null],
    'good-minus-Server-functions' => [$test, [], fn () => lt_set_grants('whmcs', array_values(array_diff(LT_NINE, ['Server functions'])))],
    'good-minus-Monitor-functions' => [$test, [], fn () => lt_set_grants('whmcs', array_values(array_diff(LT_NINE, ['Monitor functions'])))],
    'bad-password' => [$neg, ['password' => 'Wrong-Password-XYZ'], fn () => lt_set_grants('whmcs', LT_NINE)],
    'bad-hostname' => [$neg, ['hostname' => 'https://panel.test.example', 'password' => LT_NEG_PASS], fn () => null],
    'redirect-302' => [$neg, ['hostname' => '127.0.0.2', 'ipaddress' => '127.0.0.2', 'port' => 80], fn () => file_put_contents(LT_DIR . '/srv/redirect-mode.txt', '302')],
    'html-200' => [$neg, ['hostname' => '127.0.0.3', 'ipaddress' => '127.0.0.3', 'port' => 80], fn () => file_put_contents(LT_DIR . '/srv/html-mode.txt', 'html')],
];

foreach ($scenarios as $name => [$sid, $serverCols, $prep]) {
    $prep();
    if ($serverCols !== []) {
        lt_update_server($sid, $serverCols);
    }
    lt_clear_attempts();
    $reqMark = lt_mark_file(LT_REQLOG);
    $logMark = harness_log_mark();
    $meta = ispconfigconnect_GetRemoteMetaData(harness_server_params($sid));
    $reqs = lt_requests_since(LT_REQLOG, $reqMark);
    lt_store_remote_meta($sid, $meta);

    $_SERVER['REQUEST_URI'] = '/admin/configservers.php';
    $_GET = [];
    $html = ispconfigconnect_metaPanelFooterOutput(['filename' => 'configservers']);
    preg_match('~<script type="application/json" id="ispconfigconnect-server-meta">(.*?)</script>~s', $html, $m);
    $json = $m[1] ?? '';
    $block = null;
    foreach (json_decode($json, true) ?: [] as $entry) {
        if ($entry['id'] === $sid) {
            $block = $entry;
        }
    }
    lt_out("[$name] GetRemoteMetaData", ['meta' => $meta, 'panel_requests' => $reqs, 'modulelog' => array_map(fn ($r) => $r['action'] . ' => ' . substr($r['response'], 0, 90), lt_log($logMark)), 'attempts_login' => lt_attempts()]);
    lt_out("[$name] stored tblservers_remote.meta_data", \WHMCS\Database\Capsule::table('tblservers_remote')->where('server_id', $sid)->value('meta_data'));
    lt_out("[$name] hook block (what the admin sees)", $block);

    lt_assert(is_array($meta) && array_is_list($meta) === false && count(array_filter($meta, fn ($v) => !is_scalar($v))) === 0, "meta [$name]: flat scalar array (never throws)", $results, $meta);
    lt_assert(!str_contains($json, '<') && !str_contains($json, '>'), "meta [$name]: hook payload carries no raw < or > (HTML from the panel is hex-escaped)", $results);
    $logins = count(array_filter($reqs, fn ($l) => str_ends_with($l, '?login')));
    lt_assert($logins <= 1, "meta [$name]: at most one login on panel 2 ($logins)", $results, $reqs);

    if ($name === 'good') {
        $keys = array_keys($meta);
        sort($keys);
        lt_assert($keys === ['checked_at', 'client_count', 'ispconfig_status', 'ispconfig_version', 'pending_jobs', 'server_count', 'service_count', 'sso_available', 'sso_state'], 'meta [good]: expected key set', $results, $keys);
        lt_assert($meta['ispconfig_status'] === 'ok' && $meta['sso_state'] === 'absent' && $meta['sso_available'] === false, 'meta [good]: status ok, SSO absent', $results, $meta);
        $actions = array_map(fn ($r) => $r['action'], lt_log($logMark));
        lt_assert($actions === ['login', 'server_get_app_version', 'monitor_jobqueue_count', 'client_get_all', 'server_get_all', 'get_function_list', 'logout'], 'meta [good]: one session: login, 4 probes, get_function_list, logout', $results, $actions);
        $labels = array_map(fn ($l) => $l['label'], $block['lines'] ?? []);
        lt_assert($labels === ['ISPConfig Version', 'Pending Jobs', 'Clients', 'ISPConfig Nodes', 'Single Sign-On', 'Last Updated'], 'meta [good]: rendered lines', $results, $labels);
    }
    if ($name === 'good-minus-Server-functions') {
        $labels = array_map(fn ($l) => $l['label'] . '=' . $l['value'], $block['lines'] ?? []);
        lt_assert(($meta['ispconfig_status'] ?? '') === 'degraded' && !isset($meta['ispconfig_version']) && !isset($meta['server_count']) && isset($meta['client_count']),
            'meta [minus Server]: degraded, version + nodes lines dropped, others kept', $results, $labels);
    }
    if ($name === 'good-minus-Monitor-functions') {
        lt_assert(($meta['ispconfig_status'] ?? '') === 'degraded' && !isset($meta['pending_jobs']) && isset($meta['ispconfig_version']), 'meta [minus Monitor]: pending-jobs line dropped only', $results, $meta);
    }
    if (in_array($name, ['bad-password', 'redirect-302', 'html-200'], true)) {
        $ssoLine = null;
        foreach ($block['lines'] ?? [] as $l) {
            if ($l['label'] === 'Single Sign-On') {
                $ssoLine = $l['value'];
            }
        }
        lt_out("[$name] Single Sign-On line", $ssoLine);
        lt_assert($ssoLine === null || !str_contains($ssoLine, 'get_function_list'),
            "meta [$name]: the SSO line does not tell the admin to grant get_function_list when the real fault is " . ($name === 'bad-password' ? 'the login' : 'the transport'), $results, $ssoLine);
    }
}

// MetaData() (WHMCS calls it with no server) reads the cache: no SSO label because the only good panel says absent.
lt_set_grants('whmcs', LT_NINE);
lt_update_server($neg, ['hostname' => '127.0.0.1', 'ipaddress' => '127.0.0.1', 'port' => 8082, 'password' => LT_NEG_PASS]);
$md = ispconfigconnect_MetaData();
lt_out('MetaData()', $md);
lt_assert(!isset($md['ServiceSingleSignOnLabel']), 'MetaData(): no SSO label while no panel reports the add-on', $results, $md);

lt_clear_attempts();
lt_out('summary', ['pass' => count(array_filter($results, fn ($r) => $r['ok'])), 'fail' => count(array_filter($results, fn ($r) => !$r['ok']))]);
lt_save('meta-render');
