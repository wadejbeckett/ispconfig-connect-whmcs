<?php

/**
 * Step 19: single sign-on, active versus suspended - on a panel WITHOUT the ispconfig-sso add-on.
 *
 * UI -> module mapping:
 *   "Open the Servers page once"         GetRemoteMetaData(server params), stored in tblservers_remote
 *   client area product page             ispconfigconnect_ClientArea(params) + templates/overview.tpl (Smarty)
 *   Log in to ISPConfig / dosinglesignon ispconfigconnect_ServiceSingleSignOn(params)            (customer)
 *   admin service page login button      ispconfigconnect_ServiceSingleSignOn(params) with ADMINAREA defined
 *   Servers page "Login to Control Panel" ispconfigconnect_AdminSingleSignOn(server params)
 *   admin service tab                    ispconfigconnect_AdminServicesTabFields(params)
 */

declare(strict_types=1);

require __DIR__ . '/lib.php';
require_once LT_DIR . '/smarty/vendor/autoload.php';

use WHMCS\Module\Server\Ispconfigconnect\Sso\SsoAdvice;

$st = lt_state();
$server = $st['server'];
$s1 = $st['s1'];
$s2 = $st['s2'];

$customerSentence = SsoAdvice::customer();
$absentAdmin = SsoAdvice::admin(SsoAdvice::STATE_ABSENT);

/** Render the shipped client-area template with ClientArea()'s variables, as WHMCS would. */
function render_overview(array $ca, int $sid): string
{
    $smarty = new Smarty();
    $smarty->setCompileDir(LT_DIR . '/smarty/compile');
    $smarty->setTemplateDir('/home/user/ispconfig-connect-whmcs/modules/servers/ispconfigconnect');
    $svc = svc($sid);
    // Page-scope variables WHMCS's productdetails page provides around the module template.
    $smarty->assign([
        'product' => 'ISPC product', 'groupname' => 'G1', 'domain' => $svc['domain'], 'status' => $svc['domainstatus'],
        'nextduedate' => '2026-10-28', 'billingcycle' => 'Monthly', 'WEB_ROOT' => 'https://billing.test.example',
        'LANG' => ['orderproduct' => 'Product', 'orderdomain' => 'Domain', 'visitwebsite' => 'Visit',
            'clientareastatus' => 'Status', 'clientareahostingnextduedate' => 'Next Due', 'orderbillingcycle' => 'Cycle'],
    ]);
    $smarty->assign($ca['templateVariables']);

    return $smarty->fetch($ca['tabOverviewReplacementTemplate']);
}

/** MetaData() in a FRESH process (it memoises the SSO answer per request, as it would per WHMCS request). */
function metadata_fresh(): array
{
    $code = "require '" . LT_DIR . "/lib.php'; echo json_encode(ispconfigconnect_MetaData());";
    $json = shell_exec(lt_php() . ' -r ' . escapeshellarg($code));

    return json_decode((string) $json, true) ?? ['raw' => $json];
}

/** ServiceSingleSignOn() in a fresh process with ADMINAREA defined - the real admin default, no test seam. */
function admin_sso_fresh(int $sid): array
{
    $code = "define('ADMINAREA', true); require '" . LT_DIR . "/lib.php'; echo json_encode(ispconfigconnect_ServiceSingleSignOn(harness_params($sid)));";
    $json = shell_exec(lt_php() . ' -r ' . escapeshellarg($code));

    return json_decode((string) $json, true) ?? ['raw' => $json];
}

/** The SSO outcome row (ServiceSingleSignOn/AdminSingleSignOn) among the rows a call wrote. */
function sso_row(array $log): array
{
    foreach ($log as $row) {
        if (in_array($row['action'], ['ServiceSingleSignOn', 'AdminSingleSignOn'], true)) {
            return $row + ['decoded' => json_decode((string) $row['response'], true)];
        }
    }

    return ['action' => '', 'decoded' => []];
}

function sso_call(string $label, int $sid, ?bool $adminArea, array $extra = []): array
{
    $mark = harness_log_mark();
    $req = req_mark();
    $r = ispconfigconnect_ServiceSingleSignOn(harness_params($sid, $extra), null, $adminArea);
    $reqs = req_since($req);
    $log = mlog_raw($mark);
    dump("$label result", $r);
    out('  panel requests: ' . json_encode($reqs));
    foreach ($log as $row) {
        out("  modulelog #{$row['id']} {$row['action']} req={$row['request']}");
        out("     resp={$row['response']}");
    }

    return ['result' => $r, 'reqs' => $reqs, 'log' => $log];
}

hr('19.0 before the Servers page was ever opened (cold tblservers_remote)');
\WHMCS\Database\Capsule::table('tblservers_remote')->delete();
$md = metadata_fresh();
dump('MetaData()', $md);
check('19.0-md', !isset($md['ServiceSingleSignOnLabel']) && !isset($md['AdminSingleSignOnLabel']), 'MetaData: no ServiceSingleSignOnLabel/AdminSingleSignOnLabel with a cold cache', $md);
$ca = ispconfigconnect_ClientArea(harness_params($s1));
check('19.0-ca', $ca['templateVariables']['ssoAvailable'] === false, 'ClientArea(S1 Active, cold cache): ssoAvailable=false (unknown never renders a button)', $ca['templateVariables']);
$cold = sso_call('ServiceSingleSignOn(S1 Active, customer, cold cache)', $s1, false);
check('19.0-sso-msg', $cold['result'] === ['success' => false, 'errorMsg' => $customerSentence], 'cold cache: customer gets only the customer sentence', $cold['result']);
check('19.0-sso-asked', logins_in($cold['reqs']) === 1, 'cold cache: the panel IS asked once (an unprobed server is never refused from the cache)', $cold['reqs']);
$row = sso_row($cold['log']);
$d = $row['decoded'];
check('19.0-sso-log', $row['action'] === 'ServiceSingleSignOn' && ($d['sso_state'] ?? '') === 'absent' && ($d['detail'] ?? '') === $absentAdmin, 'cold cache: Module Log records sso_state=absent with the operator "Not installed" detail', $cold['log']);

hr('19.1 "Open the Servers page once"');
$mark = harness_log_mark();
$req = req_mark();
$metas = open_servers_page([$server]);
$reqs = req_since($req);
dump('GetRemoteMetaData', $metas[$server]);
dump('tblservers_remote', \WHMCS\Database\Capsule::table('tblservers_remote')->get()->all());
check('19.1-meta', ($metas[$server]['sso_state'] ?? null) === 'absent' && ($metas[$server]['sso_available'] ?? null) === false, 'GetRemoteMetaData caches sso_state=absent, sso_available=false', $metas[$server]);
check('19.1-login', logins_in($reqs) === 1, 'one login for the metadata render', $reqs);

$md = metadata_fresh();
dump('MetaData() after the Servers page', $md);
check('19.1-md', !isset($md['ServiceSingleSignOnLabel']), 'MetaData: no ServiceSingleSignOnLabel (no server has the add-on)', $md);
check('19.1-md-admin', !isset($md['AdminSingleSignOnLabel']), 'MetaData: AdminSingleSignOnLabel never advertised', $md);

hr('19.2 admin service tab (S1) - single sign-on row');
$mark = harness_log_mark();
$tab = ispconfigconnect_AdminServicesTabFields(harness_params($s1));
dump('AdminServicesTabFields(S1)', $tab);
check('19.2-tab', ($tab['ISPConfig Single Sign-On'] ?? '') === $absentAdmin, 'admin tab single sign-on row says "Not installed - this panel does not have the ispconfig-sso add-on ..."', $tab['ISPConfig Single Sign-On'] ?? null);

hr('19.3 client area S1 (Active) - button absent on this panel');
$ca = ispconfigconnect_ClientArea(harness_params($s1));
dump('ClientArea(S1) templateVariables', $ca['templateVariables']);
$html = render_overview($ca, $s1);
file_put_contents(LT_DIR . '/step19-clientarea-S1-active.html', $html);
check('19.3-ca', $ca['templateVariables']['ssoAvailable'] === false, 'ClientArea(S1 Active): ssoAvailable=false because the panel reported the add-on absent');
check('19.3-html', !str_contains($html, 'dosinglesignon') && !str_contains($html, 'Log in to ISPConfig') && str_contains($html, 'Open Control Panel'), 'rendered overview.tpl: no "Log in to ISPConfig" button, "Open Control Panel" still shown');

hr('19(a) S1 Active: Log in to ISPConfig (customer) - add-on absent');
$a = sso_call('ServiceSingleSignOn(S1 Active, customer)', $s1, false);
check('19a-msg', $a['result'] === ['success' => false, 'errorMsg' => $customerSentence], '(a) customer sees only the customer sentence (the add-on is absent here, so no landing in ISPConfig)', $a['result']);
check('19a-nocall', $a['reqs'] === [], '(a) cached "absent" answers without opening a panel session (0 requests)', $a['reqs']);
$row = sso_row($a['log']);
$d = $row['decoded'];
check('19a-log', $row['action'] === 'ServiceSingleSignOn' && ($d['sso_state'] ?? '') === 'absent' && ($d['detail'] ?? '') === $absentAdmin && !preg_match('~https?://|sso_token=|[?&]token=~', (string) $row['response']), '(a) Module Log: ServiceSingleSignOn sso_state=absent, operator detail, no URL/token', $a['log']);

hr('19(b) S2 website-only Suspend, then client area and direct dosinglesignon=1');
$mark = harness_log_mark();
$r = harness_call('SuspendAccount', $s2);
dump('SuspendAccount(S2)', $r);
out('  actions: ' . implode(', ', mlog_actions($mark)));
dump('S2 panel', ['client' => panel_client($st['c2']), 'web_domain' => harness_panel_rows('SELECT domain_id, active FROM web_domain WHERE domain_id = 2')]);
check('19b-susp', $r === 'success' && svc($s2)['domainstatus'] === 'Suspended' && panel_client($st['c2'])['locked'] === 'n', '(b) S2 Suspended, website-only: client NOT locked', [svc($s2)['domainstatus'], panel_client($st['c2'])]);
$ca = ispconfigconnect_ClientArea(harness_params($s2));
$html = render_overview($ca, $s2);
file_put_contents(LT_DIR . '/step19-clientarea-S2-suspended.html', $html);
check('19b-button', $ca['templateVariables']['ssoAvailable'] === false && !str_contains($html, 'dosinglesignon'), '(b) no button for suspended S2');
$b = sso_call('ServiceSingleSignOn(S2 Suspended, customer, dosinglesignon=1)', $s2, false);
check('19b-msg', $b['result'] === ['success' => false, 'errorMsg' => $customerSentence], '(b) direct URL shows only "Single sign-on is not available for this service right now..."', $b['result']);
$d = sso_row($b['log'])['decoded'];
check('19b-log', ($d['detail'] ?? '') === 'Single sign-on is only available while the service is active; this service is Suspended.', '(b) Module Log detail: "Single sign-on is only available while the service is active; this service is Suspended."', $b['log']);
check('19b-nocall', $b['reqs'] === [], '(b) refused before any panel request', $b['reqs']);

hr('19(c) S1 entire-client Suspend, then the same');
$mark = harness_log_mark();
$r = harness_call('SuspendAccount', $s1);
dump('SuspendAccount(S1)', $r);
out('  actions: ' . implode(', ', mlog_actions($mark)));
check('19c-susp', $r === 'success' && svc($s1)['domainstatus'] === 'Suspended' && panel_client($st['c1'])['locked'] === 'y', '(c) S1 Suspended, client locked=y', panel_client($st['c1']));
$ca = ispconfigconnect_ClientArea(harness_params($s1));
$html = render_overview($ca, $s1);
file_put_contents(LT_DIR . '/step19-clientarea-S1-suspended.html', $html);
check('19c-button', $ca['templateVariables']['ssoAvailable'] === false && !str_contains($html, 'dosinglesignon'), '(c) no button for suspended S1');
$c = sso_call('ServiceSingleSignOn(S1 Suspended, customer, dosinglesignon=1)', $s1, false);
$d = sso_row($c['log'])['decoded'];
check('19c-msg', $c['result'] === ['success' => false, 'errorMsg' => $customerSentence], '(c) direct URL: customer sentence only', $c['result']);
check('19c-log', ($d['detail'] ?? '') === 'Single sign-on is only available while the service is active; this service is Suspended.', '(c) Module Log detail names the Suspended status', $c['log']);
check('19c-nocall', $c['reqs'] === [], '(c) refused before any panel request', $c['reqs']);

hr('19(b/c) status gate isolated: tblservers_remote says the add-on IS installed');
// A panel with the add-on is not available here; simulate the cached state WHMCS would hold
// for one (sso_state=installed), so the button gate and the status gate are observed alone.
$meta = $metas[$server];
$meta['sso_state'] = 'installed';
$meta['sso_available'] = true;
store_remote_meta($server, $meta);
$md = metadata_fresh();
check('19g-md', ($md['ServiceSingleSignOnLabel'] ?? null) === 'Log in to ISPConfig', 'cache says installed: MetaData advertises ServiceSingleSignOnLabel "Log in to ISPConfig"', $md);
check('19g-md-admin', !isset($md['AdminSingleSignOnLabel']), 'AdminSingleSignOnLabel still not advertised');
// Unsuspend S1 to have one Active service next to the Suspended S2.
$r = harness_call('UnsuspendAccount', $s1);
check('19g-unsusp', $r === 'success' && svc($s1)['domainstatus'] === 'Active', 'Unsuspend S1 (for the Active comparison)', $r);
$caA = ispconfigconnect_ClientArea(harness_params($s1));
$caS = ispconfigconnect_ClientArea(harness_params($s2));
$htmlA = render_overview($caA, $s1);
file_put_contents(LT_DIR . '/step19-clientarea-S1-active-cache-installed.html', $htmlA);
check('19g-ca-active', $caA['templateVariables']['ssoAvailable'] === true && str_contains($htmlA, 'clientarea.php?action=productdetails&amp;id=' . $s1 . '&amp;dosinglesignon=1'), 'Active S1 on an "installed" server: button rendered, pointing at WHMCS\'s dosinglesignon URL', $caA['templateVariables']);
check('19g-ca-susp', $caS['templateVariables']['ssoAvailable'] === false, 'Suspended S2 on the same server: no button (status gate)', $caS['templateVariables']);
$g1 = sso_call('ServiceSingleSignOn(S2 Suspended, customer, cache installed)', $s2, false);
$d = sso_row($g1['log'])['decoded'];
check('19g-susp', $g1['result']['errorMsg'] === $customerSentence && ($d['detail'] ?? '') === 'Single sign-on is only available while the service is active; this service is Suspended.' && $g1['reqs'] === [], 'Suspended S2: status gate refuses first, no panel request', $g1);
$g2 = sso_call('ServiceSingleSignOn(S1 Active, customer, cache installed but panel lacks it)', $s1, false);
$d = sso_row($g2['log'])['decoded'];
check('19g-active', $g2['result']['errorMsg'] === $customerSentence && ($d['sso_state'] ?? '') === 'absent' && logins_in($g2['reqs']) === 1, 'Active S1: passes the gate, reaches the panel (1 login), panel answers invalid_method -> not installed', $g2);
// Robustness: WHMCS passes status as $params['status']; the model is the fallback.
$g3 = sso_call('ServiceSingleSignOn(S2, no $params[status], model says Suspended)', $s2, false, ['status' => null]);
$d = sso_row($g3['log'])['decoded'];
check('19g-model', str_contains((string) ($d['detail'] ?? ''), 'this service is Suspended'), 'without $params[status] the model\'s domainstatus still gates', $g3['log']);
$caModel = ispconfigconnect_ClientArea(harness_params($s2, ['status' => null]));
check('19g-model-ca', $caModel['templateVariables']['ssoAvailable'] === false, 'ClientArea without $params[status]: model fallback still hides the button');

hr('19(d) admin context: S2 (Suspended, website-only) and S1 (locked)');
// Put the truthful cache back first.
open_servers_page([$server]);
$d1 = sso_call('ServiceSingleSignOn(S2 Suspended, ADMIN)', $s2, true);
$d = sso_row($d1['log'])['decoded'];
check('19d-nogate', sso_row($d1['log'])['action'] === 'AdminSingleSignOn' && ($d['sso_state'] ?? '') === 'absent' && logins_in($d1['reqs']) === 1, '(d) admin path is NOT status-gated: it reaches the panel (1 login) for a Suspended service', $d1);
check('19d-msg', $d1['result'] === ['success' => false, 'errorMsg' => $absentAdmin], '(d) admin gets the operator diagnosis ("Not installed ...") instead of the customer sentence', $d1['result']);
$fresh = admin_sso_fresh($s2);
dump('ServiceSingleSignOn(S2) with ADMINAREA defined, fresh process', $fresh);
check('19d-adminarea', ($fresh['errorMsg'] ?? '') === $absentAdmin, '(d) with ADMINAREA defined and no test seam, the admin context is detected', $fresh);
$r = harness_call('SuspendAccount', $s1);
check('19d-lock', $r === 'success' && panel_client($st['c1'])['locked'] === 'y', 'S1 suspended again (locked) for the admin check', panel_client($st['c1']));
$d2 = sso_call('ServiceSingleSignOn(S1 locked, ADMIN)', $s1, true);
check('19d-locked', ($d2['result']['errorMsg'] ?? '') === $absentAdmin && logins_in($d2['reqs']) === 1, '(d) S1 locked, admin: reaches the panel; this panel answers "not installed" (the add-on\'s locked-client refusal cannot be exercised here)', $d2);

hr('19 AdminSingleSignOn (server-level and with a service)');
$mark = harness_log_mark();
$req = req_mark();
$ad = ispconfigconnect_AdminSingleSignOn(harness_server_params($server));
dump('AdminSingleSignOn(server params)', $ad);
out('  requests: ' . json_encode(req_since($req)) . '  modulelog: ' . json_encode(mlog($mark)));
check('19s-server', $ad === ['success' => true, 'redirectTo' => 'http://127.0.0.1:8086/'], 'server-level AdminSingleSignOn hands the admin the panel login page, no API call', $ad);
check('19s-noreq', req_since($req) === [] && mlog($mark) === [], 'server-level AdminSingleSignOn made no panel request and no log entry');
$mark = harness_log_mark();
$req = req_mark();
$ad2 = ispconfigconnect_AdminSingleSignOn(harness_params($s1));
$reqs = req_since($req);
dump('AdminSingleSignOn(S1 params)', $ad2);
print_mlog($mark);
check('19s-service', $ad2 === ['success' => true, 'redirectTo' => 'http://127.0.0.1:8086/'] && logins_in($reqs) === 1, 'service-scoped AdminSingleSignOn: SSO not installed -> falls back to the panel login page', [$ad2, $reqs]);

hr('19 restore: unsuspend S1 and S2');
check('19r-1', harness_call('UnsuspendAccount', $s1) === 'success', 'Unsuspend S1');
check('19r-2', harness_call('UnsuspendAccount', $s2) === 'success', 'Unsuspend S2');
dump('panel clients', harness_panel_rows('SELECT client_id, username, locked FROM client'));
dump('web_domain', harness_panel_rows('SELECT domain_id, domain, active FROM web_domain'));

$all = \WHMCS\Database\Capsule::table('tblmodulelog')->get()->all();
$leak = array_filter($all, fn ($r) => str_contains($r->response . $r->request, 'connect_sso_token') && str_contains($r->response, 'token='));
check('19-nourl', $leak === [], 'no module-log row carries an SSO login URL');

lt_finish('step19');
