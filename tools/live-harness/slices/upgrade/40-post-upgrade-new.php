<?php
/**
 * PHASE 2 - post-upgrade behaviour on the old build's data, NEW build.
 */
declare(strict_types=1);
require __DIR__ . '/common.php';

use WHMCS\Database\Capsule;

$state = up_state();
$S = $state['svc'];
$C = $state['client'];
$Wb = $state['web'];
$Pr = $state['prod'];
$checks = [];
$ok = static function (bool $cond, string $label) use (&$checks): void {
    $checks[] = [$cond ? 'OK  ' : 'FAIL', $label];
    echo '    [', $cond ? 'OK' : 'FAIL', '] ', $label, "\n";
};
$noLogin = static fn (array $r): bool => !in_array('login', $r['actions'], true);
$susp = static function (int $svc): string {
    $v = Capsule::table('tblcustomfieldsvalues as v')->join('tblcustomfields as f', 'f.id', '=', 'v.fieldid')
        ->where('v.relid', $svc)->where('f.fieldname', 'ispconfig_suspended_as')->orderByDesc('v.id')->pluck('v.value')->all();

    return json_encode($v);
};

// ---------------------------------------------------------------------------
up_h('2.8 / 23(c): hand-edited client ids');
$r = up_call('TerminateAccount', $S['Z0'], [], "client id '0'");
$ok(str_starts_with((string) $r['result'], 'Terminate refused: the stored ISPConfig client id #0 no longer exists'), 'Z0 Terminate -> stale refusal');
$ok(!in_array('client_get', $r['actions'], true), 'Z0: no client_get with id <= 0');
$r = up_call('TerminateAccount', $S['Zabc'], [], "client id 'abc'");
$ok(str_starts_with((string) $r['result'], 'Terminate refused: the stored ISPConfig client id'), "Zabc Terminate -> stale refusal (plan 2.8 wording)");
$ok(str_starts_with((string) $r['result'], 'Terminate refused: this service is not linked'), 'Zabc Terminate -> refused as NOT LINKED (actual)');
$r = up_call('UnlinkAccount', $S['Zabc'], [], "plan 2.8: 'Unlink or relink them'");
up_show_props($S['Zabc']);
$ok(str_contains(up_props_brief($S['Zabc']), 'client_id=abc'), "Zabc: the 'abc' row is STILL there after Unlink");
$r = up_call('UnlinkAccount', $S['Z0'], [], 'Unlink the 0 row');
up_show_props($S['Z0']);
$ok($r['result'] === 'success' && !str_contains(up_props_brief($S['Z0']), 'client_id=0'), 'Z0 Unlink blanks the 0 row');

// ---------------------------------------------------------------------------
up_h('2.5 shared client G(Suspended)/H(Active) on client #' . $C['G']);
foreach ([['TerminateAccount', 'G'], ['ChangePassword', 'G'], ['ChangePackage', 'G'], ['TerminateAccount', 'H'], ['SuspendAccount', 'H'], ['ChangePassword', 'H'], ['ChangePackage', 'H']] as [$fn, $k]) {
    $other = $k === 'G' ? $S['H'] : $S['G'];
    $r = up_call($fn, $S[$k], [], "shared client, $k");
    $label = ['TerminateAccount' => 'Terminate', 'ChangePassword' => 'Change Password', 'ChangePackage' => 'Change Package', 'SuspendAccount' => 'Suspend'][$fn];
    $ok(str_starts_with((string) $r['result'], "$label refused: this service's ISPConfig client #{$C['G']} is also linked to service #$other"), "$fn on $k refused naming service #$other");
    $ok($noLogin($r) && $r['datalog'] === [], "$fn on $k: no login, nothing queued");
}
up_show_client($C['G'], 'shared client still locked, intact');
Capsule::table('tblhosting')->where('id', $S['H'])->update(['username' => 'upg']);
$r = up_call('LinkAccount', $S['H'], [], "plan 2.5 says Link is refused on shared clients: H presses Link naming its own (shared) client 'upg'");
$r2 = up_call('RelinkAccount', $S['H'], [], "Replace on H naming 'upg'");
up_show_props($S['H']);
$ok(str_contains((string) $r['result'], 'refused') && str_contains((string) $r2['result'], 'refused'), 'Link/Replace on an existing sharer naming the SAME client are refused (plan 2.5 wording)');
$svcP = harness_add_service($Pr['P1'], $state['server'], 'shared-new.test', ['userid' => 124, 'username' => 'upg', 'domainstatus' => 'Active']);
$state['svc']['P'] = $svcP;
$r = up_call('LinkAccount', $svcP, [], "a NEW service links to the shared client 'upg'");
$ok(str_starts_with((string) $r['result'], "Link refused: this service's ISPConfig client #{$C['G']} is also linked to service #{$S['G']}") && str_contains((string) $r['result'], "service #{$S['H']}"), 'new-service Link to the shared client refused naming G and H');
$r = up_call('RelinkAccount', $svcP, [], 'Replace on the new service');
$ok(str_starts_with((string) $r['result'], 'Replace ISPConfig Link refused: this service'), 'new-service Replace refused');
$r = up_call('UnsuspendAccount', $S['G'], [], 'Unsuspend G: the only other sharer H is Active');
$ok($r['result'] === 'success', 'Unsuspend G allowed');
$ok((bool) array_filter($r['log'], static fn ($l) => str_contains($l, 'UnsuspendAccount') && str_contains($l, 'service #' . $S['H'])), 'module log has an UnsuspendAccount line naming H as a live sharer');
up_show_client($C['G'], 'after Unsuspend G');
up_p('    G ispconfig_suspended_as rows', $susp($S['G']));

// ---------------------------------------------------------------------------
up_h('2.5 shared client I/J, BOTH Suspended by the old build, client #' . $C['I']);
$r = up_call('UnsuspendAccount', $S['I'], [], 'I: other sharer J Suspended');
$ok(str_starts_with((string) $r['result'], "Unsuspend refused: this service's ISPConfig client #{$C['I']} is also linked to service #{$S['J']}"), 'Unsuspend I refused naming J (Suspended)');
$ok($noLogin($r), 'no login');
$r = up_call('UnsuspendAccount', $S['J'], [], 'J: other sharer I Suspended');
$ok(str_starts_with((string) $r['result'], "Unsuspend refused: this service's ISPConfig client #{$C['I']} is also linked to service #{$S['I']}"), 'Unsuspend J refused naming I (Suspended) -> neither can be unsuspended');
$r = up_call('TerminateAccount', $S['J'], [], "the refusal's 'terminate the other service first' route");
$ok(str_contains((string) $r['result'], 'refused'), 'Terminate J (the suggested route) is itself refused (shared)');
$r = up_call('UnlinkAccount', $S['J'], [], "the refusal's Unlink route");
up_show_props($S['J']);
$r = up_call('UnsuspendAccount', $S['I'], [], 'I after J unlinked');
$ok($r['result'] === 'success', 'Unsuspend I succeeds once J is unlinked');
up_show_client($C['I'], 'after Unsuspend I');

// ---------------------------------------------------------------------------
up_h('Old-build entire-client suspension, product still entire (B1)');
$r = up_call('UnsuspendAccount', $S['B1']);
$ok($r['result'] === 'success' && in_array('client_update', $r['actions'], true), 'Unsuspend B1: client_update');
$ok((bool) array_filter($r['log'], static fn ($l) => str_contains($l, 'client_update') && str_contains($l, '"locked":"n"')), "client_update carries locked='n'");
up_show_client($C['B1'], 'B1 after Unsuspend');
$ok(up_client($C['B1'])['locked'] === 'n' && up_web_active($Wb['B1']) === 'y', 'B1 unlocked, website active');
up_p('    B1 ispconfig_suspended_as rows', $susp($S['B1']));

up_h('16b(d) legacy path 1: entire lock by the old build, product now website-only (B2 on P1b)');
up_set_option($Pr['P1b'], 13, '');
$dl = up_datalog_max();
$r = up_call('UnsuspendAccount', $S['B2'], [], 'P1b unticked');
$ok(str_starts_with((string) $r['result'], "Unsuspend refused: this service's ISPConfig client #{$C['B2']} is locked, but this product suspends the website only and the module has no record of locking it"), 'refusal with instructions');
$ok(!in_array('client_update', $r['actions'], true) && !in_array('sites_web_domain_set_status', $r['actions'], true) && $r['datalog'] === [], 'nothing changed (no client_update / set_status / datalog)');
$ok($r['status'] === 'Suspended' && up_client($C['B2'])['locked'] === 'y', 'still Suspended in WHMCS, still locked');
up_set_option($Pr['P1b'], 13, 'on');
$r = up_call('UnsuspendAccount', $S['B2'], [], 'P1b ticked again, as the refusal instructs');
$ok($r['result'] === 'success' && up_client($C['B2'])['locked'] === 'n', 'Unsuspend after ticking: client unlocked');
up_show_client($C['B2'], 'B2 after');
up_set_option($Pr['P1b'], 13, '');

up_h('Old-build website-only suspension, product still website-only (D1)');
$r = up_call('UnsuspendAccount', $S['D1']);
$ok($r['result'] === 'success' && in_array('sites_web_domain_set_status', $r['actions'], true) && !in_array('client_update', $r['actions'], true), 'Unsuspend D1: set_status, no client_update');
$ok(up_web_active($Wb['D1']) === 'y', 'D1 website active');

up_h('Legacy path 2: website-only by the old build, product now entire (D2 on P2b)');
up_set_option($Pr['P2b'], 13, 'on');
$r = up_call('UnsuspendAccount', $S['D2'], [], 'P2b ticked');
$ok($r['result'] === 'success', 'Unsuspend D2 success');
$ok(in_array('sites_web_domain_get', $r['actions'], true) && in_array('sites_web_domain_set_status', $r['actions'], true) && !in_array('client_update', $r['actions'], true), 'sites_web_domain_get + set_status, no client_update');
$ok((bool) array_filter($r['log'], static fn ($l) => str_contains($l, 'UnsuspendAccount') && str_contains($l, 'set active again')), 'module log records the decision');
$ok(up_web_active($Wb['D2']) === 'y' && up_client($C['D2'])['locked'] === 'n', 'D2 website reactivated, client not locked');

up_h('Legacy path 2b: website-only by the old build, product now entire, Suspend pressed again (D3 on P2c)');
up_set_option($Pr['P2c'], 13, 'on');
$r = up_call('SuspendAccount', $S['D3'], [], 'P2c ticked; WHMCS status already Suspended');
$ok($r['result'] === 'success', 'Suspend D3 success');
up_show_client($C['D3'], 'D3 after re-suspend');
$ok(up_client($C['D3'])['locked'] === 'y', 'client locked');
up_p('    D3 ispconfig_suspended_as rows', $susp($S['D3']));
$r = up_call('UnsuspendAccount', $S['D3']);
up_show_client($C['D3'], 'D3 after Unsuspend');
$ok($r['result'] === 'success' && up_client($C['D3'])['locked'] === 'n' && up_web_active($Wb['D3']) === 'y', 'D3 unlocked AND website active');

up_h('2.10: K - locked client whose prev_active the old Change Package poisoned');
$dl = up_datalog_max();
$r = up_call('ChangePackage', $S['K'], [], 'new build, client locked');
$ok(str_starts_with((string) $r['result'], "Change Package refused: this service's ISPConfig client is locked (suspended)."), 'Change Package refused on a locked client');
$ok(!in_array('client_update', $r['actions'], true) && $r['datalog'] === [], 'only client_get, nothing queued');
$r = up_call('UnsuspendAccount', $S['K']);
up_show_client($C['K'], 'K after Unsuspend');
$c = up_client($C['K']);
$ok($c['locked'] === 'n' && $c['web'][0]['active'] === 'n' && $c['mail'][0]['active'] === 'n', "plan 2.10 claim: Unsuspend restores nothing (objects stay 'n')");

up_h('2.10: L - website-only Suspended, site re-activated by the old Change Package');
$ok(up_web_active($Wb['L']) === 'y', 'L site active before');
$r = up_call('SuspendAccount', $S['L'], [], 'press Suspend again under the new build');
$ok($r['result'] === 'success' && up_web_active($Wb['L']) === 'n', 'L site offline again');
up_p('    L ispconfig_suspended_as rows', $susp($S['L']));
$r = up_call('UnsuspendAccount', $S['L']);
$ok($r['result'] === 'success' && up_web_active($Wb['L']) === 'y', 'L Unsuspend -> site active');

// ---------------------------------------------------------------------------
up_h('2.9 / 13(b,c): website-only services with no website id (E Active, E2 Suspended by old no-op)');
$r = up_call('SuspendAccount', $S['E'], [], 'E: no website id');
$ok(str_starts_with((string) $r['result'], 'Suspend refused: this product suspends the website only, but no ISPConfig website id is stored for this service, so there is nothing safe to suspend. Press "Replace ISPConfig Link"'), 'Suspend refused with the plan text');
$ok($r['actions'] === [] && $r['datalog'] === [], 'no panel call at all');
$ok(up_client($C['E'])['locked'] === 'n' && $r['status'] === 'Active', 'client not locked, WHMCS still Active');
up_p('    E ispconfig_suspended_as rows', $susp($S['E']));
$r = up_call('RelinkAccount', $S['E'], [], "Replace ISPConfig Link, Username 'upe'");
$ok($r['result'] === 'success', 'Replace success');
$ok(array_values(array_intersect($r['actions'], ['client_get_by_username', 'client_get_sites_by_user', 'sites_web_domain_get'])) === ['client_get_by_username', 'client_get_sites_by_user', 'sites_web_domain_get'], 'client_get_by_username, client_get_sites_by_user, sites_web_domain_get');
up_show_props($S['E'], 'after Replace');
$ok(str_contains(up_props_brief($S['E']), 'domain_id=' . $Wb['E']), 'website id #' . $Wb['E'] . ' stored');
$r = up_call('SuspendAccount', $S['E']);
$ok($r['result'] === 'success' && up_web_active($Wb['E']) === 'n' && up_client($C['E'])['locked'] === 'n', 'E Suspend: website off, client not locked');
up_p('    E ispconfig_suspended_as rows', $susp($S['E']));
$r = up_call('UnsuspendAccount', $S['E']);
$ok($r['result'] === 'success' && up_web_active($Wb['E']) === 'y', 'E Unsuspend: website on');
up_p('    E ispconfig_suspended_as rows', $susp($S['E']));

$r = up_call('UnsuspendAccount', $S['E2'], [], 'E2: Suspended by the old build (no-op), no website id');
$ok(str_starts_with((string) $r['result'], 'Unsuspend refused: this product suspends the website only, but no ISPConfig website id is stored'), 'Unsuspend refused');
$ok($r['actions'] === [], 'no panel call');
Capsule::table('tblhosting')->where('id', $S['E2'])->update(['domain' => 'nomatch.test']);
$r = up_call('RelinkAccount', $S['E2'], [], "13 optional: domain matches no website of the client");
$ok(str_starts_with((string) $r['result'], "Replace ISPConfig Link kept the existing link to ISPConfig client #{$C['E2']}, but could not store the website id this product needs to suspend:"), 'Replace answers the kept-link text');
Capsule::table('tblhosting')->where('id', $S['E2'])->update(['domain' => 'web-link-susp.test']);
$r = up_call('RelinkAccount', $S['E2'], [], 'domain restored');
$ok($r['result'] === 'success' && str_contains(up_props_brief($S['E2']), 'domain_id=' . $Wb['E2']), 'E2 website id stored');
$ok(up_web_active($Wb['E2']) === 'y', 'E2 website still LIVE although WHMCS says Suspended (the old no-op)');
$r = up_call('SuspendAccount', $S['E2'], [], "plan 2.9: 'press Suspend again after storing the id'");
$ok($r['result'] === 'success' && up_web_active($Wb['E2']) === 'n', 'E2 now actually suspended on the panel');
$r = up_call('UnsuspendAccount', $S['E2']);
$ok($r['result'] === 'success' && up_web_active($Wb['E2']) === 'y', 'E2 Unsuspend: website on');

// ---------------------------------------------------------------------------
up_h('2.6 stale rows: F (old Change Package P1->P3 then old Unlink)');
up_show_props($S['F']);
$tab = ispconfigconnect_AdminServicesTabFields(harness_params($S['F']));
up_p('    F admin tab Client ID', $tab['ISPConfig Client ID'] ?? '?');
$ok(($tab['ISPConfig Client ID'] ?? '') === (string) $C['F'], "plan 2.6: current row blank -> module 'still reads the old value' (#{$C['F']})");
$r = up_call('UnlinkAccount', $S['F'], [], "plan 2.6: 'press Unlink ... Unlink now blanks every product's rows'");
up_show_props($S['F'], 'after new Unlink');
$ok(!preg_match('/P\d+\.client_id=\d/', up_props_brief($S['F'])), "every product's rows blank after Unlink");
$svcQ = harness_add_service($Pr['P1'], $state['server'], 'cp-unlink-new.test', ['userid' => 125, 'username' => 'upf', 'domainstatus' => 'Active']);
$state['svc']['Q'] = $svcQ;
$r = up_call('LinkAccount', $svcQ, [], "a new service Q links to F's old client 'upf'");
$ok($r['result'] === 'success', "Q Link to client #{$C['F']} allowed (F's stale P1 row is not counted as a sharer)");
up_p('    guard for client #' . $C['F'] . ' seen from Q', json_encode(ispconfigconnect_otherServicesLinkedToClient($svcQ, $C['F'])));
Capsule::table('tblhosting')->where('id', $S['F'])->update(['packageid' => $Pr['P1']]);
up_p('    F moved back to P1 (product edit, no module command)');
$tab = ispconfigconnect_AdminServicesTabFields(harness_params($S['F']));
up_p('    F admin tab Client ID after the move', $tab['ISPConfig Client ID'] ?? '?');
up_p('    F admin tab Link after the move', html_entity_decode((string) ($tab['ISPConfig Link'] ?? '?')));
$ok(($tab['ISPConfig Client ID'] ?? '') === 'not linked', 'F stays unlinked after a move back to its old product');
$r = up_call('TerminateAccount', $svcQ, [], "Q now shares client #{$C['F']} with the resurrected F");
up_p('    guard for client #' . $C['F'] . ' seen from Q after the move', json_encode(ispconfigconnect_otherServicesLinkedToClient($svcQ, $C['F'])));
$r = up_call('UnlinkAccount', $S['F'], [], 'Unlink F now that it reads linked');
up_show_props($S['F'], 'after Unlink with F on P1');
Capsule::table('tblhosting')->where('id', $S['F'])->update(['packageid' => $Pr['P3']]);

up_h('2.6 stale rows: M (old Change Package P1->P3 then old Replace to client #12) and N (linked to M\'s old client #13)');
up_show_props($S['M']);
$tab = ispconfigconnect_AdminServicesTabFields(harness_params($S['M']));
$ok(($tab['ISPConfig Client ID'] ?? '') === '12' && ($tab['ISPConfig Domain ID'] ?? '') === 'n/a', 'M reads client #12 and NO website id (the P1 website id #13 belongs to the old client)');
$r = up_call('ChangePassword', $S['N'], [], "N on client #13: M's stale P1 row must not block it");
$ok($r['result'] === 'success', 'N Change Password not blocked by M');
$svcO = harness_add_service($Pr['P1'], $state['server'], 'cp-relink-o.test', ['userid' => 126, 'username' => 'upm', 'domainstatus' => 'Active']);
$state['svc']['O'] = $svcO;
$r = up_call('LinkAccount', $svcO, [], "new service O links to 'upm' (client #13)");
$ok(str_contains((string) $r['result'], "service #{$S['N']}") && !str_contains((string) $r['result'], "service #{$S['M']} "), 'O refused naming N only, not M');
Capsule::table('tblhosting')->where('id', $S['M'])->update(['packageid' => $Pr['P1']]);
$tab = ispconfigconnect_AdminServicesTabFields(harness_params($S['M']));
up_p('    M moved back to P1 (product edit): admin tab Client ID / Domain ID', ($tab['ISPConfig Client ID'] ?? '?') . ' / ' . ($tab['ISPConfig Domain ID'] ?? '?'));
$ok(($tab['ISPConfig Client ID'] ?? '') === '12', 'M still reads client #12 after a move back to P1');
$r = up_call('ChangePackage', $S['M'], [], 'Change Package M back to P1');
Capsule::table('tblhosting')->where('id', $S['M'])->update(['packageid' => $Pr['P3']]);
$tab = ispconfigconnect_AdminServicesTabFields(harness_params($S['M']));
up_p('    M back on P3: Client ID', $tab['ISPConfig Client ID'] ?? '?');

// ---------------------------------------------------------------------------
up_h('Usage sweep under the new build (reading the right link) + plan 2.11 remedy');
$sweep = static function () use ($state): void {
    $m = harness_log_mark();
    ispconfigconnect_UsageUpdate(harness_server_params($state['server']));
    foreach (harness_log_since($m) as $row) {
        if ($row->action === 'UsageUpdate') {
            echo "    UsageUpdate log: ", $row->response, "\n";
        }
    }
};
$sweep();
$bw = static fn (string $k): string => (string) Capsule::table('tblhosting')->where('id', $state['svc'][$k])->value('bwusage');
foreach (['A', 'M', 'N', 'F', 'Q', 'H', 'J'] as $k) {
    printf("    %-2s svc #%-3d bwusage=%s lastupdate=%s\n", $k, $state['svc'][$k], $bw($k), Capsule::table('tblhosting')->where('id', $state['svc'][$k])->value('lastupdate'));
}
$ok($bw('M') === '300', 'M billed from client #12 (orphan-x.test, 300 MB), not its stale P1 client #13');
$ok($bw('N') === '100', 'N billed from client #13 (cp-relink.test, 100 MB)');
$ok($bw('Q') === '200', 'Q billed from client #11 (cp-unlink.test, 200 MB)');
$ok($bw('A') === '99999', "plan 2.11: A keeps the carried-forward 99999 (lastupdate is this month)");
Capsule::table('tblhosting')->where('id', $S['A'])->update(['bwusage' => 0]);
$sweep();
$ok($bw('A') === '500', 'plan 2.11 remedy: after bwusage=0 the sweep writes 500');

// ---------------------------------------------------------------------------
up_h('Rollback preparation: R (P2d website-only) suspended by the NEW build');
$r = up_call('SuspendAccount', $S['R']);
up_p('    R ispconfig_suspended_as rows', $susp($S['R']));
$ok($r['result'] === 'success' && up_web_active($Wb['R']) === 'n', 'R website off, record website');
$r = up_call('SuspendAccount', $S['A2'], [], 'A2 (P1 entire) suspended by the NEW build, left Suspended for the rollback');
up_p('    A2 ispconfig_suspended_as rows', $susp($S['A2']));

up_state_save($state);

up_h('Final section 2 queries after the post-upgrade actions');
$W = harness_db_name();
up_show_sql('2.7 again', $W, "SELECT id,relid,fieldname FROM tblcustomfields WHERE type='product' AND fieldname LIKE 'ispconfig%' AND fieldname NOT IN ('ispconfig_client_id','ispconfig_client_username','ispconfig_domain_id','ispconfig_ftp_user_id','ispconfig_mail_domain_id','ispconfig_dns_zone_id','ispconfig_database_id','ispconfig_database_user_id');");
up_show_sql('2.9 again', $W, <<<'SQL'
SELECT h.id,h.domain,h.domainstatus FROM tblhosting h
JOIN tblproducts p ON p.id=h.packageid AND p.servertype='ispconfigconnect' AND p.configoption13=''
WHERE h.domainstatus IN ('Active','Suspended') AND NOT EXISTS (
  SELECT 1 FROM tblcustomfieldsvalues v JOIN tblcustomfields f ON f.id=v.fieldid
  AND f.type='product' AND f.fieldname='ispconfig_domain_id' WHERE v.relid=h.id AND v.value<>'');
SQL);
up_show_sql('2.6 again', $W, <<<'SQL'
SELECT v.relid service_id,h.packageid current_product,h.domainstatus,f.relid row_product,f.fieldname,v.value
FROM tblcustomfieldsvalues v
JOIN tblcustomfields f ON f.id=v.fieldid AND f.type='product' AND f.fieldname LIKE 'ispconfig\_%'
JOIN tblhosting h ON h.id=v.relid
WHERE v.value<>'' AND f.relid<>h.packageid ORDER BY v.relid,f.fieldname;
SQL);

echo "\n==== CHECK SUMMARY ====\n";
foreach ($checks as [$s, $l]) {
    echo "$s $l\n";
}
$err = @file_get_contents('/opt/ispc/p7/php-error.log');
echo 'panel php-error.log: ', $err === false || $err === '' ? 'empty' : $err, "\n";
