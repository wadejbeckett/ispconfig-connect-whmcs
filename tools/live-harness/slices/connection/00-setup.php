<?php

/**
 * Setup for the connection slice:
 *  - fresh WHMCS tables in whmcs2;
 *  - server 1 ISPC-TEST  -> 127.0.0.1:8082, remote user whmcs (real creds), Secure off (panel is plain HTTP);
 *  - server 2 ISPC-NEGATIVE -> same host/port, throwaway remote user "negative", no products;
 *  - product P1 "ISPC Entire" and one Active service S-verify on ISPC-TEST (for the Verify Permissions button);
 *  - panel: throwaway remote user "negative" with the nine documented groups.
 */

declare(strict_types=1);

require __DIR__ . '/common.php';

harness_reset_whmcs();
$test = harness_add_server(['name' => 'ISPC-TEST', 'hostname' => '127.0.0.1', 'ipaddress' => '127.0.0.1']);
$neg = harness_add_server([
    'name' => 'ISPC-NEGATIVE', 'hostname' => '127.0.0.1', 'ipaddress' => '127.0.0.1',
    'username' => LT_NEG_USER, 'password' => LT_NEG_PASS,
]);
$p1 = harness_add_product('ISPC Entire');
$svc = harness_add_service($p1, $test, 'verify-perms.example', ['domainstatus' => 'Active']);

// Throwaway remote user on the panel.
$db = lt_paneldb();
$db->query("DELETE FROM remote_user WHERE remote_username = '" . LT_NEG_USER . "'");
$hash = password_hash(LT_NEG_PASS, PASSWORD_DEFAULT);
$fn = lt_functions_value(LT_NINE);
$st = $db->prepare('INSERT INTO remote_user (sys_userid, sys_groupid, sys_perm_user, sys_perm_group, sys_perm_other, remote_username, remote_password, remote_access, remote_ips, remote_functions) VALUES (1,1,"riud","riud","",?,?,"y","",?)');
$user = LT_NEG_USER;
$st->bind_param('sss', $user, $hash, $fn);
$st->execute();
lt_clear_attempts();

lt_out('whmcs servers', \WHMCS\Database\Capsule::table('tblservers')->get(['id', 'name', 'hostname', 'ipaddress', 'port', 'secure', 'username'])->all());
lt_out('product/service', ['product' => $p1, 'service' => $svc]);
lt_out('panel remote users', lt_q('SELECT remote_userid, remote_username, remote_access, remote_ips, LENGTH(remote_functions) fn_len FROM remote_user'));
lt_out('groups found in panel remote.conf.php', array_keys(lt_groups()));
lt_save('00-setup');
