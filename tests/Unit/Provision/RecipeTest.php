<?php

declare(strict_types=1);

namespace Ispconfigconnect\Tests\Unit\Provision;

use Ispconfigconnect\Tests\Support\FakeIspconfigServer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WHMCS\Module\Server\Ispconfigconnect\Api\Exception\RemoteFaultException;
use WHMCS\Module\Server\Ispconfigconnect\Api\RestClient;
use WHMCS\Module\Server\Ispconfigconnect\Api\SessionManager;
use WHMCS\Module\Server\Ispconfigconnect\Provision\CreateResult;
use WHMCS\Module\Server\Ispconfigconnect\Provision\ProvisioningException;
use WHMCS\Module\Server\Ispconfigconnect\Provision\Recipe;
use WHMCS\Module\Server\Ispconfigconnect\Support\ProductConfig;
use WHMCS\Module\Server\Ispconfigconnect\Support\ServiceParams;

/**
 * Recipe is exercised through the full real stack - Recipe -> SessionManager
 * -> RestClient -> FakeIspconfigServer (the executable 3.3.1p1 wire contract)
 * - never against mocks, so every request here is a valid remote/json.php
 * round trip.
 */
#[CoversClass(Recipe::class)]
#[CoversClass(CreateResult::class)]
#[CoversClass(ProvisioningException::class)]
final class RecipeTest extends TestCase
{
    private function recipe(FakeIspconfigServer $fake): Recipe
    {
        return new Recipe(new SessionManager(new RestClient($fake), 'remote', 'secret'));
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function product(array $overrides = []): ProductConfig
    {
        $defaults = [
            'clientTemplateId' => 1,
            'webServerId' => 2,
            'createWebsite' => true,
            'createFtpUser' => true,
            'createMailDomain' => false,
            'createDnsZone' => false,
            'dnsTemplateId' => 1,
            'ns1' => 'ns1.example.net',
            'ns2' => 'ns2.example.net',
            'dnsAdminEmail' => 'hostmaster@example.net',
            'createDatabase' => false,
            'phpMode' => 'php-fpm',
            'suspendEntireClient' => true,
            'panelUrlOverride' => null,
        ];

        return new ProductConfig(...array_merge($defaults, $overrides));
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function service(array $overrides = []): ServiceParams
    {
        $defaults = [
            'serviceId' => 42,
            'domain' => 'example.com',
            'username' => 'exampleuser',
            'password' => 'S3cret!pass',
            'clientDetails' => [
                'name' => 'Jane Doe',
                'company' => 'Acme GmbH',
                'email' => 'jane@example.com',
                'address1' => 'Main Street 1',
                'city' => 'Springfield',
                'state' => 'IL',
                'postcode' => '62701',
                'country' => 'US',
                'phone' => '+1.5551234567',
            ],
            'whmcsClientId' => 77,
            'serverIp' => '203.0.113.10',
            'language' => 'english',
            'configOptions' => [],
        ];

        return new ServiceParams(...array_merge($defaults, $overrides));
    }

    /**
     * Advance the fake's per-store id counters through the wire (a seed
     * client plus records owned by it) so that every id asserted on the
     * real create is distinct - a CreateResult field wired to the wrong
     * call could otherwise pass by all ids being 1.
     */
    private function advanceIdCounters(FakeIspconfigServer $fake): void
    {
        $client = new RestClient($fake);
        $sessionId = $client->login('remote', 'secret');
        $call = static fn (string $method, array $params): mixed => $client->call(
            $method,
            ['session_id' => $sessionId] + $params
        );

        $seedClientId = $call('client_add', ['reseller_id' => 0, 'params' => [
            'contact_name' => 'Seed Client',
            'username' => 'seeduser',
            'email' => 'seed@example.org',
            // NOTEMPTY over the remote API (client.tform.php) - the panel
            // SELECT default does not apply to API calls.
            'language' => 'en',
        ]]);
        $webDefaults = [
            'server_id' => 2, 'hd_quota' => -1, 'traffic_quota' => -1,
            // REGEX-without-allowempty fields (web_vhost_domain.tform.php).
            'pm_process_idle_timeout' => 10, 'pm_max_requests' => 0,
            'http_port' => 80, 'https_port' => 443,
        ];
        $call('sites_web_domain_add', ['client_id' => $seedClientId, 'readonly' => false,
            'params' => ['domain' => 'seed1.example.org'] + $webDefaults]);
        $call('sites_web_domain_add', ['client_id' => $seedClientId, 'readonly' => false,
            'params' => ['domain' => 'seed2.example.org'] + $webDefaults]);
        $call('sites_ftp_user_add', ['client_id' => $seedClientId, 'params' => [
            'username' => 'seedftp', 'quota_size' => -1,
            'uid' => 'web1', 'gid' => 'client1', 'dir' => '/var/www/clients/client1/web1',
        ]]);
        $call('mail_domain_add', ['client_id' => $seedClientId, 'params' => ['domain' => 'seed1.example.org']]);
        $call('sites_database_user_add', ['client_id' => $seedClientId, 'params' => ['database_user' => 'c1_seed']]);
        $client->logout($sessionId);
    }

    // ── create ─────────────────────────────────────────────────────────

    public function testFullCreateProvisionsEveryStageAndReturnsAllIds(): void
    {
        $fake = new FakeIspconfigServer();
        $masterTemplateId = $fake->addClientTemplate('Basic', 'm');
        $addonTemplateId = $fake->addClientTemplate('Extra Space', 'a');
        $this->advanceIdCounters($fake);
        $logOffset = count($fake->requestLog);

        $result = $this->recipe($fake)->create(
            $this->product([
                'clientTemplateId' => $masterTemplateId,
                'createMailDomain' => true,
                'createDnsZone' => true,
                'createDatabase' => true,
            ]),
            $this->service(['configOptions' => ['ISPConfig Extra Space' => '1']])
        );

        // Ids are distinct by seeding, so a swapped field cannot pass.
        self::assertSame(2, $result->clientId);
        self::assertSame('exampleuser', $result->username);
        self::assertSame(3, $result->domainId);
        self::assertSame(2, $result->ftpUserId);
        self::assertSame(2, $result->mailDomainId);
        self::assertSame(1, $result->dnsZoneId);
        self::assertSame(2, $result->databaseUserId);
        self::assertSame(1, $result->databaseId);

        $client = $fake->clients()[2];
        self::assertSame('exampleuser', $client['username']);
        self::assertSame($masterTemplateId, $client['template_master']);

        // Assignment record shape per client_template_assigned
        // (client.inc.php client_template_additional_add()).
        self::assertSame(
            [1 => ['assigned_template_id' => 1, 'client_id' => 2, 'client_template_id' => $addonTemplateId]],
            $fake->templateAssignments()
        );

        $site = $fake->sites()[3];
        self::assertSame('example.com', $site['domain']);
        self::assertSame(2, $site['client_id']);
        // The four advanced-tab REGEX fields without allowempty
        // (web_vhost_domain.tform.php) - absent values fail validation over
        // the remote API, so create must always send them (stock-example
        // values, remoting_client/examples/sites_web_domain_add.php).
        self::assertSame(10, $site['pm_process_idle_timeout']);
        self::assertSame(0, $site['pm_max_requests']);
        self::assertSame(80, $site['http_port']);
        self::assertSame(443, $site['https_port']);

        // uid/gid/dir must come from the FETCHED web domain record.
        $ftp = $fake->ftpUsers()[2];
        self::assertSame('exampleuser', $ftp['username']);
        self::assertSame(3, $ftp['parent_domain_id']);
        self::assertSame('web3', $ftp['uid']);
        self::assertSame('client2', $ftp['gid']);
        self::assertSame('/var/www/clients/client2/web3', $ftp['dir']);

        $mail = $fake->mailDomains()[2];
        self::assertSame('example.com', $mail['domain']);
        self::assertSame(2, $mail['client_id']);

        // dns_templatezone_add returns bool; the id must have been resolved
        // via dns_zone_get_id against the trailing-dot origin the template
        // stores ({DOMAIN}. - install/sql/ispconfig3.sql).
        self::assertSame('example.com.', $fake->dnsZones()[1]['origin']);

        self::assertSame('c2_example', $fake->databaseUsers()[2]['database_user']);
        $database = $fake->databases()[1];
        self::assertSame('c2_example', $database['database_name']);
        self::assertSame(2, $database['database_user_id']);
        self::assertSame(2, $database['client_id']);

        self::assertSame(
            [
                'login',
                'client_add',
                'client_templates_get_all',
                'client_template_additional_add',
                'sites_web_domain_add',
                'sites_web_domain_get',
                'sites_ftp_user_add',
                'mail_domain_add',
                'dns_templatezone_add',
                'dns_zone_get_id',
                'sites_database_user_add',
                'sites_database_add',
            ],
            array_column(array_slice($fake->requestLog, $logOffset), 'method')
        );
    }

    public function testDefaultsOnlyCreateNeverCallsMailDnsOrDatabaseFunctions(): void
    {
        $fake = new FakeIspconfigServer();

        $result = $this->recipe($fake)->create($this->product(), $this->service());

        self::assertSame(
            ['login', 'client_add', 'sites_web_domain_add', 'sites_web_domain_get', 'sites_ftp_user_add'],
            array_column($fake->requestLog, 'method')
        );
        self::assertSame(1, $result->clientId);
        self::assertSame(1, $result->domainId);
        self::assertSame(1, $result->ftpUserId);
        self::assertNull($result->mailDomainId);
        self::assertNull($result->dnsZoneId);
        self::assertNull($result->databaseUserId);
        self::assertNull($result->databaseId);
    }

    public function testCreateWithEmptyWhmcsLanguageStillSatisfiesTheNotemptyValidator(): void
    {
        $fake = new FakeIspconfigServer();

        // 'language' is NOTEMPTY in client.tform.php and the remote API has
        // no server-side default - the mapper's 'en' fallback must reach the
        // wire or client_add faults.
        $result = $this->recipe($fake)->create($this->product(), $this->service(['language' => '']));

        self::assertSame('en', $fake->clients()[$result->clientId]['language']);
    }

    public function testCreateFailureAtClientAddPropagatesUnwrappedWithoutRollback(): void
    {
        $fake = new FakeIspconfigServer();
        $this->advanceIdCounters($fake);

        try {
            // 'seeduser' already exists -> client_add data_processing_error.
            $this->recipe($fake)->create($this->product(), $this->service(['username' => 'seeduser']));
            self::fail('create() must rethrow the client_add fault');
        } catch (\Throwable $fault) {
            self::assertNotInstanceOf(ProvisioningException::class, $fault);
            self::assertInstanceOf(RemoteFaultException::class, $fault);
        }

        // Nothing was created, so nothing may have been deleted.
        self::assertNotContains('client_delete_everything', array_column($fake->requestLog, 'method'));
        self::assertCount(1, $fake->clients());
    }

    // ── rollback ───────────────────────────────────────────────────────

    public function testCreateRollsBackClientWhenALaterStageFails(): void
    {
        $fake = new FakeIspconfigServer();
        $fake->failNextWith('sites_ftp_user_add', 'database_error<br />INSERT INTO ftp_user failed');

        try {
            $this->recipe($fake)->create($this->product(), $this->service());
            self::fail('create() must throw after the ftp stage fault');
        } catch (ProvisioningException $exception) {
            self::assertSame('ftp_user', $exception->stage);
            self::assertTrue($exception->rollbackSucceeded);
            // The ORIGINAL fault message must survive the wrapping.
            self::assertStringContainsString('database_error<br />INSERT INTO ftp_user failed', $exception->getMessage());
            self::assertInstanceOf(RemoteFaultException::class, $exception->getPrevious());
            self::assertSame('database_error<br />INSERT INTO ftp_user failed', $exception->getPrevious()->getMessage());
        }

        // client_delete_everything cascaded: no client, no orphaned site.
        self::assertSame([], $fake->clients());
        self::assertSame([], $fake->sites());
        self::assertSame([], $fake->ftpUsers());
        $methods = array_column($fake->requestLog, 'method');
        self::assertSame('client_delete_everything', end($methods));
    }

    public function testCreateReportsWhenTheRollbackItselfFails(): void
    {
        $fake = new FakeIspconfigServer();
        $fake->failNextWith('sites_ftp_user_add', 'database_error<br />original fault');
        $fake->failNextWith('client_delete_everything', 'database_error<br />delete failed too');

        try {
            $this->recipe($fake)->create($this->product(), $this->service());
            self::fail('create() must throw after the ftp stage fault');
        } catch (ProvisioningException $exception) {
            self::assertSame('ftp_user', $exception->stage);
            self::assertFalse($exception->rollbackSucceeded);
            self::assertStringContainsString('database_error<br />original fault', $exception->getMessage());
        }

        // The failed rollback left the client (and its site) orphaned.
        self::assertArrayHasKey(1, $fake->clients());
        self::assertArrayHasKey(1, $fake->sites());
    }

    /**
     * Drive the fake directly (outside the Recipe under test) - e.g. to
     * disable an object the way a panel admin would before a suspension.
     *
     * @param array<string, mixed> $params
     */
    private function rawCall(FakeIspconfigServer $fake, string $method, array $params): mixed
    {
        $client = new RestClient($fake);
        $sessionId = $client->login('remote', 'secret');
        $result = $client->call($method, ['session_id' => $sessionId] + $params);
        $client->logout($sessionId);

        return $result;
    }

    // ── suspend / unsuspend ────────────────────────────────────────────

    public function testEntireClientSuspendLocksAndTheCascadeDisablesTheWebsite(): void
    {
        $fake = new FakeIspconfigServer();
        $recipe = $this->recipe($fake);
        $result = $recipe->create($this->product(), $this->service());
        $logOffset = count($fake->requestLog);

        $recipe->suspend($result->clientId, $result->domainId, true);

        self::assertSame('y', $fake->clients()[$result->clientId]['locked']);
        // func_client_lock (functions.inc.php) disables ALL client objects -
        // the website goes inactive through the cascade, so no separate
        // sites_web_domain_set_status call is made (it would poison the
        // remembered prev_active state on unsuspend).
        self::assertSame('n', $fake->sites()[$result->domainId]['active']);
        self::assertSame('n', $fake->ftpUsers()[$result->ftpUserId]['active']);
        self::assertSame(
            ['client_get', 'client_template_additional_get', 'client_update'],
            array_column(array_slice($fake->requestLog, $logOffset), 'method')
        );
    }

    public function testEntireClientSuspendThenUnsuspendPreservesPasswordAndAddonAssignments(): void
    {
        $fake = new FakeIspconfigServer();
        $fake->addClientTemplate('Basic', 'm');
        $addonId = $fake->addClientTemplate('Extra Space', 'a');
        $recipe = $this->recipe($fake);
        $result = $recipe->create(
            $this->product(['clientTemplateId' => 1]),
            $this->service(['configOptions' => ['ISPConfig Extra Space' => '1']])
        );
        // Single-crypt marker from client_add - the regression fixture.
        self::assertSame('crypt:S3cret!pass', $fake->clients()[$result->clientId]['password']);

        $recipe->suspend($result->clientId, $result->domainId, true);
        $recipe->unsuspend($result->clientId, $result->domainId, true);

        // Without _ispconfig_pw_crypted=1 each client_update would re-crypt
        // the stored hash (client.inc.php pre-merge + remoting_lib getSQL);
        // without template_additional=<current ids> the merged '' column
        // would wipe client_template_assigned (client_templates.inc.php).
        self::assertSame('crypt:S3cret!pass', $fake->clients()[$result->clientId]['password']);
        self::assertSame(
            [$addonId],
            array_column($fake->templateAssignments(), 'client_template_id')
        );
        self::assertSame('n', $fake->clients()[$result->clientId]['locked']);
        self::assertSame('y', $fake->sites()[$result->domainId]['active']);
    }

    public function testDoubleSuspendThenUnsuspendStillRestoresTheWebsite(): void
    {
        $fake = new FakeIspconfigServer();
        $recipe = $this->recipe($fake);
        $result = $recipe->create($this->product(), $this->service());

        $recipe->suspend($result->clientId, $result->domainId, true);
        $logOffset = count($fake->requestLog);
        // Re-locking an already-locked client would overwrite tmp_data with
        // everything-inactive (func_client_lock, functions.inc.php) - the
        // guard must skip the second lock after the client_get check.
        $recipe->suspend($result->clientId, $result->domainId, true);
        self::assertSame(
            ['client_get'],
            array_column(array_slice($fake->requestLog, $logOffset), 'method')
        );

        $recipe->unsuspend($result->clientId, $result->domainId, true);

        self::assertSame('n', $fake->clients()[$result->clientId]['locked']);
        self::assertSame('y', $fake->sites()[$result->domainId]['active']);
        self::assertSame([], $fake->clientTmpData($result->clientId));
    }

    public function testUnsuspendKeepsObjectsThatWereInactiveBeforeTheSuspension(): void
    {
        $fake = new FakeIspconfigServer();
        $recipe = $this->recipe($fake);
        $result = $recipe->create($this->product(), $this->service());
        // An admin disabled the website independently of billing.
        $this->rawCall($fake, 'sites_web_domain_set_status', [
            'primary_id' => $result->domainId, 'status' => 'inactive',
        ]);

        $recipe->suspend($result->clientId, $result->domainId, true);
        $recipe->unsuspend($result->clientId, $result->domainId, true);

        // func_client_lock remembered the site as previously-inactive; the
        // unlock must not resurrect it - which also proves unsuspend makes
        // no sites_web_domain_set_status call in entire-client mode.
        self::assertSame('n', $fake->clients()[$result->clientId]['locked']);
        self::assertSame('n', $fake->sites()[$result->domainId]['active']);
        self::assertSame('y', $fake->ftpUsers()[$result->ftpUserId]['active']);
    }

    public function testUnsuspendOfANotLockedClientMakesNoClientUpdate(): void
    {
        $fake = new FakeIspconfigServer();
        $recipe = $this->recipe($fake);
        $result = $recipe->create($this->product(), $this->service());
        // Deliberately-disabled object that a stray client_update's
        // truthy-'n' lock handling would wrongly re-enable
        // (client.inc.php: if($params['locked']) runs for 'n' too).
        $this->rawCall($fake, 'sites_web_domain_set_status', [
            'primary_id' => $result->domainId, 'status' => 'inactive',
        ]);
        $logOffset = count($fake->requestLog);

        $recipe->unsuspend($result->clientId, $result->domainId, true);

        self::assertSame(
            ['client_get'],
            array_column(array_slice($fake->requestLog, $logOffset), 'method')
        );
        self::assertSame('n', $fake->sites()[$result->domainId]['active']);
    }

    public function testWebsiteOnlySuspendNeverTouchesTheClientRecord(): void
    {
        $fake = new FakeIspconfigServer();
        $recipe = $this->recipe($fake);
        $result = $recipe->create($this->product(), $this->service());
        $logOffset = count($fake->requestLog);

        $recipe->suspend($result->clientId, $result->domainId, false);

        // 'suspend disables the website only' is impossible via locked='y'
        // (the lock cascades to every object - functions.inc.php), so this
        // mode must not call client_update at all.
        self::assertSame(
            ['sites_web_domain_set_status'],
            array_column(array_slice($fake->requestLog, $logOffset), 'method')
        );
        self::assertSame('n', $fake->clients()[$result->clientId]['locked']);
        self::assertSame('n', $fake->sites()[$result->domainId]['active']);
        self::assertSame('y', $fake->ftpUsers()[$result->ftpUserId]['active']);

        $recipe->unsuspend($result->clientId, $result->domainId, false);

        self::assertSame('n', $fake->clients()[$result->clientId]['locked']);
        self::assertSame('y', $fake->sites()[$result->domainId]['active']);
    }

    public function testWebsiteOnlySuspendWithoutADomainIdMakesNoCallsAtAll(): void
    {
        $fake = new FakeIspconfigServer();
        $recipe = $this->recipe($fake);
        $result = $recipe->create($this->product(), $this->service());
        $logOffset = count($fake->requestLog);

        $recipe->suspend($result->clientId, null, false);
        $recipe->unsuspend($result->clientId, null, false);

        self::assertSame([], array_slice($fake->requestLog, $logOffset));
        self::assertSame('y', $fake->sites()[$result->domainId]['active']);
    }

    // ── terminate ──────────────────────────────────────────────────────

    public function testTerminateDeletesTheClientAndEverythingItOwns(): void
    {
        $fake = new FakeIspconfigServer();
        $recipe = $this->recipe($fake);
        $result = $recipe->create($this->product(), $this->service());

        $recipe->terminate($result->clientId);

        self::assertSame([], $fake->clients());
        self::assertSame([], $fake->sites());
        self::assertSame([], $fake->ftpUsers());
        $methods = array_column($fake->requestLog, 'method');
        self::assertSame('client_delete_everything', end($methods));
    }

    // ── changePassword ─────────────────────────────────────────────────

    public function testChangePasswordWithoutIdsRotatesThePanelPasswordOnly(): void
    {
        $fake = new FakeIspconfigServer();
        $recipe = $this->recipe($fake);
        $result = $recipe->create($this->product(), $this->service());
        $ftpPasswordBefore = $fake->ftpUsers()[$result->ftpUserId]['password'];
        $logOffset = count($fake->requestLog);

        $recipe->changePassword($result->clientId, 'N3w!password');

        // client_change_password crypts the new clear server-side
        // (client.inc.php) - single crypt marker, no double-crypt.
        self::assertSame('crypt:N3w!password', $fake->clients()[$result->clientId]['password']);
        self::assertSame($ftpPasswordBefore, $fake->ftpUsers()[$result->ftpUserId]['password']);
        self::assertSame(
            ['client_change_password'],
            array_column(array_slice($fake->requestLog, $logOffset), 'method')
        );
    }

    public function testChangePasswordRotatesFtpAndDatabaseUserCredentialsWhenIdsGiven(): void
    {
        $fake = new FakeIspconfigServer();
        $recipe = $this->recipe($fake);
        $result = $recipe->create(
            $this->product(['createDatabase' => true]),
            $this->service()
        );
        $logOffset = count($fake->requestLog);

        $recipe->changePassword(
            $result->clientId,
            'N3w!password',
            $result->ftpUserId,
            $result->databaseUserId
        );

        // The module provisioned FTP and DB users with the service password,
        // so a password change must rotate them too. Partial updates are safe
        // here - plain updateQuery, no client_update-style pre-merge
        // (remote.d/sites.inc.php sites_ftp_user_update /
        // sites_database_user_update). Param keys: 'password'
        // (ftp_user.tform.php, PASSWORD/CRYPT) and 'database_password'
        // (database_user.tform.php, PASSWORD/MYSQL).
        self::assertSame('crypt:N3w!password', $fake->clients()[$result->clientId]['password']);
        self::assertSame('crypt:N3w!password', $fake->ftpUsers()[$result->ftpUserId]['password']);
        self::assertSame(
            'crypt:N3w!password',
            $fake->databaseUsers()[$result->databaseUserId]['database_password']
        );
        self::assertSame(
            ['client_change_password', 'sites_ftp_user_update', 'sites_database_user_update'],
            array_column(array_slice($fake->requestLog, $logOffset), 'method')
        );
        // The updates went to the RIGHT records - nothing else changed.
        self::assertSame('exampleuser', $fake->ftpUsers()[$result->ftpUserId]['username']);
        self::assertSame(
            'c1_example',
            $fake->databaseUsers()[$result->databaseUserId]['database_user']
        );
    }

    // ── changePackage ──────────────────────────────────────────────────

    public function testChangePackageAppliesTheAddonDeltaInOneClientUpdate(): void
    {
        $fake = new FakeIspconfigServer();
        $basicId = $fake->addClientTemplate('Basic', 'm');
        $proId = $fake->addClientTemplate('Pro', 'm');
        $spaceId = $fake->addClientTemplate('Extra Space', 'a');
        $mailId = $fake->addClientTemplate('Extra Mail', 'a');
        $recipe = $this->recipe($fake);
        $result = $recipe->create(
            $this->product(['clientTemplateId' => $basicId]),
            $this->service(['configOptions' => ['ISPConfig Extra Space' => '1']])
        );
        self::assertSame(
            [$spaceId],
            array_column($fake->templateAssignments(), 'client_template_id')
        );
        $logOffset = count($fake->requestLog);

        $recipe->changePackage($result->clientId, $this->product(['clientTemplateId' => $proId]), [$mailId]);

        // ONE protective client_update: template_additional carries the
        // DESIRED bare ids and update_client_templates' old-style count-diff
        // applies the delta atomically (client_templates.inc.php) - no
        // client_template_additional_add/_delete calls.
        self::assertSame(
            ['client_update'],
            array_column(array_slice($fake->requestLog, $logOffset), 'method')
        );
        self::assertSame($proId, $fake->clients()[$result->clientId]['template_master']);
        // The pw_crypted flag must have protected the stored hash.
        self::assertSame('crypt:S3cret!pass', $fake->clients()[$result->clientId]['password']);
        // 'Extra Space' unassigned, 'Extra Mail' assigned; record shape per
        // client_template_assigned (client.inc.php).
        $assignments = array_values($fake->templateAssignments());
        self::assertCount(1, $assignments);
        self::assertSame(
            ['assigned_template_id' => 2, 'client_id' => $result->clientId, 'client_template_id' => $mailId],
            $assignments[0]
        );
    }

    public function testChangePackageWithUnchangedAddonsKeepsTheExistingAssignmentRows(): void
    {
        $fake = new FakeIspconfigServer();
        $basicId = $fake->addClientTemplate('Basic', 'm');
        $spaceId = $fake->addClientTemplate('Extra Space', 'a');
        $recipe = $this->recipe($fake);
        $result = $recipe->create(
            $this->product(['clientTemplateId' => $basicId]),
            $this->service(['configOptions' => ['ISPConfig Extra Space' => '1']])
        );
        $assignmentsBefore = $fake->templateAssignments();
        $logOffset = count($fake->requestLog);

        $recipe->changePackage($result->clientId, $this->product(['clientTemplateId' => $basicId]), [$spaceId]);

        self::assertSame(
            ['client_update'],
            array_column(array_slice($fake->requestLog, $logOffset), 'method')
        );
        // Old-style count-diff no-op: the very same assignment rows survive
        // (same assigned_template_id - not delete-and-reinsert).
        self::assertSame($assignmentsBefore, $fake->templateAssignments());
    }

    public function testChangePackageSkipsMalformedAndDuplicateDesiredIds(): void
    {
        $fake = new FakeIspconfigServer();
        $basicId = $fake->addClientTemplate('Basic', 'm');
        $mailId = $fake->addClientTemplate('Extra Mail', 'a');
        $recipe = $this->recipe($fake);
        $result = $recipe->create(
            $this->product(['clientTemplateId' => $basicId]),
            $this->service()
        );

        // A 0/negative id would emit a bogus '0' entry into the count-diff;
        // a duplicate would double-assign the template.
        $recipe->changePackage(
            $result->clientId,
            $this->product(['clientTemplateId' => $basicId]),
            [0, $mailId, $mailId, -3]
        );

        self::assertSame(
            [$mailId],
            array_column($fake->templateAssignments(), 'client_template_id')
        );
    }

    // ── jobqueueCount ──────────────────────────────────────────────────

    public function testJobqueueCountReturnsTheMonitorValue(): void
    {
        $fake = new FakeIspconfigServer();
        $fake->jobqueueCount = 7;
        $recipe = $this->recipe($fake);

        self::assertSame(7, $recipe->jobqueueCount(null));
        // null -> 0, the source's "all servers" default (monitor.inc.php).
        $last = $fake->requestLog[array_key_last($fake->requestLog)];
        self::assertSame('monitor_jobqueue_count', $last['method']);
        self::assertSame(0, $last['params']['server_id']);

        self::assertSame(7, $recipe->jobqueueCount(3));
        $last = $fake->requestLog[array_key_last($fake->requestLog)];
        self::assertSame(3, $last['params']['server_id']);
    }
}
