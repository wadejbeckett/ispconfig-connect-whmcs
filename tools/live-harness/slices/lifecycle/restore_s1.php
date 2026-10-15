<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';
$st = lt_state();
$S1 = $st['S1'];
out('S1 before: ' . json_encode(svc($S1)) . ' ' . json_encode(susp_as($S1)));
print_props($S1);
$r = lt_call('UnsuspendAccount', $S1);
\WHMCS\Database\Capsule::update("UPDATE tblcustomfieldsvalues v JOIN tblcustomfields f ON f.id=v.fieldid SET v.value='' WHERE f.type='product' AND f.fieldname='ispconfig_suspended_as' AND v.relid=?", [$S1]);
print_snap($st['N1'], '(S1 restored)');
out('S1 after: ' . json_encode(svc($S1)) . ' ' . json_encode(susp_as($S1)));
