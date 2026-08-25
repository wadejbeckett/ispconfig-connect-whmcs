<?php

declare(strict_types=1);

namespace Ispconfigconnect\Tests\Unit\Metrics;

use Ispconfigconnect\Tests\Support\FakeIspconfigServer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WHMCS\Module\Server\Ispconfigconnect\Api\RestClient;
use WHMCS\Module\Server\Ispconfigconnect\Api\SessionManager;
use WHMCS\Module\Server\Ispconfigconnect\Metrics\Provider;
use WHMCS\Module\Server\Ispconfigconnect\Usage\ClientUsage;
use WHMCS\Module\Server\Ispconfigconnect\Usage\QuotaMath;
use WHMCS\Module\Server\Ispconfigconnect\Usage\ServiceRef;

/**
 * What WHMCS is told each metric MEANS, checked against what ISPConfig 3.3.1p1
 * actually stores. A wrong factor here is a wrong invoice, so the fixture is
 * built so that no metric can be right by accident: every value is a sum over
 * SEVERAL objects, every unit factor is exercised with a number that gives a
 * visibly different answer under the wrong interpretation, and the counts
 * deliberately disagree with each other (3 websites but 5 traffic-bearing
 * hostnames, 3 mailboxes but only 2 with any usage, 3 databases but only 2
 * with a size).
 *
 * The megabyte is BINARY throughout. ISPConfig treats its own MB that way -
 * 300-quota_notify.inc.php compares traffic_quota against
 * traffic_bytes/1024/1024, and apache2_plugin.inc.php:981 turns hd_quota into
 * hd_quota*1024 one-kilobyte quota blocks - and WHMCS's tblhosting columns are
 * documented simply as megabytes
 * (developers.whmcs.com/api-reference/updateclientproduct/).
 */
#[CoversClass(Provider::class)]
#[CoversClass(ClientUsage::class)]
final class MetricValueSemanticsTest extends TestCase
{
    private const KIB = 1024;
    private const MIB = 1048576;
    private const GIB = 1073741824;

    private FakeIspconfigServer $fake;

    protected function setUp(): void
    {
        $this->fake = new FakeIspconfigServer();
    }

    private function provider(): Provider
    {
        return new Provider(new SessionManager(new RestClient($this->fake), 'remote', 'secret'));
    }

    /**
     * One customer with a realistic estate: three websites, a subdomain vhost
     * and an alias vhost, three mailboxes, three databases, four FTP users and
     * two DNS zones.
     *
     * @return array{clientId: int, username: string}
     */
    private function seedBusyCustomer(): array
    {
        $clientId = $this->fake->addClient('svc1');

        // --- websites (type='vhost'): the only rows get_quota_data returns ---
        $shop = $this->fake->addWebsite($clientId, 'shop.example', hdQuotaMegabytes: 4096);
        $blog = $this->fake->addWebsite($clientId, 'blog.example', hdQuotaMegabytes: 2048);
        $docs = $this->fake->addWebsite($clientId, 'docs.example', hdQuotaMegabytes: 1024);

        // harddisk_quota monitor values are repquota 1K blocks: 1 GiB + 512 MiB
        // + 256 MiB = 1792 MiB of disk across the three sites.
        $this->fake->setDiskUsage('web' . $shop, usedKilobytes: 1048576);
        $this->fake->setDiskUsage('web' . $blog, usedKilobytes: 524288);
        $this->fake->setDiskUsage('web' . $docs, usedKilobytes: 262144);

        // --- a subdomain vhost and an alias vhost -------------------------
        // get_quota_data() filters type='vhost' so these are NOT websites and
        // hold no disk of their own, but get_trafficquota_data() covers all
        // three types, so their bytes belong to this client's bandwidth.
        $this->fake->addWebsite($clientId, 'staging.shop.example', type: 'vhostsubdomain');
        $this->fake->addWebsite($clientId, 'shop.example.net', type: 'vhostalias');

        // --- web_traffic sums, BYTES, month to date -----------------------
        // 3 GiB + 1 GiB + 512 MiB + 256 MiB = 4864 MiB. docs.example has no
        // web_traffic row at all, so its period member is absent, not zero.
        $this->fake->setWebTraffic('shop.example', thisMonthBytes: 3 * self::GIB);
        $this->fake->setWebTraffic('blog.example', thisMonthBytes: 1 * self::GIB);
        $this->fake->setWebTraffic('staging.shop.example', thisMonthBytes: 512 * self::MIB);
        $this->fake->setWebTraffic('shop.example.net', thisMonthBytes: 256 * self::MIB);

        // --- mailboxes: quota and used are both BYTES ---------------------
        // 256 MiB + 640 MiB = 896 MiB. The third mailbox exists but has no
        // email_quota monitor entry, so it counts as a mailbox and adds no
        // storage.
        $this->fake->addMailUser($clientId, 'info@shop.example', quotaBytes: 1 * self::GIB, usedBytes: 256 * self::MIB);
        $this->fake->addMailUser($clientId, 'sales@shop.example', quotaBytes: 2 * self::GIB, usedBytes: 640 * self::MIB);
        $this->fake->addMailUser($clientId, 'noreply@blog.example', quotaBytes: 0);

        // --- databases: used_raw is information_schema BYTES --------------
        // 100 MiB + 200 MiB = 300 MiB; the third has never been measured.
        $this->fake->addDatabase($clientId, 'c1_shop', quotaMegabytes: 500);
        $this->fake->addDatabase($clientId, 'c1_blog', quotaMegabytes: -1);
        $this->fake->addDatabase($clientId, 'c1_docs', quotaMegabytes: 250);
        $this->fake->setDatabaseSize('c1_shop', bytes: 100 * self::MIB);
        $this->fake->setDatabaseSize('c1_blog', bytes: 200 * self::MIB);

        $this->fake->addFtpUser($clientId, $shop, 'svc1');
        $this->fake->addFtpUser($clientId, $shop, 'svc1_deploy');
        $this->fake->addFtpUser($clientId, $blog, 'svc1_blog');
        $this->fake->addFtpUser($clientId, $docs, 'svc1_docs');

        $this->fake->addDnsZone($clientId, 'shop.example');
        $this->fake->addDnsZone($clientId, 'blog.example');

        return ['clientId' => $clientId, 'username' => 'svc1'];
    }

    /** @return array<string, int> */
    private function busyCustomerMetrics(): array
    {
        $client = $this->seedBusyCustomer();

        return $this->provider()->tenantUsage(
            new ServiceRef(101, $client['clientId'], $client['username'])
        );
    }

    public function testEveryMetricForAMultiObjectCustomer(): void
    {
        self::assertSame(
            [
                'diskusage' => 1792,      // (1048576+524288+262144) KiB
                'bandwidth' => 4864,      // (3072+1024+512+256) MiB, this month
                'mailquota' => 896,       // (256+640) MiB
                'databasequota' => 300,   // (100+200) MiB
                'websites' => 3,          // type='vhost' only
                'mailboxes' => 3,         // including the one with no usage
                'databases' => 3,         // including the one never measured
                'ftpusers' => 4,
                'dnszones' => 2,
            ],
            $this->busyCustomerMetrics()
        );
    }

    /**
     * Disk: get_quota_data() derives used_raw as the KILOBYTE monitor value
     * times 1024 (quota_lib.inc.php:43, "Convert from kb to bytes"), and the
     * harddisk_quota monitor writes repquota/du 1K blocks
     * (100-monitor_hd_quota.inc.php). Two wrong readings are plausible, so
     * both are named and excluded.
     */
    public function testDiskUsageIsBinaryMegabytesOfBytesNotOfKilobytes(): void
    {
        $metrics = $this->busyCustomerMetrics();

        $blocksKib = 1048576 + 524288 + 262144;             // what the monitor holds
        self::assertSame(intdiv($blocksKib * self::KIB, self::MIB), $metrics['diskusage']);

        // Reading used_raw as if it were already kilobytes would report 1.75 MB
        // for 1.75 GB of disk.
        self::assertNotSame(
            QuotaMath::bytesToMegabytes($blocksKib),
            $metrics['diskusage']
        );
        // Dividing bytes by a DECIMAL megabyte would over-report by ~4.9%.
        self::assertNotSame(
            (int) round($blocksKib * self::KIB / 1000000),
            $metrics['diskusage']
        );
    }

    /**
     * Bandwidth: SUM(web_traffic.traffic_bytes) is already bytes
     * (quota_lib.inc.php:98), and it covers vhost, vhostsubdomain AND
     * vhostalias - so a customer's subdomains are billed to that customer and
     * the number is strictly larger than what the three websites alone used.
     */
    public function testBandwidthIsBinaryMegabytesAndIncludesSubdomainsAndAliases(): void
    {
        $metrics = $this->busyCustomerMetrics();

        self::assertSame(3072 + 1024 + 512 + 256, $metrics['bandwidth']);
        // The two websites with traffic account for 4096 MiB on their own; the
        // extra 768 is the subdomain and the alias, which have no web_domain
        // row of type 'vhost' and so are invisible to the websites metric.
        self::assertGreaterThan(4096, $metrics['bandwidth']);
        self::assertSame(3, $metrics['websites']);
    }

    /**
     * Mail: BOTH mail_user.quota and the email_quota monitor value are BYTES.
     * The monitor writes doveadm kB * 1024 (100-monitor_email_quota.inc.php),
     * and interface/web/mail/user_quota_stats.php:61 divides the column by
     * 1048576 to display MB - so the panel and the invoice agree.
     */
    public function testMailboxStorageIsBinaryMegabytesOfBytes(): void
    {
        $metrics = $this->busyCustomerMetrics();

        self::assertSame(256 + 640, $metrics['mailquota']);
        // Treating the monitor value as kilobytes would report 896 GB.
        self::assertNotSame(
            QuotaMath::bytesToMegabytes((256 + 640) * self::MIB * self::KIB),
            $metrics['mailquota']
        );
    }

    /**
     * Databases: used_raw is db_mysql::getDatabaseSize(), i.e.
     * SUM(data_length+index_length) from information_schema - true bytes -
     * even though quota_lib expands the QUOTA column with a decimal factor.
     * The metric reports usage only, so the decimal quirk cannot reach it.
     */
    public function testDatabaseStorageIsBinaryMegabytesOfInformationSchemaBytes(): void
    {
        $metrics = $this->busyCustomerMetrics();

        self::assertSame(100 + 200, $metrics['databasequota']);
        self::assertNotSame(
            (int) round((100 + 200) * self::MIB / 1000000),
            $metrics['databasequota']
        );
    }

    /**
     * Entity counts are counts of records, one per object, so a customer with
     * several of a thing is summed once each. The three counts below are
     * deliberately different from one another and from the number of hostnames
     * that carried traffic.
     */
    public function testEntityCountsAreOnePerObjectAndNeverMultiplied(): void
    {
        $metrics = $this->busyCustomerMetrics();

        self::assertSame(3, $metrics['websites']);
        self::assertSame(3, $metrics['mailboxes']);
        self::assertSame(3, $metrics['databases']);
        self::assertSame(4, $metrics['ftpusers']);
        self::assertSame(2, $metrics['dnszones']);
    }

    /**
     * A second customer on the same panel must not move the first one's
     * numbers - the same fixture read with a neighbour present has to give
     * byte-identical answers.
     */
    public function testANeighbourOnTheSamePanelChangesNothing(): void
    {
        $client = $this->seedBusyCustomer();

        $neighbour = $this->fake->addClient('svc2');
        $neighbourSite = $this->fake->addWebsite($neighbour, 'other.example', hdQuotaMegabytes: 8192);
        $this->fake->setDiskUsage('web' . $neighbourSite, usedKilobytes: 4194304);
        $this->fake->setWebTraffic('other.example', thisMonthBytes: 50 * self::GIB);
        $this->fake->addMailUser($neighbour, 'hi@other.example', quotaBytes: self::GIB, usedBytes: self::GIB);
        $this->fake->addDatabase($neighbour, 'c2_other', quotaMegabytes: 100);
        $this->fake->setDatabaseSize('c2_other', bytes: 90 * self::MIB);
        $this->fake->addFtpUser($neighbour, $neighbourSite, 'svc2');
        $this->fake->addDnsZone($neighbour, 'other.example');

        $mine = $this->provider()->tenantUsage(
            new ServiceRef(101, $client['clientId'], $client['username'])
        );

        self::assertSame(1792, $mine['diskusage']);
        self::assertSame(4864, $mine['bandwidth']);
        self::assertSame(896, $mine['mailquota']);
        self::assertSame(300, $mine['databasequota']);
        self::assertSame(3, $mine['websites']);
        self::assertSame(4, $mine['ftpusers']);
        self::assertSame(2, $mine['dnszones']);
    }

    // ---- edge cases ------------------------------------------------------

    /**
     * EDGE: zero. A freshly provisioned website has no monitor entry and no
     * web_traffic row yet - quota_lib leaves used/soft/hard NULL and omits the
     * period members entirely. That must read as zero usage, not as a fatal
     * and not as a skipped service.
     */
    public function testABrandNewWebsiteWithNoMonitorDataReportsZeroesAndNotAFailure(): void
    {
        $clientId = $this->fake->addClient('fresh');
        $this->fake->addWebsite($clientId, 'fresh.example', hdQuotaMegabytes: 1024);

        $metrics = $this->provider()->tenantUsage(new ServiceRef(101, $clientId, 'fresh'));

        self::assertSame(0, $metrics['diskusage']);
        self::assertSame(0, $metrics['bandwidth']);
        self::assertSame(0, $metrics['mailquota']);
        self::assertSame(0, $metrics['databasequota']);
        self::assertSame(1, $metrics['websites']);
        self::assertSame(0, $metrics['mailboxes']);
    }

    /**
     * EDGE: unlimited. The module provisions every website with hd_quota = -1
     * and traffic_quota = -1, and mailboxes with quota = 0, so "unlimited" is
     * the NORMAL state - and the usage metrics must be completely indifferent
     * to it. The same objects with finite quotas must produce identical
     * numbers, which is the property that stops a -1 or a 0 sentinel from ever
     * being mistaken for a usage figure.
     */
    public function testUnlimitedQuotasDoNotChangeAnyReportedUsage(): void
    {
        $limited = $this->fake->addClient('limited');
        $limitedSite = $this->fake->addWebsite(
            $limited,
            'limited.example',
            hdQuotaMegabytes: 4096,
            trafficQuotaMegabytes: 10240
        );
        $this->fake->setDiskUsage('web' . $limitedSite, usedKilobytes: 524288);
        $this->fake->setWebTraffic('limited.example', thisMonthBytes: 2 * self::GIB);
        $this->fake->addMailUser($limited, 'a@limited.example', quotaBytes: self::GIB, usedBytes: 128 * self::MIB);
        $this->fake->addDatabase($limited, 'c1_limited', quotaMegabytes: 500);
        $this->fake->setDatabaseSize('c1_limited', bytes: 64 * self::MIB);

        // hd_quota -1 and traffic_quota -1 are the sentinels the module writes;
        // mail_user.quota 0 and web_database.database_quota -1 are the panel's
        // own. apache2_plugin.inc.php:980 gates on "hd_quota > 0", so 0 is
        // unlimited there too.
        $unlimited = $this->fake->addClient('unlimited');
        $unlimitedSite = $this->fake->addWebsite(
            $unlimited,
            'unlimited.example',
            hdQuotaMegabytes: -1,
            trafficQuotaMegabytes: -1
        );
        $this->fake->setDiskUsage('web' . $unlimitedSite, usedKilobytes: 524288);
        $this->fake->setWebTraffic('unlimited.example', thisMonthBytes: 2 * self::GIB);
        $this->fake->addMailUser($unlimited, 'a@unlimited.example', quotaBytes: 0, usedBytes: 128 * self::MIB);
        $this->fake->addDatabase($unlimited, 'c1_unlimited', quotaMegabytes: -1);
        $this->fake->setDatabaseSize('c1_unlimited', bytes: 64 * self::MIB);

        $provider = $this->provider();

        self::assertSame(
            $provider->tenantUsage(new ServiceRef(101, $limited, 'limited')),
            $provider->tenantUsage(new ServiceRef(102, $unlimited, 'unlimited'))
        );
    }

    /** EDGE: hd_quota 0 is unlimited too - apache2_plugin.inc.php:980. */
    public function testAZeroDiskQuotaIsUnlimitedNotAZeroLimit(): void
    {
        $clientId = $this->fake->addClient('zeroquota');
        $siteId = $this->fake->addWebsite($clientId, 'zero.example', hdQuotaMegabytes: 0);
        $this->fake->setDiskUsage('web' . $siteId, usedKilobytes: 524288);

        $usage = (new \WHMCS\Module\Server\Ispconfigconnect\Usage\Collector(
            new SessionManager(new RestClient($this->fake), 'remote', 'secret')
        ))->usageFor($clientId);

        self::assertTrue($usage->diskUnlimited());
        self::assertSame(0, $usage->diskLimitMegabytes());
        self::assertSame(512, $usage->diskUsageMegabytes());
    }

    /**
     * EDGE: missing objects. A client whose mailboxes and databases were
     * deleted by hand still has a website, so the service is attributable and
     * the vanished objects simply read as zero.
     */
    public function testDeletedMailboxesAndDatabasesReadAsZeroWhileAWebsiteRemains(): void
    {
        $clientId = $this->fake->addClient('shrunk');
        $siteId = $this->fake->addWebsite($clientId, 'shrunk.example');
        $this->fake->setDiskUsage('web' . $siteId, usedKilobytes: 131072);

        $metrics = $this->provider()->tenantUsage(new ServiceRef(101, $clientId, 'shrunk'));

        self::assertSame(128, $metrics['diskusage']);
        self::assertSame(0, $metrics['mailquota']);
        self::assertSame(0, $metrics['mailboxes']);
        self::assertSame(0, $metrics['databasequota']);
        self::assertSame(0, $metrics['databases']);
    }

    /**
     * EDGE: huge. Multi-terabyte figures exceed 32 bits and are handed over as
     * mysqli STRINGS, so they must survive as exact integers - a float round
     * trip here would show up as a few megabytes of drift on a large invoice.
     */
    public function testMultiTerabyteFiguresStayExactIntegers(): void
    {
        $eightTib = 8 * 1024 * self::GIB;
        $fiveTib = 5 * 1024 * self::GIB;

        $clientId = $this->fake->addClient('whale');
        $siteId = $this->fake->addWebsite($clientId, 'whale.example');
        $this->fake->setDiskUsage('web' . $siteId, usedKilobytes: intdiv($eightTib, self::KIB));
        $this->fake->setWebTraffic('whale.example', thisMonthBytes: $fiveTib);

        $metrics = $this->provider()->tenantUsage(new ServiceRef(101, $clientId, 'whale'));

        self::assertSame(8 * 1024 * 1024, $metrics['diskusage']);   // 8_388_608 MB
        self::assertSame(5 * 1024 * 1024, $metrics['bandwidth']);   // 5_242_880 MB
        self::assertIsInt($metrics['diskusage']);
        self::assertIsInt($metrics['bandwidth']);
    }

    // ---- documented interpretations --------------------------------------

    /**
     * Sub-megabyte usage rounds to a WHOLE megabyte, half away from zero. The
     * value we hand WHMCS is an int because \WHMCS\UsageBilling\Metrics\Usage
     * is fed one number per metric and tblhosting's usage columns are whole
     * megabytes; the consequence is that a tenant using less than half a
     * megabyte bills as zero. That is the interpretation this module chose,
     * and it under-reports rather than over-reports, which is the safe
     * direction for a customer invoice.
     *
     * @param int $bytes
     * @param int $expectedMegabytes
     */
    #[DataProvider('roundingCases')]
    public function testSubMegabyteUsageRoundsToWholeMegabytesHalfAwayFromZero(
        int $bytes,
        int $expectedMegabytes
    ): void {
        $clientId = $this->fake->addClient('rounding' . $bytes);
        $siteId = $this->fake->addWebsite($clientId, 'r' . $bytes . '.example');
        $this->fake->setDiskUsage('web' . $siteId, usedKilobytes: intdiv($bytes, self::KIB));

        $metrics = $this->provider()->tenantUsage(new ServiceRef(101, $clientId, 'rounding' . $bytes));

        self::assertSame($expectedMegabytes, $metrics['diskusage']);
    }

    /** @return array<string, array{int, int}> */
    public static function roundingCases(): array
    {
        return [
            'nothing at all' => [0, 0],
            '400 KiB bills as nothing' => [409600, 0],
            'exactly half a MiB rounds up' => [524288, 1],
            'just under half a MiB rounds down' => [523264, 0],
            'one and a half MiB rounds up' => [1572864, 2],
            'exactly one MiB' => [1048576, 1],
        ];
    }

    /**
     * A DOCUMENTED DISAGREEMENT, pinned so nobody "fixes" it by accident.
     *
     * quota_lib.inc.php:301 expands web_database.database_quota with a DECIMAL
     * factor - "database_quota * 1000 * 1000" - while the server-side
     * over-quota check for the same column uses 1024*1024
     * (100-monitor_database_size.inc.php). ClientUsage keeps the panel's own
     * decimal-expanded byte figure verbatim, so reading it back through the
     * binary divisor reports a 500 MB panel quota as 477 MB.
     *
     * This is deliberately harmless: no metric and no tblhosting column
     * carries a database LIMIT, so the discrepancy never reaches an invoice -
     * only databasequota USAGE is billed, and that comes from used_raw, which
     * is true information_schema bytes. Left as-is rather than "corrected",
     * because the byte value on ClientUsage is meant to be what the panel
     * said, not a reinterpretation of it.
     */
    public function testTheDatabaseQuotaReadbackKeepsIspconfigsDecimalExpansion(): void
    {
        $clientId = $this->fake->addClient('dbquota');
        $this->fake->addWebsite($clientId, 'dbq.example');
        $this->fake->addDatabase($clientId, 'c1_dbq', quotaMegabytes: 500);
        $this->fake->setDatabaseSize('c1_dbq', bytes: 100 * self::MIB);

        $usage = (new \WHMCS\Module\Server\Ispconfigconnect\Usage\Collector(
            new SessionManager(new RestClient($this->fake), 'remote', 'secret')
        ))->metricsFor($clientId, 'dbquota');

        // The wire value, untouched: 500 * 1000 * 1000.
        self::assertSame(500000000, $usage->databaseQuotaBytes);
        // Read back as binary megabytes it is 477, not the 500 the panel shows.
        self::assertSame(477, $usage->databaseQuotaMegabytes());
        // USAGE, the only half that is ever billed, is exact.
        self::assertSame(100, $usage->databaseUsageMegabytes());
    }
}
