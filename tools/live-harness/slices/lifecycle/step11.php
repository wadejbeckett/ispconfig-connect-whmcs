<?php
// Step 11: Entire-client Suspend and Unsuspend with a canary.
declare(strict_types=1);
require __DIR__ . '/lib.php';
$st = lt_state();
$S1 = $st['S1'];
$N = $st['N1'];

hr('Step 11: Entire-client Suspend/Unsuspend with a canary');
$eff = props_effective($S1);
$mailId = $eff['mail_domain_id'];
print_snap($N, '(before canary)');
panel_admin("set-active mail_domain domain_id $mailId n");
$s0 = print_snap($N, '(canary: mail_domain #' . $mailId . ' deactivated by hand)');

hr('Step 11: Suspend S1');
$r = lt_call('SuspendAccount', $S1);
assert_line('11', $r['result'] === 'success', 'Suspend returns success: ' . json_encode($r['result']));
$acts = array_values(array_filter($r['actions'], fn ($a) => !in_array($a, ['login', 'logout'], true)));
out('  API calls (excluding login/logout): ' . implode(' -> ', $acts));
$iGet = array_search('client_get', $acts, true);
$iUpd = array_search('client_update', $acts, true);
$upd = req_of($r['log'], 'client_update');
assert_line('11', $iGet !== false && $iUpd !== false && $iGet < $iUpd && count($upd) === 1, 'client_get then (one) client_update: ' . implode(' -> ', $acts));
assert_line('11', ($upd[0]['params']['locked'] ?? null) === 'y', 'client_update carries locked=\'y\': ' . json_encode($upd[0]['params'] ?? null));
$s1 = print_snap($N, '(after Suspend)');
assert_line('11', $s1['client']['locked'] === 'y', "client.locked = 'y': " . $s1['client']['locked']);
$allOff = !in_array('y', array_merge(actives($s1, 'web_domain'), actives($s1, 'ftp_user'), actives($s1, 'mail_domain'), actives($s1, 'web_database')), true);
assert_line('11', $allOff, 'every object inactive: web_domain=' . json_encode(actives($s1, 'web_domain')) . ' ftp_user=' . json_encode(actives($s1, 'ftp_user')) . ' mail_domain=' . json_encode(actives($s1, 'mail_domain')) . ' web_database=' . json_encode(actives($s1, 'web_database')));
$pa = prev_active_entries($s1['client']['tmp_data']);
assert_line('11', $pa === ['mail_domain' => [$mailId => ['active' => 'n']]], 'tmp_data prev_active lists only the canary (mail_domain #' . $mailId . '): ' . json_encode($pa));
$cur = prop_current($S1, 'ispconfig_suspended_as');
$effS = props_effective($S1)['suspended_as'];
assert_line('11', $cur === 'client' && $effS === 'client', "S1 ispconfig_suspended_as = 'client' (current-product row " . json_encode($cur) . ', module-effective ' . json_encode($effS) . ')');
print_props($S1);

hr('Step 11: Unsuspend S1');
$r = lt_call('UnsuspendAccount', $S1);
assert_line('11', $r['result'] === 'success', 'Unsuspend returns success: ' . json_encode($r['result']));
$upd = req_of($r['log'], 'client_update');
assert_line('11', count($upd) === 1 && ($upd[0]['params']['locked'] ?? null) === 'n', "client_update with locked='n': " . json_encode(array_map(fn ($u) => $u['params']['locked'] ?? null, $upd)));
$s2 = print_snap($N, '(after Unsuspend)');
assert_line('11', $s2['client']['locked'] === 'n', "client.locked = 'n': " . $s2['client']['locked']);
$ok = actives($s2, 'web_domain') === ['y'] && actives($s2, 'ftp_user') === ['y'] && actives($s2, 'web_database') === ['y'] && actives($s2, 'mail_domain') === ['n'];
assert_line('11', $ok, 'everything active again except the canary: web=' . json_encode(actives($s2, 'web_domain')) . ' ftp=' . json_encode(actives($s2, 'ftp_user')) . ' db=' . json_encode(actives($s2, 'web_database')) . ' mail_domain(canary)=' . json_encode(actives($s2, 'mail_domain')));
assert_line('11', is_array($s2['client']['tmp_data']) && !array_key_exists('prev_active', $s2['client']['tmp_data']), 'prev_active removed from tmp_data after the unlock: ' . json_encode($s2['client']['tmp_data']));
$cur = prop_current($S1, 'ispconfig_suspended_as');
$effS = props_effective($S1)['suspended_as'];
assert_line('11', $cur === '' && $effS === null, 'ispconfig_suspended_as is blank (current-product row ' . json_encode($cur) . ', module-effective ' . json_encode($effS) . ')');

hr('Step 11: re-enable the canary by hand');
panel_admin("set-active mail_domain domain_id $mailId y");
$s3 = print_snap($N, '(canary re-enabled)');
assert_line('11', actives($s3, 'mail_domain') === ['y'], 'canary re-enabled');
