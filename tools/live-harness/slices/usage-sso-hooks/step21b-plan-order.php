<?php

/**
 * Plan-order check: step 20 ends with "Delete the seeded row afterwards", and step 21 then expects
 * "Bandwidth (about 500 MB)". The Bandwidth METRIC is read live from the panel
 * (trafficquota_get_by_user this_month over web_traffic), not from tblhosting.bwusage, so following
 * the plan in order gives ~0 MB in step 21. Also the slice's clean-up of the seeded row.
 */

declare(strict_types=1);

require __DIR__ . '/lib.php';

$st = lt_state();
$provider = ispconfigconnect_MetricProvider(harness_server_params($st['server']));
$bw = static function () use ($provider, $st): ?int {
    foreach ($provider->tenantUsage($st['u1']) as $m) {
        if ($m->systemName() === 'bandwidth') {
            return (int) $m->usage()?->value();
        }
    }

    return null;
};

hr('with the seeded web_traffic row (the order this slice ran step 21 in)');
$before = $bw();
dump('Bandwidth metric', $before);
dump('tblhosting S1', svc($st['s1']));

hr('the plan\'s order: step 20 deletes the seeded row, then step 21 collects');
pq("DELETE FROM web_traffic WHERE hostname='test-one.example' AND traffic_date=CURDATE()");
$after = $bw();
dump('Bandwidth metric after the delete', $after);
ispconfigconnect_UsageUpdate(harness_server_params($st['server']));
dump('tblhosting S1 after a sweep (same-month high-water mark keeps bwusage)', svc($st['s1']));
check('21b-order', $after >= 450, 'following the plan in order (row deleted at the end of step 20), step 21 still shows "Bandwidth (about 500 MB)"', ['with_row' => $before, 'after_plan_delete' => $after]);

hr('clean-up: every seeded panel row');
pq('DELETE FROM web_traffic');
pq("DELETE FROM monitor_data WHERE type IN ('harddisk_quota','email_quota','database_size')");
pq("UPDATE web_domain SET ssl_key='' WHERE domain_id=1");
pq("UPDATE client SET id_rsa='', street='', bank_account_iban='', vat_id='', notes='' WHERE client_id=?", [$st['hand']]);
pq('DELETE FROM attempts_login');
dump('remaining web_traffic', pq('SELECT COUNT(*) n FROM web_traffic')[0]['n']);

lt_finish('step21b');
