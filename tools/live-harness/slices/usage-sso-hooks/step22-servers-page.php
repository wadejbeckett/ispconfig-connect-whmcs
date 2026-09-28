<?php

/**
 * Step 22: Configuration > System Settings > Servers.
 *
 * A page render = WHMCS calls ispconfigconnect_GetRemoteMetaData() per server of this module and
 * stores the flat array in tblservers_remote.meta_data; then the admin footer hook
 * (includes/hooks/ispconfigconnect.php -> AdminAreaFooterOutput) emits the block + script, which
 * runs in the browser. The browser part is executed in jsdom against a WHMCS-shaped servers list
 * (core's row shape: name cell with the makedefault link, the edit link alone in an icon column,
 * a .server-usage-count "Accounts: N / Services: N" cell), with a server-GROUP table sharing the
 * id namespace and a non-ISPConfig (cPanel) server on the SAME hostname.
 */

declare(strict_types=1);

require __DIR__ . '/lib.php';

// The installed hook file, exactly as install-hook.sh put it in place in step 3.
$hookFile = LT_DIR . '/sandbox/whmcs-b/includes/hooks/ispconfigconnect.php';
if (!is_file($hookFile)) {
    $hookFile = '/home/user/ispconfig-connect-whmcs/modules/servers/ispconfigconnect/hooks/ispconfigconnect.php';
}
require $hookFile;
check('22-hookreg', in_array(['AdminAreaFooterOutput', 1, 'ispconfigconnect_metaPanelFooterOutput'], $GLOBALS['harness_hooks'], true), 'loading the installed hook file registers AdminAreaFooterOutput', $GLOBALS['harness_hooks']);

$st = lt_state();
$test = $st['server'];

// ISPC-NEGATIVE: same panel, throwaway remote user, WRONG password. And a cPanel server on the same host.
pq("DELETE FROM remote_user WHERE remote_username='negative'");
pq("INSERT INTO remote_user (sys_userid, sys_groupid, sys_perm_user, sys_perm_group, sys_perm_other, remote_username, remote_password, remote_access, remote_ips, remote_functions) VALUES (1,1,'riud','riud','','negative',?,'y','','')", [password_hash('Negative-Pass-1', PASSWORD_DEFAULT)]);
pq('DELETE FROM attempts_login');
\WHMCS\Database\Capsule::table('tblservers')->where('id', '>', $test)->delete();
$neg = harness_add_server(['name' => 'ISPC-NEGATIVE', 'username' => 'negative', 'password' => 'Wrong-Password-XYZ']);
$cpanel = harness_add_server(['name' => 'CPANEL-OTHER', 'type' => 'cpanel', 'username' => 'root', 'password' => 'x']);
\WHMCS\Database\Capsule::table('tblservers_remote')->delete();
// cPanel's own cached row, as core would keep it for a bundled module.
\WHMCS\Database\Capsule::table('tblservers_remote')->insert(['server_id' => $cpanel, 'num_accounts' => 7, 'meta_data' => json_encode(['version' => '110.0', 'load' => '0.1']), 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
$st['neg'] = $neg;
$st['cpanel'] = $cpanel;
lt_save_state($st);

hr('22.1 the page render, server side: GetRemoteMetaData per ISPConfig server');
$perServer = [];
foreach ([$test, $neg] as $sid) {
    $mark = harness_log_mark();
    $req = req_mark();
    $meta = ispconfigconnect_GetRemoteMetaData(harness_server_params($sid));
    store_remote_meta($sid, $meta);
    $reqs = req_since($req);
    $perServer[$sid] = ['meta' => $meta, 'reqs' => $reqs, 'actions' => mlog_actions($mark)];
    dump("server $sid GetRemoteMetaData", $meta);
    out('  requests: ' . json_encode($reqs));
    out('  module-log actions: ' . implode(', ', mlog_actions($mark)));
}
$attempts = pq('SELECT ip, times FROM attempts_login');
dump('attempts_login', $attempts);
check('22-meta-test', ($perServer[$test]['meta']['ispconfig_status'] ?? '') === 'ok' && (int) $perServer[$test]['meta']['client_count'] === 4, 'ISPC-TEST metadata: status ok, client_count 4', $perServer[$test]['meta']);
check('22-login-test', logins_in($perServer[$test]['reqs']) === 1, 'ISPC-TEST: exactly one login for the page render', $perServer[$test]['reqs']);
check('22-meta-neg', ($perServer[$neg]['meta']['ispconfig_status'] ?? '') === 'unavailable' && str_contains((string) ($perServer[$neg]['meta']['ispconfig_error'] ?? ''), 'The login failed') && str_contains((string) ($perServer[$neg]['meta']['ispconfig_error'] ?? ''), 'remaining probes skipped'), 'ISPC-NEGATIVE metadata: unavailable, probe error names the failed login and the skipped probes', $perServer[$neg]['meta']);
check('22-login-neg', logins_in($perServer[$neg]['reqs']) === 1 && count($perServer[$neg]['reqs']) === 1, 'ISPC-NEGATIVE: one failed login, no second attempt, remaining probes skipped', $perServer[$neg]['reqs']);
check('22-attempts', count($attempts) === 1 && (int) $attempts[0]['times'] === 1, 'attempts_login rose by exactly 1 (the NEGATIVE render)', $attempts);
pq('DELETE FROM attempts_login');

hr('22.2 the footer hook on each configservers.php action');
$pagesHtml = [];
$footer = [];
foreach ([
    'list' => ['/admin/configservers.php', []],
    'list-explicit' => ['/admin/configservers.php?action=list', ['action' => 'list']],
    'edit' => ["/admin/configservers.php?action=manage&id=$test", ['action' => 'manage', 'id' => (string) $test]],
    'group' => ['/admin/configservers.php?action=managegroup&id=1', ['action' => 'managegroup', 'id' => '1']],
    'add' => ['/admin/configservers.php?action=add', ['action' => 'add']],
    'other-admin-page' => ['/admin/clientssummary.php?userid=77', ['userid' => '77']],
] as $name => [$uri, $get]) {
    $_SERVER['REQUEST_URI'] = $uri;
    $_GET = $get;
    $filename = pathinfo((string) parse_url($uri, PHP_URL_PATH), PATHINFO_FILENAME);
    $req = req_mark();
    $mark = harness_log_mark();
    $footer[$name] = ispconfigconnect_metaPanelFooterOutput(['filename' => $filename, 'adminurl' => '/admin']);
    check("22-noapi-$name", req_since($req) === [] && mlog($mark) === [], "footer hook on $name made no panel request and no module-log entry");
    out(sprintf('  %-17s footer bytes=%d', $name, strlen($footer[$name])));
}
check('22-list', $footer['list'] !== '' && str_contains($footer['list'], 'id="ispconfigconnect-server-meta"') && $footer['list'] === $footer['list-explicit'], 'servers list (no action, or action=list): block + script emitted');
foreach (['edit', 'group', 'add', 'other-admin-page'] as $name) {
    check("22-empty-$name", $footer[$name] === '', "$name page: no ISPConfig block or script", strlen($footer[$name]));
}
file_put_contents(LT_DIR . '/step22-footer-list.html', $footer['list']);
preg_match('~<script type="application/json" id="ispconfigconnect-server-meta">(.*?)</script>~s', $footer['list'], $m);
$payload = json_decode($m[1] ?? '[]', true);
dump('footer payload', $payload);
check('22-payload-scope', array_column($payload, 'id') === [$test, $neg], 'payload lists only the two ISPConfig servers (the cPanel row is never decorated)', array_column($payload, 'id'));
check('22-payload-raw', !preg_match('~[<>]~', $m[1] ?? ''), 'payload JSON carries no raw < or >');

hr('22.3 the browser: WHMCS-shaped pages in jsdom');
$token = 'abc123';
$row = static function (int $id, string $name, string $host, string $ip, string $accounts, int $services, int $numAccounts): string {
    return "<tr id=\"srow$id\">"
        . "<td id=\"name$id\"><a href=\"?action=makedefault&amp;id=$id&amp;token=abc123\">" . htmlspecialchars($name) . "</a></td>"
        . "<td>$ip</td>"
        . "<td>$accounts</td>"
        . "<td class=\"server-usage-count\" id=\"usage$id\">Accounts: $numAccounts<br>Services: $services</td>"
        . '<td></td><td></td>'
        . "<td><a href=\"?action=toggle&amp;id=$id&amp;token=abc123\">Disable</a></td>"
        . "<td id=\"edit$id\"><a href=\"?action=manage&amp;id=$id\"><img src=\"images/edit.gif\" alt=\"Edit\"></a></td>"
        . "<td><a href=\"#\" onclick=\"doDelete('$id');return false\"><img src=\"images/delete.gif\" alt=\"Delete\"></a></td>"
        . '</tr>';
};
$servicesTest = \WHMCS\Database\Capsule::table('tblhosting')->where('server', $test)->whereIn('domainstatus', ['Active', 'Suspended'])->count();
$serversTable = '<table class="datatable" id="servers" width="100%"><tr><th>Server Name</th><th>IP Address</th><th>Active Accounts</th><th>Remote Usage Stats</th><th></th><th></th><th></th><th width="20"></th><th width="20"></th></tr>'
    . '<tr><td colspan="9"><strong>ISPConfig Connect</strong></td></tr>'
    . $row($test, 'ISPC-TEST', '127.0.0.1', '127.0.0.1', "$servicesTest/0", (int) ($perServer[$test]['meta']['service_count'] ?? 0), 0)
    . $row($neg, 'ISPC-NEGATIVE', '127.0.0.1', '127.0.0.1', '0/0', 0, 0)
    . '<tr><td colspan="9"><strong>cPanel</strong></td></tr>'
    . $row($cpanel, 'CPANEL-OTHER', '127.0.0.1', '127.0.0.1', '0/0', 3, 7)
    . '</table>';
// Server groups share the id namespace; put the group table FIRST, where a leaky matcher bites.
$groupsTable = '<h2>Server Groups</h2><table class="datatable" id="groups"><tr><th>Group Name</th><th>Fill Type</th><th></th></tr>'
    . "<tr id=\"grow1\"><td id=\"gname1\"><a href=\"?action=managegroup&amp;id=$test\">G1</a></td><td>Fill</td><td class=\"server-usage-count\" id=\"gusage1\">Accounts: 0<br>Services: 9</td></tr>"
    . "<tr id=\"grow2\"><td id=\"gname2\"><a href=\"?action=managegroup&amp;id=$neg\">G2</a></td><td>Fill</td><td class=\"server-usage-count\" id=\"gusage2\">Accounts: 0<br>Services: 0</td></tr></table>";
$shell = static fn (string $body, string $foot): string => '<!doctype html><html><head><title>Servers - WHMCS</title></head><body><div id="sidebar"><a href="clientssummary.php?id=1">client 1</a></div><div id="content">'
    . $body . '</div>' . $foot . '</body></html>';
$editForm = '<form method="post" action="configservers.php?action=save&amp;id=' . $test . '"><table class="form"><tr><td>Hostname</td><td><input name="hostname" value="127.0.0.1"></td></tr></table></form>';
$groupForm = '<form method="post" action="configservers.php?action=savegroup&amp;id=1"><select multiple name="serverslist[]"><option value="' . $test . '">ISPC-TEST</option></select></form>';
$pages = [
    'list' => ['url' => 'https://billing.test.example/admin/configservers.php', 'html' => $shell($groupsTable . $serversTable, $footer['list'])],
    'list-rerun' => ['url' => 'https://billing.test.example/admin/configservers.php', 'html' => $shell($groupsTable . $serversTable, $footer['list'] . $footer['list'])],
    'edit' => ['url' => "https://billing.test.example/admin/configservers.php?action=manage&id=$test", 'html' => $shell($editForm, $footer['edit'])],
    'group' => ['url' => 'https://billing.test.example/admin/configservers.php?action=managegroup&id=1', 'html' => $shell($groupForm, $footer['group'])],
];
foreach ($pages as $name => $p) {
    file_put_contents(LT_DIR . "/step22-page-$name.html", $p['html']);
}
$proc = proc_open(['/opt/node22/bin/node', LT_DIR . '/step22-servers-page.js'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null,
    ['NODE_PATH' => '/tmp/claude-0/-home-user-ispconfig-connect-whmcs/3b4b6608-32bb-51b3-a26d-ea850c8c07e5/scratchpad/js/node_modules', 'PATH' => getenv('PATH')]);
fwrite($pipes[0], json_encode(['pages' => $pages]));
fclose($pipes[0]);
$outJs = stream_get_contents($pipes[1]);
$errJs = stream_get_contents($pipes[2]);
$rc = proc_close($proc);
file_put_contents(LT_DIR . '/step22-jsdom-result.json', $outJs);
out("  node exit=$rc stderr=" . substr($errJs, 0, 500));
$res = json_decode((string) $outJs, true);
dump('jsdom list page', ['errors' => $res['list']['errors'] ?? null, 'blocks' => array_map(fn ($b) => ['cellId' => $b['cellId'], 'cellIndex' => $b['cellIndex'], 'lines' => array_column($b['lines'], 'text')], $res['list']['blocks'] ?? []), 'usage' => $res['list']['usage'] ?? null]);

$list = $res['list'] ?? [];
check('22-js-ok', $rc === 0 && ($list['errors'] ?? ['x']) === [], 'the page renders: the script runs with no JS error', [$rc, $list['errors'] ?? null, $errJs]);
$byCell = [];
foreach ($list['blocks'] ?? [] as $b) {
    $byCell[$b['cellId']] = $b;
}
check('22-js-where', array_keys($byCell) === ["name$test", "name$neg"], 'blocks placed under ISPC-TEST\'s and ISPC-NEGATIVE\'s names only - not the group rows, not the cPanel row', array_keys($byCell));
check('22-js-firstcol', ($byCell["name$test"]['cellIndex'] ?? null) === 0 && ($byCell["name$neg"]['cellIndex'] ?? null) === 0, 'block is in the FIRST column (not the edit-icon column)', array_map(fn ($b) => $b['cellIndex'], $byCell));
$labels = array_map(fn ($l) => explode(':', $l['text'])[0], $byCell["name$test"]['lines'] ?? []);
check('22-js-lines', $labels === ['ISPConfig Version', 'Pending Jobs', 'Clients', 'ISPConfig Nodes', 'Single Sign-On', 'Last Updated'], 'ISPC-TEST block: ISPConfig version, pending jobs, clients, nodes, single sign-on line, last updated', $labels);
$texts = array_column($byCell["name$test"]['lines'] ?? [], 'text');
check('22-js-values', in_array('Clients: 4', $texts, true) && in_array('Single Sign-On: Not installed', $texts, true) && in_array('Last Updated: just now', $texts, true) && in_array('Pending Jobs: ' . ($perServer[$test]['meta']['pending_jobs'] ?? '?'), $texts, true), 'ISPC-TEST values: Clients 4, SSO Not installed, Last Updated just now, pending jobs as probed', $texts);
check('22-js-accounts', ($list['usage']["usage$test"]['text'] ?? '') === 'Accounts: 4Services: ' . ($perServer[$test]['meta']['service_count'] ?? '?') && ($list['usage']["usage$test"]['title'] ?? '') === 'Live count from the ISPConfig panel', 'Accounts cell shows the live client count (4), matched by the edit link (not hostname text)', $list['usage']["usage$test"] ?? null);
check('22-js-cpanel', ($list['usage']["usage$cpanel"]['text'] ?? '') === 'Accounts: 7Services: 3' && ($list['usage']["usage$cpanel"]['title'] ?? null) === null, 'cPanel row on the same hostname untouched', $list['usage']["usage$cpanel"] ?? null);
check('22-js-groups', ($list['usage']['gusage1']['text'] ?? '') === 'Accounts: 0Services: 9' && ($list['usage']['gusage2']['text'] ?? '') === 'Accounts: 0Services: 0', 'server-group rows (same ids) untouched', [$list['usage']['gusage1'] ?? null, $list['usage']['gusage2'] ?? null]);
$negTexts = array_column($byCell["name$neg"]['lines'] ?? [], 'text');
$negWarn = array_values(array_filter($byCell["name$neg"]['lines'] ?? [], fn ($l) => str_contains($l['cls'], 'warn')));
check('22-js-neg', count($negWarn) === 1 && str_starts_with($negWarn[0]['text'], 'Probe Errors: ') && str_contains($negWarn[0]['text'], 'The login failed'), 'ISPC-NEGATIVE shows its probe errors (warn line)', $negTexts);
$negSso = array_values(array_filter($negTexts, fn ($t) => str_starts_with($t, 'Single Sign-On: ')));
dump('ISPC-NEGATIVE single sign-on line', $negSso);
check('22-js-neg-sso', !str_contains(implode(' ', $negSso), 'grant get_function_list'), 'ISPC-NEGATIVE: the single sign-on line does not send the admin to grant get_function_list when the LOGIN failed (sso_state=unknown was recorded because the probe was skipped)', $negSso);
check('22-js-neg-accounts', ($list['usage']["usage$neg"]['text'] ?? '') === 'Accounts: 0Services: 0', 'ISPC-NEGATIVE Accounts cell left alone (no live count to give)', $list['usage']["usage$neg"] ?? null);
check('22-js-idempotent', count($res['list-rerun']['blocks'] ?? []) === 2, 'running the script twice does not duplicate the block', count($res['list-rerun']['blocks'] ?? []));
check('22-js-edit', ($res['edit']['blocks'] ?? ['x']) === [] && ($res['edit']['ispconfigScriptPresent'] ?? true) === false, 'edit page: no ISPConfig block or script in the source');
check('22-js-group', ($res['group']['blocks'] ?? ['x']) === [] && ($res['group']['ispconfigScriptPresent'] ?? true) === false, 'group page: no ISPConfig block or script in the source');

hr('22.4 hook file loaded twice (a stray copy) is harmless');
$code = "define('WHMCS', true); function add_hook(...\$a) {} require '$hookFile'; require '$hookFile'; echo 'ok';";
$twice = shell_exec(lt_php() . ' -d display_errors=1 -d error_reporting=-1 -r ' . escapeshellarg($code) . ' 2>&1');
check('22-twice', trim((string) $twice) === 'ok', 'loading includes/hooks/ispconfigconnect.php twice: no fatal, no warning', $twice);

lt_finish('step22');
