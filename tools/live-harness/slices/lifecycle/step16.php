<?php
// Step 16: Change Package on a suspended website-only service (S2).
declare(strict_types=1);
require __DIR__ . '/lib.php';
$st = lt_state();
$S2 = $st['S2'];
$N2 = $st['N2'];

function web_datalog(int $mark): array
{
    $out = [];
    foreach (harness_panel_rows("SELECT datalog_id, dbtable, dbidx, action, data FROM sys_datalog WHERE datalog_id > $mark ORDER BY datalog_id") as $d) {
        $data = @unserialize((string) $d['data']);
        $out[] = sprintf('%s#%s:%s active %s->%s locked %s->%s', $d['dbtable'], $d['dbidx'], $d['action'],
            var_export($data['old']['active'] ?? null, true), var_export($data['new']['active'] ?? null, true),
            var_export($data['old']['locked'] ?? null, true), var_export($data['new']['locked'] ?? null, true));
    }
    return $out;
}

hr('Step 16 (a): Suspend S2 (P2, website-only)');
out('  service: ' . json_encode(svc($S2)));
$r = lt_call('SuspendAccount', $S2);
assert_line('16a', $r['result'] === 'success' && api_calls($r) === ['sites_web_domain_set_status'] && susp_as($S2)['effective'] === 'website', 'Suspend S2 (website-only) succeeds, suspended_as=website');
print_snap($N2, '(suspended website-only)');

hr('Step 16 (a): set product to P4 (copy of P2, template Pro), Change Package');
$MARK = dl_mark();
\WHMCS\Database\Capsule::table('tblhosting')->where('id', $S2)->update(['packageid' => $st['P4']]);
$r = lt_call('ChangePackage', $S2);
assert_line('16a', $r['result'] === 'success', 'Change Package returns success: ' . json_encode($r['result']));
$calls = api_calls($r);
$iU = array_search('client_update', $calls, true);
$iS = array_search('sites_web_domain_set_status', $calls, true);
$ss = req_of($r['log'], 'sites_web_domain_set_status');
assert_line('16a', $iU !== false && $iS !== false && $iU < $iS && ($ss[0]['status'] ?? null) === 'inactive' && count(req_of($r['log'], 'client_update')) === 1,
    'client_update followed by sites_web_domain_set_status inactive: ' . json_encode($calls));
$dl = web_datalog($MARK);
out('  datalog after MARK: ' . json_encode($dl));
$s = print_snap($N2, '(after Change Package to P4)');
assert_line('16a', actives($s, 'web_domain') === ['n'], 'the site stays offline (final web_domain.active=' . json_encode(actives($s, 'web_domain')) . ')');
$flap = array_values(array_filter($dl, fn ($x) => str_starts_with($x, 'web_domain')));
out('  NOTE web_domain datalog sequence (the queued jobs server.sh would replay): ' . json_encode($flap));
assert_line('16a', $s['client']['locked'] === 'n', 'the client is not locked: ' . $s['client']['locked']);
assert_line('16a', (string) $s['client']['template_master'] === '2', 'template now Pro (2): ' . $s['client']['template_master']);
assert_line('16a', actives($s, 'mail_domain') === ['y'] && actives($s, 'ftp_user') === ['y'] && actives($s, 'web_database') === ['y'], 'mail/FTP/DB stay active');
$sa = susp_as($S2);
assert_line('16a', $sa['current_row'] === 'website' && $sa['effective'] === 'website', 'ispconfig_suspended_as = website on the new product: ' . json_encode($sa));
assert_line('16a', svc($S2)['domainstatus'] === 'Suspended', 'WHMCS status stays Suspended');

hr('Step 16 (b): S2 still Suspended, set product to P1 (entire client), Change Package');
$MARK = dl_mark();
\WHMCS\Database\Capsule::table('tblhosting')->where('id', $S2)->update(['packageid' => $st['P1']]);
$r = lt_call('ChangePackage', $S2);
assert_line('16b', $r['result'] === 'success', 'Change Package returns success: ' . json_encode($r['result']));
$calls = api_calls($r);
$upd = req_of($r['log'], 'client_update');
out('  API calls: ' . implode(' -> ', $calls));
assert_line('16b', count($upd) === 2 && !array_key_exists('locked', $upd[0]['params']) && ($upd[0]['params']['template_master'] ?? null) === 1 && ($upd[1]['params']['locked'] ?? null) === 'y',
    'client_update (template Basic) followed by a second client_update with locked=y: ' . json_encode(array_map(fn ($u) => array_intersect_key($u['params'], array_flip(['template_master', 'locked'])), $upd)));
out('  datalog after MARK: ' . json_encode(web_datalog($MARK)));
$s = print_snap($N2, '(after Change Package to P1)');
assert_line('16b', $s['client']['locked'] === 'y', 'the client is now locked: ' . $s['client']['locked']);
$allOff = !in_array('y', array_merge(actives($s, 'web_domain'), actives($s, 'ftp_user'), actives($s, 'mail_domain'), actives($s, 'web_database')), true);
assert_line('16b', $allOff, 'every object inactive under the lock');
$pa = prev_active_entries($s['client']['tmp_data']);
assert_line('16b', $pa === [], 'lock snapshot prev_active is empty (website was re-activated before the lock, so the unlock restores it): ' . json_encode($pa));
$sa = susp_as($S2);
assert_line('16b', $sa['current_row'] === 'client' && $sa['effective'] === 'client', 'ispconfig_suspended_as = client: ' . json_encode($sa));
print_props($S2);

hr('Step 16 (b): Unsuspend S2');
$r = lt_call('UnsuspendAccount', $S2);
assert_line('16b', $r['result'] === 'success', 'Unsuspend returns success: ' . json_encode($r['result']));
$upd = req_of($r['log'], 'client_update');
assert_line('16b', count($upd) === 1 && ($upd[0]['params']['locked'] ?? null) === 'n', 'Unsuspend unlocks the client (client_update locked=n)');
$s = print_snap($N2, '(after Unsuspend)');
$allOn = actives($s, 'web_domain') === ['y'] && actives($s, 'ftp_user') === ['y'] && actives($s, 'mail_domain') === ['y'] && actives($s, 'web_database') === ['y'];
assert_line('16b', $s['client']['locked'] === 'n' && $allOn, 'client unlocked; the site, mail and FTP (and DB) are all active');
$sa = susp_as($S2);
print_props($S2);
assert_line('16b', $sa['effective'] === null && $sa['current_row'] === '', 'ispconfig_suspended_as blank after Unsuspend: ' . json_encode($sa));
