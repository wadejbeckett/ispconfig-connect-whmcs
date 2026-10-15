<?php
// Setup + Step 9: Create (S1 on P1 for C1, domain Test-One.Example, username blank) + IDN create.
declare(strict_types=1);
require __DIR__ . '/lib.php';

@unlink(LT_DIR . '/assertions.log');
harness_reset_whmcs();
$server = harness_add_server();
$P1 = harness_add_product('ISPC Entire');                    // template Basic, all on, suspend entire on
$P2 = harness_add_product('ISPC Website-only', [             // template Basic, website+FTP (+mail+DB so "mail/DB keep working" is observable), suspend entire OFF
    'configoption5' => 'on', 'configoption6' => '', 'configoption11' => 'on', 'configoption13' => '',
]);
$P3 = harness_add_product('ISPC Pro', ['configoption1' => '2']); // as P1, template Pro
$P4 = harness_add_product('ISPC Website-only Pro', [          // copy of P2 with template Pro (step 16a)
    'configoption1' => '2', 'configoption5' => 'on', 'configoption6' => '', 'configoption11' => 'on', 'configoption13' => '',
]);
$C1 = 77;
$C2 = 78;
$state = compact('server', 'P1', 'P2', 'P3', 'P4', 'C1', 'C2');

hr('Setup');
out('TestConnection: ' . json_encode(ispconfigconnect_TestConnection(harness_server_params($server))));
out("products: P1=$P1 P2=$P2 P3=$P3 P4=$P4; server=$server");
foreach (\WHMCS\Database\Capsule::table('tblproducts')->get() as $p) {
    out(sprintf('  product %d %-22s co1=%s co3=%s co4=%s co5=%s co6=%s co11=%s co13=%s', $p->id, $p->name, $p->configoption1, $p->configoption3, $p->configoption4, $p->configoption5, $p->configoption6, $p->configoption11, var_export($p->configoption13, true)));
}

hr('Step 9: Create S1 (P1, C1, Test-One.Example, username blank, password set)');
$S1 = harness_add_service($P1, $server, 'Test-One.Example', ['userid' => $C1, 'username' => '']);
$state['S1'] = $S1;
$svcPass = svc($S1) + ['password' => \WHMCS\Database\Capsule::table('tblhosting')->where('id', $S1)->value('password')];
out('  service: ' . json_encode(svc($S1)));
$dlBefore = dl_mark();
$r = lt_call('CreateAccount', $S1);
assert_line('9', $r['result'] === 'success', 'Create returns "success" (Module Command Success): got ' . json_encode($r['result']));
print_props($S1);
$eff = props_effective($S1);
$N = (int) $eff['client_id'];
$state['N1'] = $N;
$snap = print_snap($N, '(after Create)');
$c = $snap['client'];
assert_line('9', $c !== null && $c['notes'] === 'Managed by WHMCS service #' . $S1, 'new client notes = "Managed by WHMCS service #' . $S1 . '": got ' . json_encode($c['notes'] ?? null));
$wd = $snap['web_domain'][0] ?? null;
assert_line('9', $wd !== null && $wd['domain'] === 'test-one.example' && count($snap['web_domain']) === 1, 'website test-one.example in lower case: got ' . json_encode($snap['web_domain']));
assert_line('9', count($snap['ftp_user']) === 1, 'an FTP user exists: ' . json_encode($snap['ftp_user']));
assert_line('9', count($snap['mail_domain']) === 1 && $snap['mail_domain'][0]['domain'] === 'test-one.example', 'the mail domain exists: ' . json_encode($snap['mail_domain']));
assert_line('9', count($snap['dns_soa']) === 1 && rtrim($snap['dns_soa'][0]['origin'], '.') === 'test-one.example', 'a DNS zone exists: ' . json_encode($snap['dns_soa']));
assert_line('9', count($snap['web_database']) === 1, 'a database exists: ' . json_encode($snap['web_database']));
$want = ['client_id', 'client_username', 'domain_id', 'ftp_user_id', 'mail_domain_id', 'dns_zone_id', 'database_id', 'database_user_id'];
$missing = array_filter($want, fn ($k) => $eff[$k] === null || $eff[$k] === '');
assert_line('9', $missing === [], 'S1 custom fields hold client id, username, website id and the other ids; missing: ' . json_encode(array_values($missing)));
$ids_ok = $eff['domain_id'] === (int) $wd['domain_id'] && $eff['ftp_user_id'] === (int) $snap['ftp_user'][0]['ftp_user_id']
    && $eff['mail_domain_id'] === (int) $snap['mail_domain'][0]['domain_id'] && $eff['dns_zone_id'] === (int) $snap['dns_soa'][0]['id']
    && $eff['database_id'] === (int) $snap['web_database'][0]['database_id'] && $eff['client_username'] === $c['username'];
assert_line('9', $ids_ok, 'stored ids match the panel rows (domain/ftp/mail/dns/db/username)');
$adminOnly = array_unique(array_map(fn ($r) => $r['adminonly'], props_rows($S1)));
assert_line('9', $adminOnly === ['on'], 'custom fields are admin-only (adminonly=on): ' . json_encode($adminOnly));
$pw = (string) \WHMCS\Database\Capsule::table('tblhosting')->where('id', $S1)->value('password');
assert_line('9', log_free_of($r['log'], $pw) && log_free_of($r['log'], HARNESS_REMOTE_PASS), 'service password "' . $pw . '" and remote password never appear in the Module Log rows');
$sid_leak = true;
foreach ($r['log'] as $row) {
    if (preg_match('/"session_id":"(?!\*\*\*)[0-9a-f]{10,}/', (string) $row->request . $row->response)) { $sid_leak = false; }
}
assert_line('9', $sid_leak, 'no unmasked session id in the Module Log');
out('  datalog entries queued by Create: ' . count(dl_since($dlBefore)) . ' (server.sh does not run here, so they stay queued)');
out('  WHMCS tblhosting.username after Create: ' . json_encode(svc($S1)['username']) . ' (harness model has no save(), so the write-back cannot land; the module stores the username as ispconfig_client_username)');

$tab = ispconfigconnect_AdminServicesTabFields(harness_params($S1));
out('  AdminServicesTabFields: ' . json_encode($tab, JSON_UNESCAPED_SLASHES));
$expect = sprintf('Linked to ISPConfig client #%d (%s).', $N, $c['username']);
assert_line('9', ($tab['ISPConfig Link'] ?? null) === $expect, 'admin tab shows "' . $expect . '": got ' . json_encode($tab['ISPConfig Link'] ?? null));

hr('Step 9 (IDN): Create S5 (P1, C1, domain Bücher-Test.Example) - intl is loaded: ' . (extension_loaded('intl') ? 'yes' : 'no'));
$S5 = harness_add_service($P1, $server, 'Bücher-Test.Example', ['userid' => $C1, 'username' => '']);
$state['S5'] = $S5;
$r = lt_call('CreateAccount', $S5);
assert_line('9-IDN', $r['result'] === 'success', 'IDN Create returns success: ' . json_encode($r['result']));
if ($r['result'] === 'success') {
    $e5 = props_effective($S5);
    $s5 = print_snap((int) $e5['client_id'], '(IDN client)');
    $puny = idn_to_ascii('bücher-test.example', IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);
    assert_line('9-IDN', ($s5['web_domain'][0]['domain'] ?? null) === $puny, "website stored as punycode $puny: got " . json_encode($s5['web_domain'][0]['domain'] ?? null));
    assert_line('9-IDN', ($s5['mail_domain'][0]['domain'] ?? null) === $puny && rtrim($s5['dns_soa'][0]['origin'] ?? '', '.') === $puny, 'mail domain and DNS zone use the punycode too: ' . json_encode([$s5['mail_domain'], $s5['dns_soa']]));
    $state['N5'] = (int) $e5['client_id'];
}
lt_save_state($state);
out();
out('state: ' . json_encode($state));
