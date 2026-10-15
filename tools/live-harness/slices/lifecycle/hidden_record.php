<?php
// Clean reproduction: a real 'client' suspension record hidden by a newer blank row
// on the product the suspended service is moved to. Ordinary lifecycle only.
declare(strict_types=1);
require __DIR__ . '/lib.php';
$st = lt_state();
[$P1, $P2] = [$st['P1'], $st['P2']];

hr('fresh S6 on P1 (entire), C2, test-six.example');
$S6 = harness_add_service($P1, $st['server'], 'test-six.example', ['userid' => $st['C2'], 'username' => '']);
out('  Create: ' . harness_call('CreateAccount', $S6));
$N6 = (int) props_effective($S6)['client_id'];
$st['S6'] = $S6;
$st['N6'] = $N6;
lt_save_state($st);
out('  Suspend on P1 (overdue invoice): ' . harness_call('SuspendAccount', $S6) . ' / Unsuspend (paid): ' . harness_call('UnsuspendAccount', $S6));
\WHMCS\Database\Capsule::table('tblhosting')->where('id', $S6)->update(['packageid' => $P2]);
out('  downgrade to P2 + Change Package: ' . harness_call('ChangePackage', $S6));
out('  Suspend on P2: ' . harness_call('SuspendAccount', $S6) . ' / Unsuspend: ' . harness_call('UnsuspendAccount', $S6));
\WHMCS\Database\Capsule::table('tblhosting')->where('id', $S6)->update(['packageid' => $P1]);
out('  upgrade to P1 + Change Package: ' . harness_call('ChangePackage', $S6));
out('  Suspend on P1: ' . harness_call('SuspendAccount', $S6));
print_props($S6);
$s = panel_snap($N6);
out('  panel: locked=' . $s['client']['locked'] . ' web=' . json_encode(actives($s, 'web_domain')) . ' mail=' . json_encode(actives($s, 'mail_domain')));

hr('admin moves the suspended S6 to P2 (Save; Change Package is refused on the locked client), then Unsuspend');
\WHMCS\Database\Capsule::table('tblhosting')->where('id', $S6)->update(['packageid' => $P2]);
$r = lt_call('ChangePackage', $S6);
out('  suspended_as as the module reads it now: ' . json_encode(susp_as($S6)));
$r = lt_call('UnsuspendAccount', $S6);
$s = print_snap($N6, '(after Unsuspend on P2)');
assert_line('hidden', $r['result'] === 'success' && $s['client']['locked'] === 'n',
    'INSTALL.md: "moving a suspended service to another product no longer strands it: a client locked under the old setting is unlocked" - got ' . json_encode($r['result']) . ', locked=' . $s['client']['locked'] . ', WHMCS status ' . svc($S6)['domainstatus']);

hr('restore: the refusal\'s own workaround (tick the box, Unsuspend, untick)');
\WHMCS\Database\Capsule::table('tblproducts')->where('id', $P2)->update(['configoption13' => 'on']);
out('  Unsuspend with P2 ticked: ' . harness_call('UnsuspendAccount', $S6));
\WHMCS\Database\Capsule::table('tblproducts')->where('id', $P2)->update(['configoption13' => '']);
$s = print_snap($N6, '(restored)');
out('  S6: ' . json_encode(svc($S6)) . ' ' . json_encode(susp_as($S6)));
