<?php
/** Plan 2.4: Hostname values the old build tolerated vs what the new build refuses. Temporary server row, removed afterwards. */
declare(strict_types=1);
require __DIR__ . '/common.php';

use WHMCS\Database\Capsule;

$state = up_state();
up_h('2.4 malformed Hostname values (temporary server row, all resolve to this host)');
foreach (['127.0.0.1', 'http://127.0.0.1', '127.0.0.1:8087', '127.0.0.1/', ' 127.0.0.1', 'whmcs@127.0.0.1'] as $host) {
    $id = harness_add_server(['name' => 'TMP-HOST', 'hostname' => $host, 'ipaddress' => '']);
    $m = harness_log_mark();
    $r = ispconfigconnect_TestConnection(harness_server_params($id));
    $acts = up_actions($m);
    printf("  %-20s success=%-5s requests_logged=%-2d login=%s | %s\n", json_encode($host), json_encode($r['success'] ?? null), count(array_filter($acts, static fn ($a) => $a !== 'TestConnection')), in_array('login', $acts, true) ? 'yes' : 'no', mb_substr((string) ($r['error'] ?? ''), 0, 170));
    Capsule::table('tblservers')->where('id', $id)->delete();
}
up_p('attempts_login rows on the panel', harness_panel_rows('SELECT * FROM attempts_login'));
