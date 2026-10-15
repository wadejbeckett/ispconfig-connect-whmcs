<?php

/**
 * Plan step 23b: ISPConfig Connect on a PRODUCT ADDON.
 *  - parent linked (S2 -> client #2): every operation refused before any panel call;
 *  - parent unlinked: the addon works with its own (addon-scoped) ids.
 * WHMCS passes an addon: serviceid = PARENT service id, addonId = hosting-addon id,
 * model = the addon (addon-scoped custom fields), status = the addon's status.
 */

declare(strict_types=1);

require __DIR__ . '/lib.php';

use WHMCS\Database\Capsule;

$st = load_state();
[$S2, $P1, $srv1] = [$st['s2'], $st['p1'], $st['srv1']];
$N2 = (int) $st['s2_client'];
$ADDON_DEF = 7;
$addonConfig = [];
for ($i = 1; $i <= 24; $i++) {
    $addonConfig['configoption' . $i] = (string) Capsule::table('tblproducts')->where('id', $P1)->value('configoption' . $i);
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

function addon_props(int $hostingAddonId): array
{
    return Capsule::table('tblcustomfieldsvalues as v')->join('tblcustomfields as f', 'f.id', '=', 'v.fieldid')
        ->where('f.type', 'addon')->where('v.relid', $hostingAddonId)->orderBy('f.fieldname')
        ->pluck('v.value', 'f.fieldname')->all();
}

hdr('23b. Addon #501 on S2 (parent linked to client #' . $N2 . ')');
$s2Rows = prop_rows($S2);
$c2 = client_snapshot($N2);
$fns = [
    'CreateAccount' => 'Create Account', 'SuspendAccount' => 'Suspend', 'UnsuspendAccount' => 'Unsuspend',
    'TerminateAccount' => 'Terminate', 'ChangePassword' => 'Change Password', 'ChangePackage' => 'Change Package',
    'LinkAccount' => 'Link', 'RelinkAccount' => 'Replace ISPConfig Link', 'UnlinkAccount' => 'Unlink',
];
foreach ($fns as $fn => $label) {
    $params = addon_params($S2, 501, $ADDON_DEF, $fn === 'UnsuspendAccount' ? 'Suspended' : 'Active', $addonConfig, ['username' => 'testtwo2', 'password' => 'Addon-Pass-1-Xy9']);
    [$r, $rows, $d] = run("$fn on addon #501", fn () => ('ispconfigconnect_' . $fn)($params));
    check("23b-$fn-text", str_contains($r, "ISPConfig Connect cannot run on this product addon: its parent service #$S2 is itself linked to ISPConfig client #$N2") && str_ends_with($r, 'Nothing has been changed.'), "$fn refused with the addon text", $r);
    check("23b-$fn-nopanel", no_panel_traffic($d) && $rows === [], "$fn: no panel call and no Module Log row", fmt_delta($d));
}
foreach (['AdminServicesTabFields', 'ClientArea'] as $fn) {
    $params = addon_params($S2, 501, $ADDON_DEF, 'Active', $addonConfig);
    [$r, $rows, $d] = run("$fn on addon #501", fn () => ('ispconfigconnect_' . $fn)($params));
    check("23b-$fn", no_panel_traffic($d), "$fn renders without any panel call", json_encode($r));
}
// The harness's own addon path (HarnessServiceModel with addonId), for comparison.
[$r, , $d] = run('CreateAccount via harness_call(addonId=501)', fn () => harness_call('CreateAccount', $S2, ['addonId' => 501]));
check('23b-harness-path', str_contains($r, 'cannot run on this product addon') && no_panel_traffic($d), 'harness addon path refused the same way');
check('23b-unchanged', prop_rows($S2) === $s2Rows && client_snapshot($N2) === $c2 && svc($S2)->domainstatus === 'Active', 'S2 and its ISPConfig client are unchanged');
check('23b-addon-rows', addon_props(501) === [], 'nothing stored for the addon');

hdr('23b-U. Addon #502 on an UNLINKED parent (U1 on a non-ISPConfig product) works with its own ids');
$P9 = (int) Capsule::table('tblproducts')->insertGetId(['type' => 'hostingaccount', 'gid' => 1, 'name' => 'Plain Hosting', 'servertype' => '', 'servergroup' => 0]
    + array_fill_keys(array_map(static fn ($i) => 'configoption' . $i, range(1, 24)), ''));
$U1 = harness_add_service($P9, $srv1, 'addon-parent.example', ['userid' => 301, 'domainstatus' => 'Active']);
$U1rowsBefore = prop_rows($U1);
$pa = static fn (string $status, array $extra = []) => addon_params($U1, 502, $ADDON_DEF, $status, $addonConfig, $extra + ['password' => 'Addon-Pass-2-Xy9']);
[$r, $rows] = run('CreateAccount addon #502', fn () => ispconfigconnect_CreateAccount($pa('Pending')));
check('23b-U-create', $r === 'success', 'Create on an addon of an unlinked parent succeeds', $r);
$ap = addon_props(502);
out('  addon #502 own properties: ' . json_encode($ap));
$Y = (int) ($ap['ispconfig_client_id'] ?? 0);
$Yuser = (string) ($ap['ispconfig_client_username'] ?? '');
check('23b-U-ownids', $Y > 0 && prop_rows($U1) === $U1rowsBefore, "ids stored on the addon's own fields (client #$Y $Yuser); parent U1 has no ispconfig rows");
out('  panel client: ' . json_encode(prow("SELECT client_id, username, notes FROM client WHERE client_id=$Y")));
[$r] = run('SuspendAccount addon #502', fn () => ispconfigconnect_SuspendAccount($pa('Active')));
check('23b-U-suspend', $r === 'success' && prow("SELECT locked FROM client WHERE client_id=$Y")[0]['locked'] === 'y', 'Suspend locks the addon\'s own client', $r);
[$r] = run('UnsuspendAccount addon #502', fn () => ispconfigconnect_UnsuspendAccount($pa('Suspended')));
check('23b-U-unsuspend', $r === 'success' && prow("SELECT locked FROM client WHERE client_id=$Y")[0]['locked'] === 'n', 'Unsuspend unlocks it', $r);
$h = pval("SELECT password FROM client WHERE client_id=$Y");
[$r] = run('ChangePassword addon #502', fn () => ispconfigconnect_ChangePassword($pa('Active', ['password' => 'Addon-Pass-3-Xy9'])));
check('23b-U-cp', $r === 'success' && pval("SELECT password FROM client WHERE client_id=$Y") !== $h, 'Change Password rotates the addon\'s client', $r);
check('23b-U-parent', prop_rows($U1) === $U1rowsBefore, 'parent U1 still has no ispconfig rows');

hdr('23b-U2. A second ISPConfig addon (#503) on the SAME unlinked parent');
[$r] = run('CreateAccount addon #503 (same parent, no username passed)', fn () => ispconfigconnect_CreateAccount(addon_params($U1, 503, $ADDON_DEF, 'Pending', $addonConfig, ['password' => 'Addon-Pass-4-Xy9'])));
out('  addon #503 properties: ' . json_encode(addon_props(503)));
$GLOBALS['second_addon'] = $r;

hdr('23b-G. Is an ADDON-held client visible to the shared-client guard of product services?');
$W = harness_add_service($P1, $srv1, 'guard-probe.example', ['userid' => 302, 'domainstatus' => 'Active', 'username' => $Yuser]);
[$r, $rows] = run("LinkAccount W (#$W) to the addon's account $Yuser", fn () => harness_call('LinkAccount', $W));
check('23b-G-link', str_starts_with($r, 'Link refused'), "a product service's Link to the client addon #502 holds is refused", $r);
if ($r === 'success') {
    [$r2] = run("SuspendAccount W (#$W) - whole-client lock of the addon's client", fn () => harness_call('SuspendAccount', $W));
    out('  client #' . $Y . ' locked now: ' . prow("SELECT locked FROM client WHERE client_id=$Y")[0]['locked'] . ' (the addon #502 hosting went offline via service W)');
    check('23b-G-effect', $r2 !== 'success', 'W cannot lock the addon\'s client', $r2);
    run('UnsuspendAccount W', fn () => harness_call('UnsuspendAccount', $W));
    run('UnlinkAccount W', fn () => harness_call('UnlinkAccount', $W));
}
// Reverse direction: an addon linking to a client a PRODUCT service holds is refused.
[$r] = run('LinkAccount addon #504 (unlinked parent) to testtwo2 (held by S2)', fn () => ispconfigconnect_LinkAccount(addon_params($U1, 504, $ADDON_DEF, 'Active', $addonConfig, ['username' => 'testtwo2'])));
check('23b-G-reverse', str_starts_with($r, "Link refused: this service's ISPConfig client #$N2 is also linked to service #$S2 ("), 'an addon Link to a client a product service holds is refused', $r);

hdr('23b-T. Terminate addon #502: deletes its own client only');
$others = ['c2' => client_snapshot($N2)];
[$r, $rows] = run('TerminateAccount addon #502', fn () => ispconfigconnect_TerminateAccount($pa('Active')));
check('23b-T', $r === 'success' && (int) pval("SELECT COUNT(*) FROM client WHERE client_id=$Y") === 0 && client_snapshot($N2) === $others['c2'], 'Terminate deletes only the addon\'s client', $r);
out('  addon #502 properties after: ' . json_encode(addon_props(502)));
check('23b-T-clear', array_filter(addon_props(502), static fn ($v) => $v !== '') === [] && prop_rows($U1) === $U1rowsBefore, 'addon rows blanked; parent untouched');

$st['U1'] = $U1;
$st['W'] = $W;
save_state($st);
summary();
