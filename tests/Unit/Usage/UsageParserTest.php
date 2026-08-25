<?php

declare(strict_types=1);

namespace Ispconfigconnect\Tests\Unit\Usage;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WHMCS\Module\Server\Ispconfigconnect\Usage\ClientUsage;
use WHMCS\Module\Server\Ispconfigconnect\Usage\UsageParser;

/**
 * Fixtures here are hand-built to match what ISPConfig 3.3.1p1's quota_lib
 * emits through remote/json.php - see the per-fixture citations. Nothing in
 * this test talks to the network: UsageParser is a pure function and this is
 * where the wire shapes are pinned.
 */
#[CoversClass(UsageParser::class)]
#[CoversClass(ClientUsage::class)]
final class UsageParserTest extends TestCase
{
    /**
     * quota_lib::get_quota_data() with $readable = false: a web_domain row per
     * type='vhost' site, augmented with the harddisk_quota monitor values
     * (used/soft/hard in KILOBYTES - repquota block counts) and the *_raw
     * fields it derives in BYTES.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function siteRow(array $overrides = []): array
    {
        return array_merge([
            'domain_id' => '5',
            'sys_groupid' => '3',
            'server_id' => '1',
            'domain' => 'example.com',
            'type' => 'vhost',
            'active' => 'y',
            'system_user' => 'web5',
            'system_group' => 'client2',
            'document_root' => '/var/www/clients/client2/web5',
            'hd_quota' => '1024',        // MB column
            'traffic_quota' => '10240',  // MB column
            'used' => '524288',          // KB, unserialized monitor string
            'soft' => '1048576',
            'hard' => '1048576',
            'files' => '1234',
            'used_raw' => 536870912,     // used * 1024
            'soft_raw' => 1073741824,
            'hard_raw' => 1073741824,
            'files_raw' => '1234',
            'used_percentage' => 50,
        ], $overrides);
    }

    public function testSingleSiteDiskAndTrafficConvertToMegabytes(): void
    {
        $usage = UsageParser::parse(
            clientId: 2,
            diskQuota: [self::siteRow()],
            traffic: ['example.com' => ['domain_id' => '5', 'this_month' => '2147483648', 'this_year' => '4294967296']],
        );

        self::assertSame(512, $usage->diskUsageMegabytes());
        self::assertSame(1024, $usage->diskLimitMegabytes());
        self::assertFalse($usage->diskUnlimited());
        self::assertSame(2048, $usage->bandwidthUsageMegabytes());
        self::assertSame(10240, $usage->bandwidthLimitMegabytes());
        self::assertFalse($usage->bandwidthUnlimited());
        self::assertSame(1, $usage->websiteCount);
        self::assertSame(3, $usage->sysGroupId);
    }

    public function testMultipleSitesSumUsageAndLimits(): void
    {
        $usage = UsageParser::parse(
            clientId: 2,
            diskQuota: [
                self::siteRow(),
                self::siteRow([
                    'domain_id' => '6',
                    'domain' => 'second.example',
                    'system_user' => 'web6',
                    'hd_quota' => '512',
                    'traffic_quota' => '1024',
                    'used_raw' => 268435456,
                ]),
            ],
            traffic: [
                'example.com' => ['domain_id' => '5', 'this_month' => '1048576'],
                'second.example' => ['domain_id' => '6', 'this_month' => '3145728'],
            ],
        );

        self::assertSame(768, $usage->diskUsageMegabytes());   // 512 + 256
        self::assertSame(1536, $usage->diskLimitMegabytes());  // 1024 + 512
        self::assertSame(4, $usage->bandwidthUsageMegabytes()); // 1 + 3
        self::assertSame(11264, $usage->bandwidthLimitMegabytes());
        self::assertSame(2, $usage->websiteCount);
    }

    public function testUnlimitedSiteQuotaMakesTheWholeLimitUnlimited(): void
    {
        $usage = UsageParser::parse(
            clientId: 2,
            diskQuota: [
                self::siteRow(['hd_quota' => '1024', 'traffic_quota' => '10240']),
                self::siteRow([
                    'domain_id' => '6',
                    'domain' => 'second.example',
                    'system_user' => 'web6',
                    'hd_quota' => '-1',
                    'traffic_quota' => '-1',
                    'soft_raw' => -1,
                    'hard_raw' => -1,
                ]),
            ],
            traffic: [],
        );

        self::assertTrue($usage->diskUnlimited());
        self::assertTrue($usage->bandwidthUnlimited());
        // WHMCS's tblhosting has no unlimited sentinel of its own: 0 is the
        // "no limit" value, so an unlimited ISPConfig quota reports as 0 MB.
        self::assertSame(0, $usage->diskLimitMegabytes());
        self::assertSame(0, $usage->bandwidthLimitMegabytes());
    }

    public function testZeroQuotaIsUnlimitedToo(): void
    {
        // apache2_plugin.inc.php only sets a filesystem quota when
        // hd_quota > 0, so 0 behaves exactly like -1.
        $usage = UsageParser::parse(
            clientId: 2,
            diskQuota: [self::siteRow(['hd_quota' => '0', 'traffic_quota' => '0'])],
            traffic: [],
        );

        self::assertTrue($usage->diskUnlimited());
        self::assertTrue($usage->bandwidthUnlimited());
    }

    public function testMissingMonitorDataYieldsZeroUsedNotAFatal(): void
    {
        // No harddisk_quota monitor row for this system_user yet (the cron runs
        // every 5 minutes and a brand new site is not in it): quota_lib leaves
        // used/soft/hard as null and derives used_raw = 0, soft_raw/hard_raw = -1.
        $usage = UsageParser::parse(
            clientId: 2,
            diskQuota: [self::siteRow([
                'used' => null,
                'soft' => null,
                'hard' => null,
                'files' => null,
                'used_raw' => 0,
                'soft_raw' => -1,
                'hard_raw' => -1,
                'files_raw' => null,
                'used_percentage' => 0,
            ])],
            traffic: ['example.com' => ['domain_id' => '5']], // no traffic rows this month
        );

        self::assertSame(0, $usage->diskUsageMegabytes());
        self::assertSame(0, $usage->bandwidthUsageMegabytes());
        // The hd_quota COLUMN still carries the configured limit even with no
        // monitor data, so the limit survives.
        self::assertSame(1024, $usage->diskLimitMegabytes());
    }

    public function testUsedRawIsDerivedFromUsedKilobytesWhenAbsent(): void
    {
        $row = self::siteRow();
        unset($row['used_raw']);

        $usage = UsageParser::parse(clientId: 2, diskQuota: [$row], traffic: []);

        self::assertSame(512, $usage->diskUsageMegabytes()); // 524288 KB
    }

    public function testClientWithNoWebsiteReportsZeroUsageAndUnlimited(): void
    {
        // quota_lib returns queryAllRecords()'s empty array, which json_encodes
        // as [] - the state of a DNS-only or mail-only product.
        $usage = UsageParser::parse(clientId: 2, diskQuota: [], traffic: []);

        self::assertSame(0, $usage->diskUsageMegabytes());
        self::assertSame(0, $usage->bandwidthUsageMegabytes());
        self::assertTrue($usage->diskUnlimited());
        self::assertTrue($usage->bandwidthUnlimited());
        self::assertSame(0, $usage->websiteCount);
        self::assertNull($usage->sysGroupId);
    }

    public function testNonArrayResponsesAreTreatedAsEmpty(): void
    {
        $usage = UsageParser::parse(clientId: 2, diskQuota: null, traffic: false);

        self::assertSame(0, $usage->diskUsageMegabytes());
        self::assertSame(0, $usage->websiteCount);
        self::assertSame(0, $usage->bandwidthUsageMegabytes());
    }

    public function testTrafficEntriesAreSummedIncludingSubdomainsAndAliases(): void
    {
        // get_trafficquota_data() selects type IN (vhost, vhostsubdomain,
        // vhostalias) while get_quota_data() selects vhost only, so the traffic
        // map can carry hostnames that have no row in the disk quota list.
        $usage = UsageParser::parse(
            clientId: 2,
            diskQuota: [self::siteRow()],
            traffic: [
                'example.com' => ['domain_id' => '5', 'this_month' => '1048576'],
                'shop.example.com' => ['domain_id' => '9', 'this_month' => '2097152'],
            ],
        );

        self::assertSame(3, $usage->bandwidthUsageMegabytes());
    }

    public function testMailQuotaRowsAreSummedInBytes(): void
    {
        // get_mailquota_data($clientid, false): mail_user rows where quota and
        // used are BOTH bytes (mail/user_quota_stats.php divides quota by
        // 1048576 to display MB; the email_quota monitor stores doveadm kB*1024).
        $usage = UsageParser::parse(
            clientId: 2,
            diskQuota: [],
            traffic: [],
            mailQuota: [
                ['mailuser_id' => '7', 'sys_groupid' => '3', 'email' => 'a@example.com', 'quota' => '1073741824', 'used' => '268435456', 'quota_raw' => '1073741824', 'used_raw' => '268435456'],
                ['mailuser_id' => '8', 'sys_groupid' => '3', 'email' => 'b@example.com', 'quota' => '536870912', 'used' => 0, 'quota_raw' => '536870912', 'used_raw' => 0],
            ],
        );

        self::assertSame(2, $usage->mailboxCount);
        self::assertSame(256, $usage->mailUsageMegabytes());
        self::assertSame(1536, $usage->mailQuotaMegabytes());
        self::assertFalse($usage->mailQuotaUnlimited());
        self::assertSame(3, $usage->sysGroupId);
    }

    public function testMailboxWithZeroQuotaIsUnlimited(): void
    {
        // mail_user.quota == 0 means unlimited (get_mailquota_data only rewrites
        // it to -1 in its $readable branch, which the remote API never uses).
        $usage = UsageParser::parse(
            clientId: 2,
            diskQuota: [],
            traffic: [],
            mailQuota: [
                ['email' => 'a@example.com', 'quota' => '1073741824', 'used_raw' => '1024'],
                ['email' => 'b@example.com', 'quota' => '0', 'used_raw' => '2048'],
            ],
        );

        self::assertTrue($usage->mailQuotaUnlimited());
        self::assertSame(0, $usage->mailQuotaMegabytes());
        self::assertSame(2, $usage->mailboxCount);
    }

    public function testDatabaseQuotaUsesTheDecimalMegabyteRawFieldIspconfigComputes(): void
    {
        // get_databasequota_data() derives database_quota_raw as
        // database_quota * 1000 * 1000 (DECIMAL MB - an ISPConfig
        // inconsistency; the server-side over-quota check in
        // 100-monitor_database_size.inc.php uses 1024*1024). used_raw is the
        // information_schema data_length+index_length sum, in bytes.
        $usage = UsageParser::parse(
            clientId: 2,
            diskQuota: [],
            traffic: [],
            mailQuota: [],
            databaseQuota: [
                ['database_id' => '2', 'sys_groupid' => '3', 'database_name' => 'c2_shop', 'database_quota' => '500', 'database_quota_raw' => 500000000, 'used_raw' => 104857600],
                ['database_id' => '3', 'sys_groupid' => '3', 'database_name' => 'c2_blog', 'database_quota' => '100', 'database_quota_raw' => 100000000, 'used_raw' => '52428800'],
            ],
        );

        self::assertSame(2, $usage->databaseCount);
        self::assertSame(150, $usage->databaseUsageMegabytes());   // 100 + 50 MiB
        self::assertSame(600000000, $usage->databaseQuotaBytes);
        self::assertFalse($usage->databaseQuotaUnlimited());
    }

    public function testUnlimitedDatabaseQuotaWins(): void
    {
        $usage = UsageParser::parse(
            clientId: 2,
            diskQuota: [],
            traffic: [],
            mailQuota: [],
            databaseQuota: [
                ['database_name' => 'c2_shop', 'database_quota' => '500', 'database_quota_raw' => 500000000, 'used_raw' => 1024],
                ['database_name' => 'c2_blog', 'database_quota' => '-1', 'database_quota_raw' => -1, 'used_raw' => 2048],
            ],
        );

        self::assertTrue($usage->databaseQuotaUnlimited());
        self::assertSame(0, $usage->databaseQuotaMegabytes());
    }

    public function testEntityCountsComeFromTheFilteredRecordLists(): void
    {
        $usage = UsageParser::parse(
            clientId: 2,
            diskQuota: [self::siteRow()],
            traffic: [],
            mailQuota: [['email' => 'a@example.com', 'quota' => '0', 'used_raw' => 0]],
            databaseQuota: [['database_name' => 'c2_shop', 'database_quota_raw' => -1, 'used_raw' => 0]],
            ftpUsers: [
                ['ftp_user_id' => '1', 'username' => 'alice', 'sys_groupid' => '3'],
                ['ftp_user_id' => '2', 'username' => 'alice_backup', 'sys_groupid' => '3'],
            ],
            dnsZones: [['id' => '4', 'origin' => 'example.com.', 'sys_groupid' => '3']],
        );

        self::assertSame(1, $usage->websiteCount);
        self::assertSame(1, $usage->mailboxCount);
        self::assertSame(1, $usage->databaseCount);
        self::assertSame(2, $usage->ftpUserCount);
        self::assertSame(1, $usage->dnsZoneCount);
    }

    public function testSysGroupIdIsNullWhenTheRowsDisagree(): void
    {
        // Rows spanning more than one sys_groupid are the signature of a
        // RESELLER client id: clientid_to_groups_list() concatenates the
        // reseller's own group with its sub-clients' groups
        // (functions.inc.php:687, the parent_client_id branch), and
        // getAuthSQL()'s IN (...) then admits all of them. Such a response
        // cannot be billed to one service.
        $usage = UsageParser::parse(
            clientId: 2,
            diskQuota: [self::siteRow(['sys_groupid' => '3']), self::siteRow(['domain_id' => '9', 'sys_groupid' => '4'])],
            traffic: [],
        );

        self::assertNull($usage->sysGroupId);
        self::assertSame([3, 4], $usage->sysGroupIds);
    }

    public function testGroupIdsIgnoreRowsWithoutTheColumn(): void
    {
        $row = self::siteRow();
        unset($row['sys_groupid']);

        $usage = UsageParser::parse(clientId: 2, diskQuota: [$row], traffic: []);

        self::assertSame([], $usage->sysGroupIds);
        self::assertNull($usage->sysGroupId);
    }

    public function testWithGroupIdOverlaysADiscoveredGroup(): void
    {
        $usage = UsageParser::parse(clientId: 2, diskQuota: [], traffic: []);

        self::assertNull($usage->sysGroupId);
        self::assertSame(7, $usage->withSysGroupId(7)->sysGroupId);
        self::assertSame(2, $usage->withSysGroupId(7)->clientId);
    }

    public function testWithEntityCountsMergesFtpAndDnsCountsIn(): void
    {
        $usage = UsageParser::parse(clientId: 2, diskQuota: [self::siteRow()], traffic: []);

        $counted = $usage->withEntityCounts(ftpUserCount: 3, dnsZoneCount: 2);

        self::assertSame(3, $counted->ftpUserCount);
        self::assertSame(2, $counted->dnsZoneCount);
        self::assertSame(1, $counted->websiteCount);
        self::assertSame(512, $counted->diskUsageMegabytes());
    }
}
