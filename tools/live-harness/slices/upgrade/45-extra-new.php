<?php
/** Extra NEW-build checks: 2.8 'relink them' for the non-numeric id; Unlink + Link as the module-only remedy for M's stale rows. */
declare(strict_types=1);
require __DIR__ . '/common.php';

use WHMCS\Database\Capsule;

$state = up_state();
$S = $state['svc'];
$W = harness_db_name();
up_h("2.8 'relink them': Zabc (ispconfig_client_id='abc') linked by name to an orphan client");
up_show_props($S['Zabc'], 'before');
up_p('    orphan client', up_client_brief($state['client']['W2']));
Capsule::table('tblhosting')->where('id', $S['Zabc'])->update(['username' => 'upw2']);
up_call('LinkAccount', $S['Zabc'], [], "Link as 'upw2'");
up_show_props($S['Zabc'], 'after');
up_show_sql('2.8 again', $W, "SELECT v.relid,v.value FROM tblcustomfieldsvalues v JOIN tblcustomfields f ON f.id=v.fieldid WHERE f.fieldname='ispconfig_client_id' AND v.value<>'' AND (v.value NOT REGEXP '^[0-9]+$' OR v.value='0');");

up_h("Module-only remedy for M's stale P1 rows: Replace with the SAME username, then Unlink + Link");
up_show_props($S['M'], 'before');
Capsule::table('tblhosting')->where('id', $S['M'])->update(['username' => 'upx']);
up_call('RelinkAccount', $S['M'], [], "Replace naming the client already stored ('upx')");
up_show_props($S['M'], 'after same-name Replace (stale P1 rows still there?)');
up_call('UnlinkAccount', $S['M']);
up_show_props($S['M'], 'after Unlink');
up_call('LinkAccount', $S['M'], [], "Link 'upx'");
up_show_props($S['M'], 'after Link');
Capsule::table('tblhosting')->where('id', $S['M'])->update(['packageid' => $state['prod']['P1']]);
$p = ispconfigconnect_serviceProperties(harness_params($S['M']));
up_p('    M moved to P1 after Unlink + Link: client_id / domain_id', json_encode([$p->clientId(), $p->domainId()]));
Capsule::table('tblhosting')->where('id', $S['M'])->update(['packageid' => $state['prod']['P3']]);
