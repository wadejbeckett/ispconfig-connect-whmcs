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
        $one = $this->fake->addClient('one');
        $two = $this->fake->addClient('two');
        $this->fake->addWebsite($one, 'one.example.com');
        $this->fake->addWebsite($two, 'two.example.com');
        $this->fake->addWebsite($two, 'two-extra.example.com');
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

        // Accounts and services are both ISPConfig CLIENTS: this module creates
        // one client per WHMCS service. The three seeded websites are counted
        // nowhere - reaching a vhost total would mean a `SELECT *` over
        // web_domain, TLS private keys included (RemoteMetaData docblock).
        self::assertSame(2, $meta['service_count']);
        self::assertSame(2, $meta['client_count']);
        self::assertArrayNotHasKey('website_count', $meta);
        self::assertSame('3.3.1p1', $meta['ispconfig_version']);
        self::assertSame(4, $meta['pending_jobs']);
        self::assertSame(1, $meta['server_count']);
        self::assertSame(RemoteMetaData::STATUS_OK, $meta['ispconfig_status']);
        self::assertArrayHasKey('checked_at', $meta);
        self::assertArrayNotHasKey('ispconfig_error', $meta);

        self::assertSame(0, $this->fake->activeSessionCount());
    }

    /**
     * The probe that decides whether an SSO button is ever offered. FALSE IS
     * THE DEFAULT and is asserted first: single sign-on needs the separate
     * ispconfig-sso project on the panel, which this module does not ship, so a
     * stock ISPConfig - the panel almost every install points at - must cache a
     * definite "no" rather than nothing or an error.
     */
    public function testReportsWhetherThePanelHasTheOptionalSsoProject(): void
    {
        $stock = ispconfigconnect_GetRemoteMetaData($this->params());

        self::assertFalse($stock['sso_available']);
        self::assertSame(RemoteMetaData::STATUS_OK, $stock['ispconfig_status'], 'absence is not a fault');
        self::assertArrayNotHasKey('ispconfig_error', $stock);

        self::assertSame('absent', $stock['sso_state']);

        $this->fake->installCompanion();

        $withAddOn = ispconfigconnect_GetRemoteMetaData($this->params());
        self::assertTrue($withAddOn['sso_available']);
        self::assertSame('installed', $withAddOn['sso_state']);
    }

    /**
     * The probe is itself grant-checked, and being refused means the answer is
     * genuinely UNKNOWN. Caching that as `sso_available: false` would publish
     * "this panel has no single sign-on" to every reader of the row - the
     * servers page included - on the strength of a permission we were denied.
     * So the boolean is withheld and the state says why.
     */
    public function testARefusedProbeCachesNoAnswerRatherThanAFalseOne(): void
    {
        // Everything the metadata probes need, and nothing else: only the SSO
        // presence probe is refused.
        $this->fake->setGrants(['server_get', 'client_get_all', 'monitor_jobqueue_count']);

        $meta = ispconfigconnect_GetRemoteMetaData($this->params());

        self::assertArrayNotHasKey('sso_available', $meta);
        self::assertSame('unknown', $meta['sso_state']);
        self::assertArrayNotHasKey('ispconfig_error', $meta, 'a refused SSO probe is not a probe failure');
    }

    public function testADegradedProbeStillReturnsTheKeysThatAnswered(): void
    {
        $this->fake->failNextWith('client_get_all', 'database_error SELECT client_id FROM client');

        $meta = ispconfigconnect_GetRemoteMetaData($this->params());

        self::assertSame(RemoteMetaData::STATUS_DEGRADED, $meta['ispconfig_status']);
        self::assertArrayNotHasKey('client_count', $meta);
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
