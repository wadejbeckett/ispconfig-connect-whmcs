<?php

/** Extra probes inside the sharing slice, then the plan's closing Terminate of S2 (23c). */

declare(strict_types=1);

require __DIR__ . '/lib.php';

use WHMCS\Database\Capsule;

$st = load_state();
[$S2, $P1, $P2, $srv1] = [$st['s2'], $st['p1'], $st['p2'], $st['srv1']];
$D1 = $st['dup']['D1'];
$D2 = $st['dup']['D2'];
$M = (int) $st['dup']['M'];
$fidId = field_id($P1, 'ispconfig_client_id');

function guard_ids(int $as, int $clientId): array
{
    return array_column(ispconfigconnect_otherServicesLinkedToClient($as, $clientId) ?? [], 'serviceid');
}

hdr('X1. Non-canonical numeric strings in ispconfig_client_id: reader vs guard (D1 holds client #' . $M . ')');
Capsule::table('tblcustomfieldsvalues')->where('relid', $D2)->delete();
$vid = insert_value($fidId, $D2, '');
foreach ([(string) $M, " $M", "0$M", "$M.0", "+$M", "{$M}e0", "$M "] as $v) {
    Capsule::table('tblcustomfieldsvalues')->where('id', $vid)->update(['value' => $v]);
    $reader = module_reads($D2)['client_id'];
    $seen = in_array($D2, guard_ids($D1, $M), true);
    out(sprintf('  stored %-8s -> reader client_id=%s | guard (from D1, client #%d) sees D2: %s', var_export($v, true), var_export($reader, true), $M, $seen ? 'YES' : 'no'));
    $GLOBALS['x1'][$v] = [$reader, $seen];
    check('X1-' . trim($v), $reader !== $M || $seen, "value '$v': a service the reader links to #$M is seen by the guard");
}
Capsule::table('tblcustomfieldsvalues')->where('relid', $D2)->delete();

hdr('X2. Which sharer statuses count (forced sharer D2 = #' . $M . ' vs D1)');
insert_value($fidId, $D2, (string) $M);
foreach (['Pending', 'Active', 'Suspended', 'Completed', 'Terminated', 'Cancelled', 'Fraud'] as $status) {
    set_status($D2, $status);
    $seen = in_array($D2, guard_ids($D1, $M), true);
    out("  D2 $status -> counted as sharer: " . ($seen ? 'yes' : 'no'));
    $expect = !in_array($status, ['Terminated', 'Cancelled', 'Fraud'], true);
    check("X2-$status", $seen === $expect, "status $status " . ($expect ? 'counts' : 'does not count'));
}
set_status($D2, 'Active');

hdr('X3. Website-only Suspend/Unsuspend on a SHARED client is allowed (touches only this service\'s website); entire-client ops refused');
$N2 = (int) $st['s2_client'];
$fid2 = field_id($P2, 'ispconfig_client_id');
$sharer = harness_add_service($P2, $srv1, 'x3-sharer.example', ['userid' => 401, 'domainstatus' => 'Active']);
insert_value($fid2, $sharer, (string) $N2);
$mark = harness_log_mark();
$r = harness_call('SuspendAccount', $S2);
out('  Suspend S2 (P2 website-only, client shared with #' . $sharer . '): ' . $r . ' | ' . implode(',', log_actions(log_rows($mark))));
$site = prow('SELECT domain_id, active FROM web_domain WHERE domain_id=' . (int) module_reads($S2)['domain_id'])[0];
$lock = prow("SELECT locked FROM client WHERE client_id=$N2")[0]['locked'];
check('X3-suspend', $r === 'success' && $site['active'] === 'n' && $lock === 'n', 'website-only Suspend proceeds on a shared client: site off, client not locked', json_encode($site));
$r = harness_call('UnsuspendAccount', $S2);
check('X3-unsuspend', $r === 'success' && prow('SELECT active FROM web_domain WHERE domain_id=' . (int) $site['domain_id'])[0]['active'] === 'y', 'website-only Unsuspend proceeds', $r);
$r = harness_call('ChangePassword', $S2);
check('X3-cp', str_starts_with($r, "Change Password refused: this service's ISPConfig client #$N2 is also linked to service #$sharer"), 'Change Password still refused', $r);
set_status($sharer, 'Cancelled');

hdr('X4. Addon provisioned on an unlinked parent, parent linked LATER: the addon can no longer be terminated');
$P9 = (int) Capsule::table('tblproducts')->where('name', 'Plain Hosting')->value('id');
$U2 = harness_add_service($P9, $srv1, 'late-parent.example', ['userid' => 402, 'domainstatus' => 'Active']);
$cfg = [];
for ($i = 1; $i <= 24; $i++) {
    $cfg['configoption' . $i] = (string) Capsule::table('tblproducts')->where('id', $P1)->value('configoption' . $i);
}
$ap = static fn (string $status) => addon_params($U2, 601, 7, $status, $cfg, ['password' => 'Addon-Late-1-Xy9']);
$r = ispconfigconnect_CreateAccount($ap('Pending'));
$addonClient = (int) Capsule::table('tblcustomfieldsvalues as v')->join('tblcustomfields as f', 'f.id', '=', 'v.fieldid')->where('f.type', 'addon')->where('f.fieldname', 'ispconfig_client_id')->where('v.relid', 601)->value('v.value');
out("  addon #601 Create: $r -> client #$addonClient");
Capsule::table('tblhosting')->where('id', $U2)->update(['username' => 'dupfive11']);
$r = harness_call('LinkAccount', $U2);
out('  parent U2 Link to dupfive11: ' . $r);
$r = ispconfigconnect_TerminateAccount($ap('Active'));
out('  addon #601 Terminate: ' . $r);
$exists = (int) pval("SELECT COUNT(*) FROM client WHERE client_id=$addonClient");
check('X4', !($exists === 1 && !str_contains($r, 'success')), 'the addon\'s own client can still be removed through the module', "client #$addonClient still exists=$exists; Terminate said: " . substr($r, 0, 160));
harness_call('UnlinkAccount', $U2);
out('  cleanup: parent U2 unlinked; addon #601 Terminate now: ' . ispconfigconnect_TerminateAccount($ap('Active')));

hdr('23c (closing). Restore done earlier; Terminate S2 normally');
$c = (int) $st['s2_client'];
$before = (int) pval("SELECT COUNT(*) FROM web_domain WHERE sys_groupid=(SELECT groupid FROM sys_group WHERE client_id=$c)");
$mark = harness_log_mark();
$r = harness_call('TerminateAccount', $S2);
out('  Terminate S2: ' . $r . ' | ' . implode(',', log_actions(log_rows($mark))));
check('23c-final', $r === 'success' && (int) pval("SELECT COUNT(*) FROM client WHERE client_id=$c") === 0 && nonblank_props($S2) === [] && svc($S2)->domainstatus === 'Terminated', 'after restoring the id, Terminate S2 succeeds and blanks its rows', "websites before: $before");

summary();
