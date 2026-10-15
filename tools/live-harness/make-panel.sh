#!/bin/sh
# Create an isolated ISPConfig 3.3-line panel instance (interface + remote API only)
# backed by MariaDB, served by PHP's built-in server.
#   make-panel.sh <n> <port>
# Instance n: tree /opt/ispc/p<n>, database dbispconfig<n>, API http://127.0.0.1:<port>/remote/json.php
# Remote user: whmcs / Whmcs-Remote-Pass-1 (all functions granted), admin panel login admin / admin.
set -eu
N=$1
PORT=$2
SRC=/tmp/isp-src/neuroradiology_ispconfig
DIR=/opt/ispc/p$N
DB=dbispconfig$N
DBUSER=ispc$N
DBPASS=ispc-pass-$N

rm -rf "$DIR"
mkdir -p "$DIR" /var/log/ispconfig
cp -a "$SRC/interface" "$DIR/interface"
mkdir -p "$DIR/security" "$DIR/interface/cache" "$DIR/interface/temp" "$DIR/interface/web/temp"
cp "$SRC/install/tpl/security_settings.ini.master" "$DIR/security/security_settings.ini"

mariadb -e "DROP DATABASE IF EXISTS $DB; CREATE DATABASE $DB CHARACTER SET utf8mb4;
  CREATE USER IF NOT EXISTS '$DBUSER'@'localhost' IDENTIFIED BY '$DBPASS';
  GRANT ALL ON $DB.* TO '$DBUSER'@'localhost'; FLUSH PRIVILEGES;"
mariadb "$DB" < "$SRC/install/sql/ispconfig3.sql"

sed -e "s/{mysql_server_host}/localhost/; s/{mysql_server_port}/3306/; s/{mysql_server_database}/$DB/" \
    -e "s/{mysql_server_ispconfig_user}/$DBUSER/; s/{mysql_server_ispconfig_password}/$DBPASS/" \
    -e "s/{mysql_master_server_host}/localhost/; s/{mysql_master_server_port}/3306/; s/{mysql_master_server_database}/$DB/" \
    -e "s/{mysql_master_server_ispconfig_user}/$DBUSER/; s/{mysql_master_server_ispconfig_password}/$DBPASS/" \
    -e "s/{server_id}/1/; s/{ispconfig_log_priority}/2/; s/{theme}/default/; s/{language}/en/" \
    -e "s/{language_file_import_enabled}/true/; s#{timezone}#UTC#" \
    "$SRC/install/tpl/config.inc.php.master" > "$DIR/interface/lib/config.inc.php"

php -r '
$db = new mysqli("localhost", $argv[1], $argv[2], $argv[3]);
$db->set_charset("utf8mb4");
$sys = file_get_contents($argv[4] . "/install/tpl/system.ini.master");
$srv = file_get_contents($argv[4] . "/install/tpl/server.ini.master");
$st = $db->prepare("UPDATE sys_ini SET config = ? WHERE sysini_id = 1"); $st->bind_param("s", $sys); $st->execute();
$st = $db->prepare("INSERT INTO server (server_id, sys_userid, sys_groupid, sys_perm_user, sys_perm_group, sys_perm_other, server_name, mail_server, web_server, dns_server, file_server, db_server, vserver_server, config, updated, active, dbversion, firewall_server, proxy_server) VALUES (1,1,1,\"riud\",\"riud\",\"r\",\"panel.test.example\",1,1,1,1,1,0,?,0,1,1,0,0)");
$st->bind_param("s", $srv); $st->execute();
$db->query("INSERT INTO server_ip (sys_userid, sys_groupid, sys_perm_user, sys_perm_group, sys_perm_other, server_id, client_id, ip_type, ip_address, virtualhost, virtualhost_port) VALUES (1,1,\"riud\",\"riud\",\"\",1,0,\"IPv4\",\"203.0.113.10\",\"y\",\"80,443\")");
// Remote user with every remote function group granted.
$functions = [];
foreach (glob($argv[4] . "/interface/lib/classes/remote.d/*.inc.php") as $f) {
    if (preg_match_all("/function\s+([a-z0-9_]+)\s*\(/i", file_get_contents($f), $m)) { $functions = array_merge($functions, $m[1]); }
}
foreach (glob($argv[4] . "/interface/web/*/lib/remote.conf.php") as $f) {
    $function_list = []; include $f;
    foreach ($function_list as $k => $v) { $functions = array_merge($functions, explode(",", $v)); }
}
$functions = array_values(array_unique(array_filter(array_map("trim", $functions))));
$groups = [];
foreach (glob($argv[4] . "/interface/web/*/lib/remote.conf.php") as $f) { $function_list = []; include $f; $groups = array_merge($groups, array_keys($function_list)); }
$remote = implode(";", array_unique($groups));
$pw = password_hash("Whmcs-Remote-Pass-1", PASSWORD_DEFAULT);
$st = $db->prepare("INSERT INTO remote_user (sys_userid, sys_groupid, sys_perm_user, sys_perm_group, sys_perm_other, remote_username, remote_password, remote_access, remote_ips, remote_functions) VALUES (1,1,\"riud\",\"riud\",\"\",\"whmcs\",?,\"y\",\"\",?)");
$st->bind_param("ss", $pw, $remote); $st->execute();
// Two master client templates with visibly different limits.
$db->query("INSERT INTO client_template (sys_userid, sys_groupid, sys_perm_user, sys_perm_group, sys_perm_other, template_name, template_type, limit_web_domain, limit_web_quota, limit_traffic_quota, limit_ftp_user, limit_maildomain, limit_mailbox, limit_database, limit_dns_zone, web_servers, mail_servers, db_servers, dns_servers) VALUES (1,1,\"riud\",\"riud\",\"\",\"Basic\",\"m\",1,1000,10000,2,1,5,1,1,\"1\",\"1\",\"1\",\"1\")");
$db->query("INSERT INTO client_template (sys_userid, sys_groupid, sys_perm_user, sys_perm_group, sys_perm_other, template_name, template_type, limit_web_domain, limit_web_quota, limit_traffic_quota, limit_ftp_user, limit_maildomain, limit_mailbox, limit_database, limit_dns_zone, web_servers, mail_servers, db_servers, dns_servers) VALUES (1,1,\"riud\",\"riud\",\"\",\"Pro\",\"m\",10,20000,200000,10,10,100,10,10,\"1\",\"1\",\"1\",\"1\")");
echo "remote groups: $remote\n";
' "$DBUSER" "$DBPASS" "$DB" "$SRC"

# PHP built-in server for the panel (the module speaks plain HTTP when Secure is unticked).
nohup /usr/bin/php8.3 -d display_errors=0 -d log_errors=1 -d error_log="$DIR/php-error.log" \
    -S 127.0.0.1:$PORT -t "$DIR/interface/web" > "$DIR/server.log" 2>&1 &
echo $! > "$DIR/server.pid"
sleep 1
echo "panel $N ready: http://127.0.0.1:$PORT/remote/json.php (db $DB)"
