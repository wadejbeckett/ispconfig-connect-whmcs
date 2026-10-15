<?php
require __DIR__ . '/harness.php';
harness_reset_whmcs();
$server = harness_add_server();
echo "TestConnection: ", json_encode(ispconfigconnect_TestConnection(harness_server_params($server))), "\n";
$p1 = harness_add_product('ISPC Entire');
$s1 = harness_add_service($p1, $server, 'Test-One.Example');
$mark = harness_log_mark();
echo "Create: ", harness_call('CreateAccount', $s1), "\n";
foreach (harness_log_since($mark) as $row) { echo "  log: {$row->action} ", substr($row->response, 0, 160), "\n"; }
print_r(harness_panel_rows("SELECT client_id, username, locked, template_master FROM client"));
print_r(harness_panel_rows("SELECT domain_id, domain, active, sys_groupid FROM web_domain"));
