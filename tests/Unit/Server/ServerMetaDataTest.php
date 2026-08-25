<?php

declare(strict_types=1);

namespace Ispconfigconnect\Tests\Unit\Server;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WHMCS\Module\Server\Ispconfigconnect\Server\ServerMetaData;

#[CoversClass(ServerMetaData::class)]
final class ServerMetaDataTest extends TestCase
{
    public function testReadsATblserversRemoteJoinRow(): void
    {
        $server = ServerMetaData::fromRow([
            'id' => '3',
            'name' => 'ISPConfig 1',
            'hostname' => 'panel.example.net',
            'meta_data' => '{"ispconfig_version":"3.3.1p1","pending_jobs":4}',
            'updated_at' => '2026-08-24 09:15:00',
        ]);

        self::assertSame(3, $server->serverId);
        self::assertSame('ISPConfig 1', $server->name);
        self::assertSame('panel.example.net', $server->hostname);
        self::assertSame('3.3.1p1', $server->values['ispconfig_version']);
        self::assertSame(4, $server->values['pending_jobs']);
        self::assertSame('2026-08-24 09:15:00', $server->updatedAt);
    }

    /** A server WHMCS has never refreshed has no tblservers_remote row at all. */
    public function testHandlesAServerWithNoStoredMetaData(): void
    {
        $server = ServerMetaData::fromRow([
            'id' => 7,
            'name' => 'ISPConfig 2',
            'hostname' => 'panel2.example.net',
            'meta_data' => null,
            'updated_at' => null,
        ]);

        self::assertSame([], $server->values);
        self::assertNull($server->updatedAt);
    }

    public function testCorruptJsonDegradesToNoValuesInsteadOfThrowing(): void
    {
        $server = ServerMetaData::fromRow([
            'id' => 1,
            'name' => 'x',
            'hostname' => 'h',
            'meta_data' => '{not json',
            'updated_at' => '',
        ]);

        self::assertSame([], $server->values);
        self::assertNull($server->updatedAt);
    }

    public function testJsonThatIsNotAnObjectIsIgnored(): void
    {
        $server = ServerMetaData::fromRow([
            'id' => 1,
            'name' => 'x',
            'hostname' => 'h',
            'meta_data' => '"a string"',
            'updated_at' => null,
        ]);

        self::assertSame([], $server->values);
    }

    /** Capsule can hand back an already-decoded value depending on the driver. */
    public function testAcceptsAnAlreadyDecodedMetaDataArray(): void
    {
        $server = ServerMetaData::fromRow([
            'id' => 1,
            'name' => 'x',
            'hostname' => 'h',
            'meta_data' => ['service_count' => 12],
            'updated_at' => null,
        ]);

        self::assertSame(12, $server->values['service_count']);
    }

    public function testMissingColumnsDoNotFatal(): void
    {
        $server = ServerMetaData::fromRow([]);

        self::assertSame(0, $server->serverId);
        self::assertSame('', $server->name);
        self::assertSame('', $server->hostname);
        self::assertSame([], $server->values);
        self::assertNull($server->updatedAt);
    }
}
