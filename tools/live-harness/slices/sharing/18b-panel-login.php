<?php
/** Step 18 follow-up: real ISPConfig interface login with the new and the old password (after installing the IDS whitelist in instance 4). */
declare(strict_types=1);
require __DIR__ . '/lib.php';
$st = load_state();
$newPw = $st['s1_password'];
$oldPw = 'PerPanel-Rotation-14-Xy9'; // the password in force before step 18 (last successful per-panel rotation)
function login_try(string $user, string $pw): array {
    $jar = tempnam(sys_get_temp_dir(), 'ispc');
    $ch = curl_init('http://127.0.0.1:8084/login/index.php');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query(['username' => $user, 'password' => $pw, 's_mod' => 'login', 's_pg' => 'index']),
        CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar]);
    $resp = (string) curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    $ch = curl_init('http://127.0.0.1:8084/dashboard/dashboard.php');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_COOKIEFILE => $jar]);
    $dash = (string) curl_exec($ch); $dcode = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch); @unlink($jar);
    $err = preg_match('/box_error">(.*?)<\/div>/s', $resp, $m) ? trim(strip_tags($m[1])) : '';
    $body = substr($resp, strpos($resp, "\r\n\r\n") + 4);
    return ['login_http' => $code, 'login_error' => $err, 'login_body_start' => substr(trim($body), 0, 80), 'dashboard_http' => $dcode,
        'dashboard_shows_user' => str_contains($dash, 'testone1'), 'dashboard_redirects_to_login' => (bool) preg_match('/login\/index\.php/', $dash)];
}
hdr('18b. Real panel login (ISPConfig interface /login/index.php) as testone1');
$good = login_try('testone1', $newPw);
$bad = login_try('testone1', $oldPw);
out('  NEW password: ' . json_encode($good));
out('  OLD password: ' . json_encode($bad));
check('18b-new', $good['login_error'] === '' && in_array($good['login_http'], [200, 302], true) && $good['dashboard_http'] === 200 && $bad['dashboard_http'] === 302, 'new password logs in to the panel', json_encode($good));
check('18b-old', $bad['login_error'] !== '', 'old password is rejected by the panel', $bad['login_error']);
out('  attempts_login after: ' . json_encode(prow('SELECT ip, times FROM attempts_login')));
panel_db('dbispconfig4')->query('DELETE FROM attempts_login');
summary();
