<?php

/** Plan step 17 items 1-5: Link/Replace refusals, forced shared client, Unsuspend with a Suspended/Active sharer, Unlink. */

declare(strict_types=1);

require __DIR__ . '/lib.php';

use WHMCS\Database\Capsule;

$st = load_state();
[$S1, $S4, $P1, $P3] = [$st['s1'], $st['s4'], $st['p1'], $st['p3']];
$N = (int) $st['s1_client'];
$S1user = $st['s1_user'];

/** Run one module call, returning [result, log rows, panel delta]. */
function run(string $label, callable $fn): array
{
    $mark = harness_log_mark();
    $before = panel_requests();
    $result = $fn();
    $delta = panel_delta($before);
    $rows = log_rows($mark);
    out("  >> $label");
    out('     returned: ' . (is_string($result) ? $result : json_encode($result)));
    out('     panel traffic: ' . fmt_delta($delta));
    print_log($rows, 600);

    return [$result, $rows, $delta];
}

hdr('17.0 precondition: S4 on P1, Active, never created; WHMCS admin "Save Changes" writes blank rows for P1\'s admin-only fields');
foreach (Capsule::table('tblcustomfields')->where('type', 'product')->where('relid', $P1)->get() as $f) {
    if (!Capsule::table('tblcustomfieldsvalues')->where('fieldid', $f->id)->where('relid', $S4)->exists()) {
        insert_value((int) $f->id, $S4, '');
    }
}
print_props($S4, '(after admin save)');
$snap0 = client_snapshot($N);
out('  panel client #' . $N . ' snapshot: ' . json_encode($snap0['client']) . ' web=' . json_encode($snap0['web_domain']));

hdr('17.1 S4 Username = S1\'s ISPConfig username (' . $S1user . '); Link, then Replace');
Capsule::table('tblhosting')->where('id', $S4)->update(['username' => $S1user]);
[$r] = run('LinkAccount S4', fn () => harness_call('LinkAccount', $S4));
check('17.1a', str_starts_with($r, "Link refused: this service's ISPConfig client #$N is also linked to service #$S1 ("), 'Link refused naming S1', $r);
check('17.1a-detail', str_contains($r, "WHMCS client #{$st['C1']}, Active)") && str_ends_with($r, 'Nothing has been changed.'), 'names C1 + Active, ends "Nothing has been changed."');
check('17.1a-domain-case', str_contains($r, '(test-one.example,'), 'domain shown lower-case as in the plan (test-one.example)', 'module prints tblhosting.domain verbatim');
[$r] = run('RelinkAccount S4', fn () => harness_call('RelinkAccount', $S4));
check('17.1b', str_starts_with($r, "Replace ISPConfig Link refused: this service's ISPConfig client #$N is also linked to service #$S1 ("), 'Replace refused, same text headed "Replace ISPConfig Link refused"', $r);
check('17.1c', nonblank_props($S4) === [], 'S4 stays unlinked (no non-blank ispconfig_* row)');
print_props($S4);

hdr('17.2 S4 Username = nosuchuser; Link');
Capsule::table('tblhosting')->where('id', $S4)->update(['username' => 'nosuchuser']);
[$r, $rows] = run('LinkAccount S4', fn () => harness_call('LinkAccount', $S4));
check('17.2a', str_contains($r, 'no account named "nosuchuser"'), 'refusal says there is no account named nosuchuser', $r);
check('17.2b', str_contains($r, 'Automatic adoption is not installed'), 'refusal says automatic adoption is not installed');
check('17.2c', nonblank_props($S4) === [], 'S4 still unlinked');

hdr('17.3 TEST ONLY: force a shared client - INSERT a P1 ispconfig_client_id row for S4 = ' . $N);
$fid = field_id($P1, 'ispconfig_client_id');
out("  SELECT id FROM tblcustomfields WHERE type='product' AND fieldname='ispconfig_client_id' AND relid=$P1 -> $fid");
$vid = insert_value($fid, $S4, (string) $N);
out("  INSERT INTO tblcustomfieldsvalues (fieldid,relid,value) VALUES ($fid,$S4,'$N') -> id $vid");
print_props($S4);
check('17.3-reader', module_reads($S4)['client_id'] === $N, 'S4 now reads as linked to #' . $N . ' (duplicate: blank admin-save row + inserted row)', json_encode(module_reads($S4)));

$ops = [
    'Terminate' => 'TerminateAccount',
    'Suspend' => 'SuspendAccount',
    'Change Password' => 'ChangePassword',
    'Change Package' => 'ChangePackage',
];
foreach ($ops as $label => $fn) {
    $snapBefore = client_snapshot($N);
    $propsBefore = prop_rows($S1);
    $dl = max_datalog();
    if ($fn === 'ChangePassword') {
        Capsule::table('tblhosting')->where('id', $S1)->update(['password' => 'Refused-Rotation-1']);
    }
    if ($fn === 'ChangePackage') {
        Capsule::table('tblhosting')->where('id', $S1)->update(['packageid' => $P3]);
    }
    [$r, $rows, $delta] = run("$fn S1", fn () => harness_call($fn, $S1));
    check("17.3-$fn-text", str_starts_with($r, "$label refused: this service's ISPConfig client #$N is also linked to service #$S4 ("), "$label refused naming S4", $r);
    check("17.3-$fn-nologin", no_panel_traffic($delta), "$label: no panel request / login at all", fmt_delta($delta));
    check("17.3-$fn-nolog", !in_array('login', log_actions($rows), true), "$label: no login row in tblmodulelog", implode(',', log_actions($rows)));
    check("17.3-$fn-panel", client_snapshot($N) === $snapBefore && datalog_since($dl) === [], "$label: client #$N and all its objects unchanged, no datalog");
    check("17.3-$fn-props", prop_rows($S1) === $propsBefore, "$label: S1's stored ids unchanged");
    check("17.3-$fn-status", svc($S1)->domainstatus === 'Active', "$label: S1 still Active in WHMCS");
    if ($fn === 'ChangePackage') {
        // Refused: put the WHMCS product back (the admin would, since nothing was applied).
        Capsule::table('tblhosting')->where('id', $S1)->update(['packageid' => $P1]);
    }
}
check('17.3-exists', count(prow("SELECT client_id FROM client WHERE client_id=$N")) === 1, "client #$N still exists");

// Extra: the refusals from the OTHER side (S4 is itself refused the same way).
foreach (['TerminateAccount', 'SuspendAccount'] as $fn) {
    [$r, , $delta] = run("$fn S4 (the forced sharer)", fn () => harness_call($fn, $S4));
    check("17.3x-$fn-S4", str_contains($r, "is also linked to service #$S1 (") && no_panel_traffic($delta), "S4 $fn refused naming S1, no panel traffic");
}
// Extra: Unsuspend on an ACTIVE, unlocked S1 while S4 (Active) shares: allowed, logs the sharer, changes nothing.
$snapBefore = client_snapshot($N);
[$r, $rows] = run('UnsuspendAccount S1 (Active, S4 Active sharer)', fn () => harness_call('UnsuspendAccount', $S1));
check('17.3x-unsuspend-active', $r === 'success' && client_snapshot($N) === $snapBefore, 'Unsuspend of an unlocked shared client: success, no change (client_get only)', implode(',', log_actions($rows)));

hdr('17.4 S1 is Active: Unlink S4, Suspend S1, re-insert the row, set S4 Suspended, Unsuspend S1; then S4 Active, Unsuspend S1');
[$r] = run('UnlinkAccount S4', fn () => harness_call('UnlinkAccount', $S4));
check('17.4-unlink', $r === 'success' && nonblank_props($S4) === [], 'Unlink S4 success, rows blank', $r);
print_props($S4);
[$r, $rows] = run('SuspendAccount S1', fn () => harness_call('SuspendAccount', $S1));
$locked = prow("SELECT locked, LENGTH(tmp_data) l FROM client WHERE client_id=$N")[0];
check('17.4-suspend', $r === 'success' && $locked['locked'] === 'y', 'Suspend S1 success, client locked', json_encode($locked));
$vid2 = insert_value($fid, $S4, (string) $N);
out("  re-INSERT (fid $fid, S4, '$N') -> id $vid2");
print_props($S4);
set_status($S4, 'Suspended');
out('  UPDATE tblhosting SET domainstatus=\'Suspended\' WHERE id=' . $S4);
$snapBefore = client_snapshot($N);
$dl = max_datalog();
[$r, $rows, $delta] = run('UnsuspendAccount S1 (S4 Suspended)', fn () => harness_call('UnsuspendAccount', $S1));
check('17.4a-text', str_starts_with($r, "Unsuspend refused: this service's ISPConfig client #$N is also linked to service #$S4 (") && str_contains($r, 'Suspended), which WHMCS has suspended.'), 'Unsuspend refused naming S4 as suspended', $r);
check('17.4a-nopanel', no_panel_traffic($delta) && !in_array('login', log_actions($rows), true), 'no panel request / login', fmt_delta($delta));
check('17.4a-state', client_snapshot($N) === $snapBefore && datalog_since($dl) === [] && svc($S1)->domainstatus === 'Suspended', 'client still locked, nothing changed, S1 still Suspended');

set_status($S4, 'Active');
out('  UPDATE tblhosting SET domainstatus=\'Active\' WHERE id=' . $S4);
[$r, $rows] = run('UnsuspendAccount S1 (S4 Active)', fn () => harness_call('UnsuspendAccount', $S1));
$locked = prow("SELECT locked, LENGTH(tmp_data) l FROM client WHERE client_id=$N")[0];
check('17.4b-success', $r === 'success' && $locked['locked'] === 'n' && svc($S1)->domainstatus === 'Active', 'Unsuspend succeeds, client unlocked, S1 Active', json_encode($locked));
$sharedLine = array_values(array_filter($rows, static fn ($row) => $row->action === 'UnsuspendAccount'));
check('17.4b-log', $sharedLine !== [] && str_contains($sharedLine[0]->response, "service #$S4 (test-four.example, WHMCS client #{$st['C2']}, Active)") && str_contains($sharedLine[0]->response, 'unlock allowed'), 'Module Log has an UnsuspendAccount line naming S4 as a live sharer', $sharedLine[0]->response ?? '(none)');
$snapAfter = client_snapshot($N);
out('  after unsuspend: ' . json_encode($snapAfter));

hdr('17.5 Unlink S4 (give S4 an old-product row too, to prove Unlink blanks EVERY product row)');
$fidP3 = ensure_field($P3, 'ispconfig_client_id');
$fidP3u = ensure_field($P3, 'ispconfig_client_username');
insert_value($fidP3, $S4, (string) $N);
insert_value($fidP3u, $S4, $S1user);
print_props($S4, '(before Unlink)');
[$r, $rows, $delta] = run('UnlinkAccount S4', fn () => harness_call('UnlinkAccount', $S4));
check('17.5-unlink', $r === 'success', 'Unlink S4 success', $r);
check('17.5-nopanel', no_panel_traffic($delta), 'Unlink touches no panel', fmt_delta($delta));
check('17.5-log', ($rows[0]->action ?? '') === 'UnlinkAccount' && str_contains($rows[0]->response, '"panel_touched":false'), 'UnlinkAccount log line with panel_touched=false');
print_props($S4, '(after Unlink)');
check('17.5-allblank', nonblank_props($S4) === [], 'every ispconfig_* row of S4 on every product (P1 duplicates + P3) is blank');
check('17.5-reader', module_reads($S4)['client_id'] === null, 'S4 reads as unlinked');
[$r2] = run('UnlinkAccount S4 again', fn () => harness_call('UnlinkAccount', $S4));
check('17.5-unlink-idem', str_starts_with($r2, 'Unlink: this service is not linked'), 'a second Unlink says nothing to clear', $r2);

// S1's operations work again.
[$r] = run('SuspendAccount S1', fn () => harness_call('SuspendAccount', $S1));
check('17.5-s1-suspend', $r === 'success' && prow("SELECT locked FROM client WHERE client_id=$N")[0]['locked'] === 'y', 'S1 Suspend works again');
[$r] = run('UnsuspendAccount S1', fn () => harness_call('UnsuspendAccount', $S1));
check('17.5-s1-unsuspend', $r === 'success' && prow("SELECT locked FROM client WHERE client_id=$N")[0]['locked'] === 'n', 'S1 Unsuspend works again');
out('  panel objects after: ' . json_encode(client_snapshot($N)));

summary();
