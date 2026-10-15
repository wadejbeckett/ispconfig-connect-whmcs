<?php
// Step 14: Change Package on an active client (S1: P1 -> P3 Pro).
declare(strict_types=1);
require __DIR__ . '/lib.php';
$st = lt_state();
$S1 = $st['S1'];
$N = $st['N1'];

/** web_domain datalog entries after $mark with the 'active' transitions they carry. */
function web_active_transitions(int $mark): array
{
    $out = [];
    foreach (harness_panel_rows("SELECT datalog_id, dbtable, dbidx, action, data FROM sys_datalog WHERE datalog_id > $mark ORDER BY datalog_id") as $d) {
        $data = @unserialize((string) $d['data']);
        $old = $data['old']['active'] ?? null;
        $new = $data['new']['active'] ?? null;
        $out[] = sprintf('%s#%s %s active:%s->%s', $d['dbtable'], $d['dbidx'], $d['action'], var_export($old, true), var_export($new, true));
    }
    return $out;
}

hr('Step 14: Change Package S1 (Active) P1 -> P3 (Pro)');
out('  service: ' . json_encode(svc($S1)));
$before = print_snap($N, '(before)');
$MARK = dl_mark();
out("  MARK = $MARK");
\WHMCS\Database\Capsule::table('tblhosting')->where('id', $S1)->update(['packageid' => $st['P3']]);
$r = lt_call('ChangePackage', $S1);
assert_line('14', $r['result'] === 'success', 'Module Command Success: ' . json_encode($r['result']));
$calls = api_calls($r);
out('  API calls: ' . implode(' -> ', $calls));
$upd = req_of($r['log'], 'client_update');
assert_line('14', count($upd) === 1 && ($calls[0] ?? null) === 'client_get' && in_array('client_update', $calls, true), 'client_get then ONE client_update: ' . json_encode($calls));
$p = $upd[0]['params'] ?? [];
assert_line('14', ($p['template_master'] ?? null) === 2 && array_key_exists('template_additional', $p) && !array_key_exists('locked', $p), 'client_update carries template_master=Pro(2) and template_additional, with no locked key: ' . json_encode($p));
$after = print_snap($N, '(after Change Package)');
$c = $after['client'];
assert_line('14', $c['locked'] === 'n', 'client stays unlocked: ' . $c['locked']);
assert_line('14', prev_active_entries($c['tmp_data']) === null, 'client has no prev_active: ' . json_encode($c['tmp_data']));
$pro = harness_panel_rows('SELECT limit_web_domain, limit_web_quota, limit_ftp_user, limit_maildomain, limit_mailbox, limit_database FROM client_template WHERE template_id = 2')[0];
$mine = array_intersect_key($c, $pro);
assert_line('14', (string) $c['template_master'] === '2' && $mine == $pro, "shows Pro's limits: client " . json_encode($mine) . ' vs template ' . json_encode($pro));
$trans = web_active_transitions($MARK);
out('  datalog after MARK: ' . json_encode($trans));
$webOff = array_filter($trans, fn ($t) => str_starts_with($t, 'web_domain') && str_contains($t, "->'n'"));
assert_line('14', $webOff === [] && actives($after, 'web_domain') === ['y'], 'the site is never set inactive (no web_domain active->n in the datalog; web_domain.active=' . json_encode(actives($after, 'web_domain')) . '). The HTTP 200 curl loop itself is not runnable: no web server/server.sh here');
print_props($S1);
$e = props_effective($S1);
$p3rows = array_filter(props_rows($S1), fn ($x) => (int) $x['product'] === $st['P3'] && $x['value'] !== '');
assert_line('14', $e['client_id'] === $N && count($p3rows) >= 8, 'stored ids migrated onto P3\'s custom fields (resaveAll): ' . count($p3rows) . ' non-blank P3 rows');
$sa = susp_as($S1);
out('  suspended_as after Change Package: ' . json_encode($sa));

hr('Step 14 (known ISPConfig behaviour): a hand-disabled object on an ACTIVE client is re-enabled by client_update (S5 IDN client, P1 -> P3)');
$S5 = $st['S5'];
$N5 = $st['N5'];
$mail5 = props_effective($S5)['mail_domain_id'];
panel_admin("set-active mail_domain domain_id $mail5 n");
\WHMCS\Database\Capsule::table('tblhosting')->where('id', $S5)->update(['packageid' => $st['P3']]);
$r = lt_call('ChangePackage', $S5);
$s5 = print_snap($N5, '(S5 after Change Package)');
assert_line('14-known', $r['result'] === 'success' && actives($s5, 'mail_domain') === ['y'], 'documented limitation reproduced: the hand-disabled mail domain was re-enabled by the package change (mail_domain.active=' . json_encode(actives($s5, 'mail_domain')) . ')');
