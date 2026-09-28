<?php

/** Fresh WHMCS side (whmcs4) + provisioning of S1, S2, S3 on panel 4 and S5 on panel 5. */

declare(strict_types=1);

require __DIR__ . '/lib.php';

use WHMCS\Database\Capsule;

hdr('SETUP: WHMCS tables in ' . harness_db_name() . ', panels dbispconfig4 (8084) and dbispconfig5 (8085 / 127.0.0.2)');
harness_reset_whmcs();

$srv1 = harness_add_server(['name' => 'ISPC-TEST', 'hostname' => '127.0.0.1', 'ipaddress' => '127.0.0.1', 'port' => 8084]);
// ISPC-NEGATIVE: the same panel and (per the plan) the same Hostname.
$srv2 = harness_add_server(['name' => 'ISPC-NEGATIVE', 'hostname' => '127.0.0.1', 'ipaddress' => '127.0.0.1', 'port' => 8084]);
// ISPC-TEST-2: a DIFFERENT ISPConfig panel (instance 5), reached on its own loopback address.
$srv3 = harness_add_server(['name' => 'ISPC-TEST-2', 'hostname' => '127.0.0.2', 'ipaddress' => '127.0.0.2', 'port' => 8085]);
out("servers: ISPC-TEST=$srv1 ISPC-NEGATIVE=$srv2 ISPC-TEST-2=$srv3");

foreach ([$srv1, $srv3] as $s) {
    $tc = ispconfigconnect_TestConnection(harness_server_params($s));
    out("TestConnection server $s: " . json_encode($tc));
}

$p1 = harness_add_product('ISPC Entire');
$p2 = harness_add_product('ISPC Website-only', [
    'configoption5' => '', 'configoption6' => '', 'configoption11' => '', 'configoption13' => '',
]);
$p3 = harness_add_product('ISPC Pro', ['configoption1' => '2']);
out("products: P1=$p1 P2=$p2 P3=$p3");

$C1 = 77;
$C2 = 88;
$C3 = 99;
$s1 = harness_add_service($p1, $srv1, 'Test-One.Example', ['userid' => $C1]);
$s2 = harness_add_service($p2, $srv1, 'test-two.example', ['userid' => $C2]);
$s3 = harness_add_service($p2, $srv1, 'test-three.example', ['userid' => $C2]);
$s4 = harness_add_service($p1, $srv1, 'test-four.example', ['userid' => $C2, 'domainstatus' => 'Active']);
$s5 = harness_add_service($p1, $srv3, 'test-five.example', ['userid' => $C3]);
$s6 = harness_add_service($p1, $srv2, 'test-six.example', ['userid' => $C3, 'domainstatus' => 'Active']);
out("services: S1=$s1 S2=$s2 S3=$s3 S4=$s4 (not created) S5=$s5 (ISPC-TEST-2) S6=$s6 (ISPC-NEGATIVE, not created)");

foreach ([$s1, $s2, $s3, $s5] as $sid) {
    $mark = harness_log_mark();
    $r = harness_call('CreateAccount', $sid);
    out("Create S$sid: $r");
    if ($r !== 'success') {
        print_log(log_rows($mark));
    }
    print_props($sid);
}

$state = [
    'srv1' => $srv1, 'srv2' => $srv2, 'srv3' => $srv3, 'p1' => $p1, 'p2' => $p2, 'p3' => $p3,
    'C1' => $C1, 'C2' => $C2, 'C3' => $C3,
    's1' => $s1, 's2' => $s2, 's3' => $s3, 's4' => $s4, 's5' => $s5, 's6' => $s6,
];
foreach (['s1', 's2', 's3', 's5'] as $k) {
    $r = module_reads($state[$k]);
    $state[$k . '_client'] = $r['client_id'];
    $state[$k . '_user'] = $r['username'];
}
save_state($state);
out(json_encode($state));

out('panel 4 clients:');
foreach (prow('SELECT client_id, username, locked, template_master, notes FROM client') as $r) {
    out('  ' . json_encode($r));
}
out('panel 5 clients:');
foreach (prow('SELECT client_id, username, locked, template_master, notes FROM client', 'dbispconfig5') as $r) {
    out('  ' . json_encode($r));
}
out('tblhosting:');
foreach (Capsule::table('tblhosting')->get(['id', 'userid', 'packageid', 'server', 'domain', 'domainstatus', 'username']) as $r) {
    out('  ' . json_encode($r));
}
