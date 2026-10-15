<?php
// Extra checks around steps 11-16b (not literal plan steps).
declare(strict_types=1);
require __DIR__ . '/lib.php';
$st = lt_state();
[$S1, $N1, $S2, $N2, $S3, $N3, $P1, $P2] = [$st['S1'], $st['N1'], $st['S2'], $st['N2'], $st['S3'], $st['N3'], $st['P1'], $st['P2']];

function blank_records(int $sid): void
{
    \WHMCS\Database\Capsule::update("UPDATE tblcustomfieldsvalues v JOIN tblcustomfields f ON f.id=v.fieldid SET v.value='' WHERE f.type='product' AND f.fieldname='ispconfig_suspended_as' AND v.relid=?", [$sid]);
}

hr('E1: Suspend pressed twice on a locked client does not re-take the lock snapshot (canary survives)');
$mail = props_effective($S1)['mail_domain_id'];
panel_admin("set-active mail_domain domain_id $mail n");
out('  Suspend #1: ' . harness_call('SuspendAccount', $S1));
$a = panel_snap($N1);
$r = lt_call('SuspendAccount', $S1);
$b = panel_snap($N1);
assert_line('E1', $r['result'] === 'success' && api_calls($r) === ['client_get'] && $a['client']['tmp_data'] == $b['client']['tmp_data'] && prev_active_entries($b['client']['tmp_data']) === ['mail_domain' => [$mail => ['active' => 'n']]],
    'second Suspend only reads client_get; prev_active still lists only the canary: ' . json_encode(prev_active_entries($b['client']['tmp_data'])));
out('  Unsuspend: ' . harness_call('UnsuspendAccount', $S1));
$c = print_snap($N1, '(after Unsuspend)');
assert_line('E1', actives($c, 'mail_domain') === ['n'] && actives($c, 'web_domain') === ['y'] && $c['client']['locked'] === 'n', 'canary stays off, the rest is restored');
panel_admin("set-active mail_domain domain_id $mail y");

hr('E2: across modes the other way - S1 locked (P1), product set to website-only P2, Change Package');
out('  Suspend: ' . harness_call('SuspendAccount', $S1));
\WHMCS\Database\Capsule::table('tblhosting')->where('id', $S1)->update(['packageid' => $P2]);
$dl = dl_mark();
$r = lt_call('ChangePackage', $S1);
assert_line('E2', str_starts_with((string) $r['result'], 'Change Package refused: this service\'s ISPConfig client is locked') && api_calls($r) === ['client_get'] && dl_since($dl) === [], 'refused on the locked client, nothing sent: ' . json_encode(api_calls($r)));
$r = lt_call('UnsuspendAccount', $S1);
$s = print_snap($N1, '(Unsuspend on the website-only product)');
assert_line('E2', $r['result'] === 'success' && $s['client']['locked'] === 'n' && actives($s, 'mail_domain') === ['y'] && actives($s, 'web_domain') === ['y'], 'Unsuspend follows the recorded mode (client) and unlocks under the website-only product');
$r = lt_call('ChangePackage', $S1);
assert_line('E2', $r['result'] === 'success', 'Change Package to P2 now succeeds: ' . json_encode($r['result']));
out('  S1 suspended_as: ' . json_encode(susp_as($S1)));
\WHMCS\Database\Capsule::table('tblhosting')->where('id', $S1)->update(['packageid' => $P1]);
out('  back to P1: ' . harness_call('ChangePackage', $S1));
blank_records($S1);

hr('E3: website-only suspension turned into a whole-client lock by pressing Suspend again after ticking the box');
out('  Suspend S2 (website-only): ' . harness_call('SuspendAccount', $S2));
\WHMCS\Database\Capsule::table('tblproducts')->where('id', $P2)->update(['configoption13' => 'on']);
$r = lt_call('SuspendAccount', $S2);
$s = print_snap($N2, '(after second Suspend with the box ticked)');
$ss = req_of($r['log'], 'sites_web_domain_set_status');
assert_line('E3', $r['result'] === 'success' && ($ss[0]['status'] ?? null) === 'active' && $s['client']['locked'] === 'y' && prev_active_entries($s['client']['tmp_data']) === [] && susp_as($S2)['effective'] === 'client',
    'website set active first, then locked; snapshot has no inactive website; record = client: calls=' . json_encode(api_calls($r)));
\WHMCS\Database\Capsule::table('tblproducts')->where('id', $P2)->update(['configoption13' => '']);
$r = lt_call('UnsuspendAccount', $S2);
$s = print_snap($N2, '(Unsuspend after unticking)');
assert_line('E3', $r['result'] === 'success' && $s['client']['locked'] === 'n' && actives($s, 'web_domain') === ['y'] && actives($s, 'mail_domain') === ['y'], 'Unsuspend unlocks; website, mail, FTP active');

hr('E4: Change Password after Unlink + Link (S3 from step 13)');
out('  S3 effective ids: ' . json_encode(props_effective($S3)));
$ftpBefore = harness_panel_rows("SELECT password FROM ftp_user WHERE ftp_user_id = (SELECT MIN(ftp_user_id) FROM ftp_user WHERE sys_groupid = (SELECT groupid FROM sys_group WHERE client_id = $N3))")[0]['password'];
$dbBefore = harness_panel_rows("SELECT database_password FROM web_database_user WHERE sys_groupid = (SELECT groupid FROM sys_group WHERE client_id = $N3)")[0]['database_password'];
\WHMCS\Database\Capsule::table('tblhosting')->where('id', $S3)->update(['password' => 'New-Pass-E4-xyz']);
$r = lt_call('ChangePassword', $S3);
$ftpAfter = harness_panel_rows("SELECT password FROM ftp_user WHERE ftp_user_id = (SELECT MIN(ftp_user_id) FROM ftp_user WHERE sys_groupid = (SELECT groupid FROM sys_group WHERE client_id = $N3))")[0]['password'];
$dbAfter = harness_panel_rows("SELECT database_password FROM web_database_user WHERE sys_groupid = (SELECT groupid FROM sys_group WHERE client_id = $N3)")[0]['database_password'];
out('  API calls: ' . json_encode(api_calls($r)) . ' ftp hash changed: ' . json_encode($ftpBefore !== $ftpAfter) . ' db hash changed: ' . json_encode($dbBefore !== $dbAfter));
assert_line('E4', $r['result'] === 'success' && ($ftpBefore !== $ftpAfter) && ($dbBefore !== $dbAfter), 'Change Password on a re-linked service rotates the FTP and DB passwords too (or says it did not)');
