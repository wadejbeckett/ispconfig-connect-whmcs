<?php

declare(strict_types=1);

namespace Ispconfigconnect\Tests\Unit\Usage;

use Ispconfigconnect\Tests\Support\FakeIspconfigServer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WHMCS\Module\Server\Ispconfigconnect\Api\RestClient;
use WHMCS\Module\Server\Ispconfigconnect\Api\SessionManager;
use WHMCS\Module\Server\Ispconfigconnect\Metrics\Provider;
use WHMCS\Module\Server\Ispconfigconnect\Usage\ServiceRef;
use WHMCS\Module\Server\Ispconfigconnect\Usage\UsageException;
use WHMCS\Module\Server\Ispconfigconnect\Usage\UsageParser;

/**
 * The quota responses are not typed data. mysqli hands every COLUMN back as a
 * string; quota_lib computes the *_raw members in PHP so THOSE arrive as JSON
 * numbers; a missing harddisk_quota monitor entry leaves used/soft/hard NULL;
 * array_merge_recursive over two monitor_data rows can turn a scalar into an
 * array before quota_lib collapses it; and a MySQL SUM() over a bigint comes
 * back as a DECIMAL string. Anything that reaches an arithmetic operator here
 * ends up on an invoice, so every one of those shapes is pinned.
 */
#[CoversClass(UsageParser::class)]
final class UsageWireValueEdgeCaseTest extends TestCase
{
    private const GIB = 1073741824;

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function siteRow(array $overrides = []): array
    {
        return array_merge([
            'domain_id' => '5',
            'sys_groupid' => '3',
            'domain' => 'example.com',
            'type' => 'vhost',
            'active' => 'y',
            'system_user' => 'web5',
            'hd_quota' => '-1',
            'traffic_quota' => '-1',
            'used' => '524288',
            'soft' => null,
            'hard' => null,
            'files' => null,
            'used_raw' => 536870912,
            'soft_raw' => -1,
            'hard_raw' => -1,
        ], $overrides);
    }

    /**
     * @param mixed $usedRaw
     * @param int $expectedMegabytes
     */
    #[DataProvider('diskUsedRawShapes')]
    public function testDiskUsedRawSurvivesEveryShapeTheWireCanCarry(
        mixed $usedRaw,
        int $expectedMegabytes
    ): void {
        $usage = UsageParser::parse(
            clientId: 2,
            diskQuota: [self::siteRow(['used_raw' => $usedRaw])],
            traffic: [],
        );

        self::assertSame($expectedMegabytes, $usage->diskUsageMegabytes());
    }

    /** @return array<string, array{mixed, int}> */
    public static function diskUsedRawShapes(): array
    {
        return [
            'json number, as quota_lib computes it' => [536870912, 512],
            'mysqli string' => ['536870912', 512],
            'decimal string from a SUM' => ['536870912.0000', 512],
            'float' => [536870912.0, 512],
            'scientific notation string' => ['5.36870912e8', 512],
            'padded string' => ['  536870912  ', 512],
            // A monitor entry that never arrived: quota_lib multiplies NULL by
            // 1024 and gets 0.
            'null' => [null, 0],
            'zero' => [0, 0],
            // array_merge_recursive left an array behind and the collapse in
            // get_quota_data() did not reach it. Never a fatal, never a number.
            'array' => [[1024, 2048], 0],
            'non-numeric junk' => ['n/a', 0],
            // Nothing physical produces this, but a negative must never make a
            // usage figure negative.
            'negative' => [-536870912, 0],
        ];
    }

    /**
     * Every member of a traffic entry except the period sums is metadata.
     * domain_id in particular is always present and always numeric, so a
     * parser that summed the row instead of reading 'this_month' would quietly
     * bill it. A big domain_id with no traffic at all proves it is not read.
     */
    public function testTrafficMetadataMembersNeverLeakIntoTheBandwidthFigure(): void
    {
        $usage = UsageParser::parse(
            clientId: 2,
            diskQuota: [],
            traffic: [
                'quiet.example' => ['domain_id' => '999999999'],
                'busy.example' => ['domain_id' => '888888888', 'this_month' => (string) self::GIB],
            ],
        );

        self::assertSame(1024, $usage->bandwidthUsageMegabytes());
    }

    /**
     * Disk rows carry the same hazard: used_percentage, files_raw, hd_quota,
     * soft_raw and hard_raw are all numeric, and only used_raw is usage.
     */
    public function testDiskMetadataMembersNeverLeakIntoTheDiskFigure(): void
    {
        $usage = UsageParser::parse(
            clientId: 2,
            diskQuota: [self::siteRow([
                'used_raw' => 0,
                'used' => '0',
                'hd_quota' => '4096',
                'soft_raw' => 4294967296,
                'hard_raw' => 4294967296,
                'files_raw' => '123456',
                'used_percentage' => 87,
            ])],
            traffic: [],
        );

        self::assertSame(0, $usage->diskUsageMegabytes());
    }

    /**
     * A mailbox row where the email_quota monitor never ran: quota_lib defaults
     * used to array(1 => 0), takes member 1, and copies it into used_raw - so
     * the wire value is an honest zero, and the mailbox still counts.
     */
    public function testAMailboxWithNoMonitorEntryCountsButAddsNoStorage(): void
    {
        $usage = UsageParser::parse(
            clientId: 2,
            diskQuota: [],
            traffic: [],
            mailQuota: [
                ['mailuser_id' => '1', 'sys_groupid' => '3', 'email' => 'a@x.test', 'quota' => '0', 'quota_raw' => '0', 'used' => 0, 'used_raw' => 0],
                ['mailuser_id' => '2', 'sys_groupid' => '3', 'email' => 'b@x.test', 'quota' => '1073741824', 'quota_raw' => '1073741824', 'used' => '268435456', 'used_raw' => '268435456'],
            ],
        );

        self::assertSame(2, $usage->mailboxCount);
        self::assertSame(256, $usage->mailUsageMegabytes());
        // One unlimited mailbox makes the client's mail quota unlimited.
        self::assertTrue($usage->mailQuotaUnlimited());
    }

    /**
     * A row that is not an array at all - a permission-denied path can put
     * false or a string into the list - is skipped rather than counted.
     */
    public function testNonArrayRowsAreSkippedAndDoNotInflateCounts(): void
    {
        $usage = UsageParser::parse(
            clientId: 2,
            diskQuota: [self::siteRow(), false, 'permission_denied', null],
            traffic: ['busy.example' => ['this_month' => '1048576'], 'broken.example' => false],
        );

        self::assertSame(1, $usage->websiteCount);
        self::assertSame(512, $usage->diskUsageMegabytes());
        self::assertSame(1, $usage->bandwidthUsageMegabytes());
    }

    /**
     * A row with no sys_groupid at all cannot be attributed, and must not be
     * silently treated as belonging to the client - it just contributes no
     * group. (Collector then falls back to sys_user.default_group.)
     */
    public function testRowsWithoutASysGroupIdContributeNoGroup(): void
    {
        $usage = UsageParser::parse(
            clientId: 2,
            diskQuota: [self::siteRow(['sys_groupid' => null]), self::siteRow(['domain_id' => '6'])],
            traffic: [],
        );

        self::assertSame([3], $usage->sysGroupIds);
        self::assertSame(3, $usage->sysGroupId);
    }

    /**
     * The reseller refusal, exercised through the METRICS depth rather than
     * the usage depth: mail and database rows also carry sys_groupid there, so
     * a client id whose records span several groups has three chances to be
     * detected. Summing another client's websites into one service's bill is
     * the worst thing this module could do, so it refuses the whole read.
     */
    public function testARecordSetSpanningGroupsIsRefusedAtTheMetricsDepthToo(): void
    {
        $fake = new FakeIspconfigServer();
        $clientId = $fake->addClient('reseller');
        $fake->addWebsite($clientId, 'one.example');

        // clientid_to_groups_list() concatenates a reseller's own group with
        // its sub-clients' groups (functions.inc.php:687), so quota_lib answers
        // with rows from several sys_groupids at once.
        $fake->quotaData = [
            ['domain_id' => '1', 'sys_groupid' => '3', 'type' => 'vhost', 'used_raw' => 1073741824, 'hd_quota' => '-1'],
            ['domain_id' => '2', 'sys_groupid' => '9', 'type' => 'vhost', 'used_raw' => 2147483648, 'hd_quota' => '-1'],
        ];

        $provider = new Provider(new SessionManager(new RestClient($fake), 'remote', 'secret'));

        try {
            $provider->tenantUsage(new ServiceRef(101, $clientId, 'reseller'));
            self::fail('a record set spanning several groups must not be billed to one service');
        } catch (UsageException $refused) {
            self::assertStringContainsString('spans 2 sys_groupid', $refused->getMessage());
        }
    }
}
