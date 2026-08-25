<?php

declare(strict_types=1);

namespace Ispconfigconnect\Tests\Module;

/**
 * WHMCS Server Sync (developers.whmcs.com/provisioning-modules/server-sync/):
 * the three ListAccounts* MetaData keys plus ispconfigconnect_ListAccounts(),
 * driven end to end through the real stack against the ISPConfig behavioral
 * double.
 */
final class ListAccountsTest extends ModuleTestCase
{
    public function testMetaDataCarriesTheThreeServerSyncKeys(): void
    {
        $meta = ispconfigconnect_MetaData();

        // Without all three, WHMCS never offers the Sync Accounts button.
        self::assertSame('Domain', $meta['ListAccountsUniqueIdentifierDisplayName']);
        self::assertSame('domain', $meta['ListAccountsUniqueIdentifierField']);
        // configoption1 is the Client Template dropdown, whose stored value is
        // the ISPConfig template id - which is what 'product' reports.
        self::assertSame('configoption1', $meta['ListAccountsProductField']);
    }

    public function testListsProvisionedAndHandMadeClientsWithTheDocumentedKeys(): void
    {
        self::assertSame('success', ispconfigconnect_CreateAccount($this->params()));
        $other = $this->fake->addClient('otherclient');
        $this->fake->addWebsite($other, 'other.example');

        $result = ispconfigconnect_ListAccounts($this->params());

        self::assertTrue($result['success']);
        self::assertCount(2, $result['accounts']);

        $provisioned = $result['accounts'][0];
        self::assertSame(
            ['email', 'username', 'domain', 'uniqueIdentifier', 'product', 'primaryip', 'created', 'status'],
            array_keys($provisioned)
        );
        self::assertSame('jane@example.com', $provisioned['email']);
        self::assertSame('exampleuser', $provisioned['username']);
        self::assertSame('example.com', $provisioned['domain']);
        self::assertSame('example.com', $provisioned['uniqueIdentifier']);
        self::assertSame((string) $this->masterTemplateId, $provisioned['product']);
        self::assertSame('203.0.113.10', $provisioned['primaryip']);
        self::assertSame('Active', $provisioned['status']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $provisioned['created']);

        self::assertSame('other.example', $result['accounts'][1]['uniqueIdentifier']);
    }

    public function testSuspendedClientsAreReportedWithTheWhmcsSuspendedStatus(): void
    {
        self::assertSame('success', ispconfigconnect_CreateAccount($this->params()));
        self::assertSame('success', ispconfigconnect_SuspendAccount($this->params()));

        $result = ispconfigconnect_ListAccounts($this->params());

        // The strings are WHMCS\Service\Status::ACTIVE / ::SUSPENDED, which the
        // test double declares with WHMCS's own tblhosting.domainstatus values.
        self::assertSame('Suspended', $result['accounts'][0]['status']);
    }

    public function testAnEmptyPanelListsNoAccountsButStillSucceeds(): void
    {
        $result = ispconfigconnect_ListAccounts($this->params());

        self::assertTrue($result['success']);
        self::assertSame([], $result['accounts']);
    }

    public function testUsesOneLoginForTheWholeListingAndLogsOutAfterwards(): void
    {
        self::assertSame('success', ispconfigconnect_CreateAccount($this->params()));
        $mark = $this->wireMark();

        ispconfigconnect_ListAccounts($this->params());

        $methods = $this->methodsSince($mark);
        self::assertSame(1, count(array_filter($methods, static fn (string $m): bool => $m === 'login')));
        self::assertSame(0, $this->fake->activeSessionCount());
    }

    public function testTemplatesAreFetchedOnceForTheWholePanelNotPerClient(): void
    {
        foreach (['a1', 'a2', 'a3'] as $username) {
            $clientId = $this->fake->addClient($username);
            $this->fake->addWebsite($clientId, $username . '.example');
        }
        $mark = $this->wireMark();

        ispconfigconnect_ListAccounts($this->params());

        $methods = $this->methodsSince($mark);
        self::assertSame(
            1,
            count(array_filter($methods, static fn (string $m): bool => $m === 'client_templates_get_all'))
        );
        // One wildcard client_get returns the whole client table.
        self::assertSame(
            1,
            count(array_filter($methods, static fn (string $m): bool => $m === 'client_get'))
        );
    }

    public function testAPanelFailureIsReportedAsTheDocumentedErrorArray(): void
    {
        $this->fake->failNextWith('client_templates_get_all', 'database_error SELECT * FROM client_template');

        $result = ispconfigconnect_ListAccounts($this->params());

        self::assertFalse($result['success']);
        self::assertArrayNotHasKey('accounts', $result);
        self::assertStringContainsString('database_error', $result['error']);
        // Even on the failure path the batch session is closed.
        self::assertSame(0, $this->fake->activeSessionCount());
    }

    public function testAMissingGrantIsReportedRatherThanThrown(): void
    {
        $this->fake->setGrants(['client_get']);

        $result = ispconfigconnect_ListAccounts($this->params());

        self::assertFalse($result['success']);
        self::assertNotSame('', $result['error']);
    }

    public function testAnUnreachablePanelIsReportedRatherThanThrown(): void
    {
        $this->fake->apiEnabled = false;

        $result = ispconfigconnect_ListAccounts($this->params());

        self::assertFalse($result['success']);
        self::assertStringContainsString('Remote API is disabled', $result['error']);
    }
}
