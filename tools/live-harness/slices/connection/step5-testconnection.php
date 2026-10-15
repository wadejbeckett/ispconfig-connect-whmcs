<?php

/**
 * Plan step 5: Test Connection on ISPC-TEST with all grants.
 * Run A: the make-panel remote user (EVERY group ticked).
 * Run B: exactly the nine groups of docs/REMOTE-USER.md / prerequisite 5.
 * Assert: success text; module-log sequence login, 13 probes (plan order), get_function_list, logout,
 * then the module's own TestConnection line; masking of password and session id; exactly one login
 * on the wire (php -S request log = auth.log stand-in; this develop-branch panel writes no auth.log
 * line for API logins); attempts_login untouched; probe faults not reported as missing grants.
 */

declare(strict_types=1);

require __DIR__ . '/common.php';

$test = 1;
$expectedSeq = [
    'login', 'server_get_all', 'client_templates_get_all', 'monitor_jobqueue_count', 'client_get',
    'client_get_all', 'client_get_by_username', 'client_get_sites_by_user', 'sites_web_domain_get',
    'sites_ftp_user_get', 'dns_zone_get', 'quota_get_by_user', 'mailquota_get_by_user',
    'databasequota_get_by_user', 'get_function_list', 'logout', 'TestConnection',
];
$results = [];
$allGroups = array_keys(lt_groups());

foreach (['A-all-groups' => $allGroups, 'B-nine-groups' => LT_NINE] as $run => $labels) {
    lt_set_grants('whmcs', $labels);
    lt_clear_attempts();
    $reqMark = lt_mark_file(LT_REQLOG);
    $logMark = harness_log_mark();
    $sessionsBefore = lt_q('SELECT COUNT(*) n FROM remote_session')[0]['n'];

    $tc = ispconfigconnect_TestConnection(harness_server_params($test));

    $reqs = lt_requests_since(LT_REQLOG, $reqMark);
    $raw = lt_log_raw($logMark);
    $seq = array_map(fn ($r) => $r->action, $raw);
    lt_out("$run TestConnection", $tc);
    lt_out("$run wire requests", $reqs);
    lt_out("$run tblmodulelog", lt_log($logMark, 600));

    lt_assert($tc['success'] === true && str_starts_with($tc['error'], 'Connected. Single sign-on: '), "step5 $run: 'Connected. Single sign-on: ...'", $results, $tc);
    lt_assert($seq === $expectedSeq, "step5 $run: module log = login, 13 probes, get_function_list, logout, TestConnection", $results, $seq);
    $logins = array_values(array_filter($reqs, fn ($l) => str_ends_with($l, '?login')));
    lt_assert(count($logins) === 1, "step5 $run: exactly one API login on the wire", $results, $reqs);
    lt_assert(lt_attempts() === [], "step5 $run: attempts_login stays empty", $results);

    // Masking: password + session id never stored in clear.
    $blob = json_encode(array_map(fn ($r) => [$r->request, $r->response, $r->arrdata], $raw));
    lt_assert(!str_contains($blob, HARNESS_REMOTE_PASS), "step5 $run: remote password absent from tblmodulelog", $results);
    $loginRow = $raw[0];
    lt_assert(str_contains($loginRow->request, '"password":"***"') && $loginRow->response === '***', "step5 $run: login row masks password and session id", $results, [$loginRow->request, $loginRow->response]);
    $sessionLike = preg_match_all('/"[a-z][0-9a-f]{40}"/', $blob);
    lt_assert($sessionLike === 0, "step5 $run: no 41-char session id anywhere in the log", $results, $sessionLike);
    $sidMasked = true;
    foreach (array_slice($raw, 1, 15) as $r) {
        $sidMasked = $sidMasked && str_contains($r->request, '"session_id":"***"');
    }
    lt_assert($sidMasked, "step5 $run: every probe/logout request shows session_id as ***", $results);
    lt_assert(lt_q('SELECT COUNT(*) n FROM remote_session')[0]['n'] === $sessionsBefore, "step5 $run: session logged out (remote_session count unchanged)", $results);

    // Probe answers: which were faults, and none was counted as a missing grant.
    $faults = [];
    foreach ($raw as $r) {
        if (in_array($r->action, array_slice($expectedSeq, 1, 13), true)) {
            $faults[$r->action] = strlen($r->response) > 160 ? substr($r->response, 0, 160) . '...' : $r->response;
        }
    }
    lt_out("$run probe responses", $faults);
    $tcRow = end($raw);
    lt_out("$run TestConnection module-log line", ['request' => $tcRow->request, 'response' => $tcRow->response]);
}

// Test Connection is also what WHMCS runs on the SAVED form; the same success via a VerifyPermissions press.
$logMark = harness_log_mark();
$vp = harness_call('VerifyPermissions', 1);
lt_out('Verify Permissions (nine groups)', ['result' => $vp, 'log' => array_map(fn ($r) => $r['action'], lt_log($logMark))]);
lt_assert($vp === 'success', 'step5(extra) Verify Permissions clean with the nine groups', $results, $vp);

lt_out('summary', ['pass' => count(array_filter($results, fn ($r) => $r['ok'])), 'fail' => count(array_filter($results, fn ($r) => !$r['ok']))]);
lt_save('step5');
