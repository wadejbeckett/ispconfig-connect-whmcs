<?php

/**
 * Plan step 6: Reduced grants. Start from the nine documented groups; untick ONE group
 * (UPDATE remote_user.remote_functions exactly as the ISPConfig form stores it), press Test Connection
 * on ISPC-TEST and Verify Permissions (admin custom button -> ispconfigconnect_VerifyPermissions) on a
 * service. Plan groups: Quota, Client, Sites FTP-User, DNS zone, Sites Domain, Server.
 * Extras: Monitor, Sites database, Mail domain (no read probe exists for the last two).
 * Informational: the make-panel "every group" user with Server functions unticked (overlapping groups).
 * Re-grant everything and prove both are clean again.
 */

declare(strict_types=1);

require __DIR__ . '/common.php';

$test = 1;
$svc = 1;
$results = [];
$plan = [
    'Quota functions' => ["'quota_get_by_user' (needed by quota_get_by_user)", "'mailquota_get_by_user' (needed by mailquota_get_by_user)", "'databasequota_get_by_user' (needed by databasequota_get_by_user)"],
    'Client functions' => ["'client_get' (needed by client_get)", "'client_get_all' (needed by client_get_all)", "'client_get_by_username' (needed by client_get_by_username)", "'client_get_sites_by_user' (needed by client_get_sites_by_user)"],
    'Sites FTP-User functions' => ["'sites_ftp_user_get' (needed by sites_ftp_user_get)"],
    'DNS zone functions' => ["'dns_zone_get' (needed by dns_zone_get)"],
    'Sites Domain functions' => ["'sites_web_domain_get' (needed by sites_web_domain_get)"],
    // The plan says only server_get. The same ISPConfig checkbox also carries client_templates_get_all and
    // get_function_list (docs/REMOTE-USER.md says so itself), so the probes are expected to report more.
    'Server functions' => ["'server_get' (needed by server_get_all)"],
    'Monitor functions' => ["'monitor_jobqueue_count' (needed by monitor_jobqueue_count)"],
    'Sites database functions' => [],
    'Mail domain functions' => [],
];

$tcPrefix = 'Connected and logged in, but the remote user is missing remote_functions grants: ';
$tcSuffix = '. Enable them on the remote user under System > Remote Users in ISPConfig (docs/REMOTE-USER.md documents the full grant list).';
$vpPrefix = 'The remote user is missing remote_functions grants: ';
$vpSuffix = '. Enable them under System > Remote Users in ISPConfig; docs/REMOTE-USER.md documents the full grant list.';

foreach ($plan as $group => $expectedList) {
    $labels = array_values(array_diff(LT_NINE, [$group]));
    lt_set_grants('whmcs', $labels);
    lt_clear_attempts();

    $reqMark = lt_mark_file(LT_REQLOG);
    $logMark = harness_log_mark();
    $tc = ispconfigconnect_TestConnection(harness_server_params($test));
    $tcLog = lt_log($logMark, 300);
    $tcReqs = lt_requests_since(LT_REQLOG, $reqMark);

    $logMark2 = harness_log_mark();
    $vp = harness_call('VerifyPermissions', $svc);
    $vpLog = lt_log($logMark2, 600);

    $denied = array_values(array_map(fn ($r) => $r['action'], array_filter($tcLog, fn ($r) => str_contains($r['response'], 'You do not have the permissions'))));
    lt_out("untick [$group] TestConnection", $tc);
    lt_out("untick [$group] Verify Permissions", $vp);
    lt_out("untick [$group] probes answered permission_denied (TestConnection)", $denied);
    lt_out("untick [$group] VerifyPermissions module-log line", end($vpLog));
    lt_out("untick [$group] logins on the wire (TestConnection)", count(array_filter($tcReqs, fn ($l) => str_ends_with($l, '?login'))));

    if ($expectedList === []) {
        lt_assert($tc['success'] === true, "step6(extra) untick $group: Test Connection clean (no read probe for this group)", $results, $tc);
        lt_assert($vp === 'success', "step6(extra) untick $group: Verify Permissions clean (no read probe for this group)", $results, $vp);
        continue;
    }

    $expectedTc = $tcPrefix . implode(', ', $expectedList) . $tcSuffix;
    $expectedVp = $vpPrefix . implode(', ', $expectedList) . $vpSuffix;
    $tag = in_array($group, ['Monitor functions'], true) ? 'step6(extra)' : 'step6';
    lt_assert($tc === ['success' => false, 'error' => $expectedTc], "$tag untick $group: Test Connection names only " . implode(' ', $expectedList), $results, $tc);
    lt_assert($vp === $expectedVp, "$tag untick $group: Verify Permissions shows the same list", $results, $vp);
    lt_assert(count(array_filter($tcReqs, fn ($l) => str_ends_with($l, '?login'))) === 1, "$tag untick $group: one login", $results);
}

// Informational: the panel's default all-groups user with ONLY Server functions unticked.
$all = array_keys(lt_groups());
lt_set_grants('whmcs', array_values(array_diff($all, ['Server functions'])));
$tc = ispconfigconnect_TestConnection(harness_server_params($test));
$vp = harness_call('VerifyPermissions', $svc);
lt_out('INFO all-groups user minus Server functions: TestConnection', $tc);
lt_out('INFO all-groups user minus Server functions: Verify Permissions', $vp);

// Re-grant everything (nine groups), both clean.
lt_set_grants('whmcs', LT_NINE);
$tc = ispconfigconnect_TestConnection(harness_server_params($test));
$vp = harness_call('VerifyPermissions', $svc);
lt_out('re-granted TestConnection', $tc);
lt_out('re-granted Verify Permissions', $vp);
lt_assert($tc['success'] === true && str_starts_with($tc['error'], 'Connected. Single sign-on: '), 'step6 after re-grant: Test Connection clean', $results, $tc);
lt_assert($vp === 'success', 'step6 after re-grant: Verify Permissions clean', $results, $vp);

lt_out('summary', ['pass' => count(array_filter($results, fn ($r) => $r['ok'])), 'fail' => count(array_filter($results, fn ($r) => !$r['ok']))]);
lt_save('step6');
