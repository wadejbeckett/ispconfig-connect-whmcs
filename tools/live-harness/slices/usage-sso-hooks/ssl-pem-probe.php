<?php
// Does a REAL multi-line PEM ssl_key survive into tblmodulelog? The module masks it BY VALUE
// (RestClient MASKED_KEYS -> logModuleCall replaceVars); the harness's logModuleCall json_encodes
// arrays before str_replace, so a value containing newlines may not match.
declare(strict_types=1);
require __DIR__ . '/lib.php';
$st = lt_state();
$pem = (string) file_get_contents(__DIR__ . '/keys/test-ssl.key');
pq('UPDATE web_domain SET ssl_key=? WHERE domain_id=1', [$pem]);
$line2 = explode("\n", $pem)[1];
$mark = harness_log_mark();
$p = ispconfigconnect_MetricProvider(harness_server_params(1));
$p->tenantUsage($st['u1']);
$rows = mlog_raw($mark);
foreach ($rows as $r) {
    if ($r['action'] === 'quota_get_by_user') {
        echo "quota_get_by_user logged response (first 600 bytes):\n", substr($r['response'], 0, 600), "\n";
        echo 'contains 2nd PEM line verbatim: ', str_contains($r['response'], $line2) ? 'YES' : 'no', "\n";
        echo 'contains "BEGIN PRIVATE KEY": ', str_contains($r['response'], 'PRIVATE KEY') ? 'YES' : 'no', "\n";
    }
}
// What the module handed to logModuleCall, reproduced with WHMCS's documented print_r-style serialisation:
$resp = \WHMCS\Module\Server\Ispconfigconnect\Api\RestClient::scrubResponse('quota_get_by_user', [['domain_id' => '1', 'ssl_key' => $pem]]);
$vars = \WHMCS\Module\Server\Ispconfigconnect\Api\RestClient::maskableValues([['ssl_key' => $pem]]);
$printR = str_replace($vars, '***', print_r($resp, true));
$json = str_replace($vars, '***', (string) json_encode($resp, JSON_UNESCAPED_SLASHES));
echo "print_r then str_replace -> PEM present: ", str_contains($printR, $line2) ? 'YES' : 'no', "\n";
echo "json_encode then str_replace -> PEM present: ", str_contains($json, $line2) ? 'YES' : 'no', "\n";
pq("UPDATE web_domain SET ssl_key='' WHERE domain_id=1");
