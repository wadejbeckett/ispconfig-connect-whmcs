<?php

declare(strict_types=1);

namespace Ispconfigconnect\Tests\Module;

/**
 * CreateAccount edge behavior: the all-or-nothing rollback surfaced through
 * the WHMCS error-string convention, the double-provision guard, and
 * username generation for services ordered without one.
 */
final class CreateAccountEdgeTest extends ModuleTestCase
{
    public function testMidRecipeFailureReturnsStageAndLeavesNoClientBehind(): void
    {
        // A database_error fault text is NOT sentinel-matched - it stays a
        // plain RemoteFaultException (spec §1.3).
        $this->fake->failNextWith('sites_web_domain_add', 'database_error INSERT INTO web_domain');

        $result = ispconfigconnect_CreateAccount($this->params());

        self::assertNotSame('success', $result);
        // The operation and the failed stage are both named (spec 2.7/2.8).
        self::assertStringContainsString('Create Account failed', $result);
        self::assertStringContainsString('web_domain', $result);
        // All-or-nothing: the rollback removed the half-created client.
        self::assertSame([], $this->fake->clients());
        self::assertSame([], $this->fake->sites());
        // Nothing was persisted for a failed create.
        self::assertNull($this->model->serviceProperties->get('ispconfig_client_id'));
    }

    public function testDoubleProvisionGuardRefusesWithoutAnyWireCall(): void
    {
        $this->model->serviceProperties->save(['ispconfig_client_id' => '7']);

        $result = ispconfigconnect_CreateAccount($this->params());

        self::assertNotSame('success', $result);
        self::assertStringContainsString('#7', $result);
        self::assertStringContainsStringIgnoringCase('already', $result);
        // The guard fires before any API traffic - nothing to roll back.
        self::assertSame([], $this->fake->requestLog);
        self::assertSame([], $this->fake->clients());
    }

    public function testEmptyServiceUsernameIsGeneratedAndWrittenBack(): void
    {
        $result = ispconfigconnect_CreateAccount($this->params(['username' => '']));

        self::assertSame('success', $result);

        $clients = array_values($this->fake->clients());
        self::assertCount(1, $clients);
        $username = (string) $clients[0]['username'];
        self::assertNotSame('', $username);
        // Deterministic scheme: alnum(sld) + service id.
        self::assertSame('example42', $username);

        self::assertSame($username, $this->model->serviceProperties->get('ispconfig_client_username'));
        // The generated username is written back to the WHMCS service model.
        self::assertSame($username, $this->model->username);
        self::assertGreaterThan(0, $this->model->saveCalls);
    }

    public function testEmptyServicePasswordIsRejectedBeforeAnyApiCall(): void
    {
        $result = ispconfigconnect_CreateAccount($this->params(['password' => '']));

        self::assertNotSame('success', $result);
        self::assertStringContainsStringIgnoringCase('password', $result);
        // Without the guard, ISPConfig would accept the create and provision
        // FTP and MySQL users with EMPTY credentials (the *_add forms treat
        // an absent PASSWORD field as "skip") - so no API call may happen.
        self::assertSame([], $this->fake->requestLog);
        self::assertSame([], $this->fake->clients());
    }

    public function testPropertySaveFailureReturnsErrorNamingEveryCreatedId(): void
    {
        // Provisioning succeeds, then the WHMCS property store fails: the
        // ISPConfig objects exist but WHMCS holds no record of them. The
        // error string must carry every created id so the operator can
        // reconcile the orphan (at minimum the client id).
        $this->model->serviceProperties->throwOnSaveMessage = 'db gone away';

        $result = ispconfigconnect_CreateAccount($this->params([
            'configoption5' => 'on',  // Create Mail Domain
            'configoption6' => 'on',  // Create DNS Zone
            'configoption11' => 'on', // Create Database
        ]));

        self::assertNotSame('success', $result);
        $clientId = (int) array_key_first($this->fake->clients());
        self::assertStringContainsString('client #' . $clientId, $result);
        foreach (
            [
                'web domain #' . array_key_first($this->fake->sites()),
                'FTP user #' . array_key_first($this->fake->ftpUsers()),
                'mail domain #' . array_key_first($this->fake->mailDomains()),
                'DNS zone #' . array_key_first($this->fake->dnsZones()),
                'database user #' . array_key_first($this->fake->databaseUsers()),
                'database #' . array_key_first($this->fake->databases()),
            ] as $expected
        ) {
            self::assertStringContainsString($expected, $result);
        }
        self::assertStringContainsString('db gone away', $result);
        // The provisioned objects are NOT rolled back - they are live and
        // billable; the operator reconciles instead.
        self::assertCount(1, $this->fake->clients());
        // The session was still closed on the way out.
        self::assertSame(0, $this->fake->activeSessionCount());
    }

    public function testUsernameWriteBackFailureStillReturnsSuccess(): void
    {
        // The username write-back to the service model is cosmetic: the
        // authoritative ids are already stored as service properties, so a
        // failing model save must not fail the whole (successful) create.
        $this->model->failSave = true;

        $result = ispconfigconnect_CreateAccount($this->params(['username' => '']));

        self::assertSame('success', $result);
        self::assertCount(1, $this->fake->clients());
        self::assertSame(
            'example42',
            $this->model->serviceProperties->get('ispconfig_client_username')
        );
        self::assertGreaterThan(0, $this->model->saveCalls);
    }

    public function testErrorStringNeverContainsAStackTrace(): void
    {
        $this->fake->failNextWith('client_add', 'database_error INSERT INTO client');

        $result = ispconfigconnect_CreateAccount($this->params());

        self::assertNotSame('success', $result);
        self::assertStringContainsString('Create Account failed', $result);
        self::assertStringNotContainsString('#0 ', $result);   // trace frame marker
        self::assertStringNotContainsString(__FILE__, $result);
        self::assertStringNotContainsString('.php(', $result); // file(line) frames
    }
}
