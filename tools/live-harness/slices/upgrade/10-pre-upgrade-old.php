<?php
/**
 * PHASE 0 - pre-upgrade state, built ENTIRELY by the old build (1ed05b3).
 * MODULE_DIR=/opt/livetest/upgrade/old/modules/servers/ispconfigconnect
 */
declare(strict_types=1);
require __DIR__ . '/common.php';

use WHMCS\Database\Capsule;

if (!str_starts_with((string) getenv('MODULE_DIR'), '/opt/livetest/upgrade/old')) {
    fwrite(STDERR, "phase 0 must run under the OLD build\n");
    exit(2);
}

harness_reset_whmcs();
$state = ['svc' => [], 'prod' => [], 'client' => [], 'web' => []];

up_h('P0.0 server + Test Connection (old build)');
$server = harness_add_server();
$state['server'] = $server;
$m = harness_log_mark();
up_p('TestConnection', ispconfigconnect_TestConnection(harness_server_params($server)));
up_p('log rows', count(up_log($m)));

up_h('P0.1 products');
$p = [];
$websiteOnly = ['configoption5' => '', 'configoption6' => '', 'configoption11' => '', 'configoption13' => ''];
$p['P1'] = harness_add_product('ISPC Entire');
$p['P2'] = harness_add_product('ISPC Website-only', $websiteOnly);
$p['P3'] = harness_add_product('ISPC Pro', ['configoption1' => '2']);
$p['P1b'] = harness_add_product('ISPC Entire (flip to website-only after upgrade)');
$p['P2b'] = harness_add_product('ISPC Website-only (flip to entire after upgrade)', $websiteOnly);
$p['P2c'] = harness_add_product('ISPC Website-only (flip to entire, re-suspend)', $websiteOnly);
$p['P2pro'] = harness_add_product('ISPC Website-only Pro', $websiteOnly + ['configoption1' => '2']);
$p['P2d'] = harness_add_product('ISPC Website-only (rollback mode flip)', $websiteOnly);
$state['prod'] = $p;
foreach ($p as $k => $id) {
    $row = Capsule::table('tblproducts')->where('id', $id)->first();
    printf("  %-6s id=%d template=%s co13(suspend entire)='%s' web=%s ftp=%s mail=%s dns=%s db=%s\n", $k, $id, $row->configoption1, $row->configoption13, $row->configoption3, $row->configoption4, $row->configoption5, $row->configoption6, $row->configoption11);
}

/** Create a service and run the OLD CreateAccount; returns the service id. */
$create = static function (string $key, string $product, string $domain, int $userid, string $username) use (&$state, $p, $server): int {
    $svc = harness_add_service($p[$product], $server, $domain, ['userid' => $userid, 'username' => $username]);
    $state['svc'][$key] = $svc;
    $r = up_call('CreateAccount', $svc, [], "$key on $product");
    if ($r['result'] !== 'success') {
        throw new RuntimeException("create $key failed: " . $r['result']);
    }
    $props = [];
    foreach (up_props($svc) as $row) {
        $props[$row['fieldname']] = $row['value'];
    }
    $state['client'][$key] = (int) $props['ispconfig_client_id'];
    $state['web'][$key] = (int) ($props['ispconfig_domain_id'] ?? 0);
    up_show_props($svc);
    up_show_client((int) $props['ispconfig_client_id']);

    return $svc;
};

up_h('P0.2 entire-client services (P1): two Active, one Suspended; one on P1b Suspended');
$create('A', 'P1', 'entire-active.test', 101, 'upa');
$create('A2', 'P1', 'entire-active2.test', 102, 'upa2');
$create('B1', 'P1', 'entire-susp.test', 103, 'upb1');
up_call('SuspendAccount', $state['svc']['B1'], [], 'old entire-client suspend');
up_show_client($state['client']['B1'], 'after old suspend');
$create('B2', 'P1b', 'entire-susp-flip.test', 104, 'upb2');
up_call('SuspendAccount', $state['svc']['B2'], [], 'old entire-client suspend (product will become website-only)');
up_show_client($state['client']['B2'], 'after old suspend');

up_h('P0.3 website-only services (P2): one Active, Suspended ones on P2 / P2b / P2c');
$create('C', 'P2', 'web-active.test', 105, 'upc');
$create('D1', 'P2', 'web-susp.test', 106, 'upd1');
up_call('SuspendAccount', $state['svc']['D1'], [], 'old website-only suspend');
up_show_client($state['client']['D1'], 'after old suspend');
$create('D2', 'P2b', 'web-susp-flip.test', 107, 'upd2');
up_call('SuspendAccount', $state['svc']['D2'], [], 'old website-only suspend (product will become entire)');
up_show_client($state['client']['D2'], 'after old suspend');
$create('D3', 'P2c', 'web-susp-flip2.test', 108, 'upd3');
up_call('SuspendAccount', $state['svc']['D3'], [], 'old website-only suspend (product will become entire; re-suspend)');
up_show_client($state['client']['D3'], 'after old suspend');

up_h('P0.4 website-only services linked by Link under the old build (no website id stored)');
foreach (['E' => ['web-link.test', 109, 'upe'], 'E2' => ['web-link-susp.test', 110, 'upe2']] as $key => [$dom, $uid, $user]) {
    $svc = $create($key, 'P2', $dom, $uid, $user);
    up_call('UnlinkAccount', $svc, [], 'old Unlink');
    up_show_props($svc, 'after old Unlink');
    up_call('LinkAccount', $svc, [], "old Link as '$user'");
    up_show_props($svc, 'after old Link - note: no domain_id');
}
$r = up_call('SuspendAccount', $state['svc']['E2'], [], 'old website-only suspend with NO website id');
up_show_client($state['client']['E2'], 'after old suspend of E2 (website expected to stay active = old no-op)');

up_h('P0.5 Change Package (P1 -> P3) then Unlink under the old build (rows on two products)');
$svcF = $create('F', 'P1', 'cp-unlink.test', 111, 'upf');
Capsule::table('tblhosting')->where('id', $svcF)->update(['packageid' => $p['P3']]);
up_call('ChangePackage', $svcF, [], 'old Change Package to P3');
up_show_props($svcF, 'after old ChangePackage');
up_show_client($state['client']['F'], 'after old ChangePackage');
up_call('UnlinkAccount', $svcF, [], 'old Unlink after package change');
up_show_props($svcF, 'after old Unlink');
$tab = ispconfigconnect_AdminServicesTabFields(harness_params($svcF));
up_p('    OLD admin tab after old Unlink: Client ID', $tab['ISPConfig Client ID'] ?? '(none)');
up_p('    OLD admin tab after old Unlink: Link', $tab['ISPConfig Link'] ?? '(none)');

up_h('P0.6 Change Package (P1 -> P3) then Replace to another client under the old build');
$create('X', 'P1', 'orphan-x.test', 119, 'upx');
up_call('UnlinkAccount', $state['svc']['X'], [], 'old Unlink X: its client becomes a panel client no service holds');
$svcM = $create('M', 'P1', 'cp-relink.test', 118, 'upm');
Capsule::table('tblhosting')->where('id', $svcM)->update(['packageid' => $p['P3']]);
up_call('ChangePackage', $svcM, [], 'old Change Package to P3');
Capsule::table('tblhosting')->where('id', $svcM)->update(['username' => 'upx']);
up_call('RelinkAccount', $svcM, [], "old Replace ISPConfig Link as 'upx'");
up_show_props($svcM, 'after old Replace');

up_h('P0.7 two services sharing one client (old Link had no shared-client guard)');
$create('G', 'P1', 'shared-a.test', 112, 'upg');
$svcH = harness_add_service($p['P1'], $server, 'shared-b.test', ['userid' => 113, 'username' => 'upg', 'domainstatus' => 'Active']);
$state['svc']['H'] = $svcH;
up_call('LinkAccount', $svcH, [], "old Link H as 'upg' (G's client)");
up_show_props($svcH);
up_call('SuspendAccount', $state['svc']['G'], [], 'old Suspend G locks the SHARED client (H goes down too)');
up_show_client($state['client']['G'], 'shared client after old suspend of G');

$create('I', 'P1', 'shared-c.test', 114, 'upi');
$svcJ = harness_add_service($p['P1'], $server, 'shared-d.test', ['userid' => 115, 'username' => 'upi', 'domainstatus' => 'Active']);
$state['svc']['J'] = $svcJ;
up_call('LinkAccount', $svcJ, [], "old Link J as 'upi' (I's client)");
up_call('SuspendAccount', $state['svc']['I'], [], 'old Suspend I (locks shared client)');
up_call('SuspendAccount', $svcJ, [], 'old Suspend J (already locked: no-op success)');
up_show_client($state['client']['I'], 'shared client I/J, both Suspended');

up_h('P0.8 old-build package-change damage (plan 2.10)');
$svcK = $create('K', 'P1', 'cp-locked.test', 116, 'upk');
up_call('SuspendAccount', $svcK, [], 'old entire suspend');
up_show_client($state['client']['K'], 'K locked, before old ChangePackage');
Capsule::table('tblhosting')->where('id', $svcK)->update(['packageid' => $p['P3']]);
up_call('ChangePackage', $svcK, [], 'old Change Package on a LOCKED client');
up_show_client($state['client']['K'], 'K after old ChangePackage while locked');

$svcL = $create('L', 'P2', 'cp-websusp.test', 117, 'upl');
up_call('SuspendAccount', $svcL, [], 'old website-only suspend');
up_show_client($state['client']['L'], 'L after old website suspend');
Capsule::table('tblhosting')->where('id', $svcL)->update(['packageid' => $p['P2pro']]);
up_call('ChangePackage', $svcL, [], 'old Change Package on a website-suspended service');
up_show_client($state['client']['L'], 'L after old ChangePackage (website expected re-activated = old damage)');

up_h('P0.9 hand-edited non-positive/non-numeric client ids (plan 2.8)');
$fieldP1 = (int) Capsule::table('tblcustomfields')->where('type', 'product')->where('relid', $p['P1'])->where('fieldname', 'ispconfig_client_id')->value('id');
foreach (['Z0' => ['bad-zero.test', '0'], 'Zabc' => ['bad-abc.test', 'abc']] as $key => [$dom, $val]) {
    $svc = harness_add_service($p['P1'], $server, $dom, ['userid' => 121, 'domainstatus' => 'Active']);
    $state['svc'][$key] = $svc;
    Capsule::table('tblcustomfieldsvalues')->insert(['fieldid' => $fieldP1, 'relid' => $svc, 'value' => $val]);
    up_show_props($svc, "hand-set ispconfig_client_id='$val'");
}

up_h('P0.10 rollback-hazard product P2d service (website-only)');
$create('R', 'P2d', 'rollback-flip.test', 122, 'upr');

up_h('P0.11 usage sweep + bandwidth carry-forward under the old build (plan 2.11)');
$ym = date('Y-m-d');
$traffic = ['entire-active.test' => 524288000, 'cp-relink.test' => 104857600, 'orphan-x.test' => 314572800, 'cp-unlink.test' => 209715200];
foreach ($traffic as $host => $bytes) {
    harness_panel()->query("INSERT INTO web_traffic (hostname, traffic_date, traffic_bytes) VALUES ('$host', '$ym', $bytes) ON DUPLICATE KEY UPDATE traffic_bytes = $bytes");
}
up_p('seeded web_traffic (bytes)', $traffic);
$lastMonth = date('Y-m-d', strtotime('first day of last month')) . ' 12:00:00';
Capsule::table('tblhosting')->where('id', $state['svc']['A'])->update(['bwusage' => 99999, 'lastupdate' => $lastMonth]);
up_p("S_A bwusage=99999 lastupdate=$lastMonth (previous month's peak)");
$m = harness_log_mark();
ispconfigconnect_UsageUpdate(harness_server_params($server));
foreach (up_log($m, 1500) as $l) {
    if (str_contains($l, 'UsageUpdate')) {
        echo "  ", $l, "\n";
    }
}
foreach (Capsule::table('tblhosting')->whereIn('id', [$state['svc']['A'], $state['svc']['M'], $state['svc']['F']])->get(['id', 'domain', 'bwusage', 'lastupdate']) as $h) {
    printf("  svc #%d %-20s bwusage=%s lastupdate=%s\n", $h->id, $h->domain, $h->bwusage, $h->lastupdate);
}

up_h('P0.12 server metadata as the old build reports it (WHMCS caches it in tblservers_remote)');
$meta = ispconfigconnect_GetRemoteMetaData(harness_server_params($server));
up_p('old GetRemoteMetaData', $meta);
Capsule::table('tblservers_remote')->insert(['server_id' => $server, 'num_accounts' => (int) ($meta['accounts'] ?? 0), 'meta_data' => json_encode($meta), 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
up_p('tblservers_remote.meta_data (old)', (string) Capsule::table('tblservers_remote')->where('server_id', $server)->value('meta_data'));

up_state_save($state);
up_h('P0 done - state saved');
up_p('services', $state['svc']);
up_p('clients', $state['client']);
up_p('websites', $state['web']);
