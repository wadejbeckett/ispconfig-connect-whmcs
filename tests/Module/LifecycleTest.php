<?php

declare(strict_types=1);

namespace Ispconfigconnect\Tests\Module;

use Ispconfigconnect\Tests\Stubs\ModuleCallLog;

/**
 * End-to-end lifecycle over the full real stack (entry file -> Factory ->
 * SessionManager -> RestClient -> FakeIspconfigServer):
 * create -> suspend -> unsuspend -> changepassword -> changepackage ->
 * terminate, with fake-state assertions at every step and the
 * credential-scrubbing regression check across the entire run.
 */
final class LifecycleTest extends ModuleTestCase
{
    private const NEW_PASSWORD = 'NewP@ss!456';

    public function testFullLifecycle(): void
    {
        // -- create (every optional stage enabled) --------------------------
        $result = ispconfigconnect_CreateAccount($this->params([
            'configoption5' => 'on',  // Create Mail Domain
            'configoption6' => 'on',  // Create DNS Zone
            'configoption11' => 'on', // Create Database
        ]));
        self::assertSame('success', $result);

        $clients = $this->fake->clients();
        self::assertCount(1, $clients);
        $clientId = (int) array_key_first($clients);
        $client = $clients[$clientId];
        self::assertSame('exampleuser', $client['username']);
        self::assertSame($this->masterTemplateId, (int) $client['template_master']);
        // ISPConfig bug #6990: usertheme must always be sent explicitly.
        self::assertSame('default', $client['usertheme']);
        self::assertSame('Managed by WHMCS service #42', $client['notes']);

        self::assertCount(1, $this->fake->sites());
        self::assertCount(1, $this->fake->ftpUsers());
        self::assertCount(1, $this->fake->mailDomains());
        self::assertCount(1, $this->fake->dnsZones());
        self::assertCount(1, $this->fake->databases());
        self::assertCount(1, $this->fake->databaseUsers());

        // Every created id is persisted as a service property (spec 2.8).
        $properties = $this->model->serviceProperties;
        self::assertSame((string) $clientId, $properties->get('ispconfig_client_id'));
        self::assertSame('exampleuser', $properties->get('ispconfig_client_username'));
        self::assertSame(
            (string) array_key_first($this->fake->sites()),
            $properties->get('ispconfig_domain_id')
        );
        self::assertSame(
            (string) array_key_first($this->fake->ftpUsers()),
            $properties->get('ispconfig_ftp_user_id')
        );
        self::assertSame(
            (string) array_key_first($this->fake->mailDomains()),
            $properties->get('ispconfig_mail_domain_id')
        );
        self::assertSame(
            (string) array_key_first($this->fake->dnsZones()),
            $properties->get('ispconfig_dns_zone_id')
        );
        self::assertSame(
            (string) array_key_first($this->fake->databases()),
            $properties->get('ispconfig_database_id')
        );
        self::assertSame(
            (string) array_key_first($this->fake->databaseUsers()),
            $properties->get('ispconfig_database_user_id')
        );

        // One login per WHMCS operation, one logout at its end (spec 1.4).
        self::assertSame(0, $this->fake->activeSessionCount());
        $methods = $this->calledMethods();
        self::assertSame('logout', end($methods));

        // -- suspend --------------------------------------------------------
        self::assertSame('success', ispconfigconnect_SuspendAccount($this->params()));
        self::assertSame('y', $this->fake->clients()[$clientId]['locked']);
        $siteId = (int) array_key_first($this->fake->sites());
        // Suspend Also Disables Website is on (configoption13).
        self::assertSame('n', $this->fake->sites()[$siteId]['active']);

        // -- unsuspend ------------------------------------------------------
        self::assertSame('success', ispconfigconnect_UnsuspendAccount($this->params()));
        self::assertSame('n', $this->fake->clients()[$clientId]['locked']);
        self::assertSame('y', $this->fake->sites()[$siteId]['active']);

        // -- change password ------------------------------------------------
        self::assertSame(
            'success',
            ispconfigconnect_ChangePassword($this->params(['password' => self::NEW_PASSWORD]))
        );
        self::assertSame(self::NEW_PASSWORD, $this->fake->clients()[$clientId]['password']);

        // -- change package (new master template + addon template) ----------
        self::assertSame('success', ispconfigconnect_ChangePackage($this->params([
            'configoption1' => (string) $this->proTemplateId,
            'configoptions' => ['ISPConfig Extra Mailboxes' => 1],
        ])));
        self::assertSame($this->proTemplateId, (int) $this->fake->clients()[$clientId]['template_master']);
        $assignments = array_values($this->fake->templateAssignments());
        self::assertCount(1, $assignments);
        self::assertSame($clientId, $assignments[0]['client_id']);
        self::assertSame($this->addonTemplateId, $assignments[0]['client_template_id']);

        // -- terminate ------------------------------------------------------
        self::assertSame('success', ispconfigconnect_TerminateAccount($this->params()));
        self::assertSame([], $this->fake->clients());
        self::assertSame([], $this->fake->sites());
        self::assertSame([], $this->fake->ftpUsers());
        self::assertSame([], $this->fake->mailDomains());
        self::assertSame([], $this->fake->dnsZones());
        self::assertSame([], $this->fake->databases());
        self::assertSame([], $this->fake->databaseUsers());

        // Terminate clears every stored property (spec 2.8); '' reads as
        // "not stored" through Support\ServiceProperties.
        self::assertSame('', $properties->get('ispconfig_client_id'));
        self::assertSame('', $properties->get('ispconfig_client_username'));
        self::assertSame('', $properties->get('ispconfig_domain_id'));

        // -- credential scrubbing across the WHOLE run ----------------------
        // Every module-log entry must either not contain a secret or list it
        // in replaceVars (WHMCS's masking convention). This is the regression
        // test for the scrubbing seam (spec §4 quality bar).
        self::assertNotEmpty(ModuleCallLog::entries());
        self::assertSame([], ModuleCallLog::entriesLeaking(self::SERVICE_PASSWORD));
        self::assertSame([], ModuleCallLog::entriesLeaking(self::NEW_PASSWORD));
        self::assertSame([], ModuleCallLog::entriesLeaking(self::REMOTE_PASSWORD));
    }

    public function testSuspendWithoutStoredClientIdFailsWithClearError(): void
    {
        $result = ispconfigconnect_SuspendAccount($this->params());

        self::assertNotSame('success', $result);
        self::assertStringContainsString('ispconfig_client_id', $result);
        // No stored id -> no wire traffic at all.
        self::assertSame([], $this->fake->requestLog);
    }
}
