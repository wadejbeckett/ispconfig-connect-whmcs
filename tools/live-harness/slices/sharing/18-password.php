<?php

/** Plan step 18: Change Password on S1, verified in the panel DB and by a real panel (interface) login. */

declare(strict_types=1);

require __DIR__ . '/lib.php';

use WHMCS\Database\Capsule;

$st = load_state();
$S1 = $st['s1'];
$N = (int) $st['s1_client'];
$oldPw = (string) svc($S1)->password;
$newPw = 'Step18-New-Pass-' . substr(md5((string) microtime(true)), 0, 8) . '!';
$props = module_reads($S1);
$ftpId = (int) Capsule::table('tblcustomfieldsvalues as v')->join('tblcustomfields as f', 'f.id', '=', 'v.fieldid')->where('v.relid', $S1)->where('f.fieldname', 'ispconfig_ftp_user_id')->value('v.value');
$dbuId = (int) Capsule::table('tblcustomfieldsvalues as v')->join('tblcustomfields as f', 'f.id', '=', 'v.fieldid')->where('v.relid', $S1)->where('f.fieldname', 'ispconfig_database_user_id')->value('v.value');

function hashes(int $N, int $ftpId, int $dbuId): array
{
    return [
        'client.password' => pval("SELECT password FROM client WHERE client_id=$N"),
        'sys_user.passwort' => pval("SELECT passwort FROM sys_user WHERE client_id=$N"),
        'ftp_user.password' => pval("SELECT password FROM ftp_user WHERE ftp_user_id=$ftpId"),
        'web_database_user.database_password' => pval("SELECT database_password FROM web_database_user WHERE database_user_id=$dbuId"),
        'web_database_user.database_password_sha2' => pval("SELECT database_password_sha2 FROM web_database_user WHERE database_user_id=$dbuId"),
        'web_database_user.database_password_postgres' => pval("SELECT database_password_postgres FROM web_database_user WHERE database_user_id=$dbuId"),
    ];
}

function crypt_ok(string $pw, ?string $hash): bool
{
    return $hash !== null && $hash !== '' && hash_equals($hash, crypt($pw, $hash));
}

function mysql_native(string $pw): string
{
    return '*' . strtoupper(sha1(sha1($pw, true)));
}

/** Real panel (interface) login: POST /login/index.php; success = no error box and a session redirect. */
function panel_login(string $user, string $pw): array
{
    $jar = tempnam(sys_get_temp_dir(), 'ispc');
    $ch = curl_init('http://127.0.0.1:8084/login/index.php');
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query(['username' => $user, 'password' => $pw, 's_mod' => 'login', 's_pg' => 'index']),
        CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
    ]);
    $resp = (string) curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    // Follow-up: is the session now logged in? /index.php redirects to login when not.
    $ch = curl_init('http://127.0.0.1:8084/dashboard/dashboard.php');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_COOKIEFILE => $jar]);
    $dash = (string) curl_exec($ch);
    curl_close($ch);
    @unlink($jar);
    $error = preg_match('/<div class="box box_error">(.*?)<\/div>/s', $resp, $m) ? strip_tags($m[1]) : '';

    return ['http' => $code, 'error' => $error, 'redirect' => str_contains($resp, 'HEADER_REDIRECT') || preg_match('/^Location:/mi', $resp) === 1,
        'dashboard_loggedin' => !str_contains($dash, 'login/index.php') && !str_contains($dash, 'HEADER_REDIRECT:login'), 'dash_head' => substr($dash, 0, 300)];
}

hdr('18. Change Password on S1 (client #' . $N . ', ftp_user #' . $ftpId . ', web_database_user #' . $dbuId . ')');
$before = hashes($N, $ftpId, $dbuId);
foreach ($before as $k => $v) {
    out("  before $k = " . var_export($v, true));
}
check('18-pre-old-works', crypt_ok($oldPw, $before['client.password']) && crypt_ok($oldPw, $before['ftp_user.password']) && $before['web_database_user.database_password'] === mysql_native($oldPw), 'current WHMCS password matches the panel hashes before rotation');

$loginOld = panel_login('testone1', $oldPw);
out('  interface login with the OLD password before rotation: ' . json_encode(array_diff_key($loginOld, ['dash_head' => 1])));

Capsule::table('tblhosting')->where('id', $S1)->update(['password' => $newPw]);
out('  UPDATE tblhosting SET password=<new> (Save Changes)');
$mark = harness_log_mark();
$dl = max_datalog();
$r = harness_call('ChangePassword', $S1);
$rows = log_rows($mark);
out('  Change Password: ' . $r);
print_log($rows, 500);
check('18-success', $r === 'success', 'Module Command Success', $r);
$acts = log_actions($rows);
check('18-calls', $acts === ['login', 'client_change_password', 'sites_ftp_user_update', 'sites_database_user_update', 'logout'], 'client_change_password, sites_ftp_user_update, sites_database_user_update in the Module Log', implode(',', $acts));
$leak = Capsule::table('tblmodulelog')->where('id', '>', $mark)->where(function ($q) use ($newPw) {
    $q->where('request', 'like', '%' . $newPw . '%')->orWhere('response', 'like', '%' . $newPw . '%')->orWhere('arrdata', 'like', '%' . $newPw . '%');
})->count();
$leakAll = Capsule::table('tblmodulelog')->where(function ($q) use ($newPw, $oldPw) {
    $q->where('request', 'like', '%' . $newPw . '%')->orWhere('response', 'like', '%' . $newPw . '%')
        ->orWhere('request', 'like', '%' . $oldPw . '%')->orWhere('response', 'like', '%' . $oldPw . '%');
})->count();
check('18-masked', $leak === 0 && $leakAll === 0 && count(array_filter($rows, static fn ($row) => str_contains((string) $row->request, '"new_password":"***"') || str_contains((string) $row->request, '"password":"***"') || str_contains((string) $row->request, '"database_password":"***"'))) >= 3, 'all three password fields masked (***); neither password appears anywhere in tblmodulelog');

$after = hashes($N, $ftpId, $dbuId);
foreach ($after as $k => $v) {
    out("  after  $k = " . var_export($v, true));
}
check('18-client', $after['client.password'] !== $before['client.password'] && crypt_ok($newPw, $after['client.password']) && !crypt_ok($oldPw, $after['client.password']), 'client.password rotated: new verifies, old fails');
check('18-sysuser', $after['sys_user.passwort'] !== $before['sys_user.passwort'] && crypt_ok($newPw, $after['sys_user.passwort']) && !crypt_ok($oldPw, $after['sys_user.passwort']), 'sys_user.passwort (panel login) rotated');
check('18-ftp', $after['ftp_user.password'] !== $before['ftp_user.password'] && crypt_ok($newPw, $after['ftp_user.password']) && !crypt_ok($oldPw, $after['ftp_user.password']), 'ftp_user.password rotated');
check('18-db', $after['web_database_user.database_password'] === mysql_native($newPw) && $after['web_database_user.database_password'] !== mysql_native($oldPw), 'web_database_user.database_password rotated (mysql_native_password hash of the new password)');
out('  database_password_sha2 before/after: ' . var_export($before['web_database_user.database_password_sha2'], true) . ' / ' . var_export($after['web_database_user.database_password_sha2'], true));
$dlRows = datalog_since($dl);
out('  datalog queued: ' . json_encode($dlRows));
check('18-datalog', count(array_filter($dlRows, static fn ($d) => $d['dbtable'] === 'ftp_user')) >= 1 && count(array_filter($dlRows, static fn ($d) => $d['dbtable'] === 'web_database_user')) >= 1, 'ftp_user and web_database_user update jobs queued for server.sh (no daemon here to apply them)');

$loginNew = panel_login('testone1', $newPw);
$loginOld2 = panel_login('testone1', $oldPw);
out('  interface login with NEW password: ' . json_encode(array_diff_key($loginNew, ['dash_head' => 1])));
out('  interface login with OLD password: ' . json_encode(array_diff_key($loginOld2, ['dash_head' => 1])));
check('18-panel-login', $loginNew['error'] === '' && $loginOld2['error'] !== '', 'real panel login: new password accepted, old password rejected');
out('  attempts_login rows (from the one deliberate failure): ' . json_encode(prow('SELECT * FROM attempts_login')));
panel_db('dbispconfig4')->query('DELETE FROM attempts_login');

$st['s1_password'] = $newPw;
save_state($st);
summary();
