<?php

declare(strict_types=1);

namespace Ispconfigconnect\Tests\Module;

use WHMCS\Module\Server\Ispconfigconnect\Server\RemoteMetaData;

/**
 * ispconfigconnect_GetRemoteMetaData - the panel facts WHMCS collects while
 * rendering Configuration > Servers and stores VERBATIM as JSON in
 * tblservers_remote.meta_data.
 *
 * The contract here is live-verified against WHMCS 9.0.6, not documented:
 * a FLAT array is required (a success/metaData wrapper is not accepted), and
 * core renders the 'service_count' key as "Services: N" in the Remote Usage
 * Stats cell.
 */
final class GetRemoteMetaDataTest extends ModuleTestCase
{
    public function testReturnsAFlatScalarArrayWithTheKeysWhmcsAndThePanelNeed(): void
    {
        $this->fake->addClient('one');
        $this->fake->addClient('two');
        $this->fake->jobqueueCount = 4;

        $meta = ispconfigconnect_GetRemoteMetaData($this->params());

        // Flat: no wrapper keys, and every value must survive json_encode into
        // a text column unchanged.
        self::assertArrayNotHasKey('success', $meta);
        self::assertArrayNotHasKey('metaData', $meta);
        foreach ($meta as $key => $value) {
            self::assertIsString($key);
            self::assertTrue(is_scalar($value), sprintf('meta_data[%s] must be scalar', $key));
        }

        self::assertSame(2, $meta['service_count']);
        self::assertSame(2, $meta['client_count']);
        self::assertSame('3.3.1p1', $meta['ispconfig_version']);
        self::assertSame(4, $meta['pending_jobs']);
        self::assertSame(1, $meta['server_count']);
        self::assertSame(RemoteMetaData::STATUS_OK, $meta['ispconfig_status']);
        self::assertArrayHasKey('checked_at', $meta);
        self::assertArrayNotHasKey('ispconfig_error', $meta);

        self::assertSame(0, $this->fake->activeSessionCount());
    }

    public function testReportsTheOptionalSsoCompanionSoTheModuleCanHideADeadButton(): void
    {
        self::assertFalse(ispconfigconnect_GetRemoteMetaData($this->params())['sso_available']);

        $this->fake->installCompanion();

        self::assertTrue(ispconfigconnect_GetRemoteMetaData($this->params())['sso_available']);
    }

    public function testADegradedProbeStillReturnsTheKeysThatAnswered(): void
    {
        $this->fake->failNextWith('client_get_all', 'database_error SELECT client_id FROM client');

        $meta = ispconfigconnect_GetRemoteMetaData($this->params());

        self::assertSame(RemoteMetaData::STATUS_DEGRADED, $meta['ispconfig_status']);
        self::assertArrayNotHasKey('service_count', $meta);
        self::assertSame('3.3.1p1', $meta['ispconfig_version']);
        self::assertStringContainsString('database_error', (string) $meta['ispconfig_error']);
    }

    public function testAnUnreachablePanelStillReturnsARenderableFlatArray(): void
    {
        $this->fake->apiEnabled = false;

        $meta = ispconfigconnect_GetRemoteMetaData($this->params());

        self::assertSame(RemoteMetaData::STATUS_UNAVAILABLE, $meta['ispconfig_status']);
        self::assertArrayHasKey('checked_at', $meta);
        self::assertStringContainsString('Remote API is disabled', (string) $meta['ispconfig_error']);
    }

    public function testABrokenServerConfigurationNeverThrows(): void
    {
        // No hostname, no credentials: Factory::sessionManager() itself can
        // fail here, and WHMCS is mid-render of the servers page.
        $meta = ispconfigconnect_GetRemoteMetaData([]);

        self::assertArrayHasKey('ispconfig_status', $meta);
        self::assertSame(RemoteMetaData::STATUS_UNAVAILABLE, $meta['ispconfig_status']);
    }

    public function testTheResultSurvivesJsonEncodingIntoTheMetaDataColumn(): void
    {
        $meta = ispconfigconnect_GetRemoteMetaData($this->params());

        $json = json_encode($meta, JSON_THROW_ON_ERROR);
        self::assertSame($meta, json_decode($json, true, 512, JSON_THROW_ON_ERROR));
    }
}
