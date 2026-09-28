<?php

/**
 * Plan step 8: Transport errors (ISPC-NEGATIVE, throwaway credentials).
 * (a) Hostname 127.0.0.2, Port 80, Secure off: a php -S that answers every request with a 30x redirect
 *     to ANOTHER host (catcher 127.0.0.4:8099). 301, 302 (plan) + 307, 308 (body-preserving) as extras.
 * (b) Hostname 127.0.0.3, Port 80: a php -S answering 200 with a ~5 KB nginx-style HTML page.
 *     Extras: a multibyte char straddling byte 120; a 200 with an empty body.
 * For each: Test Connection message, request counts on each fake server, the catcher is never hit,
 * tblmodulelog carries no redirect body / no page beyond the 120-byte excerpt / no password;
 * the Servers page refresh (GetRemoteMetaData) on the same record and its rendered block.
 * Extra: the two-strike transport latch during a live mid-sweep outage (UsageUpdate, 4 linked services).
 */

declare(strict_types=1);

require __DIR__ . '/common.php';
require_once '/home/user/ispconfig-connect-whmcs/modules/servers/ispconfigconnect/hooks/ispconfigconnect.php';

const SRV = LT_DIR . '/srv';
$neg = 2;
$results = [];
lt_clear_attempts();
lt_update_server($neg, ['password' => LT_NEG_PASS, 'secure' => '']);
@unlink(SRV . '/catcher-hits.log');

$renderBlock = function (int $serverId, array $meta): ?array {
    lt_store_remote_meta($serverId, $meta);
    $_SERVER['REQUEST_URI'] = '/admin/configservers.php';
    $html = ispconfigconnect_metaPanelFooterOutput(['filename' => 'configservers']);
    preg_match('~<script type="application/json" id="ispconfigconnect-server-meta">(.*?)</script>~s', $html, $m);
    foreach (json_decode($m[1] ?? '[]', true) ?: [] as $entry) {
        if ($entry['id'] === $serverId) {
            return $entry;
        }
    }

    return null;
};

// ---------------------------------------------------------------- (a) redirects
lt_update_server($neg, ['hostname' => '127.0.0.2', 'ipaddress' => '127.0.0.2', 'port' => 80]);
foreach (['301', '302', '307', '308'] as $code) {
    file_put_contents(SRV . '/redirect-mode.txt', $code);
    $tag = in_array($code, ['301', '302'], true) ? 'step8a' : 'step8a(extra)';
    $rMark = lt_mark_file(SRV . '/redirect-access.log');
        $logMark = harness_log_mark();

    $tc = ispconfigconnect_TestConnection(harness_server_params($neg));

    $rReqs = lt_requests_since(SRV . '/redirect-access.log', $rMark);
    $cReqs = is_file(SRV . '/catcher-hits.log') ? file(SRV . '/catcher-hits.log', FILE_IGNORE_NEW_LINES) : [];
    $raw = lt_log_raw($logMark);
    $blob = json_encode(array_map(fn ($r) => [$r->action, $r->request, $r->response, $r->arrdata], $raw));
    lt_out("(a) $code TestConnection", ['result' => $tc, 'redirect_server_requests' => $rReqs, 'catcher_requests' => $cReqs, 'modulelog' => lt_log($logMark)]);

    $expected = 'Could not reach the ISPConfig API endpoint. Check the hostname, port (default 8080), the Secure checkbox, and the TLS/CA settings in the Access Hash field. (HTTP status ' . $code . ' from http://127.0.0.2:80/remote/json.php?login)';
    lt_assert($tc === ['success' => false, 'error' => $expected], "$tag $code: 'Could not reach the ISPConfig API endpoint ... (HTTP status $code from http://127.0.0.2:80/remote/json.php?login)'", $results, $tc);
    lt_assert(count($rReqs) === 1 && str_contains($rReqs[0], "[$code]: POST /remote/json.php?login") === false && $rReqs[0] === "[$code] POST /remote/json.php?login", "$tag $code: exactly one request to the redirecting host", $results, $rReqs);
    lt_assert($cReqs === [] && !is_file(SRV . '/catcher-hits.log'), "$tag $code: redirect NOT followed (catcher never hit)", $results, $cReqs);
    lt_assert(!str_contains($blob, 'REDIRECT-BODY-MARKER') && !str_contains($blob, '<html') && !str_contains($blob, LT_NEG_PASS), "$tag $code: no redirect body/HTML/password in tblmodulelog", $results);

    // Servers page refresh on the same record.
    $rMark = lt_mark_file(SRV . '/redirect-access.log');
    $meta = ispconfigconnect_GetRemoteMetaData(harness_server_params($neg));
    $rReqs = lt_requests_since(SRV . '/redirect-access.log', $rMark);
    $block = $renderBlock($neg, $meta);
    lt_out("(a) $code GetRemoteMetaData + rendered block", ['meta' => $meta, 'requests' => $rReqs, 'block' => $block]);
    lt_assert(count($rReqs) === 1 && ($meta['ispconfig_status'] ?? '') === 'unavailable' && str_contains($meta['ispconfig_error'] ?? '', "HTTP status $code") && str_contains($meta['ispconfig_error'] ?? '', 'remaining probes skipped'),
        "$tag $code: Servers page probe stops after one request and reports the status", $results, $meta);
}

// ---------------------------------------------------------------- (b) 200 + HTML
lt_update_server($neg, ['hostname' => '127.0.0.3', 'ipaddress' => '127.0.0.3', 'port' => 80]);
foreach (['html', 'utf8', 'empty'] as $mode) {
    file_put_contents(SRV . '/html-mode.txt', $mode);
    $tag = $mode === 'html' ? 'step8b' : 'step8b(extra)';
    $hMark = lt_mark_file(SRV . '/html-access.log');
    $logMark = harness_log_mark();

    // The body exactly as served, for the byte count and the cut.
    $ch = curl_init('http://127.0.0.3:80/remote/json.php?probe');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => '{}', CURLOPT_RETURNTRANSFER => true]);
    $served = (string) curl_exec($ch);
    $hMark = lt_mark_file(SRV . '/html-access.log'); // do not count our own fetch

    $tc = ispconfigconnect_TestConnection(harness_server_params($neg));
    $hReqs = lt_requests_since(SRV . '/html-access.log', $hMark);
    $raw = lt_log_raw($logMark);
    $blob = json_encode(array_map(fn ($r) => [$r->action, $r->request, $r->response, $r->arrdata], $raw), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    lt_out("(b) $mode TestConnection", ['served_bytes' => strlen($served), 'result' => $tc, 'requests' => $hReqs, 'modulelog' => lt_log($logMark, 1000)]);

    $err = $tc['error'];
    if ($mode === 'empty') {
        lt_assert($err === 'Response is not the ISPConfig JSON envelope: empty body (0 bytes) - the panel returned nothing, e.g. an HTTP redirect/error page or json_encode() failing on non-UTF-8 record data',
            "$tag empty 200: empty-body diagnostic", $results, $tc);
    } else {
        $prefix = 'Response is not the ISPConfig JSON envelope: non-JSON body, ' . strlen($served) . ' bytes, starts with: ';
        lt_assert(str_starts_with($err, $prefix), "$tag $mode: 'Response is not the ISPConfig JSON envelope: non-JSON body, " . strlen($served) . " bytes, starts with: <!DOCTYPE ...'", $results, $tc);
        $excerpt = substr($err, strlen($prefix));
        $excerptBody = str_ends_with($excerpt, '...') ? substr($excerpt, 0, -3) : $excerpt;
        lt_out("(b) $mode excerpt", ['bytes' => strlen($excerptBody), 'ends_with_ellipsis' => str_ends_with($excerpt, '...'), 'valid_utf8' => mb_check_encoding($excerptBody, 'UTF-8'), 'text' => $excerptBody]);
        lt_assert(str_starts_with($excerptBody, '<!DOCTYPE') && strlen($excerptBody) <= 120 && str_starts_with($served, $excerptBody) && str_ends_with($excerpt, '...'),
            "$tag $mode: excerpt cut at <=120 bytes (" . strlen($excerptBody) . '), a true prefix of the page, marked with ...', $results);
        if ($mode === 'utf8') {
            lt_assert(strlen($excerptBody) === 119 && mb_check_encoding($excerptBody, 'UTF-8'), "$tag utf8: cut backs off the split 2-byte char (119 bytes, valid UTF-8)", $results, strlen($excerptBody));
        }
        lt_assert(!str_contains($blob, 'DEEP-HTML-MARKER') && !str_contains($blob, 'DEEP-UTF8-MARKER') && !str_contains($blob, '</html>'), "$tag $mode: the page beyond the excerpt never reaches tblmodulelog", $results);
        $maxResp = max(array_map(fn ($r) => strlen($r->response), $raw));
        lt_assert($maxResp < 400, "$tag $mode: largest tblmodulelog response is $maxResp bytes (page is " . strlen($served) . ')', $results);
    }
    lt_assert($hReqs === ['[200] POST /remote/json.php?login'], "$tag $mode: exactly one request (the login) to the HTML server", $results, $hReqs);
    lt_assert(!str_contains($blob, LT_NEG_PASS), "$tag $mode: password masked", $results);

    $hMark = lt_mark_file(SRV . '/html-access.log');
    $meta = ispconfigconnect_GetRemoteMetaData(harness_server_params($neg));
    $hReqs = lt_requests_since(SRV . '/html-access.log', $hMark);
    $block = $renderBlock($neg, $meta);
    lt_out("(b) $mode GetRemoteMetaData + rendered block", ['meta' => $meta, 'requests' => $hReqs, 'block' => $block]);
    lt_assert(count($hReqs) === 1 && ($meta['ispconfig_status'] ?? '') === 'unavailable' && str_contains($meta['ispconfig_error'] ?? '', 'remaining probes skipped'),
        "$tag $mode: Servers page probe stops after one request", $results, $meta);
    lt_assert(!str_contains(json_encode($meta), 'DEEP-') && strlen((string) ($meta['ispconfig_error'] ?? '')) <= 500, "$tag $mode: stored meta_data bounded, no deep page content", $results);
}

// Restore ISPC-NEGATIVE.
lt_update_server($neg, ['hostname' => '127.0.0.1', 'ipaddress' => '127.0.0.1', 'port' => 8082]);

// ---------------------------------------------------------------- extra: live mid-sweep outage latch
$outage = harness_add_server(['name' => 'ISPC-OUTAGE', 'hostname' => '127.0.0.5', 'ipaddress' => '127.0.0.5', 'port' => 80]);
$pOut = harness_add_product('ISPC Outage product');
$svcIds = [];
for ($i = 1; $i <= 4; $i++) {
    $sid = harness_add_service($pOut, $outage, "outage-$i.example", ['domainstatus' => 'Active', 'username' => "outage$i"]);
    $model = new HarnessServiceModel($sid, $pOut, 'Active');
    $model->serviceProperties->save(['ispconfig_client_id' => (string) (900 + $i), 'ispconfig_client_username' => "outage$i"]);
    $svcIds[] = $sid;
}
file_put_contents(SRV . '/outage-counter.txt', '0');
file_put_contents(SRV . '/outage-allow.txt', '2'); // login + the reachability probe answer; then the panel "dies"
@unlink(SRV . '/outage-hits.log');
$logMark = harness_log_mark();
ispconfigconnect_UsageUpdate(harness_server_params($outage));
$hits = file(SRV . '/outage-hits.log', FILE_IGNORE_NEW_LINES) ?: [];
$sweep = lt_log($logMark, 2000);
lt_out('extra outage sweep: requests seen by the outage proxy', $hits);
lt_out('extra outage sweep: tblmodulelog', $sweep);
$summary = json_decode((string) end($sweep)['response'], true);
lt_assert(count($hits) === 4, 'step8(extra) live outage: 4 services cost 4 requests in total (login, probe, first read 502, re-login 502) - circuit open after two transport failures in a row', $results, $hits);
lt_assert(($summary['updated'] ?? null) === 0 && count($summary['skipped'] ?? []) === 4, 'step8(extra) live outage: every service reported skipped with a reason', $results, $summary);

lt_out('summary', ['pass' => count(array_filter($results, fn ($r) => $r['ok'])), 'fail' => count(array_filter($results, fn ($r) => !$r['ok']))]);
lt_save('step8');
