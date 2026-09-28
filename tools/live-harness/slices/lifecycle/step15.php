<?php
// Step 15: Change Package on a locked (entire-client suspended) client.
declare(strict_types=1);
require __DIR__ . '/lib.php';
$st = lt_state();
$S1 = $st['S1'];
$N = $st['N1'];

hr('Step 15: Suspend S1 (on P3, whole client)');
out('  service: ' . json_encode(svc($S1)));
$r = lt_call('SuspendAccount', $S1);
assert_line('15', $r['result'] === 'success', 'Suspend S1 returns success: ' . json_encode($r['result']));
$locked = print_snap($N, '(suspended)');
$pa0 = prev_active_entries($locked['client']['tmp_data']);
out('  prev_active after Suspend: ' . json_encode($pa0) . '; suspended_as ' . json_encode(susp_as($S1)));

hr('Step 15: set product to P1, Change Package (client locked)');
$MARK = dl_mark();
out("  MARK = $MARK");
\WHMCS\Database\Capsule::table('tblhosting')->where('id', $S1)->update(['packageid' => $st['P1']]);
$r = lt_call('ChangePackage', $S1);
$exp1 = 'Change Package refused: this service\'s ISPConfig client is locked (suspended).';
$exp2 = 'Unsuspend the service first, then change the package. Nothing has been changed.';
$res = (string) $r['result'];
assert_line('15', str_starts_with($res, $exp1) && str_contains(str_replace(' (or, if the lock was set by hand in ISPConfig, remove it there)', '', $res), $exp2),
    'refusal text matches the plan (' . $exp1 . ' ... ' . $exp2 . '): ' . json_encode($res));
assert_line('15', api_calls($r) === ['client_get'], 'Module Log shows only client_get (no client_update): ' . json_encode(api_calls($r)));
assert_line('15', dl_since($MARK) === [], 'datalog has nothing after MARK: ' . json_encode(dl_since($MARK)));
$still = print_snap($N, '(after refused Change Package)');
assert_line('15', $still['client']['locked'] === 'y' && prev_active_entries($still['client']['tmp_data']) === $pa0 && $still['client']['tmp_data'] == $locked['client']['tmp_data'], 'client still locked with its prev_active intact: ' . json_encode(prev_active_entries($still['client']['tmp_data'])));
assert_line('15', (string) $still['client']['template_master'] === '2', 'template unchanged (still Pro=2): ' . $still['client']['template_master']);
assert_line('15', svc($S1)['domainstatus'] === 'Suspended', 'WHMCS status stays Suspended');
out('  suspended_as while suspended on P1 (package change refused): ' . json_encode(susp_as($S1)));
print_props($S1);

hr('Step 15: Unsuspend S1 (now on P1)');
$r = lt_call('UnsuspendAccount', $S1);
assert_line('15', $r['result'] === 'success', 'Unsuspend returns success: ' . json_encode($r['result']));
$un = print_snap($N, '(after Unsuspend)');
$allOn = actives($un, 'web_domain') === ['y'] && actives($un, 'ftp_user') === ['y'] && actives($un, 'mail_domain') === ['y'] && actives($un, 'web_database') === ['y'];
assert_line('15', $un['client']['locked'] === 'n' && $allOn, 'after Unsuspend everything is active (locked=' . $un['client']['locked'] . ')');
$sa = susp_as($S1);
print_props($S1);
assert_line('15', $sa['effective'] === null, 'ispconfig_suspended_as reads blank after Unsuspend (module-effective): ' . json_encode($sa));

hr('Step 15: Change Package again (P1, Basic)');
$r = lt_call('ChangePackage', $S1);
assert_line('15', $r['result'] === 'success', 'second Change Package succeeds: ' . json_encode($r['result']));
$upd = req_of($r['log'], 'client_update');
assert_line('15', count($upd) === 1 && ($upd[0]['params']['template_master'] ?? null) === 1 && !array_key_exists('locked', $upd[0]['params']), 'one client_update with template Basic (1), no locked key: ' . json_encode($upd[0]['params'] ?? null));
$b = print_snap($N, '(after second Change Package)');
$basic = harness_panel_rows('SELECT limit_web_domain, limit_web_quota, limit_ftp_user, limit_maildomain, limit_mailbox, limit_database FROM client_template WHERE template_id = 1')[0];
assert_line('15', (string) $b['client']['template_master'] === '1' && array_intersect_key($b['client'], $basic) == $basic && $b['client']['locked'] === 'n', 'client on template Basic with Basic limits, unlocked');
print_props($S1);
$sa = susp_as($S1);
assert_line('15', $sa['effective'] === null && ($sa['current_row'] ?? '') === '', 'no suspension record on the Active service after the package change: ' . json_encode($sa));
