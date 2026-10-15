<?php
/**
 * PHASE 3 - plan section 4 steps 4-6: the OLD build back on the data the new
 * build wrote. MODULE_DIR = old build.
 */
declare(strict_types=1);
require __DIR__ . '/common.php';

use WHMCS\Database\Capsule;

if (!str_starts_with((string) getenv('MODULE_DIR'), '/opt/livetest/upgrade/old')) {
    fwrite(STDERR, "phase 3 must run under the OLD build\n");
    exit(2);
}
$state = up_state();
$S = $state['svc'];
$C = $state['client'];
$Wb = $state['web'];
$Pr = $state['prod'];
$checks = [];
$ok = static function (bool $cond, string $label) use (&$checks): void {
    $checks[] = [$cond ? 'OK  ' : 'FAIL', $label];
    echo '    [', $cond ? 'OK' : 'FAIL', '] ', $label, "\n";
};
$susp = static fn (int $svc): string => json_encode(Capsule::table('tblcustomfieldsvalues as v')->join('tblcustomfields as f', 'f.id', '=', 'v.fieldid')
    ->where('v.relid', $svc)->where('f.fieldname', 'ispconfig_suspended_as')->orderByDesc('v.id')->pluck('v.value')->all());

up_h('4.4 Test Connection and the Servers page with the restored build');
$m = harness_log_mark();
$tc = ispconfigconnect_TestConnection(harness_server_params($state['server']));
up_p('old TestConnection', $tc);
$ok(($tc['success'] ?? false) === true, 'old Test Connection succeeds');
up_p('cached meta_data (written by the new build)', (string) Capsule::table('tblservers_remote')->where('server_id', $state['server'])->value('meta_data'));
$meta = ispconfigconnect_GetRemoteMetaData(harness_server_params($state['server']));
up_p('old GetRemoteMetaData', $meta);
Capsule::table('tblservers_remote')->where('server_id', $state['server'])->update(['meta_data' => json_encode($meta)]);

up_h('4.5 old build on the data the new build wrote');
echo "  admin tab + client area of every service under the old build:\n";
foreach ($state['svc'] as $k => $svc) {
    $tab = ispconfigconnect_AdminServicesTabFields(harness_params($svc));
    printf("    %-5s svc #%-3d rows=%d ClientID=%-10s Domain=%-4s | %s\n", $k, $svc, count($tab), $tab['ISPConfig Client ID'] ?? '(none)', $tab['ISPConfig Domain ID'] ?? '-', mb_substr(html_entity_decode((string) ($tab['ISPConfig Link'] ?? '(EMPTY TAB)')), 0, 90));
}
$tabF = ispconfigconnect_AdminServicesTabFields(harness_params($S['F']));
$ok(($tabF['ISPConfig Client ID'] ?? '') === 'not linked', 'F (rows blanked by the new Unlink) reads unlinked under the old build too');

echo "\n  A2 was suspended by the NEW build (entire client, ispconfig_suspended_as=client): ", $susp($S['A2']), "\n";
$r = up_call('UnsuspendAccount', $S['A2'], [], 'old Unsuspend of a new-build suspension');
$ok($r['result'] === 'success' && up_client($C['A2'])['locked'] === 'n', 'old build unsuspends it');
up_p('    A2 ispconfig_suspended_as after the OLD unsuspend (old build does not know the key)', $susp($S['A2']));

echo "\n  R was suspended by the NEW build (website-only, ispconfig_suspended_as=website): ", $susp($S['R']), "\n";
$r = up_call('UnsuspendAccount', $S['R'], [], 'old Unsuspend of a new-build website-only suspension');
$ok($r['result'] === 'success' && up_web_active($Wb['R']) === 'y', 'old build reactivates the website');
up_p('    R ispconfig_suspended_as after the OLD unsuspend', $susp($S['R']));

echo "\n  E: website id stored by the NEW build's Replace\n";
$r = up_call('SuspendAccount', $S['E'], [], 'old website-only Suspend now that the id is stored');
$ok($r['result'] === 'success' && up_web_active($Wb['E']) === 'n', "old build suspends E's website (website ids stored by Replace stay stored)");
$r = up_call('UnsuspendAccount', $S['E']);
$ok($r['result'] === 'success' && up_web_active($Wb['E']) === 'y', 'old Unsuspend E');
up_p('    E ispconfig_suspended_as (written website then blank by the new build; old build leaves it)', $susp($S['E']));

echo "\n  D3: carries ispconfig_suspended_as rows on P2c; old Change Package to P2\n";
Capsule::table('tblhosting')->where('id', $S['D3'])->update(['packageid' => $Pr['P2']]);
$r = up_call('ChangePackage', $S['D3'], [], 'old Change Package on a service with the new field');
up_show_props($S['D3']);
$ok($r['result'] === 'success', 'old Change Package works with the new field present');
Capsule::table('tblhosting')->where('id', $S['D3'])->update(['packageid' => $Pr['P2c']]);

$r = up_call('ChangePassword', $S['C'], [], 'old Change Password on an untouched service');
$ok($r['result'] === 'success', 'old Change Password');

up_h('4.6 behaviour that returns with the earlier build');
$svcY = harness_add_service($Pr['P2'], $state['server'], 'rb-noid.test', ['userid' => 127, 'username' => 'upy']);
$state['svc']['Y'] = $svcY;
up_call('CreateAccount', $svcY);
$props = [];
foreach (up_props($svcY) as $row) {
    $props[$row['fieldname']] = $row['value'];
}
$state['client']['Y'] = (int) $props['ispconfig_client_id'];
$state['web']['Y'] = (int) $props['ispconfig_domain_id'];
up_call('UnlinkAccount', $svcY);
up_call('LinkAccount', $svcY, [], 'old Link: no website id');
$r = up_call('SuspendAccount', $svcY, [], 'website-only Suspend with no website id');
$ok($r['result'] === 'success' && up_web_active($state['web']['Y']) === 'y' && $r['actions'] === [], "'a website-only suspension with no website id does nothing' (success, no panel call, site live)");

echo "\n  SSO button vs service status (fake sso_state=installed in the cached metadata)\n";
$saved = (string) Capsule::table('tblservers_remote')->where('server_id', $state['server'])->value('meta_data');
$fake = json_decode($saved, true);
$fake['sso_state'] = 'installed';
$fake['sso_available'] = true;
Capsule::table('tblservers_remote')->where('server_id', $state['server'])->update(['meta_data' => json_encode($fake)]);
$ca = ispconfigconnect_ClientArea(harness_params($svcY));
up_p('    old ClientArea(Y, Suspended) ssoAvailable', json_encode($ca['templateVariables']['ssoAvailable'] ?? null));
$ok(($ca['templateVariables']['ssoAvailable'] ?? null) === true, "'the SSO button ignores the service status'");
Capsule::table('tblservers_remote')->where('server_id', $state['server'])->update(['meta_data' => $saved]);

echo "\n  shared client: G and H both Active on client #{$C['G']}\n";
up_show_client($C['G'], 'before');
$r = up_call('TerminateAccount', $S['H'], [], 'old Terminate on a SHARED client');
up_show_client($C['G'], 'after old Terminate of H');
$ok($r['result'] === 'success' && (up_client($C['G'])['exists'] ?? true) === false, "'a shared client can be terminated' (G's hosting deleted with H's)");
up_show_props($S['G'], 'G still points at the deleted client');

up_h('Rollback-window actions that set up the re-upgrade checks');
echo "  R: stale ispconfig_suspended_as=website; admin ticks Suspend Entire Client on P2d, old Suspend locks the client\n";
up_set_option($Pr['P2d'], 13, 'on');
$r = up_call('SuspendAccount', $S['R'], [], 'old Suspend, entire client');
up_show_client($C['R'], 'R');
up_p('    R ispconfig_suspended_as now', $susp($S['R']));

echo "\n  A2: stale ispconfig_suspended_as=client; old Change Package to website-only P2, old Suspend (website only)\n";
Capsule::table('tblhosting')->where('id', $S['A2'])->update(['packageid' => $Pr['P2']]);
up_call('ChangePackage', $S['A2'], [], 'old Change Package P1 -> P2');
$r = up_call('SuspendAccount', $S['A2'], [], 'old Suspend, website only');
up_show_client($C['A2'], 'A2');
up_p('    A2 ispconfig_suspended_as now', $susp($S['A2']));

echo "\n  W: old Change Package P1 -> P3, then old Replace to an orphan client (stale P1 rows, nobody else on either client)\n";
$svcW = harness_add_service($Pr['P1'], $state['server'], 'rb-relink.test', ['userid' => 128, 'username' => 'upw']);
$state['svc']['W'] = $svcW;
up_call('CreateAccount', $svcW);
$state['client']['W'] = (int) Capsule::table('tblcustomfieldsvalues as v')->join('tblcustomfields as f', 'f.id', '=', 'v.fieldid')->where('v.relid', $svcW)->where('f.fieldname', 'ispconfig_client_id')->value('v.value');
$svcW2 = harness_add_service($Pr['P1'], $state['server'], 'rb-orphan.test', ['userid' => 129, 'username' => 'upw2']);
$state['svc']['W2'] = $svcW2;
up_call('CreateAccount', $svcW2);
$state['client']['W2'] = (int) Capsule::table('tblcustomfieldsvalues as v')->join('tblcustomfields as f', 'f.id', '=', 'v.fieldid')->where('v.relid', $svcW2)->where('f.fieldname', 'ispconfig_client_id')->value('v.value');
up_call('UnlinkAccount', $svcW2, [], "W2's client becomes an orphan");
Capsule::table('tblhosting')->where('id', $svcW)->update(['packageid' => $Pr['P3']]);
up_call('ChangePackage', $svcW, [], 'old Change Package P1 -> P3');
Capsule::table('tblhosting')->where('id', $svcW)->update(['username' => 'upw2']);
up_call('RelinkAccount', $svcW, [], "old Replace ISPConfig Link to 'upw2'");
up_show_props($svcW);

up_state_save($state);
echo "\n==== CHECK SUMMARY ====\n";
foreach ($checks as [$s, $l]) {
    echo "$s $l\n";
}
$err = @file_get_contents('/opt/ispc/p7/php-error.log');
echo 'panel php-error.log: ', $err === false || $err === '' ? 'empty' : $err, "\n";
