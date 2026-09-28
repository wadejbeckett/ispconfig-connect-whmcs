<?php

/**
 * WHMCS-side harness for running the ISPConfig Connect module against a REAL
 * ISPConfig panel (see /opt/ispc/make-panel.sh) and a REAL MySQL database
 * holding WHMCS's own table layouts, through Laravel's query builder (which is
 * what WHMCS\Database\Capsule is). No WHMCS code is used: WHMCS behaviour the
 * module depends on is re-implemented here from its documented semantics.
 *
 * Environment:
 *   HARNESS_DB    MySQL database for the WHMCS tables (default whmcs1)
 *   PANEL_PORT    port of the ISPConfig panel's built-in server (default 8081)
 *   PANEL_DB      ISPConfig database, for asserting panel state (default dbispconfig1)
 *   MODULE_DIR    module under test (default: the repository checkout)
 */

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/capsule.php';

const HARNESS_REMOTE_USER = 'whmcs';
const HARNESS_REMOTE_PASS = 'Whmcs-Remote-Pass-1';

$harnessModuleDir = getenv('MODULE_DIR') ?: '/home/user/ispconfig-connect-whmcs/modules/servers/ispconfigconnect';
spl_autoload_register(static function (string $class) use ($harnessModuleDir): void {
    $prefix = 'WHMCS\\Module\\Server\\Ispconfigconnect\\';
    if (strncmp($class, $prefix, strlen($prefix)) === 0) {
        $file = $harnessModuleDir . '/lib/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

if (!defined('WHMCS')) {
    define('WHMCS', true);
}

// WHMCS core classes the module references for usage billing - the test
// suite's doubles of WHMCS\UsageBilling\* (no Capsule double involved).
require_once '/home/user/ispconfig-connect-whmcs/tests/Module/WhmcsClassDoubles.php';

function harness_db_name(): string
{
    return getenv('HARNESS_DB') ?: 'whmcs1';
}

function harness_panel_port(): int
{
    return (int) (getenv('PANEL_PORT') ?: 8081);
}

function harness_panel_db(): string
{
    return getenv('PANEL_DB') ?: 'dbispconfig1';
}

$harnessCapsule = new \WHMCS\Database\Capsule();
$harnessCapsule->addConnection([
    'driver' => 'mysql',
    'host' => 'localhost',
    'unix_socket' => '/run/mysqld/mysqld.sock',
    'database' => harness_db_name(),
    'username' => 'root',
    'password' => '',
    'charset' => 'utf8',
    'collation' => 'utf8_unicode_ci',
    // WHMCS runs its connection without strict mode.
    'strict' => false,
]);
$harnessCapsule->setAsGlobal();

/** WHMCS's logModuleCall(): one tblmodulelog row per call. */
function logModuleCall($module, $action, $requeststring, $responsedata, $processeddata = '', $replacevars = []): void
{
    $mask = static function ($data) use ($replacevars) {
        $text = is_scalar($data) || $data === null ? (string) $data : json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        foreach ((array) $replacevars as $secret) {
            if (is_string($secret) && $secret !== '') {
                $text = str_replace($secret, '***', $text);
            }
        }

        return $text;
    };
    \WHMCS\Database\Capsule::table('tblmodulelog')->insert([
        'date' => date('Y-m-d H:i:s'),
        'module' => (string) $module,
        'action' => (string) $action,
        'request' => $mask($requeststring),
        'response' => $mask($responsedata),
        'arrdata' => $mask($processeddata),
    ]);
}

$GLOBALS['harness_hooks'] = [];
function add_hook(string $hookPoint, int $priority, $function): void
{
    $GLOBALS['harness_hooks'][] = [$hookPoint, $priority, $function];
}

/**
 * The per-service property store, behaving as WHMCS's Service\Properties does
 * for non-native keys: a key is a custom field on the service's CURRENT
 * product (type 'product', relid = packageid), matched by exact fieldname or
 * by "name|label"; save() creates a missing field as an admin-only text field
 * and upserts the value row (fieldid, relid = service id); get() returns ''
 * when the field does not exist.
 */
final class HarnessServiceProperties
{
    public function __construct(private readonly HarnessServiceModel $model)
    {
    }

    private function field(string $name): ?object
    {
        $base = \WHMCS\Database\Capsule::table('tblcustomfields')
            ->where('type', $this->model->isAddon() ? 'addon' : 'product')
            ->where('relid', $this->model->isAddon() ? $this->model->addonId : $this->model->packageId);
        $exact = (clone $base)->where('fieldname', $name)->first();

        return $exact ?? (clone $base)->where('fieldname', 'like', $name . '|%')->first();
    }

    public function get(string $name): string
    {
        $field = $this->field($name);
        if ($field === null) {
            return '';
        }
        $value = \WHMCS\Database\Capsule::table('tblcustomfieldsvalues')
            ->where('fieldid', $field->id)->where('relid', $this->model->id)->value('value');

        return $value === null ? '' : (string) $value;
    }

    /** @param array<string, mixed> $values */
    public function save(array $values): bool
    {
        foreach ($values as $name => $value) {
            $field = $this->field((string) $name);
            if ($field === null) {
                $id = \WHMCS\Database\Capsule::table('tblcustomfields')->insertGetId([
                    'type' => $this->model->isAddon() ? 'addon' : 'product',
                    'relid' => $this->model->isAddon() ? $this->model->addonId : $this->model->packageId,
                    'fieldname' => (string) $name, 'fieldtype' => 'text', 'description' => '', 'fieldoptions' => '',
                    'regexpr' => '', 'adminonly' => 'on', 'required' => '', 'showorder' => '', 'showinvoice' => '',
                ]);
            } else {
                $id = (int) $field->id;
            }
            // saveSingleCustomField(): update the value row, or insert one.
            $updated = \WHMCS\Database\Capsule::table('tblcustomfieldsvalues')
                ->where('fieldid', $id)->where('relid', $this->model->id)->update(['value' => (string) $value]);
            $exists = \WHMCS\Database\Capsule::table('tblcustomfieldsvalues')
                ->where('fieldid', $id)->where('relid', $this->model->id)->exists();
            if (!$updated && !$exists) {
                \WHMCS\Database\Capsule::table('tblcustomfieldsvalues')->insert([
                    'fieldid' => $id, 'relid' => $this->model->id, 'value' => (string) $value,
                ]);
            }
        }

        return true;
    }
}

/** The service (or addon) model WHMCS passes as $params['model']. */
final class HarnessServiceModel
{
    public HarnessServiceProperties $serviceProperties;
    public string $domainstatus;

    public function __construct(public int $id, public int $packageId, string $status, public int $addonId = 0)
    {
        $this->domainstatus = $status;
        $this->serviceProperties = new HarnessServiceProperties($this);
    }

    public function isAddon(): bool
    {
        return $this->addonId > 0;
    }

    public function isService(): bool
    {
        return !$this->isAddon();
    }

    public function getAttribute(string $key): mixed
    {
        return $key === 'domainstatus' ? $this->domainstatus : null;
    }
}

/** (Re)create the WHMCS tables in the harness database. */
function harness_reset_whmcs(): void
{
    $db = harness_db_name();
    $pdo = new PDO('mysql:unix_socket=/run/mysqld/mysqld.sock', 'root', '');
    $pdo->exec("DROP DATABASE IF EXISTS `$db`; CREATE DATABASE `$db` CHARACTER SET utf8 COLLATE utf8_unicode_ci");
    $pdo->exec("USE `$db`");
    $pdo->exec("SET SESSION sql_mode = ''");
    foreach (['tblhosting', 'tblservers', 'tblcustomfields', 'tblcustomfieldsvalues'] as $table) {
        $pdo->exec((string) file_get_contents(__DIR__ . "/schema/$table.schema.sql"));
    }
    $pdo->exec((string) file_get_contents(__DIR__ . '/schema/extra.sql'));
    \WHMCS\Database\Capsule::connection()->statement("SET SESSION sql_mode = ''");
}

/** @param array<string, mixed> $overrides */
function harness_add_server(array $overrides = []): int
{
    $row = array_replace([
        'name' => 'ISPC-TEST', 'ipaddress' => '127.0.0.1', 'assignedips' => '', 'hostname' => '127.0.0.1',
        'noc' => '', 'statusaddress' => '', 'nameserver1' => '', 'nameserver1ip' => '', 'nameserver2' => '',
        'nameserver2ip' => '', 'nameserver3' => '', 'nameserver3ip' => '', 'nameserver4' => '', 'nameserver4ip' => '',
        'nameserver5' => '', 'nameserver5ip' => '', 'maxaccounts' => 0, 'type' => 'ispconfigconnect',
        'username' => HARNESS_REMOTE_USER, 'password' => HARNESS_REMOTE_PASS, 'accesshash' => '', 'secure' => '',
        'port' => harness_panel_port(), 'active' => 1, 'disabled' => 0,
    ], $overrides);

    return (int) \WHMCS\Database\Capsule::table('tblservers')->insertGetId($row);
}

/**
 * A product using the module. Defaults follow docs/LIVE-TEST-PLAN.md P1
 * ("ISPC Entire"): template Basic (1), web server 1, website/FTP/mail/DNS/DB
 * on, suspend entire client on.
 *
 * @param array<string, string> $options configoptionN => value
 */
function harness_add_product(string $name, array $options = []): int
{
    $row = ['type' => 'hostingaccount', 'gid' => 1, 'name' => $name, 'servertype' => 'ispconfigconnect', 'servergroup' => 0];
    $defaults = [
        1 => '1', 2 => '1', 3 => 'on', 4 => 'on', 5 => 'on', 6 => 'on', 7 => '1', 8 => 'ns1.test.example',
        9 => 'ns2.test.example', 10 => 'hostmaster@test.example', 11 => 'on', 12 => 'php-fpm', 13 => 'on', 14 => '',
    ];
    for ($i = 1; $i <= 24; $i++) {
        $row['configoption' . $i] = (string) ($options['configoption' . $i] ?? ($defaults[$i] ?? ''));
    }

    return (int) \WHMCS\Database\Capsule::table('tblproducts')->insertGetId($row);
}

/** @param array<string, mixed> $overrides */
function harness_add_service(int $productId, int $serverId, string $domain, array $overrides = []): int
{
    $row = array_replace([
        'userid' => 77, 'orderid' => 1, 'packageid' => $productId, 'server' => $serverId, 'regdate' => date('Y-m-d'),
        'domain' => $domain, 'paymentmethod' => 'banktransfer', 'billingcycle' => 'Monthly', 'nextinvoicedate' => date('Y-m-d'),
        'domainstatus' => 'Pending', 'username' => '', 'password' => 'Svc-Pass-' . substr(md5($domain), 0, 10),
        'notes' => '', 'subscriptionid' => '', 'promoid' => 0, 'suspendreason' => '', 'overideautosuspend' => 0,
        'overidesuspenduntil' => '0000-00-00', 'dedicatedip' => '', 'assignedips' => '', 'ns1' => '', 'ns2' => '',
    ], $overrides);

    return (int) \WHMCS\Database\Capsule::table('tblhosting')->insertGetId($row);
}

/**
 * $params as WHMCS builds them for a service-module call.
 *
 * @param array<string, mixed> $extra merged last (e.g. addonId, a changed password)
 * @return array<string, mixed>
 */
function harness_params(int $serviceId, array $extra = []): array
{
    $service = \WHMCS\Database\Capsule::table('tblhosting')->where('id', $serviceId)->first();
    $product = \WHMCS\Database\Capsule::table('tblproducts')->where('id', $service->packageid)->first();
    $server = \WHMCS\Database\Capsule::table('tblservers')->where('id', $service->server)->first();
    $model = new HarnessServiceModel((int) $service->id, (int) $service->packageid, (string) $service->domainstatus, (int) ($extra['addonId'] ?? 0));

    $params = [
        'accountid' => (int) $service->id,
        'serviceid' => (int) $service->id,
        'addonId' => 0,
        'userid' => (int) $service->userid,
        'domain' => (string) $service->domain,
        'username' => (string) $service->username,
        'password' => (string) $service->password,
        'packageid' => (int) $service->packageid,
        'pid' => (int) $service->packageid,
        'serverid' => (int) $service->server,
        'status' => (string) $service->domainstatus,
        'type' => 'hostingaccount',
        'producttype' => 'hostingaccount',
        'moduletype' => 'ispconfigconnect',
        'configoptions' => [],
        'customfields' => [],
        'model' => $model,
        'clientsdetails' => [
            'userid' => (int) $service->userid, 'firstname' => 'Jane', 'lastname' => 'Doe', 'fullname' => 'Jane Doe',
            'companyname' => 'Acme Test', 'email' => 'jane@test.example', 'address1' => 'Main Street 1',
            'city' => 'Springfield', 'state' => 'IL', 'postcode' => '62701', 'country' => 'US',
            'phonenumber' => '+1.5551234567', 'language' => 'english',
        ],
    ];
    for ($i = 1; $i <= 24; $i++) {
        $params['configoption' . $i] = (string) $product->{'configoption' . $i};
    }
    if ($server !== null) {
        $params += [
            'server' => true,
            'serverip' => (string) $server->ipaddress,
            'serverhostname' => (string) $server->hostname,
            'serverusername' => (string) $server->username,
            'serverpassword' => (string) $server->password,
            'serveraccesshash' => (string) $server->accesshash,
            'serversecure' => (string) $server->secure,
            'serverport' => (string) $server->port,
        ];
    }

    return array_replace($params, $extra);
}

/**
 * Call a module function the way WHMCS does, and apply WHMCS's own status
 * change when the result is 'success'.
 *
 * @param array<string, mixed> $extra
 */
function harness_call(string $function, int $serviceId, array $extra = []): mixed
{
    $result = ('ispconfigconnect_' . $function)(harness_params($serviceId, $extra));
    $newStatus = [
        'CreateAccount' => 'Active', 'SuspendAccount' => 'Suspended',
        'UnsuspendAccount' => 'Active', 'TerminateAccount' => 'Terminated',
    ][$function] ?? null;
    if ($result === 'success' && $newStatus !== null && (int) ($extra['addonId'] ?? 0) === 0) {
        \WHMCS\Database\Capsule::table('tblhosting')->where('id', $serviceId)->update(['domainstatus' => $newStatus]);
    }

    return $result;
}

/** Server-level params (Test Connection, ConfigOptions loaders, Sync, UsageUpdate). */
function harness_server_params(int $serverId): array
{
    $server = \WHMCS\Database\Capsule::table('tblservers')->where('id', $serverId)->first();

    return [
        'serverid' => (int) $server->id,
        'serverip' => (string) $server->ipaddress,
        'serverhostname' => (string) $server->hostname,
        'serverusername' => (string) $server->username,
        'serverpassword' => (string) $server->password,
        'serveraccesshash' => (string) $server->accesshash,
        'serversecure' => (string) $server->secure,
        'serverport' => (string) $server->port,
    ];
}

/** A read-only connection to the ISPConfig database, for asserting panel state. */
function harness_panel(): mysqli
{
    static $db = null;
    if ($db === null) {
        $db = new mysqli('localhost', 'root', '', harness_panel_db(), 0, '/run/mysqld/mysqld.sock');
        $db->set_charset('utf8mb4');
    }

    return $db;
}

/** @return list<array<string, mixed>> */
function harness_panel_rows(string $sql): array
{
    return harness_panel()->query($sql)->fetch_all(MYSQLI_ASSOC);
}

/** The module-log rows written since $sinceId. @return list<object> */
function harness_log_since(int $sinceId): array
{
    return \WHMCS\Database\Capsule::table('tblmodulelog')->where('id', '>', $sinceId)->orderBy('id')->get()->all();
}

function harness_log_mark(): int
{
    return (int) \WHMCS\Database\Capsule::table('tblmodulelog')->max('id');
}

require_once $harnessModuleDir . '/ispconfigconnect.php';
