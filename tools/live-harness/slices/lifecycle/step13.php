<?php
// Step 13: Website-only Suspend on a linked service (S3 on P2 for C2, test-three.example).
declare(strict_types=1);
require __DIR__ . '/lib.php';
$st = lt_state();

function button(string $fn, int $sid): array
{
    // Admin custom button: WHMCS calls ispconfigconnect_<Function>($params) - no status change.
    $map = ispconfigconnect_AdminCustomButtonArray();
    $label = array_search($fn, $map, true);
    out("  [button \"$label\" -> ispconfigconnect_$fn]");
    return lt_call($fn, $sid);
}

hr('Step 13: Create S3 (P2, C2, test-three.example)');
$S3 = harness_add_service($st['P2'], $st['server'], 'test-three.example', ['userid' => $st['C2'], 'username' => '']);
$st['S3'] = $S3;
$r = lt_call('CreateAccount', $S3);
assert_line('13', $r['result'] === 'success', 'Create S3 returns success: ' . json_encode($r['result']));
$eff0 = props_effective($S3);
$N3 = (int) $eff0['client_id'];
$st['N3'] = $N3;
lt_save_state($st);
$user3 = (string) $eff0['client_username'];
$site3 = (int) $eff0['domain_id'];
out("  S3 -> ISPConfig client #$N3 ($user3), website #$site3");

hr('Step 13: Unlink from ISPConfig');
$dl = dl_mark();
$r = button('UnlinkAccount', $S3);
assert_line('13', $r['result'] === 'success', 'Unlink returns success: ' . json_encode($r['result']));
print_props($S3);
$e = props_effective($S3);
assert_line('13', $e['client_id'] === null && $e['client_username'] === null && $e['domain_id'] === null, 'Unlink blanks the stored ids');
assert_line('13', api_calls($r) === ['UnlinkAccount'] && dl_since($dl) === [], 'Unlink touches nothing on the panel (no API call, no datalog): ' . json_encode($r['actions']));

hr('Step 13 (a): set Username to the ISPConfig username, Link to ISPConfig Account');
\WHMCS\Database\Capsule::table('tblhosting')->where('id', $S3)->update(['username' => $user3]);
out('  tblhosting: ' . json_encode(svc($S3)));
$r = button('LinkAccount', $S3);
assert_line('13a', $r['result'] === 'success', 'Link succeeds: ' . json_encode($r['result']));
$calls = api_calls($r);
$want = ['client_get_by_username', 'client_get_sites_by_user', 'sites_web_domain_get'];
$seen = array_values(array_intersect($calls, $want));
assert_line('13a', $seen === $want, 'Module Log shows client_get_by_username, client_get_sites_by_user, sites_web_domain_get: ' . json_encode($calls));
$linkRow = array_values(array_filter($r['log'], fn ($x) => $x->action === 'LinkAccount'))[0] ?? null;
$linkResp = $linkRow ? json_decode((string) $linkRow->response, true) : null;
out('  LinkAccount log entry: ' . ($linkRow ? $linkRow->response : '(none)'));
assert_line('13a', is_array($linkResp) && ($linkResp['ispconfig_domain_id'] ?? null) === $site3, 'the link entry records the stored website id #' . $site3 . ': ' . json_encode($linkResp['ispconfig_domain_id'] ?? null));
print_props($S3);
$tab = ispconfigconnect_AdminServicesTabFields(harness_params($S3));
out('  admin tab: ' . json_encode(array_intersect_key($tab, array_flip(['ISPConfig Link', 'ISPConfig Client ID', 'ISPConfig Username', 'ISPConfig Domain ID']))));
assert_line('13a', ($tab['ISPConfig Domain ID'] ?? null) === (string) $site3 && ($tab['ISPConfig Client ID'] ?? null) === (string) $N3, 'admin tab shows the ISPConfig website id #' . $site3 . ' (and client #' . $N3 . ')');

hr('Step 13 (b): blank the website id, Suspend');
$n = \WHMCS\Database\Capsule::update("UPDATE tblcustomfieldsvalues v JOIN tblcustomfields f ON f.id=v.fieldid SET v.value='' WHERE f.type='product' AND f.fieldname='ispconfig_domain_id' AND v.relid=?", [$S3]);
out("  plan SQL blanked $n row(s)");
$before = panel_snap($N3);
$dl = dl_mark();
$r = lt_call('SuspendAccount', $S3);
$exp = 'Suspend refused: this product suspends the website only, but no ISPConfig website id is stored for this service, so there is nothing safe to suspend. Press "Replace ISPConfig Link" ';
assert_line('13b', is_string($r['result']) && str_starts_with($r['result'], $exp), 'refusal text starts with the plan\'s text: ' . json_encode($r['result']));
assert_line('13b', $r['log'] === [] && dl_since($dl) === [], 'no panel call is made (no Module Log row, no datalog)');
$after = print_snap($N3, '(after refused Suspend)');
assert_line('13b', $after['client']['locked'] === 'n' && actives($after, 'web_domain') === ['y'] && $after == $before, 'client NOT locked, nothing changed on the panel');
assert_line('13b', svc($S3)['domainstatus'] === 'Active', 'WHMCS keeps the service Active (non-success result)');
$sa = susp_as($S3);
assert_line('13b', $sa['effective'] === null, 'no suspension record written: ' . json_encode($sa));

hr('Step 13 (c): Replace ISPConfig Link (same Username), then Suspend and Unsuspend');
$r = button('RelinkAccount', $S3);
assert_line('13c', $r['result'] === 'success', 'Replace returns success: ' . json_encode($r['result']));
$e = props_effective($S3);
assert_line('13c', $e['domain_id'] === $site3 && $e['client_id'] === $N3, 'Replace stores the website id #' . $site3 . ' again: ' . json_encode($e));
$r = lt_call('SuspendAccount', $S3);
$ss = req_of($r['log'], 'sites_web_domain_set_status');
$s = print_snap($N3, '(after Suspend)');
assert_line('13c', $r['result'] === 'success' && api_calls($r) === ['sites_web_domain_set_status'] && ($ss[0]['status'] ?? null) === 'inactive'
    && actives($s, 'web_domain') === ['n'] && $s['client']['locked'] === 'n' && actives($s, 'mail_domain') === ['y'] && actives($s, 'ftp_user') === ['y']
    && susp_as($S3)['effective'] === 'website', 'Suspend behaves as in step 12 (only set_status inactive, website off, client unlocked, mail/FTP on, suspended_as=website)');
$r = lt_call('UnsuspendAccount', $S3);
$ss = req_of($r['log'], 'sites_web_domain_set_status');
$s = print_snap($N3, '(after Unsuspend)');
$sa = susp_as($S3);
assert_line('13c', $r['result'] === 'success' && api_calls($r) === ['sites_web_domain_set_status'] && ($ss[0]['status'] ?? null) === 'active'
    && actives($s, 'web_domain') === ['y'] && $sa['effective'] === null && $sa['current_row'] === '', 'Unsuspend behaves as in step 12 (set_status active, website on, suspended_as blank): ' . json_encode($sa));

hr('Step 13 (optional): domain with no website, blank website id, Replace');
\WHMCS\Database\Capsule::table('tblhosting')->where('id', $S3)->update(['domain' => 'no-site-here.example']);
\WHMCS\Database\Capsule::update("UPDATE tblcustomfieldsvalues v JOIN tblcustomfields f ON f.id=v.fieldid SET v.value='' WHERE f.type='product' AND f.fieldname='ispconfig_domain_id' AND v.relid=?", [$S3]);
$r = button('RelinkAccount', $S3);
$exp = sprintf('Replace ISPConfig Link kept the existing link to ISPConfig client #%d, but could not store the website id this product needs to suspend: ', $N3);
assert_line('13-opt', is_string($r['result']) && str_starts_with($r['result'], $exp), 'Replace answers "' . $exp . '..." instead of success: ' . json_encode($r['result']));
$e = props_effective($S3);
assert_line('13-opt', $e['client_id'] === $N3 && $e['domain_id'] === null, 'the link to client #' . $N3 . ' is kept, no website id stored');
// Restore the domain and the website id.
\WHMCS\Database\Capsule::table('tblhosting')->where('id', $S3)->update(['domain' => 'test-three.example']);
$r = button('RelinkAccount', $S3);
assert_line('13-opt', $r['result'] === 'success' && props_effective($S3)['domain_id'] === $site3, 'restored: domain back, Replace stores website #' . $site3 . ' again');
