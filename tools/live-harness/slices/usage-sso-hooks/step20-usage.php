<?php

/**
 * Step 20: usage import (ispconfigconnect_UsageUpdate - what WHMCS's UpdateServerUsage cron task
 * calls per server with the server params) and the monthly bandwidth reset / high-water mark.
 *
 * Panel seeding (what the ISPConfig server daemon would have written):
 *   web_traffic   test-one.example CURDATE() 524288000 (the plan's row) + a LAST-MONTH row that must not count
 *                 test-two.example CURDATE() 104857600 (100 MB)
 *   monitor_data  harddisk_quota: web1 used 204800 KB (200 MB), web2 used 51200 KB (50 MB)
 */

declare(strict_types=1);

require __DIR__ . '/lib.php';

$st = lt_state();
$server = $st['server'];
$s1 = $st['s1'];
$s2 = $st['s2'];
$s3 = $st['s3'];

function usage_run(string $label): array
{
    $mark = harness_log_mark();
    $req = req_mark();
    $before = date('Y-m-d H:i:s');
    ispconfigconnect_UsageUpdate(harness_server_params(1));
    $after = date('Y-m-d H:i:s');
    $reqs = req_since($req);
    $log = mlog_raw($mark);
    $usageRow = null;
    foreach ($log as $r) {
        if ($r['action'] === 'UsageUpdate') {
            $usageRow = $r;
        }
    }
    out("  [$label] panel requests: " . count($reqs) . ' (' . logins_in($reqs) . ' login)');
    out("  [$label] module-log actions: " . implode(', ', array_map(fn ($r) => $r['action'], $log)));
    out("  [$label] UsageUpdate log: req=" . ($usageRow['request'] ?? '-') . ' resp=' . ($usageRow['response'] ?? '-'));

    return ['before' => $before, 'after' => $after, 'reqs' => $reqs, 'log' => $log, 'summary' => json_decode((string) ($usageRow['response'] ?? 'null'), true), 'row' => $usageRow];
}

function within(string $stamp, string $from, string $to): bool
{
    return $stamp >= $from && $stamp <= $to;
}

$lastMonth = (new DateTimeImmutable('first day of last month'))->format('Y-m') . '-15';
$sameMonthLastYear = (new DateTimeImmutable('now'))->modify('-1 year')->format('Y-m') . '-15';

hr('seed the panel');
pq('DELETE FROM web_traffic');
pq("INSERT INTO web_traffic (hostname,traffic_date,traffic_bytes) VALUES ('test-one.example',CURDATE(),524288000) ON DUPLICATE KEY UPDATE traffic_bytes=524288000");
pq("INSERT INTO web_traffic (hostname,traffic_date,traffic_bytes) VALUES ('test-one.example',?,1047527424)", [$lastMonth]);
pq("INSERT INTO web_traffic (hostname,traffic_date,traffic_bytes) VALUES ('test-two.example',CURDATE(),104857600)");
pq("DELETE FROM monitor_data WHERE type='harddisk_quota'");
$hd = ['user' => ['web1' => ['used' => 204800, 'soft' => 0, 'hard' => 0, 'files' => 0], 'web2' => ['used' => 51200, 'soft' => 0, 'hard' => 0, 'files' => 0]],
    'group' => ['client1' => ['used' => 204800, 'soft' => 0, 'hard' => 0], 'client2' => ['used' => 51200, 'soft' => 0, 'hard' => 0]]];
pq("INSERT INTO monitor_data (server_id,type,created,data,state) VALUES (1,'harddisk_quota',UNIX_TIMESTAMP(),?,'ok')", [serialize($hd)]);
dump('web_traffic', pq('SELECT * FROM web_traffic ORDER BY hostname, traffic_date'));
dump('upgrade-check-11 panel query', pq('SELECT hostname,SUM(traffic_bytes)/1048576 mb FROM web_traffic WHERE YEAR(traffic_date)=YEAR(CURDATE()) AND MONTH(traffic_date)=MONTH(CURDATE()) GROUP BY hostname'));
dump('PHP timezone / now', [date_default_timezone_get(), date('Y-m-d H:i:s')]);
dump('MySQL NOW()', pq('SELECT NOW() n')[0]['n']);

// WHMCS admin-configured limits that the sweep must NOT zero (the panel's quotas are unlimited).
set_svc($s1, ['disklimit' => 2048, 'bwlimit' => 10240, 'diskusage' => 0]);
set_svc($s2, ['disklimit' => 0, 'bwlimit' => 0, 'diskusage' => 0, 'bwusage' => 0, 'lastupdate' => '0000-00-00 00:00:00']);
set_svc($s3, ['diskusage' => 7, 'bwusage' => 7, 'lastupdate' => '0000-00-00 00:00:00']);

hr('20(a) bwusage=99999, lastupdate last month -> reset to this month (500)');
set_svc($s1, ['bwusage' => 99999, 'lastupdate' => "$lastMonth 12:00:00"]);
dump('S1 before', svc($s1));
$a = usage_run('a');
$s1a = svc($s1);
dump('S1 after', $s1a);
dump('S2 after', svc($s2));
dump('S3 after', svc($s3));
check('20a-bw', (int) $s1a['bwusage'] === 500, '(a) bwusage=500 (last month\'s 99999 peak reset; last month\'s 999 MB web_traffic row not counted)', $s1a);
check('20a-lastupdate', within($s1a['lastupdate'], $a['before'], $a['after']), "(a) lastupdate is now in PHP's clock ({$s1a['lastupdate']} within [{$a['before']}, {$a['after']}])", $s1a['lastupdate']);
check('20a-disk', (int) $s1a['diskusage'] === 200, '(a) diskusage=200 (monitor 204800 KB)', $s1a['diskusage']);
check('20a-limits-kept', (int) $s1a['disklimit'] === 2048 && (int) $s1a['bwlimit'] === 10240, '(a) unlimited panel quotas leave the WHMCS-configured disklimit/bwlimit untouched', $s1a);
check('20a-log', ($a['summary']['updated'] ?? 0) >= 1 && !isset($a['summary']['error']), '(a) Module Log UsageUpdate: updated>=1, no error', $a['summary']);
check('20a-login', logins_in($a['reqs']) === 1, '(a) one login for the whole sweep', $a['reqs']);
check('20a-s2', (int) svc($s2)['bwusage'] === 100 && (int) svc($s2)['diskusage'] === 50, '(a) S2 (website-only) bwusage=100, diskusage=50', svc($s2));
check('20a-s3', (int) svc($s3)['bwusage'] === 0 && (int) svc($s3)['diskusage'] === 0, '(a) S3 (no website) imported as 0/0, not skipped', [svc($s3), $a['summary']]);
check('20a-count', ($a['summary']['updated'] ?? 0) === 3 && !isset($a['summary']['skipped']), '(a) all 3 linked services updated, none skipped', $a['summary']);

hr('20(b) bwusage=99999 (this month) -> stays 99999 (high-water mark)');
set_svc($s1, ['bwusage' => 99999]);
$b = usage_run('b');
$s1b = svc($s1);
dump('S1 after', $s1b);
check('20b-bw', (int) $s1b['bwusage'] === 99999, '(b) bwusage stays 99999 (this month\'s high-water mark)', $s1b);
check('20b-log', ($b['summary']['updated'] ?? 0) >= 1 && !isset($b['summary']['error']), '(b) updated>=1, no error', $b['summary']);
check('20b-lastupdate', within($s1b['lastupdate'], $b['before'], $b['after']), '(b) lastupdate refreshed', $s1b['lastupdate']);

hr('20(c) bwusage=0 -> 500');
set_svc($s1, ['bwusage' => 0]);
$c = usage_run('c');
$s1c = svc($s1);
dump('S1 after', $s1c);
check('20c-bw', (int) $s1c['bwusage'] === 500, '(c) bwusage=500', $s1c);
check('20c-log', ($c['summary']['updated'] ?? 0) >= 1 && !isset($c['summary']['error']), '(c) updated>=1, no error', $c['summary']);

hr('20 extra rollover / high-water cases');
set_svc($s1, ['bwusage' => 99999, 'lastupdate' => '0000-00-00 00:00:00']);
usage_run('zero-date');
check('20x-zero', (int) svc($s1)['bwusage'] === 500, 'lastupdate 0000-00-00 (never updated) with bwusage 99999 -> measured 500', svc($s1));
set_svc($s1, ['bwusage' => 99999, 'lastupdate' => "$sameMonthLastYear 12:00:00"]);
usage_run('same-month-last-year');
check('20x-lastyear', (int) svc($s1)['bwusage'] === 500, "lastupdate in the same month LAST YEAR ($sameMonthLastYear) -> reset to 500 (year compared too)", svc($s1));
set_svc($s1, ['bwusage' => 120, 'lastupdate' => date('Y-m-01 00:00:05')]);
usage_run('lower-stored');
check('20x-lower', (int) svc($s1)['bwusage'] === 500, 'stored 120 this month, measured 500 -> 500 (max)', svc($s1));

hr('20 extra: suspension mid-month keeps the month\'s accrual, rollover while suspended resets it');
$r = harness_call('SuspendAccount', $s1);
check('20s-susp', $r === 'success' && harness_panel_rows('SELECT active FROM web_domain WHERE domain_id=1')[0]['active'] === 'n', 'S1 suspended: web_domain.active=n (panel stops counting its traffic)', $r);
$raw = panel_api();
$tq = $raw->call('trafficquota_get_by_user', ['client_id' => $st['c1'], 'lastdays' => 0]);
$raw->close();
dump('trafficquota_get_by_user(S1 client) while suspended', $tq);
set_svc($s1, ['bwusage' => 500, 'lastupdate' => date('Y-m-d H:i:s')]);
$s = usage_run('suspended-same-month');
check('20s-keep', (int) svc($s1)['bwusage'] === 500, 'suspended, same month: panel reports 0, stored 500 kept', [svc($s1), $s['summary']]);
check('20s-status', svc($s1)['domainstatus'] === 'Suspended' && ($s['summary']['updated'] ?? 0) === 3, 'suspended services are still swept (Active+Suspended)', $s['summary']);
set_svc($s1, ['bwusage' => 500, 'lastupdate' => "$lastMonth 12:00:00"]);
usage_run('suspended-rollover');
check('20s-reset', (int) svc($s1)['bwusage'] === 0, 'suspended across a month rollover: last month\'s 500 is reset to this month\'s 0', svc($s1));
check('20s-unsusp', harness_call('UnsuspendAccount', $s1) === 'success', 'Unsuspend S1');

hr('20 extra: panel quotas set -> disklimit/bwlimit written');
pq('UPDATE web_domain SET hd_quota=1000, traffic_quota=5000 WHERE domain_id=2');
set_svc($s2, ['disklimit' => 1, 'bwlimit' => 1]);
usage_run('limits');
check('20l-limits', (int) svc($s2)['disklimit'] === 1000 && (int) svc($s2)['bwlimit'] === 5000, 'S2 website hd_quota=1000/traffic_quota=5000 MB -> disklimit=1000, bwlimit=5000', svc($s2));
pq('UPDATE web_domain SET hd_quota=-1, traffic_quota=-1 WHERE domain_id=2');

hr('20 extra: PHP clock vs MySQL clock (UsageUpdate run with date.timezone=Pacific/Kiritimati)');
set_svc($s1, ['bwusage' => 0, 'lastupdate' => '0000-00-00 00:00:00']);
$code = "require '" . LT_DIR . "/lib.php'; \$b = date('Y-m-d H:i:s'); ispconfigconnect_UsageUpdate(harness_server_params(1)); echo json_encode([\$b, date('Y-m-d H:i:s'), date_default_timezone_get()]);";
$tz = json_decode((string) shell_exec(lt_php() . ' -d date.timezone=Pacific/Kiritimati -r ' . escapeshellarg($code)), true);
$lu = svc($s1)['lastupdate'];
dump('Kiritimati run [before, after, tz]', $tz);
dump('S1 lastupdate', $lu);
dump('MySQL NOW()', pq('SELECT NOW() n')[0]['n']);
check('20tz', is_array($tz) && within($lu, $tz[0], $tz[1]), "lastupdate follows PHP's timezone (Pacific/Kiritimati), not MySQL NOW() (UTC)", [$tz, $lu]);

hr('20 extra: an unlinked Active service on the server is skipped, not an error');
$p1 = $st['p1'];
$s5 = harness_add_service($p1, $server, 'unlinked.example', ['userid' => 79, 'domainstatus' => 'Active']);
$u = usage_run('with-unlinked');
check('20u', ($u['summary']['updated'] ?? 0) === 3 && isset($u['summary']['skipped'][$s5]) && !isset($u['summary']['error']), 'unlinked service reported under skipped/unlinked, others updated, no error', $u['summary']);
set_svc($s5, ['domainstatus' => 'Terminated']);
$st['s5'] = $s5;
lt_save_state($st);

hr('20 restore to the plan\'s state for step 21 (S1 bwusage 500), keep the seeded row until step 21');
set_svc($s1, ['bwusage' => 0, 'lastupdate' => '0000-00-00 00:00:00']);
$f = usage_run('final');
dump('S1', svc($s1));
dump('admin tab bandwidth/disk (live)', array_intersect_key(ispconfigconnect_AdminServicesTabFields(harness_params($s1)), array_flip(['ISPConfig Disk Usage', 'ISPConfig Bandwidth (this month)'])));

lt_finish('step20');
