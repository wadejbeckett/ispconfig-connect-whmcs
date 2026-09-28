<?php
/**
 * PHASE 0 addendum (OLD build): service N is linked to client #13 (upm) - the
 * client M pointed at before its old-build Replace moved M to client #12 (upx).
 * M's old product row still holds 13.
 */
declare(strict_types=1);
require __DIR__ . '/common.php';

use WHMCS\Database\Capsule;

$state = up_state();
up_h('P0.13 N linked (old Link) to the client M left behind by its old Replace');
$svcN = harness_add_service($state['prod']['P1'], $state['server'], 'cp-relink.test', ['userid' => 123, 'username' => 'upm', 'domainstatus' => 'Active']);
$state['svc']['N'] = $svcN;
up_call('LinkAccount', $svcN, [], "old Link N as 'upm'");
up_show_props($svcN);
up_show_props($state['svc']['M'], 'M');
up_state_save($state);
