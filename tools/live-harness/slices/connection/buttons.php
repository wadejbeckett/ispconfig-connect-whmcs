<?php
// Evidence: the admin custom button WHMCS renders for "Verify Permissions" and the function it calls.
declare(strict_types=1);
require __DIR__ . '/common.php';
$map = ispconfigconnect_AdminCustomButtonArray();
echo json_encode($map, JSON_PRETTY_PRINT), "\n";
echo 'Verify Permissions -> ispconfigconnect_' . $map['Verify Permissions'] . ' exists: ', function_exists('ispconfigconnect_' . $map['Verify Permissions']) ? 'yes' : 'no', "\n";
echo 'TestConnection exists: ', function_exists('ispconfigconnect_TestConnection') ? 'yes' : 'no', "; GetRemoteMetaData exists: ", function_exists('ispconfigconnect_GetRemoteMetaData') ? 'yes' : 'no', "; RenderRemoteMetaData exists: ", function_exists('ispconfigconnect_RenderRemoteMetaData') ? 'yes' : 'no', "\n";
