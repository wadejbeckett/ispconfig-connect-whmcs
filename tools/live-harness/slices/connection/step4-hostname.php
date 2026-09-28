<?php

/**
 * Plan step 4: Hostname validation (ISPC-NEGATIVE only).
 * For each malformed Hostname: store it on ISPC-NEGATIVE (tblservers), press Test Connection
 * (ispconfigconnect_TestConnection(harness_server_params(2))), and assert
 *  - the exact refusal text and reason, in plan order;
 *  - NO request reached the panel (php -S request log = the auth.log stand-in) and no attempts_login change;
 *  - NO new tblmodulelog row.
 * Extra (same validation on the stored-record paths WHMCS does not pre-filter):
 *  - the Servers-page refresh (GetRemoteMetaData) on the same stored value;
 *  - Verify Permissions for a service whose server row carries the value.
 * Then restore the bare hostname and prove the record works again.
 */

declare(strict_types=1);

require __DIR__ . '/common.php';

$neg = 2;
$prefix = 'Server Hostname/IP must be a bare host name or IP address (no scheme, port, path or user) - ';
$cases = [
    ['https://panel.test.example', 'it includes a URL scheme'],
    ['panel.test.example:8080', 'it includes a port - set the port in the WHMCS Port field'],
    ['panel.test.example/', 'it includes a path or slash'],
    ['user@panel.test.example', 'it includes user information'],
    ['panel test.example', 'it contains whitespace'],
    ['[2001:db8::1]:8080', 'it is not a valid [IPv6] literal'],
];

$results = [];
lt_clear_attempts();
foreach ($cases as [$value, $reason]) {
    lt_update_server($neg, ['hostname' => $value]);
    $reqMark = lt_mark_file(LT_REQLOG);
    $logMark = harness_log_mark();
    $attemptsBefore = lt_attempts();

    $tc = ispconfigconnect_TestConnection(harness_server_params($neg));
    $expected = $prefix . $reason . ': "' . $value . '"';

    $reqs = lt_requests_since(LT_REQLOG, $reqMark);
    $logs = lt_log($logMark);
    lt_out("TestConnection hostname=" . json_encode($value), ['result' => $tc, 'requests' => $reqs, 'modulelog' => $logs, 'attempts' => lt_attempts()]);
    lt_assert($tc === ['success' => false, 'error' => $expected], "step4 TestConnection exact text for $value", $results, $tc);
    lt_assert($reqs === [], "step4 no panel request for $value", $results, $reqs);
    lt_assert($logs === [], "step4 no tblmodulelog row for $value", $results, $logs);
    lt_assert(lt_attempts() == $attemptsBefore, "step4 attempts_login unchanged for $value", $results);

    // Stored-record path #1: the Servers page refresh.
    $reqMark = lt_mark_file(LT_REQLOG);
    $logMark = harness_log_mark();
    $meta = ispconfigconnect_GetRemoteMetaData(harness_server_params($neg));
    $reqs = lt_requests_since(LT_REQLOG, $reqMark);
    lt_out("GetRemoteMetaData hostname=" . json_encode($value), ['meta' => $meta, 'requests' => $reqs, 'modulelog' => lt_log($logMark)]);
    lt_assert(($meta['ispconfig_status'] ?? null) === 'unavailable' && ($meta['ispconfig_error'] ?? null) === $expected,
        "step4(extra) GetRemoteMetaData unavailable with the same text for $value", $results, $meta);
    lt_assert($reqs === [] && lt_log($logMark) === [], "step4(extra) GetRemoteMetaData made no request/log for $value", $results);

    // Stored-record path #2: Verify Permissions on a service whose server row holds the value.
    $reqMark = lt_mark_file(LT_REQLOG);
    $logMark = harness_log_mark();
    $vp = ispconfigconnect_VerifyPermissions(harness_params(1, harness_server_params($neg)));
    $reqs = lt_requests_since(LT_REQLOG, $reqMark);
    lt_out("VerifyPermissions hostname=" . json_encode($value), ['result' => $vp, 'requests' => $reqs, 'modulelog' => lt_log($logMark)]);
    lt_assert($vp === 'Verify Permissions failed: ' . $expected, "step4(extra) VerifyPermissions refused with the same text for $value", $results, $vp);
    lt_assert($reqs === [] && lt_log($logMark) === [], "step4(extra) VerifyPermissions made no request/log for $value", $results);
}

// Extras outside the plan's list, same rule set (upgrade check 4).
$extras = [
    // both fields empty
    ['hostname' => '', 'ipaddress' => '', 'expect' => 'Server Hostname/IP is empty - set the WHMCS server Hostname or IP Address'],
    // hostname empty -> IP Address used, and IP address with :port refused
    ['hostname' => '', 'ipaddress' => '127.0.0.1:8082', 'expect' => $prefix . 'it includes a port - set the port in the WHMCS Port field: "127.0.0.1:8082"'],
    // a fullwidth solidus maps to "/" under UTS#46 - must be refused, not converted into a path
    ['hostname' => "panel\u{FF0F}evil.example", 'ipaddress' => '127.0.0.1', 'expect_prefix' => $prefix . 'it contains characters that are not valid in a host name'],
    // bare IPv6 (not reachable here; just check it is accepted and bracketed -> curl error, not a validation error)
    ['hostname' => '::1', 'ipaddress' => '', 'expect_prefix' => 'Could not reach the ISPConfig API endpoint'],
];
foreach ($extras as $x) {
    lt_update_server($neg, ['hostname' => $x['hostname'], 'ipaddress' => $x['ipaddress']]);
    $reqMark = lt_mark_file(LT_REQLOG);
    $logMark = harness_log_mark();
    $tc = ispconfigconnect_TestConnection(harness_server_params($neg));
    $reqs = lt_requests_since(LT_REQLOG, $reqMark);
    lt_out('extra TestConnection ' . json_encode($x), ['result' => $tc, 'requests' => $reqs, 'modulelog' => lt_log($logMark)]);
    if (isset($x['expect'])) {
        lt_assert($tc['error'] === $x['expect'], 'step4(extra) ' . json_encode($x['hostname'] . '|' . $x['ipaddress']), $results, $tc);
    } else {
        lt_assert(str_starts_with($tc['error'], $x['expect_prefix']), 'step4(extra) ' . json_encode($x['hostname'] . '|' . $x['ipaddress']), $results, $tc);
    }
    lt_assert($reqs === [], 'step4(extra) no request reached panel 2 for ' . json_encode($x['hostname']), $results, $reqs);
}

// IDN host name: converted with intl (loaded here) and then used; we only check it is NOT refused by validation.
lt_update_server($neg, ['hostname' => 'bücher.invalid', 'ipaddress' => '127.0.0.1']);
$tc = ispconfigconnect_TestConnection(harness_server_params($neg));
lt_out('extra IDN TestConnection', $tc);
lt_assert(str_contains($tc['error'], 'xn--bcher-kva.invalid') && !str_contains($tc['error'], 'bare host name'),
    'step4(extra) IDN host converted to punycode, not refused', $results, $tc);

// Restore the bare hostname.
lt_update_server($neg, ['hostname' => '127.0.0.1', 'ipaddress' => '127.0.0.1']);
$reqMark = lt_mark_file(LT_REQLOG);
$tc = ispconfigconnect_TestConnection(harness_server_params($neg));
$reqs = lt_requests_since(LT_REQLOG, $reqMark);
lt_out('restored TestConnection (throwaway user, 9 groups)', ['result' => $tc, 'requests' => $reqs]);
lt_assert($tc['success'] === true, 'step4 restore: bare hostname works again', $results, $tc);

lt_out('summary', ['pass' => count(array_filter($results, fn ($r) => $r['ok'])), 'fail' => count(array_filter($results, fn ($r) => !$r['ok']))]);
lt_save('step4');
