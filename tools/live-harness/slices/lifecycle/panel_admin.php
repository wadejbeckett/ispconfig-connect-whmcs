<?php
// "By hand in ISPConfig" admin actions, performed with the panel's OWN code the
// way the interface forms do them (tform save = datalogUpdate; client_edit.php
// onAfterUpdate = func_client_lock when 'locked' changes).
//   php panel_admin.php set-active <table> <keycol> <id> <y|n>
//   php panel_admin.php lock <client_id> <y|n>
define('REMOTE_API_CALL', true);
chdir('/opt/ispc/p3/interface/web/remote');
require_once '../../lib/config.inc.php';
$conf['start_session'] = false;
require_once '../../lib/app.inc.php';
$_SESSION['s']['user']['userid'] = 1; // admin
$_SESSION['s']['user']['typ'] = 'admin';

[$self, $cmd] = $argv + [null, null];
if ($cmd === 'set-active') {
    [, , $table, $key, $id, $val] = $argv;
    $app->db->datalogUpdate($table, ['active' => $val], $key, (int) $id);
    echo "datalogUpdate($table.$key=$id, active=$val)\n";
} elseif ($cmd === 'lock') {
    [, , $clientId, $val] = $argv;
    $old = $app->db->queryOneRecord('SELECT locked FROM client WHERE client_id = ?', (int) $clientId);
    // client_edit.php: the form saves the record, then onAfterUpdate calls
    // func_client_lock() because 'locked' changed.
    $app->db->datalogUpdate('client', ['locked' => $val], 'client_id', (int) $clientId);
    if ($old['locked'] !== $val) {
        $app->functions->func_client_lock((int) $clientId, $val);
    }
    echo "client #$clientId locked: {$old['locked']} -> $val (func_client_lock " . ($old['locked'] !== $val ? 'run' : 'not run') . ")\n";
} else {
    fwrite(STDERR, "usage\n");
    exit(2);
}
