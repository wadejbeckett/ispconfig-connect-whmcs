<?php

/**
 * Plan step 7: Failed login (ISPC-NEGATIVE).
 *  1. wrong password on ISPC-NEGATIVE; Test Connection once;
 *  2. wait 30 s; "load the Servers page once" = WHMCS calls GetRemoteMetaData per ISPConfig server and
 *     stores the flat array in tblservers_remote; the module's AdminAreaFooterOutput hook renders it.
 *     NEGATIVE is refreshed first, then TEST (the order is recorded; see the counter note).
 * Assert per action: exactly one login request on the wire, attempts_login.times +1, no second login,
 * no logout, NEGATIVE's block says failed login + remaining probes skipped; TEST unaffected.
 * Extras: Verify Permissions and Server Sync (ListAccounts) with the bad password (one failed login each);
 * lockout (times forced to 10): one request, lockout advice, ISPC-TEST also refused (same source IP).
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
lt_update_server($neg, ['hostname' => '127.0.0.1', 'ipaddress' => '127.0.0.1', 'port' => 8082, 'password' => 'Wrong-Password-XYZ']);

$timesOf = function (): int {
    $a = lt_attempts();

    return $a === [] ? 0 : (int) $a[0]['times'];
};

// ---- Action 1: Test Connection -------------------------------------------------------------
$reqMark = lt_mark_file(LT_REQLOG);
$logMark = harness_log_mark();
$t0 = $timesOf();
$tc = ispconfigconnect_TestConnection(harness_server_params($neg));
$reqs = lt_requests_since(LT_REQLOG, $reqMark);
$log = lt_log($logMark);
lt_out('A1 TestConnection (wrong password)', ['result' => $tc, 'requests' => $reqs, 'modulelog' => $log, 'attempts_login' => lt_attempts()]);
lt_assert($tc['success'] === false && str_starts_with($tc['error'], 'ISPConfig rejected the login. Check the Username and Password fields of this WHMCS server'),
    'step7 Test Connection: "ISPConfig rejected the login. Check the Username and Password fields ..."', $results, $tc);
lt_assert($reqs === ['[200] POST /remote/json.php?login'], 'step7 Test Connection: exactly one (failed) login request, nothing else', $results, $reqs);
lt_assert($timesOf() === $t0 + 1, "step7 Test Connection: attempts_login.times $t0 -> " . ($t0 + 1), $results, lt_attempts());
lt_assert(count($log) === 1 && $log[0]['action'] === 'login' && !str_contains(json_encode($log), 'Wrong-Password-XYZ'), 'step7 Test Connection: one masked login row in tblmodulelog', $results, $log);

echo "waiting 30 s as the plan says...\n";
sleep(30);

// ---- Action 2: the Servers page (NEGATIVE refreshed first) ------------------------------------
$reqMark = lt_mark_file(LT_REQLOG);
$logMark = harness_log_mark();
$t1 = $timesOf();
$metaNeg = ispconfigconnect_GetRemoteMetaData(harness_server_params($neg));
lt_store_remote_meta($neg, $metaNeg);
$reqsNeg = lt_requests_since(LT_REQLOG, $reqMark);
$logNeg = lt_log($logMark);
$afterNeg = lt_attempts();
lt_out('A2 GetRemoteMetaData ISPC-NEGATIVE', ['meta' => $metaNeg, 'requests' => $reqsNeg, 'modulelog' => $logNeg, 'attempts_login' => $afterNeg]);
lt_assert($reqsNeg === ['[200] POST /remote/json.php?login'], 'step7 Servers page: NEGATIVE made exactly one login request (no second login, no get_function_list, no logout)', $results, $reqsNeg);
lt_assert($timesOf() === $t1 + 1, "step7 Servers page: attempts_login.times $t1 -> " . ($t1 + 1), $results, $afterNeg);
lt_assert(($metaNeg['ispconfig_status'] ?? '') === 'unavailable'
    && str_contains((string) ($metaNeg['ispconfig_error'] ?? ''), 'The login failed. Username or password wrong.')
    && str_contains((string) ($metaNeg['ispconfig_error'] ?? ''), 'remaining probes skipped'),
    'step7 Servers page: NEGATIVE block reports the failed login with the remaining probes skipped', $results, $metaNeg);

$reqMark = lt_mark_file(LT_REQLOG);
$logMark = harness_log_mark();
$metaTest = ispconfigconnect_GetRemoteMetaData(harness_server_params($test));
lt_store_remote_meta($test, $metaTest);
$reqsTest = lt_requests_since(LT_REQLOG, $reqMark);
lt_out('A2 GetRemoteMetaData ISPC-TEST', ['meta' => $metaTest, 'requests' => $reqsTest, 'modulelog' => array_map(fn ($r) => $r['action'], lt_log($logMark)), 'attempts_login_after' => lt_attempts()]);
lt_assert(($metaTest['ispconfig_status'] ?? '') === 'ok' && !isset($metaTest['ispconfig_error']), 'step7 Servers page: ISPC-TEST unaffected (status ok)', $results, $metaTest);
lt_assert(count(array_filter($reqsTest, fn ($l) => str_ends_with($l, '?login'))) === 1, 'step7 Servers page: ISPC-TEST one successful login', $results, $reqsTest);
lt_out('NOTE counter after ISPC-TEST success', ['attempts_login' => lt_attempts(), 'why' => 'remoting.inc.php login(): DELETE FROM attempts_login WHERE ip=? after any successful login from the same IP']);

// What the admin sees: the module's AdminAreaFooterOutput block from the stored rows.
$_SERVER['REQUEST_URI'] = '/admin/configservers.php';
$html = ispconfigconnect_metaPanelFooterOutput(['filename' => 'configservers']);
preg_match('~<script type="application/json" id="ispconfigconnect-server-meta">(.*?)</script>~s', $html, $m);
$payload = json_decode($m[1] ?? 'null', true);
lt_out('A2 Servers page block payload (rendered by hooks/ispconfigconnect.php)', $payload);
lt_out('A2 tblservers_remote', \WHMCS\Database\Capsule::table('tblservers_remote')->get()->all());
lt_out('RenderRemoteMetaData defined by the module?', function_exists('ispconfigconnect_RenderRemoteMetaData') ? 'yes' : 'no');

// ---- Extras with the bad password ---------------------------------------------------------------
lt_clear_attempts();
foreach ([
    'Verify Permissions (service params pointing at ISPC-NEGATIVE)' => fn () => ispconfigconnect_VerifyPermissions(harness_params(1, harness_server_params($neg))),
    'Server Sync ListAccounts' => fn () => ispconfigconnect_ListAccounts(harness_server_params($neg)),
] as $label => $call) {
    $reqMark = lt_mark_file(LT_REQLOG);
    $t = $timesOf();
    $r = $call();
    $reqs = lt_requests_since(LT_REQLOG, $reqMark);
    lt_out("extra $label", ['result' => $r, 'requests' => $reqs, 'attempts_login' => lt_attempts()]);
    lt_assert($reqs === ['[200] POST /remote/json.php?login'] && $timesOf() === $t + 1, "step7(extra) $label: exactly one failed login", $results, $reqs);
}

// ---- Lockout ---------------------------------------------------------------------------------------
lt_q("DELETE FROM attempts_login");
lt_q("INSERT INTO attempts_login (ip, times, login_time) VALUES (MD5('127.0.0.1'), 10, NOW())");
foreach (['NEGATIVE TestConnection' => $neg, 'TEST TestConnection' => $test] as $label => $sid) {
    $reqMark = lt_mark_file(LT_REQLOG);
    $r = ispconfigconnect_TestConnection(harness_server_params($sid));
    $reqs = lt_requests_since(LT_REQLOG, $reqMark);
    lt_out("lockout $label", ['result' => $r, 'requests' => $reqs, 'attempts_login' => lt_attempts()]);
    lt_assert(str_starts_with($r['error'], 'The panel has locked this IP out') && $reqs === ['[200] POST /remote/json.php?login'],
        "step7(extra) lockout $label: lockout advice after exactly one request", $results, $r);
}
$reqMark = lt_mark_file(LT_REQLOG);
$m = ispconfigconnect_GetRemoteMetaData(harness_server_params($test));
$reqs = lt_requests_since(LT_REQLOG, $reqMark);
lt_out('lockout GetRemoteMetaData TEST', ['meta' => $m, 'requests' => $reqs]);
lt_assert($reqs === ['[200] POST /remote/json.php?login'] && str_contains((string) ($m['ispconfig_error'] ?? ''), 'remaining probes skipped'), 'step7(extra) lockout: Servers page probe stops after one request', $results, $m);

// Clean up as the plan says.
lt_clear_attempts();
lt_update_server($neg, ['password' => LT_NEG_PASS]);
lt_out('attempts_login after cleanup', lt_attempts());

lt_out('summary', ['pass' => count(array_filter($results, fn ($r) => $r['ok'])), 'fail' => count(array_filter($results, fn ($r) => !$r['ok']))]);
lt_save('step7');
