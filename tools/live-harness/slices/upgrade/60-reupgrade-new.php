<?php
/**
 * PHASE 4 - re-upgrade to the NEW build after the rollback window: what the
 * new build makes of data the old build wrote while rolled back.
 */
declare(strict_types=1);
require __DIR__ . '/common.php';

use WHMCS\Database\Capsule;

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
    ->where('v.relid', $svc)->where('f.fieldname', 'ispconfig_suspended_as')->orderByDesc('v.id')->get(['f.relid as product', 'v.value'])->all());

up_h('Re-upgrade: R - new-build website-only record left stale, then an OLD entire-client suspension');
up_p('    R ispconfig_suspended_as rows', $susp($S['R']));
up_show_client($C['R'], 'R before');
$r = up_call('UnsuspendAccount', $S['R'], [], 'new Unsuspend');
up_show_client($C['R'], 'R after');
$ok($r['status'] === 'Active', 'WHMCS now shows R Active');
$ok(up_client($C['R'])['locked'] === 'n', 'R client unlocked (FTP etc. back)');

up_h('Re-upgrade: A2 - new-build entire-client record left stale, then an OLD website-only suspension');
up_p('    A2 ispconfig_suspended_as rows', $susp($S['A2']));
up_show_client($C['A2'], 'A2 before');
$r = up_call('UnsuspendAccount', $S['A2'], [], 'new Unsuspend');
up_show_client($C['A2'], 'A2 after');
$ok($r['status'] === 'Active', 'WHMCS now shows A2 Active');
$ok(up_web_active($Wb['A2']) === 'y', 'A2 website back online');

up_h('Re-upgrade: W - old Change Package + old Replace left stale P1 rows; W moved back to P1');
up_show_props($S['W']);
$tab = ispconfigconnect_AdminServicesTabFields(harness_params($S['W']));
up_p('    W on P3: Client ID / Domain ID', ($tab['ISPConfig Client ID'] ?? '?') . ' / ' . ($tab['ISPConfig Domain ID'] ?? '?'));
Capsule::table('tblhosting')->where('id', $S['W'])->update(['packageid' => $Pr['P1']]);
$tab = ispconfigconnect_AdminServicesTabFields(harness_params($S['W']));
up_p('    W moved to P1: Client ID / Domain ID', ($tab['ISPConfig Client ID'] ?? '?') . ' / ' . ($tab['ISPConfig Domain ID'] ?? '?'));
$ok(($tab['ISPConfig Client ID'] ?? '') === (string) $C['W2'], "W still reads the client its Replace chose (#{$C['W2']}) after the move");
up_show_client($C['W'], "client #{$C['W']} (W's pre-Replace client) before");
up_show_client($C['W2'], "client #{$C['W2']} (W's current client) before");
$r = up_call('ChangePackage', $S['W'], [], 'Change Package W to P1');
$ok(!str_contains(implode("\n", $r['log']), '"client_id":' . $C['W'] . ','), "Change Package does not touch client #{$C['W']}");
$r = up_call('TerminateAccount', $S['W'], [], 'Terminate W');
up_show_client($C['W'], "client #{$C['W']} (pre-Replace) after Terminate");
up_show_client($C['W2'], "client #{$C['W2']} (W's current client) after Terminate");
$ok((up_client($C['W'])['exists'] ?? true) !== false, "pre-Replace client #{$C['W']} NOT deleted by W's Terminate");

up_h('Re-upgrade: G still points at the client the OLD build deleted through H');
$tab = ispconfigconnect_AdminServicesTabFields(harness_params($S['G']));
up_p('    G admin tab Link', html_entity_decode((string) ($tab['ISPConfig Link'] ?? '?')));
$r = up_call('TerminateAccount', $S['G']);
$ok(str_starts_with((string) $r['result'], "Terminate refused: the stored ISPConfig client id #{$C['G']} no longer exists"), 'Terminate G -> stale refusal, nothing deleted');

echo "\n==== CHECK SUMMARY ====\n";
foreach ($checks as [$s, $l]) {
    echo "$s $l\n";
}
$err = @file_get_contents('/opt/ispc/p7/php-error.log');
echo 'panel php-error.log: ', $err === false || $err === '' ? 'empty' : $err, "\n";
