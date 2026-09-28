<?php
// Step 16b: Changing the suspension setting while suspended.
declare(strict_types=1);
require __DIR__ . '/lib.php';
$st = lt_state();
$S1 = $st['S1'];
$N1 = $st['N1'];
$S2 = $st['S2'];
$N2 = $st['N2'];
$P1 = $st['P1'];
$P2 = $st['P2'];

function set_entire(int $pid, bool $on): void
{
    \WHMCS\Database\Capsule::table('tblproducts')->where('id', $pid)->update(['configoption13' => $on ? 'on' : '']);
    out(sprintf('  [product %d: Suspend Entire Client %s, Save]', $pid, $on ? 'TICKED' : 'unticked'));
}

function all_on(array $s): bool
{
    return actives($s, 'web_domain') === ['y'] && actives($s, 'ftp_user') === ['y'] && actives($s, 'mail_domain') === ['y'] && actives($s, 'web_database') === ['y'];
}

hr('Step 16b prep: S2 is on P1 after step 16 - move it back to P2 (Active) with Change Package');
\WHMCS\Database\Capsule::table('tblhosting')->where('id', $S2)->update(['packageid' => $P2]);
$r = lt_call('ChangePackage', $S2);
assert_line('16b-prep', $r['result'] === 'success', 'S2 back on P2: ' . json_encode($r['result']));
out('  S2 suspended_as after moving back to P2 (Active): ' . json_encode(susp_as($S2)));
print_snap($N2, '(S2 on P2, Active)');

// ---------------------------------------------------------------- (a)
hr('Step 16b (a): Suspend S2 (P2 website-only), tick Suspend Entire Client on P2, Unsuspend S2');
$r = lt_call('SuspendAccount', $S2);
assert_line('16b-a', $r['result'] === 'success' && api_calls($r) === ['sites_web_domain_set_status'] && susp_as($S2)['effective'] === 'website', 'Suspend S2 website-only: ' . json_encode([$r['result'], api_calls($r), susp_as($S2)]));
set_entire($P2, true);
$r = lt_call('UnsuspendAccount', $S2);
$ss = req_of($r['log'], 'sites_web_domain_set_status');
assert_line('16b-a', $r['result'] === 'success', 'Unsuspend returns success: ' . json_encode($r['result']));
assert_line('16b-a', api_calls($r) === ['sites_web_domain_set_status'] && ($ss[0]['status'] ?? null) === 'active' && req_of($r['log'], 'client_update') === [], 'Unsuspend sets the website active (sites_web_domain_set_status active), no client_update: ' . json_encode(api_calls($r)));
$s = print_snap($N2, '(after Unsuspend)');
assert_line('16b-a', all_on($s) && $s['client']['locked'] === 'n', 'the site serves again (web_domain.active=y), client unlocked, everything active');
assert_line('16b-a', susp_as($S2)['effective'] === null, 'suspended_as blank: ' . json_encode(susp_as($S2)));
set_entire($P2, false);

// ---------------------------------------------------------------- (b)
hr('Step 16b (b): Suspend S1 (P1 whole client), untick Suspend Entire Client on P1, Unsuspend S1');
out('  S1 before: ' . json_encode(svc($S1)) . ' suspended_as ' . json_encode(susp_as($S1)));
$r = lt_call('SuspendAccount', $S1);
$s = panel_snap($N1);
assert_line('16b-b', $r['result'] === 'success' && $s['client']['locked'] === 'y' && susp_as($S1)['effective'] === 'client', 'Suspend S1 locks the client, suspended_as=client');
set_entire($P1, false);
$r = lt_call('UnsuspendAccount', $S1);
$upd = req_of($r['log'], 'client_update');
assert_line('16b-b', $r['result'] === 'success' && count($upd) === 1 && ($upd[0]['params']['locked'] ?? null) === 'n', "Unsuspend unlocks the client (client_update locked='n'): " . json_encode([$r['result'], api_calls($r)]));
$s = print_snap($N1, '(after Unsuspend)');
assert_line('16b-b', $s['client']['locked'] === 'n' && all_on($s), 'mail, FTP and the site are all active again');
$sa = susp_as($S1);
out('  S1 suspended_as after Unsuspend: ' . json_encode($sa));
$r = lt_call('ChangePackage', $S1);
assert_line('16b-b', $r['result'] === 'success', 'a following Change Package is not refused: ' . json_encode($r['result']));
set_entire($P1, true);

// ---------------------------------------------------------------- (c)
hr('Step 16b (c): Suspend S2 (P2), tick Locked on S2\'s client by hand, Unsuspend S2, untick Locked');
$r = lt_call('SuspendAccount', $S2);
assert_line('16b-c', $r['result'] === 'success' && api_calls($r) === ['sites_web_domain_set_status'], 'Suspend S2 website-only');
panel_admin("lock $N2 y");
$s = print_snap($N2, '(website-only suspended + hand lock)');
out('  prev_active taken by the hand lock: ' . json_encode(prev_active_entries($s['client']['tmp_data'])));
$r = lt_call('UnsuspendAccount', $S2);
$ss = req_of($r['log'], 'sites_web_domain_set_status');
assert_line('16b-c', $r['result'] === 'success', 'Unsuspend returns success: ' . json_encode($r['result']));
assert_line('16b-c', api_calls($r) === ['sites_web_domain_set_status'] && ($ss[0]['status'] ?? null) === 'active' && req_of($r['log'], 'client_update') === [], 'Unsuspend sets the website active, no client_update: ' . json_encode(api_calls($r)));
$s = print_snap($N2, '(after Unsuspend, hand lock still set)');
assert_line('16b-c', $s['client']['locked'] === 'y' && actives($s, 'web_domain') === ['y'], 'the hand-set lock stays in place (locked=' . $s['client']['locked'] . '), website active=' . json_encode(actives($s, 'web_domain')));
panel_admin("lock $N2 n");
$s = print_snap($N2, '(after unticking Locked by hand)');
out('  OBSERVATION final state after the admin unticks Locked: web_domain.active=' . json_encode(actives($s, 'web_domain')) . ' (WHMCS status ' . svc($S2)['domainstatus'] . ')');
assert_line('16b-c-final', actives($s, 'web_domain') === ['y'], 'after the hand lock is removed the website (Unsuspended in WHMCS) is active: ' . json_encode(actives($s, 'web_domain')));
if (actives($s, 'web_domain') !== ['y']) {
    // Put it back for the next sub-step: Suspend + Unsuspend through the module.
    out('  restoring: the website is off although S2 is Active in WHMCS; the module can only fix it with Suspend+Unsuspend');
    lt_call('SuspendAccount', $S2);
    lt_call('UnsuspendAccount', $S2);
    print_snap($N2, '(restored)');
}

// ---------------------------------------------------------------- (d)
hr('Step 16b (d): legacy whole-client lock (no ispconfig_suspended_as record) under a now website-only product');
// Emulate the earlier build's suspension: the client is locked through client_update(locked=y)
// and WHMCS has the service Suspended, but no suspension record exists on ANY product row.
$r = lt_call('SuspendAccount', $S1);
$n = \WHMCS\Database\Capsule::update("UPDATE tblcustomfieldsvalues v JOIN tblcustomfields f ON f.id=v.fieldid SET v.value='' WHERE f.type='product' AND f.fieldname='ispconfig_suspended_as' AND v.relid=?", [$S1]);
out("  [emulating the earlier build: blanked $n ispconfig_suspended_as row(s) of S1 on every product]");
print_props($S1);
$before = print_snap($N1, '(legacy lock)');
set_entire($P1, false);
$dl = dl_mark();
$r = lt_call('UnsuspendAccount', $S1);
$exp = sprintf('Unsuspend refused: this service\'s ISPConfig client #%d is locked, but this product suspends the website only and the module has no record of locking it ', $N1);
assert_line('16b-d', is_string($r['result']) && str_starts_with($r['result'], $exp), 'refusal text: ' . json_encode($r['result']));
$after = panel_snap($N1);
assert_line('16b-d', api_calls($r) === ['client_get'] && dl_since($dl) === [] && $after == $before && svc($S1)['domainstatus'] === 'Suspended', 'nothing changes (only client_get read; no datalog; panel identical; WHMCS still Suspended): ' . json_encode(api_calls($r)));
set_entire($P1, true);
$r = lt_call('UnsuspendAccount', $S1);
$s = print_snap($N1, '(after ticking the box and Unsuspend)');
assert_line('16b-d', $r['result'] === 'success' && $s['client']['locked'] === 'n' && all_on($s), 'tick the box again, Unsuspend: the client is unlocked and everything active');
print_props($S1);
