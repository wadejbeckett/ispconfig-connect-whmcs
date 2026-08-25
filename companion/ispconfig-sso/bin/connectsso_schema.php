<?php
/**
 * ispconfig-sso — create or drop the module's own tables.
 * Copyright (c) 2026 Wade Beckett. GPLv3 — see ../LICENSE.
 *
 * Reads the database credentials from the panel's own
 * interface/lib/config.inc.php, so there is nothing to configure and no second
 * place for credentials to live. Both directions are idempotent: --install uses
 * CREATE TABLE IF NOT EXISTS, --drop uses DROP TABLE IF EXISTS.
 *
 * Usage:
 *   php connectsso_schema.php --install [/usr/local/ispconfig/interface/lib/config.inc.php]
 *   php connectsso_schema.php --drop    [/usr/local/ispconfig/interface/lib/config.inc.php]
 *   php connectsso_schema.php --check   [...]   exit 0 if both tables exist
 */

$mode = null;
$conf_path = '/usr/local/ispconfig/interface/lib/config.inc.php';
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--install' || $arg === '--drop' || $arg === '--check') {
        $mode = substr($arg, 2);
    } elseif ($arg === '-h' || $arg === '--help') {
        fwrite(STDOUT, "usage: connectsso_schema.php --install|--drop|--check [config.inc.php]\n");
        exit(0);
    } else {
        $conf_path = $arg;
    }
}

if ($mode === null) {
    fwrite(STDERR, "ERROR: pass --install, --drop or --check\n");
    exit(2);
}
if (!is_readable($conf_path)) {
    fwrite(STDERR, "ERROR: ISPConfig config not readable: $conf_path\n");
    exit(1);
}

//* config.inc.php defines $conf and a pile of ISPC_* constants. It is the
//* panel's own file; we only read $conf out of it.
require $conf_path;
if (!isset($conf) || !is_array($conf) || empty($conf['db_host'])) {
    fwrite(STDERR, "ERROR: no database configuration found in $conf_path\n");
    exit(1);
}

//* PHP 8.1+ makes mysqli throw by default; turn that off so the connect_errno
//* idiom below stays live and failures are reported, not fatal.
if (function_exists('mysqli_report')) {
    mysqli_report(MYSQLI_REPORT_OFF);
}

$port = isset($conf['db_port']) ? (int)$conf['db_port'] : 3306;
try {
    $m = @new mysqli($conf['db_host'], $conf['db_user'], $conf['db_password'], $conf['db_database'], $port);
} catch (\Throwable $e) {
    $m = false;
}
if (!$m || $m->connect_errno) {
    fwrite(STDERR, "ERROR: database connection failed" . ($m ? ": " . $m->connect_error : "") . "\n");
    exit(1);
}

//* Pin the charset the way the panel's db class does (SET NAMES $conf['db_charset']).
//* A raw mysqli connection otherwise inherits mysqli.default_charset, which is
//* latin1 on some builds — harmless for the ASCII-only DDL here, but this script
//* should not be the one place in the project that connects differently.
$db_charset = !empty($conf['db_charset']) ? $conf['db_charset'] : 'utf8mb4';
if (!@$m->set_charset($db_charset) && $db_charset !== 'utf8mb4') {
    @$m->set_charset('utf8mb4');
}

$tables = array('connectsso_token', 'connectsso_attempt');

if ($mode === 'check') {
    $missing = array();
    foreach ($tables as $table) {
        $res = $m->query("SHOW TABLES LIKE '" . $m->real_escape_string($table) . "'");
        if (!$res || $res->num_rows < 1) {
            $missing[] = $table;
        }
        if ($res) {
            $res->free();
        }
    }
    $m->close();
    if ($missing) {
        fwrite(STDOUT, "  missing table(s): " . implode(', ', $missing) . "\n");
        exit(1);
    }
    fwrite(STDOUT, "  schema present\n");
    exit(0);
}

$sql_file = __DIR__ . '/../sql/' . ($mode === 'install' ? 'install.sql' : 'uninstall.sql');
if (!is_readable($sql_file)) {
    fwrite(STDERR, "ERROR: SQL file not readable: $sql_file\n");
    $m->close();
    exit(1);
}

//* The two .sql files are ours and contain only whole statements terminated by
//* ';' at end of line, with '--' comments. Split on that rather than pulling in
//* a parser, and skip comments and blanks.
$statements = array();
$buffer = '';
foreach (preg_split('/\R/', (string)file_get_contents($sql_file)) as $line) {
    $trimmed = trim($line);
    if ($trimmed === '' || substr($trimmed, 0, 2) === '--') {
        continue;
    }
    $buffer .= $line . "\n";
    if (substr($trimmed, -1) === ';') {
        $statements[] = trim($buffer);
        $buffer = '';
    }
}
if (trim($buffer) !== '') {
    $statements[] = trim($buffer);
}

foreach ($statements as $statement) {
    if (!$m->query($statement)) {
        fwrite(STDERR, "ERROR: statement failed: " . $m->error . "\n");
        $m->close();
        exit(1);
    }
}

fwrite(STDOUT, $mode === 'install'
    ? "  schema applied (" . implode(', ', $tables) . ")\n"
    : "  schema dropped (" . implode(', ', $tables) . ")\n");

$m->close();
exit(0);
