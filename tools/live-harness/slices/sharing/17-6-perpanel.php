<?php

/**
 * Plan step 17 item 6: per-panel scope of the shared-client guard.
 * S1 = ISPC-TEST (127.0.0.1:8084, panel 4) client #1 'testone1'
 * S5 = ISPC-TEST-2 (127.0.0.2:8085, panel 5 - a different panel) client #1 'testfive5' (really provisioned)
 * S6 = ISPC-NEGATIVE (same panel 4) - rows forced by SQL.
 */

declare(strict_types=1);

require __DIR__ . '/lib.php';

use WHMCS\Database\Capsule;

$st = load_state();
[$S1, $S5, $S6, $P1] = [$st['s1'], $st['s5'], $st['s6'], $st['p1']];
[$srv1, $srv2, $srv3] = [$st['srv1'], $st['srv2'], $st['srv3']];
$N = (int) $st['s1_client'];
$fidId = field_id($P1, 'ispconfig_client_id');
$fidUser = field_id($P1, 'ispconfig_client_username');
$pwCounter = 0;

function set_prop(int $sid, int $fid, string $value): void
{
    Capsule::table('tblcustomfieldsvalues')->where('fieldid', $fid)->where('relid', $sid)->update(['value' => $value]);
}

function server(int $id, array $cols): void
{
    Capsule::table('tblservers')->where('id', $id)->update($cols);
    $r = Capsule::table('tblservers')->where('id', $id)->first(['id', 'name', 'hostname', 'ipaddress', 'port']);
    out('  tblservers ' . json_encode($r));
}

/** Change Password on S1 with a fresh password; returns [result, delta, rows, pwChanged]. */
function cp_s1(string $label): array
{
    global $S1, $N, $pwCounter;
    $pwCounter++;
    $newPw = 'PerPanel-Rotation-' . $pwCounter . '-Xy9';
    Capsule::table('tblhosting')->where('id', $S1)->update(['password' => $newPw]);
    $before = pval("SELECT password FROM client WHERE client_id=$N");
    $p5before = client_snapshot(1, 'dbispconfig5');
    $mark = harness_log_mark();
    $pr = panel_requests();
    $r = harness_call('ChangePassword', $S1);
    $delta = panel_delta($pr);
    $rows = log_rows($mark);
    $after = pval("SELECT password FROM client WHERE client_id=$N");
    out("  >> $label");
    out('     Change Password S1: ' . $r);
    out('     panel traffic: ' . fmt_delta($delta));
    out('     module log actions: ' . implode(',', log_actions($rows)));
    out('     panel-4 client #' . $N . ' password hash changed: ' . ($before !== $after ? 'yes' : 'no'));
    $p5same = client_snapshot(1, 'dbispconfig5') === $p5before;
    out('     panel-5 client #1 unchanged: ' . ($p5same ? 'yes' : 'NO'));

    return [$r, $delta, $rows, $before !== $after, $p5same];
}

hdr('Baseline stored values');
print_props($S1);
print_props($S5);
out('  panel 5 client: ' . json_encode(prow('SELECT client_id, username FROM client', 'dbispconfig5')));
out('  panel 4 client: ' . json_encode(prow("SELECT client_id, username FROM client WHERE client_id=$N")));

hdr('A. ISPC-TEST-2 service S5 holds the same numeric id #' . $N . ' (real, panel 5), DIFFERENT username');
[$r, $d, , $changed, $p5same] = cp_s1('S5 username testfive5 vs S1 testone1');
check('17.6-A', $r === 'success' && $changed && $p5same && $d['p5']['requests'] === 0 && $d['p5alt']['requests'] === 0, 'ISPC-TEST-2 service does NOT block S1; only panel 4 touched', $r);

hdr('A2. S5 stored username blank -> blocks (safe direction)');
set_prop($S5, $fidUser, '');
[$r, $d] = cp_s1('S5 username blank');
check('17.6-A2', str_starts_with($r, "Change Password refused: this service's ISPConfig client #$N is also linked to service #$S5 (") && no_panel_traffic($d), 'blank username on the other side blocks', $r);
set_prop($S5, $fidUser, 'testfive5');

hdr('A3. S1 stored username blank -> blocks (safe direction)');
set_prop($S1, $fidUser, '');
[$r, $d] = cp_s1('S1 username blank');
check('17.6-A3', str_starts_with($r, "Change Password refused: this service's ISPConfig client #$N is also linked to service #$S5 (") && no_panel_traffic($d), 'blank username on this side blocks', $r);
set_prop($S1, $fidUser, 'testone1');

hdr('A4. SAME username on the other panel: rename panel-5 client #1 to testone1 and store it on S5 -> sharer (refused)');
panel_db('dbispconfig5')->query("UPDATE client SET username='testone1' WHERE client_id=1");
panel_db('dbispconfig5')->query("UPDATE sys_user SET username='testone1' WHERE client_id=1");
set_prop($S5, $fidUser, 'testone1');
[$r, $d] = cp_s1('S5 username testone1 == S1 testone1');
check('17.6-A4', str_starts_with($r, "Change Password refused: this service's ISPConfig client #$N is also linked to service #$S5 (") && no_panel_traffic($d), 'same username on a different panel is treated as a sharer (safe direction)', $r);
set_prop($S5, $fidUser, 'TestOne1');
[$r, $d] = cp_s1('S5 username TestOne1 (case differs)');
check('17.6-A4-case', str_contains($r, "also linked to service #$S5 ("), 'username comparison is case-insensitive', $r);
panel_db('dbispconfig5')->query("UPDATE client SET username='testfive5' WHERE client_id=1");
panel_db('dbispconfig5')->query("UPDATE sys_user SET username='testfive5' WHERE client_id=1");
set_prop($S5, $fidUser, 'testfive5');

hdr('A5. Symmetric: Change Password on S5 (panel 5) with S1 sharing the number but not the username');
Capsule::table('tblhosting')->where('id', $S5)->update(['password' => 'Panel5-Rotation-1-Xy9']);
$p4before = client_snapshot($N);
$pr = panel_requests();
$r = harness_call('ChangePassword', $S5);
$d = panel_delta($pr);
out('  Change Password S5: ' . $r . ' | ' . fmt_delta($d));
check('17.6-A5', $r === 'success' && $d['p4']['requests'] === 0 && client_snapshot($N) === $p4before, 'S5 rotation works and never touches panel 4');

hdr('B. ISPC-NEGATIVE (same panel, SAME Hostname 127.0.0.1 as ISPC-TEST): S6 forced to #' . $N . ' with a DIFFERENT username');
insert_value($fidId, $S6, (string) $N);
insert_value($fidUser, $S6, 'someoneelse');
print_props($S6);
server($srv2, ['hostname' => '127.0.0.1', 'ipaddress' => '127.0.0.1']);
[$r, $d] = cp_s1('ISPC-NEGATIVE same hostname, different username');
check('17.6-B', str_starts_with($r, "Change Password refused: this service's ISPConfig client #$N is also linked to service #$S6 (") && no_panel_traffic($d), 'same-address record blocks regardless of username', $r);
set_prop($S6, $fidUser, 'testone1');
[$r, $d] = cp_s1('ISPC-NEGATIVE same hostname, same username');
check('17.6-B2', str_contains($r, "also linked to service #$S6 (") && no_panel_traffic($d), 'same-address record + same username blocks', $r);

hdr('C. ISPC-NEGATIVE via a DIFFERENT address string (localhost vs 127.0.0.1, no IP), SAME username -> sharer');
server($srv2, ['hostname' => 'localhost', 'ipaddress' => '']);
set_prop($S6, $fidUser, 'testone1');
[$r, $d] = cp_s1('ISPC-NEGATIVE localhost, same username');
check('17.6-C', str_starts_with($r, "Change Password refused: this service's ISPConfig client #$N is also linked to service #$S6 (") && no_panel_traffic($d), 'different address string, same username: blocks', $r);

hdr('D. ISPC-NEGATIVE via localhost, S6 username BLANK -> sharer');
set_prop($S6, $fidUser, '');
[$r, $d] = cp_s1('ISPC-NEGATIVE localhost, blank username');
check('17.6-D', str_contains($r, "also linked to service #$S6 (") && no_panel_traffic($d), 'blank username blocks', $r);

hdr('E. ISPC-NEGATIVE via localhost, S6 username DIFFERENT -> dropped (the documented two-evidence rule; same physical panel!)');
set_prop($S6, $fidUser, 'someoneelse');
[$r, $d, , $changed] = cp_s1('ISPC-NEGATIVE localhost, different username');
out('  NOTE: this record reaches the SAME panel as ISPC-TEST; the module cannot know that, and drops the candidate');
check('17.6-E-observed', $r === 'success', 'OBSERVATION: not blocked (the guard drops it on address+username evidence)', $r);

hdr('F. ISPC-NEGATIVE hostname localhost but IP Address 127.0.0.1 (shared with ISPC-TEST) -> sharer even with a different username');
server($srv2, ['hostname' => 'localhost', 'ipaddress' => '127.0.0.1']);
[$r, $d] = cp_s1('ISPC-NEGATIVE localhost + IP 127.0.0.1, different username');
check('17.6-F', str_contains($r, "also linked to service #$S6 (") && no_panel_traffic($d), 'any shared address blocks', $r);

hdr('G. Plan wording: ISPC-TEST by name, ISPC-NEGATIVE changed to the panel IP, same username -> blocks');
server($srv1, ['hostname' => 'localhost', 'ipaddress' => '']);
server($srv2, ['hostname' => '127.0.0.1', 'ipaddress' => '']);
set_prop($S6, $fidUser, 'testone1');
[$r, $d] = cp_s1('ISPC-TEST localhost, ISPC-NEGATIVE 127.0.0.1, same username');
check('17.6-G', str_contains($r, "also linked to service #$S6 (") && no_panel_traffic($d), 'still blocks when ISPC-NEGATIVE uses the IP and both store the same username', $r);
server($srv2, ['hostname' => 'LOCALHOST.', 'ipaddress' => '']);
set_prop($S6, $fidUser, 'someoneelse');
[$r, $d] = cp_s1('ISPC-TEST localhost, ISPC-NEGATIVE "LOCALHOST." (normalised equal), different username');
check('17.6-G2', str_contains($r, "also linked to service #$S6 ("), 'address comparison normalises case and trailing dot', $r);
server($srv1, ['hostname' => '127.0.0.1', 'ipaddress' => '127.0.0.1']);
server($srv2, ['hostname' => '127.0.0.1', 'ipaddress' => '127.0.0.1']);

hdr('H. Blank both rows afterwards (S6 forced rows); S1 works again');
set_prop($S6, $fidId, '');
set_prop($S6, $fidUser, '');
print_props($S6);
[$r, $d, , $changed] = cp_s1('after blanking S6');
check('17.6-H', $r === 'success' && $changed, 'S1 Change Password succeeds once the forced rows are blank', $r);

$st['s1_password'] = svc($S1)->password;
save_state($st);
summary();
