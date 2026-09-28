<?php
require __DIR__ . '/harness.php';
$s1 = 1;
foreach (['SuspendAccount', 'UnsuspendAccount'] as $fn) { echo "$fn: ", harness_call($fn, $s1), "\n"; }
print_r(harness_panel_rows("SELECT client_id, locked, LENGTH(tmp_data) AS tmp_len FROM client"));
$tab = ispconfigconnect_AdminServicesTabFields(harness_params($s1));
echo "AdminTab keys: ", implode(', ', array_keys($tab)), "\n";
$ca = ispconfigconnect_ClientArea(harness_params($s1));
echo "ClientArea: ", json_encode(array_keys($ca['templateVariables'] ?? [])), "\n";
$server = 1;
echo "ListAccounts: ", json_encode(ispconfigconnect_ListAccounts(harness_server_params($server))), "\n";
echo "ConfigOptions loader keys: ", json_encode(array_keys(ispconfigconnect_ConfigOptions())), "\n";
