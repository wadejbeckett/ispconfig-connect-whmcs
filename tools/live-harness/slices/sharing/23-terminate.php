<?php

/** Plan step 23: Terminate success, stale-link refusals (API delete and DB delete), non-positive ids. */

declare(strict_types=1);

require __DIR__ . '/lib.php';

use WHMCS\Database\Capsule;

$st = load_state();
[$S1, $S2, $S3, $S4, $S5, $P1, $P2, $P3, $srv1] = [$st['s1'], $st['s2'], $st['s3'], $st['s4'], $st['s5'], $st['p1'], $st['p2'], $st['p3'], $st['srv1']];

const OBJ_TABLES = ['web_domain', 'ftp_user', 'mail_domain', 'dns_soa', 'dns_rr', 'web_database', 'web_database_user', 'mail_user', 'shell_user', 'cron'];

function owned_counts(int $clientId, string $db = 'dbispconfig4'): array
{
    $gid = (int) pval("SELECT groupid FROM sys_group WHERE client_id = $clientId", $db);
    $c = ['client' => (int) pval("SELECT COUNT(*) FROM client WHERE client_id=$clientId", $db),
        'sys_user' => (int) pval("SELECT COUNT(*) FROM sys_user WHERE client_id=$clientId", $db),
        'sys_group' => (int) pval("SELECT COUNT(*) FROM sys_group WHERE client_id=$clientId", $db), 'groupid' => $gid];
    foreach (OBJ_TABLES as $t) {
        $c[$t] = $gid > 0 ? (int) pval("SELECT COUNT(*) FROM $t WHERE sys_groupid=$gid", $db) : 0;
    }

    return $c;
}

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
    print_log($rows, 300);

    return [$result, $rows, $delta];
}

function client_get_ids(array $rows): array
{
    $ids = [];
    foreach ($rows as $r) {
        if ($r->action === 'client_get') {
            $req = json_decode((string) $r->request, true);
            $ids[] = $req['client_id'] ?? null;
        }
    }

    return $ids;
}

hdr('23-pre. Cross-panel Terminate: S5 (panel 5, client #1 testfive5) while S1 (panel 4, client #1 testone1) is Active');
$p4c1 = owned_counts(1);
$p5c1 = owned_counts(1, 'dbispconfig5');
out('  panel4 client#1 owns: ' . json_encode($p4c1));
out('  panel5 client#1 owns: ' . json_encode($p5c1));
[$r, $rows, $d] = run('TerminateAccount S5', fn () => harness_call('TerminateAccount', $S5));
$p5after = owned_counts(1, 'dbispconfig5');
check('23-pre-S5', $r === 'success' && $d['p4']['requests'] === 0 && owned_counts(1) === $p4c1 && array_sum(array_diff_key($p5after, ['groupid' => 1])) === 0, 'S5 terminated on panel 5 only; panel 4 client #1 untouched', json_encode($p5after));
check('23-pre-S5-props', nonblank_props($S5) === [] && svc($S5)->domainstatus === 'Terminated', 'S5 rows blank, WHMCS status Terminated');

hdr('23a. Terminate S1 (S4 unlinked). First give S1 rows on TWO products via a real Change Package P1 -> P3');
Capsule::table('tblhosting')->where('id', $S1)->update(['packageid' => $P3]);
[$r] = run('ChangePackage S1 -> P3', fn () => harness_call('ChangePackage', $S1));
check('23a-pre-cp', $r === 'success', 'Change Package to P3 succeeds (S4 no longer shares)', $r);
print_props($S1, '(rows on P1 and P3)');
$N = (int) $st['s1_client'];
$before = owned_counts($N);
out('  client #' . $N . ' owns before: ' . json_encode($before));
$dl = max_datalog();
[$r, $rows] = run('TerminateAccount S1', fn () => harness_call('TerminateAccount', $S1));
$after = owned_counts($N);
out('  client #' . $N . ' owns after:  ' . json_encode($after));
check('23a-success', $r === 'success' && svc($S1)->domainstatus === 'Terminated', 'Terminate success; WHMCS status Terminated', $r);
check('23a-calls', log_actions($rows) === ['login', 'client_get', 'client_delete_everything', 'logout'], 'Module Log: client_get (existence) then client_delete_everything', implode(',', log_actions($rows)));
check('23a-deleted', array_sum(array_diff_key($after, ['groupid' => 1])) === 0, 'every object of the client is deleted (client, sys_user, sys_group, web, ftp, mail, dns, db, db user)');
$dlRows = datalog_since($dl);
out('  datalog after MARK: ' . count($dlRows) . ' entries: ' . implode(' ', array_map(static fn ($d) => $d['dbtable'] . ':' . $d['action'], $dlRows)));
print_props($S1, '(after Terminate)');
check('23a-blank', nonblank_props($S1) === [], 'every ispconfig_* row for S1 on every product (P1 and P3) is blank');
check('23a-others', owned_counts(2)['client'] === 1 && owned_counts(3)['client'] === 1, 'other clients (#2, #3) untouched');

hdr('23b. S3: delete its client by hand (raw API client_delete_everything, not the module), then Terminate S3');
$N3 = (int) $st['s3_client'];
$res = raw_api(8084, 'client_delete_everything', ['client_id' => $N3]);
out('  raw API client_delete_everything #' . $N3 . ': ' . json_encode($res));
out('  client #' . $N3 . ' owns now: ' . json_encode(owned_counts($N3)));
$propsBefore = prop_rows($S3);
$dl = max_datalog();
[$r, $rows] = run('TerminateAccount S3', fn () => harness_call('TerminateAccount', $S3));
check('23b-text', str_starts_with($r, "Terminate refused: the stored ISPConfig client id #$N3 no longer exists on this panel"), 'Terminate refused with the stale text', $r);
check('23b-nothing', datalog_since($dl) === [] && !in_array('client_delete_everything', log_actions($rows), true), 'nothing deleted: no client_delete_everything, no datalog', implode(',', log_actions($rows)));
check('23b-state', prop_rows($S3) === $propsBefore && svc($S3)->domainstatus === 'Active', 'S3 keeps its stored ids (not cleared) and stays Active in WHMCS');
$tab = ispconfigconnect_AdminServicesTabFields(harness_params($S3));
out('  admin tab ISPConfig Link: ' . ($tab['ISPConfig Link'] ?? '?'));
check('23b-tab', str_starts_with($tab['ISPConfig Link'] ?? '', 'STALE: the stored ISPConfig client id #' . $N3), 'admin tab reports the link as STALE');

hdr('23b-DB. T1: provision, then delete ONLY its client row directly in the DB (objects left behind), then Terminate');
$T1 = harness_add_service($P1, $srv1, 'stale-db.example', ['userid' => 201]);
out('  Create T1: ' . harness_call('CreateAccount', $T1));
$NT = module_reads($T1)['client_id'];
panel_db('dbispconfig4')->query("DELETE FROM client WHERE client_id = $NT");
$orphans = owned_counts($NT);
out("  DELETE FROM client WHERE client_id=$NT -> left behind: " . json_encode($orphans));
$dl = max_datalog();
[$r, $rows] = run('TerminateAccount T1', fn () => harness_call('TerminateAccount', $T1));
check('23b-db-text', str_starts_with($r, "Terminate refused: the stored ISPConfig client id #$NT no longer exists on this panel"), 'stale refusal after a DB delete', $r);
check('23b-db-nothing', owned_counts($NT) === $orphans && datalog_since($dl) === [], 'nothing deleted: the orphaned objects are untouched');
check('23b-db-getonly', client_get_ids($rows) === [$NT], 'only client_get #' . $NT . ' was asked', json_encode(client_get_ids($rows)));

hdr('23b-DB extras (not in the plan): other write operations on the same stale link');
foreach (['SuspendAccount', 'ChangePassword', 'ChangePackage', 'UnsuspendAccount'] as $fn) {
    $objs = prow('SELECT domain_id, active FROM web_domain WHERE sys_groupid=' . (int) $orphans['groupid']);
    $dl = max_datalog();
    if ($fn === 'ChangePassword') {
        Capsule::table('tblhosting')->where('id', $T1)->update(['password' => 'Stale-Rotation-1-Xy9']);
    }
    [$r, $rows] = run("$fn T1 (stale)", fn () => harness_call($fn, $T1));
    $objsAfter = prow('SELECT domain_id, active FROM web_domain WHERE sys_groupid=' . (int) $orphans['groupid']);
    out('     orphaned website before/after: ' . json_encode($objs) . ' / ' . json_encode($objsAfter) . ' | datalog: ' . json_encode(datalog_since($dl)));
    out('     WHMCS status now: ' . svc($T1)->domainstatus);
    $GLOBALS['stale_extra'][$fn] = [$r, $objs, $objsAfter, datalog_since($dl)];
}

hdr('23c. S2: set ispconfig_client_id = 0 by SQL, Terminate; also -1 and a non-numeric value; then restore');
$fid2 = field_id($P2, 'ispconfig_client_id');
$N2 = (int) $st['s2_client'];
foreach (['0', '-1', 'abc', '0.9'] as $bad) {
    Capsule::table('tblcustomfieldsvalues')->where('fieldid', $fid2)->where('relid', $S2)->update(['value' => $bad]);
    out("  UPDATE ... SET v.value='$bad' WHERE ... relid=$S2");
    $before2 = owned_counts($N2);
    [$r, $rows, $d] = run("TerminateAccount S2 (client_id '$bad')", fn () => harness_call('TerminateAccount', $S2));
    $ids = client_get_ids($rows);
    $nonPositive = array_filter($ids, static fn ($i) => (int) $i <= 0);
    if ($bad === 'abc') {
        check("23c-$bad", str_starts_with($r, 'Terminate refused: this service is not linked'), "non-numeric '$bad' reads as unlinked (unlinked refusal, not the stale text)", $r);
    } else {
        check("23c-$bad-text", str_starts_with($r, "Terminate refused: the stored ISPConfig client id #" . (int) $bad . ' no longer exists'), "'$bad' -> the same stale refusal", $r);
    }
    check("23c-$bad-noget", $nonPositive === [] && owned_counts($N2) === $before2 && svc($S2)->domainstatus === 'Active', "'$bad': no client_get with an id <= 0, nothing deleted, S2 Active", 'client_get ids: ' . json_encode($ids) . ' ; ' . fmt_delta($d));
}
Capsule::table('tblcustomfieldsvalues')->where('fieldid', $fid2)->where('relid', $S2)->update(['value' => (string) $N2]);
out("  restored S2 ispconfig_client_id = $N2");
print_props($S2);

$st['T1'] = $T1;
save_state($st);
summary();
