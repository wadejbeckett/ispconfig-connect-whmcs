<?php
// Extra: impact of the stale ispconfig_suspended_as record seen in step 15.
// Sequence = the plan's own steps 14/15 (suspend on P3, product set back to P1 while
// suspended, Unsuspend), then the service moves (Active) to a website-only product.
declare(strict_types=1);
require __DIR__ . '/lib.php';
$st = lt_state();
$S1 = $st['S1'];
$N1 = $st['N1'];

hr('stale record: S1 P1 -> P3 (Active), Suspend on P3, product back to P1 (Save only), Unsuspend');
\WHMCS\Database\Capsule::table('tblhosting')->where('id', $S1)->update(['packageid' => $st['P3']]);
out('  ChangePackage P1->P3: ' . harness_call('ChangePackage', $S1));
out('  Suspend on P3: ' . harness_call('SuspendAccount', $S1));
\WHMCS\Database\Capsule::table('tblhosting')->where('id', $S1)->update(['packageid' => $st['P1']]);
out('  Unsuspend on P1: ' . harness_call('UnsuspendAccount', $S1));
$sa = susp_as($S1);
out('  after Unsuspend: ' . json_encode($sa) . ' WHMCS status ' . svc($S1)['domainstatus']);
assert_line('stale', $sa['effective'] === null, 'Unsuspend leaves no suspension record the module can read (INSTALL.md: "Unsuspend reverses that mode and blanks the record"): ' . json_encode($sa));
print_props($S1);

hr('stale record: Change Package (Active) to the website-only product P2');
\WHMCS\Database\Capsule::table('tblhosting')->where('id', $S1)->update(['packageid' => $st['P2']]);
out('  ChangePackage P1->P2: ' . harness_call('ChangePackage', $S1));
$sa = susp_as($S1);
out('  after Change Package: ' . json_encode($sa));
assert_line('stale', $sa['current_row'] !== 'client', 'Change Package does not copy a stale suspension record onto the new product: ' . json_encode($sa));

hr('stale record: WHMCS status set to Suspended without a module call (as plan step 17.4 / upgrade check 9 do), then Suspend pressed');
\WHMCS\Database\Capsule::table('tblhosting')->where('id', $S1)->update(['domainstatus' => 'Suspended']);
$r = lt_call('SuspendAccount', $S1);
$s = print_snap($N1, '(after Suspend on the website-only product P2)');
$lockedWhole = $s['client']['locked'] === 'y';
assert_line('stale', !$lockedWhole && actives($s, 'mail_domain') === ['y'], 'a website-only product (P2) suspends the website only (client not locked, mail/FTP/DB on): locked=' . $s['client']['locked'] . ' mail=' . json_encode(actives($s, 'mail_domain')) . ' ftp=' . json_encode(actives($s, 'ftp_user')) . ' db=' . json_encode(actives($s, 'web_database')) . ' calls=' . json_encode(api_calls($r)));

hr('control: same press with NO stale record');
out('  Unsuspend: ' . harness_call('UnsuspendAccount', $S1));
\WHMCS\Database\Capsule::update("UPDATE tblcustomfieldsvalues v JOIN tblcustomfields f ON f.id=v.fieldid SET v.value='' WHERE f.type='product' AND f.fieldname='ispconfig_suspended_as' AND v.relid=?", [$S1]);
\WHMCS\Database\Capsule::table('tblhosting')->where('id', $S1)->update(['domainstatus' => 'Suspended']);
$r = lt_call('SuspendAccount', $S1);
$s = print_snap($N1, '(control)');
out('  control: calls=' . json_encode(api_calls($r)) . ' locked=' . $s['client']['locked'] . ' web=' . json_encode(actives($s, 'web_domain')));

hr('restore S1: Unsuspend, back to P1, blank records');
out('  Unsuspend: ' . harness_call('UnsuspendAccount', $S1));
\WHMCS\Database\Capsule::table('tblhosting')->where('id', $S1)->update(['packageid' => $st['P1']]);
out('  ChangePackage ->P1: ' . harness_call('ChangePackage', $S1));
\WHMCS\Database\Capsule::update("UPDATE tblcustomfieldsvalues v JOIN tblcustomfields f ON f.id=v.fieldid SET v.value='' WHERE f.type='product' AND f.fieldname='ispconfig_suspended_as' AND v.relid=?", [$S1]);
print_snap($N1, '(restored)');
out('  S1: ' . json_encode(svc($S1)) . ' ' . json_encode(susp_as($S1)));
