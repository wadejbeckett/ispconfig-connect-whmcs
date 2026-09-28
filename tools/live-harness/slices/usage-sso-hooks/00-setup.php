<?php

/**
 * Setup for the usage-sso-hooks slice: WHMCS server ISPC-TEST (panel 6), products P1 (entire
 * client), P2 (website-only), P4 (mail-only - a client with no website), services S1 (C1, P1),
 * S2 (C2, P2), S3 (C2, P4), all created through the module; plus one hand-made panel client
 * with no website that WHMCS does not know about.
 */

declare(strict_types=1);

require __DIR__ . '/lib.php';

harness_reset_whmcs();
$server = harness_add_server(['name' => 'ISPC-TEST']);
$p1 = harness_add_product('ISPC Entire');
$p2 = harness_add_product('ISPC Website-only', [
    'configoption5' => '', 'configoption6' => '', 'configoption11' => '', 'configoption13' => '',
]);
$p4 = harness_add_product('ISPC Mail-only', [
    'configoption3' => '', 'configoption4' => '', 'configoption5' => 'on', 'configoption6' => '', 'configoption11' => '',
]);

$s1 = harness_add_service($p1, $server, 'Test-One.Example', ['userid' => 77]);
$s2 = harness_add_service($p2, $server, 'test-two.example', ['userid' => 78]);
$s3 = harness_add_service($p4, $server, 'mailonly.example', ['userid' => 78]);

hr('TestConnection');
dump('TestConnection', ispconfigconnect_TestConnection(harness_server_params($server)));

foreach (['S1' => $s1, 'S2' => $s2, 'S3' => $s3] as $name => $sid) {
    hr("Create $name (#$sid)");
    $mark = harness_log_mark();
    $r = harness_call('CreateAccount', $sid);
    dump("CreateAccount $name", $r);
    out('  actions: ' . implode(', ', mlog_actions($mark)));
    dump("  service", svc($sid));
    dump("  props", props($sid));
    if ($r !== 'success') {
        fwrite(STDERR, "Create $name failed\n");
        exit(1);
    }
}

// A client made by hand in ISPConfig (no website, not in WHMCS) - for Server Sync.
hr('hand-made panel client (no website)');
$api = panel_api();
$handId = (int) $api->call('client_add', [
    'reseller_id' => 0,
    'params' => [
        'company_name' => 'Handmade Ltd', 'contact_name' => 'Hand Made', 'customer_no' => 'HM-1',
        'email' => 'hand@made.example', 'username' => 'handmade1', 'password' => 'Hand-Made-Pass-9',
        'language' => 'en', 'usertheme' => 'default', 'country' => 'US', 'template_master' => 1,
        'web_php_options' => 'no,fast-cgi,cgi,mod,suphp,php-fpm', 'ssh_chroot' => 'no,jailkit',
        'default_mailserver' => 1, 'default_webserver' => 1, 'default_dbserver' => 1, 'default_dnsserver' => 1,
        'limit_web_ip' => '', 'notes' => 'made by hand', 'created_at' => time(),
    ],
]);
$api->close();
dump('client_add handmade1 -> client_id', $handId);

$state = [
    'server' => $server, 'p1' => $p1, 'p2' => $p2, 'p4' => $p4,
    's1' => $s1, 's2' => $s2, 's3' => $s3,
    'c1' => props($s1)['client_id'], 'c2' => props($s2)['client_id'], 'c3' => props($s3)['client_id'],
    'u1' => props($s1)['client_username'], 'u2' => props($s2)['client_username'], 'u3' => props($s3)['client_username'],
    'hand' => $handId,
];
lt_save_state($state);
hr('state');
dump('state', $state);
dump('panel clients', harness_panel_rows('SELECT client_id, username, locked, template_master, created_at FROM client ORDER BY client_id'));
dump('panel web_domain', harness_panel_rows('SELECT domain_id, domain, type, active, sys_groupid, system_user, hd_quota, traffic_quota FROM web_domain ORDER BY domain_id'));
dump('panel sys_group', harness_panel_rows('SELECT groupid, name, client_id FROM sys_group ORDER BY groupid'));
dump('panel web_database', harness_panel_rows('SELECT database_id, database_name, sys_groupid, database_quota FROM web_database'));
dump('panel ftp_user', harness_panel_rows('SELECT ftp_user_id, username, sys_groupid FROM ftp_user'));
dump('panel dns_soa', harness_panel_rows('SELECT id, origin, sys_groupid FROM dns_soa'));
dump('panel mail_domain', harness_panel_rows('SELECT domain_id, domain, sys_groupid FROM mail_domain'));
