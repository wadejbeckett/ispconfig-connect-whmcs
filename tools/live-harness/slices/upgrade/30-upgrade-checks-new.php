<?php
/**
 * PHASE 1 - plan section 2 checks 2.4-2.13, NEW build, before any live action.
 * Every SQL statement is the plan's text verbatim (only the ISPConfig database
 * name dbispconfig -> dbispconfig7 and <id> placeholders are substituted),
 * run through the mariadb CLI against the real databases.
 */
declare(strict_types=1);
require __DIR__ . '/common.php';

use WHMCS\Database\Capsule;

$state = up_state();
$W = harness_db_name();
$P = harness_panel_db();

up_h('Prereq 1: tblcustomfieldsvalues.id');
up_show_sql('prereq-1', $W, "SHOW COLUMNS FROM tblcustomfieldsvalues LIKE 'id';");

up_h('2.4 Server Hostname fields');
up_show_sql('2.4', $W, "SELECT id,name,hostname,ipaddress,port,secure FROM tblservers WHERE type='ispconfigconnect';");
$m = harness_log_mark();
up_p('new TestConnection', ispconfigconnect_TestConnection(harness_server_params($state['server'])));
foreach (up_log($m, 200) as $l) {
    echo "  ", $l, "\n";
}

up_h('2.5 Services that share one ISPConfig client');
up_show_sql('2.5', $W, <<<'SQL'
SELECT v.value client_id,
       GROUP_CONCAT(DISTINCT CONCAT('svc ',h.id,' srv ',h.server,' ',h.domainstatus) ORDER BY h.id SEPARATOR '; ') services,
       COUNT(DISTINCT h.id) n, COUNT(DISTINCT h.server) servers
FROM tblcustomfieldsvalues v
JOIN tblcustomfields f ON f.id=v.fieldid AND f.type='product' AND f.fieldname='ispconfig_client_id'
JOIN tblhosting h ON h.id=v.relid
WHERE v.value<>'' AND h.domainstatus NOT IN ('Terminated','Cancelled','Fraud')
GROUP BY v.value HAVING n>1;
SQL);
echo "\nWhat the NEW module's own guard says for each flagged client (ispconfigconnect_otherServicesLinkedToClient):\n";
foreach ([14 => $state['svc']['G'], 15 => $state['svc']['I'], 13 => $state['svc']['N']] as $client => $svc) {
    $others = ispconfigconnect_otherServicesLinkedToClient($svc, $client);
    printf("  client #%d seen from svc #%d: %s\n", $client, $svc, json_encode($others));
}
printf("  effective ispconfig_client_id of M (svc #%d): %s\n", $state['svc']['M'], json_encode(ispconfigconnect_effectivePropertyValues('ispconfig_client_id', [$state['svc']['M']])));

up_h('2.6 Custom-field rows left on old products');
up_show_sql('2.6', $W, <<<'SQL'
SELECT v.relid service_id,h.packageid current_product,h.domainstatus,f.relid row_product,f.fieldname,v.value
FROM tblcustomfieldsvalues v
JOIN tblcustomfields f ON f.id=v.fieldid AND f.type='product' AND f.fieldname LIKE 'ispconfig\_%'
JOIN tblhosting h ON h.id=v.relid
WHERE v.value<>'' AND f.relid<>h.packageid ORDER BY v.relid,f.fieldname;
SQL);
echo "\nHow the NEW property reader resolves each listed service (admin tab rows, read-only):\n";
foreach (['F', 'K', 'L', 'M'] as $k) {
    $svc = $state['svc'][$k];
    $tab = ispconfigconnect_AdminServicesTabFields(harness_params($svc));
    printf("  %s svc #%d: Client ID=%s Username=%s Domain ID=%s | Link=%s\n", $k, $svc, $tab['ISPConfig Client ID'] ?? '?', $tab['ISPConfig Username'] ?? '?', $tab['ISPConfig Domain ID'] ?? '?', $tab['ISPConfig Link'] ?? '?');
}

up_h('2.7 Custom-field names');
up_show_sql('2.7', $W, "SELECT id,relid,fieldname FROM tblcustomfields WHERE type='product' AND fieldname LIKE 'ispconfig%';");
$names = array_unique(Capsule::table('tblcustomfields')->where('type', 'product')->where('fieldname', 'like', 'ispconfig%')->pluck('fieldname')->all());
sort($names);
up_p('distinct names', $names);
$allowed = ['ispconfig_client_id', 'ispconfig_client_username', 'ispconfig_domain_id', 'ispconfig_ftp_user_id', 'ispconfig_mail_domain_id', 'ispconfig_dns_zone_id', 'ispconfig_database_id', 'ispconfig_database_user_id', 'ispconfig_suspended_as'];
up_p('names outside the allowed list', array_values(array_diff($names, $allowed)));
up_p('ispconfig_suspended_as present yet', in_array('ispconfig_suspended_as', $names, true) ? 'yes' : 'no (expected: created on the first new-build Suspend)');

up_h('2.8 Non-positive stored ids');
up_show_sql('2.8', $W, "SELECT v.relid,v.value FROM tblcustomfieldsvalues v JOIN tblcustomfields f ON f.id=v.fieldid WHERE f.fieldname='ispconfig_client_id' AND v.value<>'' AND (v.value NOT REGEXP '^[0-9]+$' OR v.value='0');");

up_h('2.9 Website-only services with no stored website id');
up_show_sql('2.9', $W, <<<'SQL'
SELECT h.id,h.domain,h.domainstatus FROM tblhosting h
JOIN tblproducts p ON p.id=h.packageid AND p.servertype='ispconfigconnect' AND p.configoption13=''
WHERE h.domainstatus IN ('Active','Suspended') AND NOT EXISTS (
  SELECT 1 FROM tblcustomfieldsvalues v JOIN tblcustomfields f ON f.id=v.fieldid
  AND f.type='product' AND f.fieldname='ispconfig_domain_id' WHERE v.relid=h.id AND v.value<>'');
SQL);
up_p('E2 website state (Suspended in WHMCS by the old build)', up_client_brief($state['client']['E2']));

up_h('2.10 Damage left by earlier package changes');
up_show_sql('2.10', $P, "SELECT client_id,username,locked,tmp_data FROM $P.client WHERE locked='y';");
foreach (['B1', 'B2', 'G', 'I', 'K'] as $k) {
    up_show_client($state['client'][$k], $k);
}
up_p('L (Suspended website-only, had an old Change Package): web_domain.active', up_web_active($state['web']['L']));

up_h('2.11 Bandwidth figures');
up_show_sql('2.11 WHMCS', $W, "SELECT id,domain,bwusage,lastupdate FROM tblhosting WHERE server={$state['server']} AND domainstatus IN ('Active','Suspended') ORDER BY bwusage DESC;");
up_show_sql('2.11 panel', $P, "SELECT hostname,SUM(traffic_bytes)/1048576 mb FROM web_traffic WHERE YEAR(traffic_date)=YEAR(CURDATE()) AND MONTH(traffic_date)=MONTH(CURDATE()) GROUP BY hostname;");

up_h('2.12 Server metadata: the old build\'s cached row read by the new build, then refreshed');
$row = (string) Capsule::table('tblservers_remote')->where('server_id', $state['server'])->value('meta_data');
up_p('cached meta_data written by the old build', $row);
up_p('new serverSsoState() on the old row', ispconfigconnect_serverSsoState(['serverid' => $state['server']]));
$ca = ispconfigconnect_ClientArea(harness_params($state['svc']['A']));
up_p('new ClientArea(A) on the old cached row: template vars', $ca['templateVariables'] ?? $ca);
$m = harness_log_mark();
$meta = ispconfigconnect_GetRemoteMetaData(harness_server_params($state['server']));
up_p('new GetRemoteMetaData (Servers page open)', $meta);
Capsule::table('tblservers_remote')->where('server_id', $state['server'])->update(['meta_data' => json_encode($meta), 'num_accounts' => (int) ($meta['client_count'] ?? 0), 'updated_at' => date('Y-m-d H:i:s')]);
up_p('logins during the refresh', count(array_filter(up_actions($m), static fn ($a) => $a === 'login')));
up_p('new serverSsoState() after refresh', ispconfigconnect_serverSsoState(['serverid' => $state['server']]));

up_h('2.13 Migration expectations: messages about automatic adoption');
up_p('adoption resolver installed', ispconfigconnect_adoptionResolver() === null ? 'no' : 'yes');
$r = ispconfigconnect_SuspendAccount(harness_params($state['svc']['X']));
up_p('Suspend on unlinked X (read-only refusal)', $r);
$tab = ispconfigconnect_AdminServicesTabFields(harness_params($state['svc']['X']));
up_p('admin tab Link row for unlinked X', $tab['ISPConfig Link'] ?? '?');

up_h('Admin tab + client area render on EVERY old-build service under the new build (nothing the old build stored breaks it)');
$m = harness_log_mark();
foreach ($state['svc'] as $k => $svc) {
    $tab = ispconfigconnect_AdminServicesTabFields(harness_params($svc));
    $ca = ispconfigconnect_ClientArea(harness_params($svc));
    printf("  %-5s svc #%-3d rows=%d ClientID=%-10s Domain=%-4s | %s | clientarea=%s\n", $k, $svc, count($tab), $tab['ISPConfig Client ID'] ?? '(none)', $tab['ISPConfig Domain ID'] ?? '-', mb_substr(html_entity_decode((string) ($tab['ISPConfig Link'] ?? '(EMPTY TAB)')), 0, 150), isset($ca['templatefile']) ? $ca['templatefile'] : json_encode(array_keys($ca)));
}
$writes = array_filter(up_actions($m), static fn ($a) => preg_match('/_(add|update|delete|set_status)$|change_password/', $a) === 1);
up_p('panel WRITE calls made by the render pass', array_values($writes));
$err = @file_get_contents('/opt/ispc/p7/php-error.log');
up_p('panel php-error.log lines', $err === false ? 'none' : substr_count($err, "\n"));
