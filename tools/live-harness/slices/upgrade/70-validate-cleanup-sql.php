<?php
/**
 * Validate a candidate plan-2.6 cleanup on a SCRATCH copy of the WHMCS DB as it
 * stood right after the upgrade (snap-pre-phase2): the reader's view of every
 * service must not change, and a later product move back must no longer
 * resurrect stale values. HARNESS_DB=whmcs7_fix, NEW build (pure DB functions only).
 */
declare(strict_types=1);
require __DIR__ . '/common.php';

use WHMCS\Database\Capsule;

if (harness_db_name() !== 'whmcs7_fix') {
    fwrite(STDERR, "run with HARNESS_DB=whmcs7_fix\n");
    exit(2);
}
$keys = ['ispconfig_client_id', 'ispconfig_client_username', 'ispconfig_domain_id', 'ispconfig_ftp_user_id', 'ispconfig_mail_domain_id', 'ispconfig_dns_zone_id', 'ispconfig_database_id', 'ispconfig_database_user_id'];
$ids = Capsule::table('tblhosting')->pluck('id')->map(fn ($i) => (int) $i)->all();
$view = static function () use ($keys, $ids): array {
    $v = [];
    foreach ($keys as $k) {
        foreach ($ids as $id) {
            // the value WHMCS get() + the module's fallback reader would return
            $props = ispconfigconnect_serviceProperties(harness_params($id));
            $m = 'get' . $k;
            $ref = new ReflectionMethod($props, 'stringValue');
            $v["$id.$k"] = $ref->invoke($props, $k);
        }
    }

    return $v;
};
$before = $view();
$sql = <<<'SQL'
UPDATE tblcustomfieldsvalues v
JOIN tblcustomfields f ON f.id=v.fieldid AND f.type='product' AND f.fieldname LIKE 'ispconfig\_%'
JOIN tblhosting h ON h.id=v.relid
JOIN tblcustomfields fc ON fc.type='product' AND fc.relid=h.packageid AND fc.fieldname=f.fieldname
JOIN tblcustomfieldsvalues vc ON vc.fieldid=fc.id AND vc.relid=h.id
SET v.value=''
WHERE f.relid<>h.packageid AND v.value<>'' AND (vc.value<>'' OR vc.id>v.id);
SQL;
echo "candidate cleanup SQL:\n$sql\n";
echo up_sql('whmcs7_fix', $sql . "\nSELECT ROW_COUNT() rows_blanked;");
$after = $view();
$diff = array_filter(array_keys($before), static fn ($k) => $before[$k] !== $after[$k]);
echo "reader view changed for: ", json_encode(array_values($diff)), "\n";
echo up_sql('whmcs7_fix', "SELECT v.relid service_id,h.packageid current_product,f.relid row_product,f.fieldname,v.value FROM tblcustomfieldsvalues v JOIN tblcustomfields f ON f.id=v.fieldid AND f.type='product' AND f.fieldname LIKE 'ispconfig\\_%' JOIN tblhosting h ON h.id=v.relid WHERE v.value<>'' AND f.relid<>h.packageid ORDER BY v.relid,f.fieldname;");
foreach ([11 => 'F', 13 => 'M'] as $svc => $k) {
    Capsule::table('tblhosting')->where('id', $svc)->update(['packageid' => 1]);
    $p = ispconfigconnect_serviceProperties(harness_params($svc));
    printf("%s moved back to P1 after the cleanup: client_id=%s domain_id=%s\n", $k, json_encode($p->clientId()), json_encode($p->domainId()));
}
