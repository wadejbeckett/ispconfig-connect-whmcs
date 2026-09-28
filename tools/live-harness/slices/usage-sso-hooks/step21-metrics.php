<?php

/**
 * Step 21: usage-billing metrics. "Create one mailbox under S1's client" (ISPConfig admin action,
 * done through the panel's remote API mail_user_add), seed the monitor data the server daemon
 * would write, then collect: ispconfigconnect_MetricProvider($serverParams)->usage() (the forced
 * usage-metrics cron task) and ->tenantUsage() (the per-service metric refresh).
 *
 * Harness extension: WHMCS's CreateAccount write-back ($model->username = ...; $model->save())
 * cannot persist through the harness model (no save()), so tblhosting.username is set here to
 * the generated username, which is what real WHMCS holds after Create.
 */

declare(strict_types=1);

require __DIR__ . '/lib.php';

$st = lt_state();
$server = $st['server'];
$s1 = $st['s1'];

const MB_NAME = 'Sekret Mailbox Owner Name';
const MB_PASS = 'Mailbox-Secret-Pass-7Q';
const MB_AUTORESP = 'SECRET-AUTORESPONDER-TEXT-4711';
const MB_EMAIL = 'info@test-one.example';
const SSL_SENTINEL = '-----BEGIN PRIVATE KEY-----SSLKEYSENTINEL0123456789-----END PRIVATE KEY-----';

hr('WHMCS username write-back (what $model->save() does in real WHMCS)');
foreach (['s1' => 'u1', 's2' => 'u2', 's3' => 'u3'] as $s => $u) {
    set_svc($st[$s], ['username' => $st[$u]]);
}
dump('tblhosting', \WHMCS\Database\Capsule::table('tblhosting')->get(['id', 'domain', 'username', 'domainstatus'])->all());

hr('create one mailbox under S1\'s client (panel admin action via mail_user_add)');
$existing = harness_panel_rows("SELECT mailuser_id FROM mail_user WHERE email='" . MB_EMAIL . "'");
if ($existing === []) {
    $api = panel_api();
    $mid = $api->call('mail_user_add', ['client_id' => $st['c1'], 'params' => [
        'server_id' => 1, 'email' => MB_EMAIL, 'login' => MB_EMAIL, 'password' => MB_PASS, 'name' => MB_NAME,
        'uid' => 5000, 'gid' => 5000, 'maildir' => '/var/vmail/test-one.example/info', 'maildir_format' => 'maildir',
        'homedir' => '/var/vmail', 'quota' => 1073741824, 'cc' => 'cc-secret@elsewhere.example', 'forward_in_lda' => 'n',
        'sender_cc' => '', 'move_junk' => 'n', 'purge_trash_days' => 0, 'purge_junk_days' => 0, 'custom_mailfilter' => '',
        'postfix' => 'y', 'greylisting' => 'n', 'access' => 'y', 'disableimap' => 'n', 'disablepop3' => 'n',
        'disabledeliver' => 'n', 'disablesmtp' => 'n', 'disablesieve' => 'n', 'disablesieve-custom' => 'n',
        'disablelda' => 'n', 'disablelmtp' => 'n', 'disabledoveadm' => 'n', 'disablequota-status' => 'n',
        'disableindexer-worker' => 'n', 'autoresponder' => 'y', 'autoresponder_subject' => 'Away',
        'autoresponder_text' => MB_AUTORESP, 'autoresponder_start_date' => date('Y-m-d H:i:s'),
        'autoresponder_end_date' => date('Y-m-d H:i:s', time() + 86400),
    ]]);
    $api->close();
    dump('mail_user_add -> mailuser_id', $mid);
}
$mu = harness_panel_rows("SELECT mailuser_id, email, name, quota, sys_groupid, postfix, autoresponder, LEFT(password,4) pwprefix FROM mail_user WHERE email='" . MB_EMAIL . "'");
dump('mail_user', $mu);
check('21-mailbox', count($mu) === 1 && (int) $mu[0]['sys_groupid'] === panel_group($st['c1']), 'mailbox created in S1\'s client group', $mu);
$pwHash = harness_panel_rows("SELECT password FROM mail_user WHERE email='" . MB_EMAIL . "'")[0]['password'];

hr('seed monitor data (what the server daemon writes) + an ssl_key on S1\'s site');
pq("DELETE FROM monitor_data WHERE type IN ('email_quota','database_size')");
pq("INSERT INTO monitor_data (server_id,type,created,data,state) VALUES (1,'email_quota',UNIX_TIMESTAMP(),?,'ok')", [serialize([MB_EMAIL => ['used' => 52428800]])]);
pq("INSERT INTO monitor_data (server_id,type,created,data,state) VALUES (1,'database_size',UNIX_TIMESTAMP(),?,'ok')", [serialize([['database_name' => 'c1_testone', 'size' => 31457280, 'sys_groupid' => panel_group($st['c1'])]])]);
pq('UPDATE web_domain SET ssl_key=?, `ssl`=\'y\' WHERE domain_id=1', [SSL_SENTINEL]);
dump('web_traffic this month', pq('SELECT hostname, SUM(traffic_bytes) b FROM web_traffic WHERE YEAR(traffic_date)=YEAR(CURDATE()) AND MONTH(traffic_date)=MONTH(CURDATE()) GROUP BY hostname'));

/** @param list<\WHMCS\UsageBilling\Metrics\Metric> $metrics */
function metric_values(array $metrics): array
{
    $v = [];
    foreach ($metrics as $m) {
        $v[$m->systemName()] = $m->usage()?->value();
    }

    return $v;
}

hr('metrics() catalogue');
$provider = ispconfigconnect_MetricProvider(harness_server_params($server));
$cat = [];
foreach ($provider->metrics() as $m) {
    $cat[] = [$m->systemName(), $m->displayName(), $m->type(), (new ReflectionClass($m->units()))->getShortName(), $m->units()->label(), $m->usage()];
}
dump('metrics()', $cat);
check('21-cat', array_column($cat, 1) === ['Disk Usage', 'Bandwidth', 'Mailbox Storage', 'Database Storage', 'Websites', 'Mailboxes', 'Databases', 'FTP Users', 'DNS Zones'], 'metric catalogue: Disk Usage, Bandwidth, Mailbox Storage, Database Storage, Websites, Mailboxes, Databases, FTP Users, DNS Zones', array_column($cat, 1));
check('21-cat-types', $cat[1][2] === 'period_month' && count(array_filter($cat, fn ($c) => $c[2] === 'snapshot')) === 8, 'Bandwidth is TYPE_PERIOD_MONTH, the rest TYPE_SNAPSHOT');
check('21-cat-units', array_column($cat, 3) === ['MegaBytes', 'MegaBytes', 'MegaBytes', 'MegaBytes', 'Accounts', 'Accounts', 'Accounts', 'Accounts', 'Domains'], 'units: MB x4, Accounts x4, Domains', array_column($cat, 3));

hr('usage() - the forced usage-metrics cron task');
$mark = harness_log_mark();
$req = req_mark();
$usage = $provider->usage();
$reqs = req_since($req);
$vals = array_map('metric_values', $usage);
dump('usage() tenants => values', $vals);
out('  module-log actions: ' . implode(', ', mlog_actions($mark)));
out('  panel requests: ' . count($reqs) . ', logins: ' . logins_in($reqs));
$expected = ['diskusage' => 200, 'bandwidth' => 500, 'mailquota' => 50, 'databasequota' => 30, 'websites' => 1, 'mailboxes' => 1, 'databases' => 1, 'ftpusers' => 1, 'dnszones' => 1];
check('21-s1', ($vals[$st['u1']] ?? null) === $expected, 'S1 (' . $st['u1'] . '): Disk 200, Bandwidth 500, Mailbox Storage 50, Database Storage 30, Websites=1, Mailboxes=1, Databases=1, FTP Users=1, DNS Zones=1', $vals[$st['u1']] ?? null);
check('21-s2', ($vals[$st['u2']] ?? null) === ['diskusage' => 50, 'bandwidth' => 100, 'mailquota' => 0, 'databasequota' => 0, 'websites' => 1, 'mailboxes' => 0, 'databases' => 0, 'ftpusers' => 1, 'dnszones' => 0], 'S2 (website-only) values', $vals[$st['u2']] ?? null);
check('21-s3', ($vals[$st['u3']] ?? null) === ['diskusage' => 0, 'bandwidth' => 0, 'mailquota' => 0, 'databasequota' => 0, 'websites' => 0, 'mailboxes' => 0, 'databases' => 0, 'ftpusers' => 0, 'dnszones' => 0], 'S3 (mail-domain only, no website) all zeros, reported (not skipped)', $vals[$st['u3']] ?? null);
check('21-keys', array_keys($vals) === [$st['u1'], $st['u2'], $st['u3']], 'usage() keyed by tenant username; each key equals that service\'s tblhosting.username', array_keys($vals));
check('21-login', logins_in($reqs) === 1, 'usage(): one login for all tenants', $reqs);
$usageLog = mlog_raw($mark);

hr('tenantUsage() - the per-service metric refresh, every tenant shape');
$shapes = [
    'username string' => $st['u1'],
    'service id int' => $s1,
    'numeric string id' => (string) $s1,
    'object id+username' => (object) ['id' => $s1, 'username' => $st['u1']],
    'object id only' => (object) ['id' => $s1],
    'HarnessServiceModel' => harness_params($s1)['model'],
];
$mark2 = harness_log_mark();
foreach ($shapes as $label => $tenant) {
    $tv = metric_values($provider->tenantUsage($tenant));
    out("  tenantUsage($label) = " . json_encode($tv));
    check('21-tenant-' . str_replace(' ', '-', $label), $tv === $expected, "tenantUsage($label) resolves S1 with the same figures", $tv);
}
$tv = metric_values($provider->tenantUsage('nosuchuser'));
check('21-tenant-unknown', array_filter($tv, fn ($v) => $v !== null) === [], 'tenantUsage(unknown) -> catalogue without usage, no exception', $tv);
$tenantLog = mlog_raw($mark2);

hr('Module Log: no mailbox PII, no password, no autoresponder text, no ssl_key');
$all = array_merge($usageLog, $tenantLog);
$blob = json_encode($all, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
foreach (['email address' => MB_EMAIL, 'mailbox name' => MB_NAME, 'mailbox password' => MB_PASS, 'password hash' => $pwHash, 'autoresponder text' => MB_AUTORESP, 'cc address' => 'cc-secret@elsewhere.example', 'ssl_key value' => 'SSLKEYSENTINEL'] as $what => $needle) {
    check('21-log-' . str_replace(' ', '-', $what), !str_contains($blob, $needle), "metrics Module Log rows carry no $what", substr_count($blob, $needle));
}
foreach ($all as $r) {
    if ($r['action'] === 'mailquota_get_by_user') {
        out('  mailquota_get_by_user log response: ' . $r['response']);
    }
    if ($r['action'] === 'quota_get_by_user' && str_contains($r['request'], '"client_id":' . $st['c1'])) {
        out('  quota_get_by_user(S1) log response: ' . $r['response']);
    }
}
$mq = array_values(array_filter($all, fn ($r) => $r['action'] === 'mailquota_get_by_user' && str_contains($r['response'], 'mailuser_id')));
check('21-log-mailrow', $mq !== [], 'the mailbox row IS logged (projected to ids and numbers)', count($mq));
// The plan says "no ssl_key anywhere": the KEY name is still logged, its VALUE masked.
$sslKeyRows = array_values(array_filter($all, fn ($r) => str_contains($r['response'], '"ssl_key"')));
dump('rows in the metrics log that still carry an "ssl_key" key', array_map(fn ($r) => [$r['id'], $r['action'], preg_match('~"ssl_key":"([^"]*)"~', $r['response'], $m) ? $m[1] : null], $sslKeyRows));
check('21-log-ssl_key-key', $sslKeyRows === [], 'literal plan text: no "ssl_key" anywhere in the metrics Module Log rows (key name included)', count($sslKeyRows));
check('21-log-ssl_key-masked', array_filter($sslKeyRows, fn ($r) => preg_match('~"ssl_key":"(?!\\*\\*\\*"|")~', $r['response']) === 1) === [], 'every logged ssl_key value is "***" (S1, which has a key) or "" (no key): the private key itself never appears');
// Every module-written row; the two admin-tooling rows (client_add, mail_user_add - my own panel
// actions sent through the module's API client, never a WHMCS path) are excluded.
$wholeLog = json_encode(\WHMCS\Database\Capsule::table('tblmodulelog')->whereNotIn('action', ['client_add', 'mail_user_add'])->orWhere('request', 'like', '%serviceid%')->get()->all());
check('21-log-anywhere', !str_contains($wholeLog, 'SSLKEYSENTINEL') && !str_contains($wholeLog, MB_AUTORESP) && !str_contains($wholeLog, MB_PASS), 'no ssl_key / autoresponder / mailbox password anywhere in tblmodulelog');

hr('a REAL multi-line PEM in web_domain.ssl_key (masking is by value via logModuleCall replaceVars)');
$pem = (string) file_get_contents(LT_DIR . '/keys/test-ssl.key');
$pemLine = explode("\n", $pem)[1];
pq('UPDATE web_domain SET ssl_key=? WHERE domain_id=1', [$pem]);
$mark3 = harness_log_mark();
$provider->tenantUsage($st['u1']);
$pemRows = array_values(array_filter(mlog_raw($mark3), fn ($r) => str_contains($r['response'], $pemLine)));
foreach ($pemRows as $r) {
    out("  LEAK row #{$r['id']} {$r['action']}: " . substr($r['response'], 0, 400) . '...');
}
check('21-log-pem-harness', $pemRows === [], 'multi-line PEM ssl_key absent from tblmodulelog (harness logModuleCall = json_encode then str_replace)', array_map(fn ($r) => [$r['id'], $r['action']], $pemRows));
$resp = \WHMCS\Module\Server\Ispconfigconnect\Api\RestClient::scrubResponse('quota_get_by_user', [['domain_id' => '1', 'ssl_key' => $pem]]);
$vars = \WHMCS\Module\Server\Ispconfigconnect\Api\RestClient::maskableValues([['ssl_key' => $pem]]);
check('21-log-pem-printr', !str_contains(str_replace($vars, '***', print_r($resp, true)), $pemLine), 'same payload serialised with print_r (what WHMCS logModuleCall is believed to do for arrays) then str_replace: PEM masked');
pq('UPDATE web_domain SET ssl_key=? WHERE domain_id=1', [SSL_SENTINEL]);

hr('both collection paths agree (UsageUpdate vs MetricProvider)');
$captured = [];
ispconfigconnect_UsageUpdate(harness_server_params($server), null, function (int $id, array $cols) use (&$captured) { $captured[$id] = $cols; });
dump('UsageUpdate columns (capture writer)', $captured);
check('21-agree', ($captured[$s1]['diskusage'] ?? null) === 200 && ($captured[$s1]['bwusage'] ?? null) >= 500, 'UsageUpdate disk/bandwidth agree with the metrics', $captured[$s1] ?? null);

lt_finish('step21');
