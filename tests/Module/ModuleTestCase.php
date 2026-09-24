<?php

declare(strict_types=1);

namespace Ispconfigconnect\Tests\Module;

use Ispconfigconnect\Tests\Stubs\ModuleCallLog;
use Ispconfigconnect\Tests\Support\FakeIspconfigServer;
use PHPUnit\Framework\TestCase;
use WHMCS\Module\Server\Ispconfigconnect\Api\HttpTransportInterface;
use WHMCS\Module\Server\Ispconfigconnect\Api\ServerConfig;
use WHMCS\Module\Server\Ispconfigconnect\Support\Factory;

// The entry file is plain procedural WHMCS code guarded by
// `if (!defined('WHMCS')) die;` - the constant must exist BEFORE the require.
if (!defined('WHMCS')) {
    define('WHMCS', true);
}

// WHMCS core classes the entry file talks to (Status, the usage-billing
// metric types). They are ionCube-encoded in a real install and absent here,
// so the doubles must be declared before the entry file runs - the module
// resolves them with class_exists()/defined() and would otherwise only ever
// exercise its fallback branches.
require_once __DIR__ . '/WhmcsClassDoubles.php';
require_once __DIR__ . '/../../modules/servers/ispconfigconnect/ispconfigconnect.php';

/**
 * Base for the end-to-end module tests: every test drives the REAL stack -
 * entry file -> Factory -> RestClient/SessionManager -> FakeIspconfigServer
 * (the executable ISPConfig 3.3.1p1 wire contract). Only the transport is
 * substituted, via the Factory's documented test seam.
 */
abstract class ModuleTestCase extends TestCase
{
    protected const SERVICE_PASSWORD = 'S3cret!pass';
    protected const REMOTE_PASSWORD = 'remote-api-pw';

    protected FakeIspconfigServer $fake;
    protected ServiceModelDouble $model;

    protected int $webServerId;
    protected int $masterTemplateId;
    protected int $proTemplateId;
    protected int $addonTemplateId;

    protected function setUp(): void
    {
        ModuleCallLog::reset();
        if (class_exists('\\WHMCS\\Database\\Capsule')) {
            \WHMCS\Database\Capsule::reset();
        }

        $this->fake = new FakeIspconfigServer('whmcsremote', self::REMOTE_PASSWORD);
        $this->webServerId = $this->fake->addServer('web01.example.net');
        $this->masterTemplateId = $this->fake->addClientTemplate('Basic Hosting', 'm');
        $this->proTemplateId = $this->fake->addClientTemplate('Pro Hosting', 'm');
        $this->addonTemplateId = $this->fake->addClientTemplate('Extra Mailboxes', 'a');

        $this->model = new ServiceModelDouble();

        Factory::$transportFactory =
            fn (ServerConfig $config): HttpTransportInterface => $this->fake;
    }

    protected function tearDown(): void
    {
        Factory::$transportFactory = null;
        if (class_exists('\\WHMCS\\Database\\Capsule')) {
            \WHMCS\Database\Capsule::reset();
        }
    }

    /**
     * A realistic WHMCS provisioning $params array wired to the fake panel.
     * Config options follow the frozen order of IMPLEMENTATION-NOTES §3.1.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    protected function params(array $overrides = []): array
    {
        $defaults = [
            'serviceid' => 42,
            'userid' => 77,
            'domain' => 'example.com',
            'username' => 'exampleuser',
            'password' => self::SERVICE_PASSWORD,
            'serverhostname' => 'panel.example.net',
            'serverip' => '203.0.113.10',
            'serverport' => '8080',
            'serversecure' => 'on',
            'serverusername' => 'whmcsremote',
            'serverpassword' => self::REMOTE_PASSWORD,
            'serveraccesshash' => '',
            'configoption1' => (string) $this->masterTemplateId, // Client Template
            'configoption2' => (string) $this->webServerId,      // Web Server
            'configoption3' => 'on',                             // Create Website
            'configoption4' => 'on',                             // Create FTP User
            'configoption5' => '',                               // Create Mail Domain
            'configoption6' => '',                               // Create DNS Zone
            'configoption7' => '1',                              // DNS Template ID
            'configoption8' => 'ns1.example.net',                // NS1
            'configoption9' => 'ns2.example.net',                // NS2
            'configoption10' => 'hostmaster@example.net',        // DNS Admin Email
            'configoption11' => '',                              // Create Database
            'configoption12' => 'php-fpm',                       // PHP Mode
            'configoption13' => 'on',                            // Suspend Entire Client
            'configoption14' => '',                              // Panel URL Override
            'clientsdetails' => [
                'firstname' => 'Jane',
                'lastname' => 'Doe',
                'fullname' => 'Jane Doe',
                'companyname' => 'Acme GmbH',
                'email' => 'jane@example.com',
                'address1' => 'Main Street 1',
                'city' => 'Springfield',
                'state' => 'IL',
                'postcode' => '62701',
                'country' => 'US',
                'phonenumber' => '+1.5551234567',
                'language' => 'english',
            ],
            'configoptions' => [],
            'model' => $this->model,
        ];

        return array_replace($defaults, $overrides);
    }

    /** @return list<string> method names of every wire call, in order */
    protected function calledMethods(): array
    {
        return array_column($this->fake->requestLog, 'method');
    }

    /** Request-log position marker; pair with methodsSince() to isolate one operation's wire calls. */
    protected function wireMark(): int
    {
        return count($this->fake->requestLog);
    }

    /** @return list<string> method names of the wire calls made after $mark */
    protected function methodsSince(int $mark): array
    {
        return array_column(array_slice($this->fake->requestLog, $mark), 'method');
    }

    /**
     * Asserts $secret cannot survive into the module log: for every entry,
     * apply WHMCS's replaceVars masking (each listed value is blanked out of
     * the stored text) and require the secret to be gone afterwards.
     *
     * A plain substring search would false-positive here: the fake models
     * crypt_password() as 'crypt:<clear>', so a stored HASH contains the
     * clear text as a substring - but the hash itself is listed in
     * replaceVars (Factory's password-key sweep), and masking it removes the
     * embedded clear text exactly as the real logModuleCall would.
     */
    protected function assertNoCredentialLeak(string $secret): void
    {
        self::assertNotSame('', $secret);
        foreach (ModuleCallLog::entries() as $index => $entry) {
            $haystack = print_r($entry['request'], true) . ' ' . print_r($entry['response'], true);
            $masks = $entry['replaceVars'];
            // Longest first, so a value containing another is masked whole.
            usort($masks, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
            $masked = str_replace($masks, '***', $haystack);
            self::assertStringNotContainsString(
                $secret,
                $masked,
                sprintf('module-log entry #%d (%s) leaks a credential', $index, $entry['action'])
            );
        }
    }
}
