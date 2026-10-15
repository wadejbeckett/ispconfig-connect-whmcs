<?php

/**
 * Step 24: Server Sync (read-only) - Configuration > Servers > Sync Accounts on ISPC-TEST.
 * WHMCS calls ispconfigconnect_ListAccounts(server params) and shows the accounts, matching each
 * on ListAccountsUniqueIdentifierField ('domain') against tblhosting.domain. The match step is
 * WHMCS core; it is EMULATED here with the same column comparison on the same WHMCS table and
 * collation, and nothing is imported or terminated.
 *
 * Panel clients: testone1 (S1, website), testtwo2 (S2, website-only product), mailonly3 (S3, no
 * website), handmade1 (made by hand, no website, not in WHMCS), plus orphan5 whose sys_user row is
 * missing (client_get_by_username faults for it). handmade1 carries PII and a multi-line id_rsa to
 * check the client_get(-1) log projection.
 */

declare(strict_types=1);

require __DIR__ . '/lib.php';

$st = lt_state();
$server = $st['server'];

hr('24.0 panel preparation');
$pem = (string) file_get_contents(LT_DIR . '/keys/test-ssl.key');
$pemLine = explode("\n", $pem)[1];
pq("UPDATE client SET street='Secret Street 99', bank_account_iban='DE89370400440532013000', vat_id='DE999999999', notes='private note xyz', id_rsa=? WHERE client_id=?", [$pem, $st['hand']]);
if (!isset($st['orphan'])) {
    $api = panel_api();
    $st['orphan'] = (int) $api->call('client_add', ['reseller_id' => 0, 'params' => [
        'company_name' => 'Orphan', 'contact_name' => 'Or Phan', 'email' => 'orphan@x.example', 'username' => 'orphan5',
        'password' => 'Orphan-Pass-55', 'language' => 'en', 'usertheme' => 'default', 'country' => 'US', 'template_master' => 2,
        'web_php_options' => 'no,fast-cgi,cgi,mod,suphp,php-fpm', 'ssh_chroot' => 'no,jailkit', 'default_mailserver' => 1,
        'default_webserver' => 1, 'default_dbserver' => 1, 'default_dnsserver' => 1, 'limit_web_ip' => '', 'created_at' => time(),
    ]]);
    $api->close();
    pq('DELETE FROM sys_user WHERE client_id=?', [$st['orphan']]);
    lt_save_state($st);
}
dump('panel clients', harness_panel_rows('SELECT client_id, username, email, locked, template_master, created_at FROM client ORDER BY client_id'));
dump('panel web_domain', harness_panel_rows('SELECT domain_id, domain, type, active, sys_groupid FROM web_domain ORDER BY domain_id'));

$md = ispconfigconnect_MetaData();
check('24-meta', ($md['ListAccountsUniqueIdentifierField'] ?? '') === 'domain' && ($md['ListAccountsUniqueIdentifierDisplayName'] ?? '') === 'Domain' && ($md['ListAccountsProductField'] ?? '') === 'configoption1', 'MetaData offers Sync Accounts: identifier field domain, product field configoption1', $md);

/** WHMCS core's match of one account against tblhosting (emulated). */
function whmcs_matches(int $serverId, string $identifier): array
{
    return \WHMCS\Database\Capsule::table('tblhosting')->where('server', $serverId)->where('domain', $identifier)->pluck('id')->all();
}

function run_sync(string $label): array
{
    $mark = harness_log_mark();
    $req = req_mark();
    $dataBefore = (int) (harness_panel_rows('SELECT COALESCE(MAX(datalog_id),0) m FROM sys_datalog')[0]['m']);
    $hostingBefore = \WHMCS\Database\Capsule::table('tblhosting')->get()->toJson();
    $r = ispconfigconnect_ListAccounts(harness_server_params(1));
    $reqs = req_since($req);
    out("  [$label] panel requests: " . count($reqs) . ' (' . logins_in($reqs) . ' login): ' . implode(' ', array_map(fn ($l) => preg_replace('~.*\?~', '', $l), $reqs)));
    return [
        'result' => $r, 'reqs' => $reqs, 'log' => mlog_raw($mark),
        'datalogUnchanged' => (int) (harness_panel_rows('SELECT COALESCE(MAX(datalog_id),0) m FROM sys_datalog')[0]['m']) === $dataBefore,
        'whmcsUnchanged' => \WHMCS\Database\Capsule::table('tblhosting')->get()->toJson() === $hostingBefore,
    ];
}

hr('24.1 Sync Accounts (all Active)');
$a = run_sync('active');
dump('ListAccounts', $a['result']);
$acc = [];
foreach ($a['result']['accounts'] ?? [] as $row) {
    $acc[$row['username']] = $row;
}
check('24-success', ($a['result']['success'] ?? false) === true && !isset($a['result']['error']), 'no error: success=true', $a['result']['success'] ?? null);
check('24-count', array_keys($acc) === ['testone1', 'testtwo2', 'mailonly3', 'handmade1', 'orphan5'], 'every panel client is listed, ordered by client id', array_keys($acc));
check('24-s1', ($acc['testone1']['domain'] ?? '') === 'test-one.example' && ($acc['testone1']['uniqueIdentifier'] ?? '') === 'test-one.example', 'testone1 listed with its primary domain test-one.example', $acc['testone1'] ?? null);
check('24-s2', ($acc['testtwo2']['domain'] ?? '') === 'test-two.example' && ($acc['testtwo2']['uniqueIdentifier'] ?? '') === 'test-two.example', 'testtwo2 listed with its primary domain', $acc['testtwo2'] ?? null);
check('24-nodomain-3', ($acc['mailonly3']['uniqueIdentifier'] ?? '') === 'client-' . $st['c3'] . '.no-domain.invalid' && ($acc['mailonly3']['domain'] ?? '') === 'client-' . $st['c3'] . '.no-domain.invalid', 'mailonly3 (no website) appears as client-' . $st['c3'] . '.no-domain.invalid', $acc['mailonly3'] ?? null);
check('24-nodomain-hand', ($acc['handmade1']['uniqueIdentifier'] ?? '') === 'client-' . $st['hand'] . '.no-domain.invalid', 'handmade1 (no website, not in WHMCS) appears as client-' . $st['hand'] . '.no-domain.invalid', $acc['handmade1'] ?? null);
check('24-orphan', ($acc['orphan5']['uniqueIdentifier'] ?? '') === 'client-' . $st['orphan'] . '.no-domain.invalid', 'orphan5 (sys_user missing; client_get_by_username faults) tolerated, synthetic identifier', $acc['orphan5'] ?? null);
check('24-status', array_unique(array_column($a['result']['accounts'] ?? [], 'status')) === ['Active'], 'all Active', array_column($a['result']['accounts'] ?? [], 'status'));
check('24-product', ($acc['testone1']['product'] ?? '') === '1' && ($acc['orphan5']['product'] ?? '') === '2', 'product = template_master id (configoption1): Basic=1, Pro=2', array_column($a['result']['accounts'] ?? [], 'product', 'username'));
check('24-fields', ($acc['testone1']['primaryip'] ?? '') === '127.0.0.1' && ($acc['testone1']['email'] ?? '') === 'jane@test.example' && preg_match('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', (string) ($acc['testone1']['created'] ?? '')) === 1, 'primaryip = server IP, email, created Y-m-d H:i:s', $acc['testone1'] ?? null);
check('24-readonly', $a['datalogUnchanged'] && $a['whmcsUnchanged'], 'read-only: no sys_datalog entry on the panel, tblhosting untouched');
check('24-login', logins_in($a['reqs']) === 1, 'one login for the whole sync', count($a['reqs']));

hr('24.2 how WHMCS would match them (emulated core step: tblhosting.domain = identifier)');
$matches = [];
foreach ($a['result']['accounts'] ?? [] as $row) {
    $matches[$row['username']] = whmcs_matches($server, $row['uniqueIdentifier']);
}
dump('matches', $matches);
check('24-match', $matches === ['testone1' => [$st['s1']], 'testtwo2' => [$st['s2']], 'mailonly3' => [], 'handmade1' => [], 'orphan5' => []], 'S1 matches (tblhosting.domain "Test-One.Example" vs "test-one.example" under utf8_unicode_ci), S2 matches; synthetic identifiers match nothing (no blank-domain match)', $matches);
// A blank-domain WHMCS service must never be matched by a no-website client.
$blank = harness_add_service($st['p4'], $server, '', ['userid' => 80, 'domainstatus' => 'Active']);
check('24-blank', whmcs_matches($server, 'client-' . $st['c3'] . '.no-domain.invalid') === [] && whmcs_matches($server, '') === [$blank], 'a blank-domain service would match an EMPTY identifier - the synthetic one keeps it unmatched', [$blank]);
\WHMCS\Database\Capsule::table('tblhosting')->where('id', $blank)->delete();

hr('24.3 privacy of the client_get(-1) log row');
$cg = array_values(array_filter($a['log'], fn ($r) => $r['action'] === 'client_get'));
$blob = json_encode($a['log'], JSON_UNESCAPED_SLASHES);
out('  client_get log response (first 700): ' . substr($cg[0]['response'] ?? '', 0, 700));
foreach (['street' => 'Secret Street 99', 'IBAN' => 'DE89370400440532013000', 'VAT id' => 'DE999999999', 'notes' => 'private note xyz', 'client password' => 'Hand-Made-Pass-9'] as $what => $needle) {
    check('24-log-' . str_replace(' ', '-', $what), !str_contains($blob, $needle), "ListAccounts module log carries no $what", substr_count($blob, $needle));
}
check('24-log-id_rsa-harness', !str_contains($blob, $pemLine), 'multi-line client.id_rsa absent from the harness tblmodulelog (masking by value; harness json_encodes before str_replace)', substr_count($blob, $pemLine));

hr('24.4 statuses: S1 entire-client suspended (locked), S2 website-only suspended');
check('24-susp1', harness_call('SuspendAccount', $st['s1']) === 'success', 'Suspend S1 (entire client)');
check('24-susp2', harness_call('SuspendAccount', $st['s2']) === 'success', 'Suspend S2 (website only)');
$b = run_sync('suspended');
$bacc = array_column($b['result']['accounts'] ?? [], 'status', 'username');
dump('statuses', $bacc);
check('24-status-susp', $bacc === ['testone1' => 'Suspended', 'testtwo2' => 'Suspended', 'mailonly3' => 'Active', 'handmade1' => 'Active', 'orphan5' => 'Active'], 'locked client -> Suspended; inactive primary website -> Suspended; others Active', $bacc);
check('24-unsusp', harness_call('UnsuspendAccount', $st['s1']) === 'success' && harness_call('UnsuspendAccount', $st['s2']) === 'success', 'Unsuspend S1 and S2');
$c = run_sync('restored');
check('24-status-back', array_unique(array_column($c['result']['accounts'] ?? [], 'status')) === ['Active'], 'all Active again', array_column($c['result']['accounts'] ?? [], 'status', 'username'));

hr('24.5 ISPC-NEGATIVE (wrong password): the sync screen gets an error string, no exception');
if (isset($st['neg']) && \WHMCS\Database\Capsule::table('tblservers')->where('id', $st['neg'])->exists()) {
    pq('DELETE FROM attempts_login');
    $req = req_mark();
    $n = ispconfigconnect_ListAccounts(harness_server_params($st['neg']));
    dump('ListAccounts(ISPC-NEGATIVE)', $n);
    check('24-neg', ($n['success'] ?? true) === false && is_string($n['error'] ?? null) && str_contains($n['error'], 'rejected the login') && logins_in(req_since($req)) === 1, 'NEGATIVE: success=false with the login advice, one failed login', $n);
    pq('DELETE FROM attempts_login');
}

lt_finish('step24');
