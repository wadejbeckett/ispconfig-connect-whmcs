<?php
// Step 12: Website-only Suspend (S2 on P2 for C2).
declare(strict_types=1);
require __DIR__ . '/lib.php';
$st = lt_state();

hr('Step 12: Create S2 (P2 website-only, C2, test-two.example)');
$S2 = harness_add_service($st['P2'], $st['server'], 'test-two.example', ['userid' => $st['C2'], 'username' => '']);
$st['S2'] = $S2;
$r = lt_call('CreateAccount', $S2);
assert_line('12', $r['result'] === 'success', 'Create S2 on P2 returns success: ' . json_encode($r['result']));
$eff = props_effective($S2);
$N2 = (int) $eff['client_id'];
$st['N2'] = $N2;
lt_save_state($st);
print_props($S2);
$s0 = print_snap($N2, '(after Create)');

hr('Step 12: Suspend S2');
$r = lt_call('SuspendAccount', $S2);
assert_line('12', $r['result'] === 'success', 'Suspend returns success: ' . json_encode($r['result']));
$calls = api_calls($r);
$ss = req_of($r['log'], 'sites_web_domain_set_status');
assert_line('12', $calls === ['sites_web_domain_set_status'] && ($ss[0]['status'] ?? null) === 'inactive' && ($ss[0]['primary_id'] ?? null) === $eff['domain_id'],
    'Suspend sends only sites_web_domain_set_status with inactive (for website #' . $eff['domain_id'] . '): calls=' . json_encode($calls) . ' req=' . json_encode($ss));
$s1 = print_snap($N2, '(after Suspend)');
assert_line('12', $s1['client']['locked'] === 'n' && prev_active_entries($s1['client']['tmp_data']) === null, 'the client is not locked (locked=' . $s1['client']['locked'] . ', tmp_data=' . json_encode($s1['client']['tmp_data']) . ')');
assert_line('12', actives($s1, 'mail_domain') === ['y'] && actives($s1, 'ftp_user') === ['y'] && actives($s1, 'web_database') === ['y'], 'mail, FTP (and database) stay active: mail=' . json_encode(actives($s1, 'mail_domain')) . ' ftp=' . json_encode(actives($s1, 'ftp_user')) . ' db=' . json_encode(actives($s1, 'web_database')));
assert_line('12', actives($s1, 'web_domain') === ['n'], 'the site is set inactive (web_domain.active=n; actually stopping the vhost needs server.sh, which does not run here): ' . json_encode(actives($s1, 'web_domain')));
$sa = susp_as($S2);
assert_line('12', $sa['current_row'] === 'website' && $sa['effective'] === 'website', "ispconfig_suspended_as = 'website': " . json_encode($sa));

hr('Step 12: Unsuspend S2');
$r = lt_call('UnsuspendAccount', $S2);
assert_line('12', $r['result'] === 'success', 'Unsuspend returns success: ' . json_encode($r['result']));
$calls = api_calls($r);
$ss = req_of($r['log'], 'sites_web_domain_set_status');
assert_line('12', $calls === ['sites_web_domain_set_status'] && ($ss[0]['status'] ?? null) === 'active', 'Unsuspend sends sites_web_domain_set_status with active (only): calls=' . json_encode($calls) . ' req=' . json_encode($ss));
$s2 = print_snap($N2, '(after Unsuspend)');
assert_line('12', actives($s2, 'web_domain') === ['y'] && $s2['client']['locked'] === 'n' && actives($s2, 'mail_domain') === ['y'] && actives($s2, 'ftp_user') === ['y'], 'site active again, client unlocked, mail/FTP active');
$sa = susp_as($S2);
assert_line('12', $sa['current_row'] === '' && $sa['effective'] === null, 'ispconfig_suspended_as blanked: ' . json_encode($sa));
