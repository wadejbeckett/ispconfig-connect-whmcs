<?php

declare(strict_types=1);

namespace Ispconfigconnect\Tests\Module;

use WHMCS\Database\Capsule;
use WHMCS\Module\Server\Ispconfigconnect\Support\ServiceProperties;

/**
 * Which other services the one-service-per-client guard counts as sharers.
 *
 * ISPConfig client ids are per-panel auto-increments, so a service on another
 * panel holding the same number is a different customer (readiness R1). And a
 * service is judged by the client id the module would actually read for it,
 * not by any stale custom-field row an old product left behind (R11).
 */
final class SharedClientScopeTest extends ModuleTestCase
{
    private const OLD_PRODUCT = 100;
    private const NEW_PRODUCT = 200;
    private const FIELD_OLD_PRODUCT = 90;
    private const FIELD_NEW_PRODUCT = 91;

    protected function setUp(): void
    {
        parent::setUp();
        Capsule::seed('tblcustomfields', [
            ['id' => self::FIELD_OLD_PRODUCT, 'type' => 'product', 'fieldname' => ServiceProperties::KEY_CLIENT_ID, 'relid' => self::OLD_PRODUCT],
            ['id' => self::FIELD_NEW_PRODUCT, 'type' => 'product', 'fieldname' => ServiceProperties::KEY_CLIENT_ID, 'relid' => self::NEW_PRODUCT],
        ]);
    }

    /** @param list<array{0: int, 1: string, 2: string}> $servers id, hostname, ipaddress */
    private function seedServers(array $servers): void
    {
        $rows = [];
        foreach ($servers as [$id, $hostname, $ip]) {
            $rows[] = ['id' => $id, 'hostname' => $hostname, 'ipaddress' => $ip, 'type' => 'ispconfigconnect'];
        }
        Capsule::seed('tblservers', $rows);
    }

    private function seedService(int $serviceId, int $serverId, int $productId = self::OLD_PRODUCT, string $status = 'Active'): void
    {
        Capsule::seed('tblhosting', [[
            'id' => $serviceId,
            'userid' => 88,
            'domain' => 'service-' . $serviceId . '.example',
            'domainstatus' => $status,
            'packageid' => $productId,
            'server' => $serverId,
        ]]);
    }

    private function seedClientIdRow(int $serviceId, int $fieldId, string $value, ?int $rowId = null): void
    {
        $row = ['fieldid' => $fieldId, 'relid' => $serviceId, 'value' => $value];
        if ($rowId !== null) {
            $row['id'] = $rowId;
        }
        Capsule::seed('tblcustomfieldsvalues', [$row]);
    }

    /** @return list<int> the service ids the guard reports for service 42 and client 7 */
    private function sharersOf42(): array
    {
        $others = ispconfigconnect_otherServicesLinkedToClient(42, 7);
        self::assertIsArray($others);

        return array_map(static fn (array $other): int => $other['serviceid'], $others);
    }

    // ── R1: client ids are per panel ─────────────────────────────────────

    public function testAServiceOnAnotherPanelIsNotASharer(): void
    {
        $this->seedServers([[1, 'panel-a.example', '10.0.0.1'], [2, 'panel-b.example', '10.0.0.2']]);
        $this->seedService(42, 2);
        $this->seedService(99, 1);
        $this->seedClientIdRow(99, self::FIELD_OLD_PRODUCT, '7');

        self::assertSame([], $this->sharersOf42());
    }

    public function testAServiceOnTheSameServerRecordIsASharer(): void
    {
        $this->seedServers([[1, 'panel-a.example', '10.0.0.1'], [2, 'panel-b.example', '10.0.0.2']]);
        $this->seedService(42, 2);
        $this->seedService(99, 2);
        $this->seedClientIdRow(99, self::FIELD_OLD_PRODUCT, '7');

        self::assertSame([99], $this->sharersOf42());
    }

    public function testAnotherServerRecordForTheSamePanelStillShares(): void
    {
        // Same host name, written differently; different IP field.
        $this->seedServers([[1, 'Panel.Example.', '10.0.0.1'], [2, 'panel.example', '10.0.0.9']]);
        $this->seedService(42, 2);
        $this->seedService(99, 1);
        $this->seedClientIdRow(99, self::FIELD_OLD_PRODUCT, '7');

        self::assertSame([99], $this->sharersOf42());
    }

    public function testAnAddressMatchAcrossFieldsStillShares(): void
    {
        // One record names the panel by IP in its Hostname field, the other
        // carries that IP in its IP Address field.
        $this->seedServers([[1, '10.0.0.1', ''], [2, 'panel.example', '10.0.0.1']]);
        $this->seedService(42, 2);
        $this->seedService(99, 1);
        $this->seedClientIdRow(99, self::FIELD_OLD_PRODUCT, '7');

        self::assertSame([99], $this->sharersOf42());
    }

    public function testARecordWithNothingComparableIsKeptAsASharer(): void
    {
        // Host name only on one side, IP only on the other: nothing proves
        // they are different panels, so the guard keeps the service.
        $this->seedServers([[1, 'panel-a.example', ''], [2, '', '10.0.0.2']]);
        $this->seedService(42, 2);
        $this->seedService(99, 1);
        $this->seedClientIdRow(99, self::FIELD_OLD_PRODUCT, '7');

        self::assertSame([99], $this->sharersOf42());
    }

    public function testServicesWithNoServerOrAMissingServerRecordStillCount(): void
    {
        $this->seedServers([[1, 'panel-a.example', '10.0.0.1'], [2, 'panel-b.example', '10.0.0.2']]);
        $this->seedService(42, 2);
        $this->seedService(98, 0);
        $this->seedService(99, 55);
        $this->seedClientIdRow(98, self::FIELD_OLD_PRODUCT, '7');
        $this->seedClientIdRow(99, self::FIELD_OLD_PRODUCT, '7');

        self::assertSame([98, 99], $this->sharersOf42());
    }

    public function testWhenThisServicesServerIsUnknownEveryServerCounts(): void
    {
        $this->seedServers([[1, 'panel-a.example', '10.0.0.1'], [2, 'panel-b.example', '10.0.0.2']]);
        $this->seedService(42, 0);
        $this->seedService(99, 1);
        $this->seedClientIdRow(99, self::FIELD_OLD_PRODUCT, '7');

        self::assertSame([99], $this->sharersOf42());
    }

    public function testTerminateOnOnePanelIsNotBlockedByTheSameClientNumberOnAnother(): void
    {
        $clientId = $this->fake->addClient('panelbcustomer');
        $this->fake->addWebsite($clientId, 'panel-b-customer.example');
        $this->model->serviceProperties->save([ServiceProperties::KEY_CLIENT_ID => (string) $clientId]);
        $this->seedServers([[1, 'panel-a.example', '10.0.0.1'], [2, 'panel-b.example', '10.0.0.2']]);
        $this->seedService(42, 2);
        // Another customer's service on panel A happens to hold the same number.
        $this->seedService(99, 1);
        $this->seedClientIdRow(99, self::FIELD_OLD_PRODUCT, (string) $clientId);

        self::assertSame('success', ispconfigconnect_TerminateAccount($this->params(['serviceid' => 42, 'serverid' => 2])));
        self::assertArrayNotHasKey($clientId, $this->fake->clients());
    }

    // ── R11: judge each service by the id the module would read ──────────

    public function testAStaleOldProductRowIsNotASharer(): void
    {
        $this->seedService(42, 0);
        // Service 99 moved to the new product and was relinked to client 8;
        // the old product's row still says 7.
        $this->seedService(99, 0, self::NEW_PRODUCT);
        $this->seedClientIdRow(99, self::FIELD_OLD_PRODUCT, '7', 500);
        $this->seedClientIdRow(99, self::FIELD_NEW_PRODUCT, '8', 501);

        self::assertSame([], $this->sharersOf42());
    }

    public function testAnOldProductRowTheReaderStillUsesIsASharer(): void
    {
        $this->seedService(42, 0);
        // The current product carries no client-id row, so the module reads
        // the old product's row - the service really is linked to client 7.
        $this->seedService(99, 0, self::NEW_PRODUCT);
        $this->seedClientIdRow(99, self::FIELD_OLD_PRODUCT, '7', 500);

        self::assertSame([99], $this->sharersOf42());
    }

    public function testABlankCurrentRowFallsBackToTheNewestRowLikeTheReader(): void
    {
        $this->seedService(42, 0);
        $this->seedService(99, 0, self::NEW_PRODUCT);
        // The current product's row is blank and OLDER than the old product's
        // row, so the reader's second pass returns the old row's 7.
        $this->seedClientIdRow(99, self::FIELD_NEW_PRODUCT, '', 500);
        $this->seedClientIdRow(99, self::FIELD_OLD_PRODUCT, '7', 501);

        self::assertSame([99], $this->sharersOf42());
    }

    public function testANewerBlankRowMeansTheServiceReadsAsUnlinked(): void
    {
        $this->seedService(42, 0);
        $this->seedService(99, 0, self::NEW_PRODUCT);
        // The newest row of any product is the blank current one, so the
        // reader returns nothing for service 99: it holds no client.
        $this->seedClientIdRow(99, self::FIELD_OLD_PRODUCT, '7', 500);
        $this->seedClientIdRow(99, self::FIELD_NEW_PRODUCT, '', 501);

        self::assertSame([], $this->sharersOf42());
    }
}
