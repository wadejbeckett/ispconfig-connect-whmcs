<?php

declare(strict_types=1);

namespace Ispconfigconnect\Tests\Unit\Usage;

use Ispconfigconnect\Tests\Support\FakeIspconfigServer;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use WHMCS\Module\Server\Ispconfigconnect\Api\RestClient;
use WHMCS\Module\Server\Ispconfigconnect\Api\SessionManager;

/**
 * The units are the whole game, and UsageParser can only be trusted if the
 * shapes it is fed are the shapes ISPConfig sends. These tests assert the RAW
 * envelope contents the double emits for the four quota functions, against
 * interface/lib/classes/quota_lib.inc.php of 3.3.1p1 - so a drift in the
 * double shows up here rather than as a silently wrong invoice.
 */
#[CoversNothing]
final class QuotaWireShapeTest extends TestCase
{
    private FakeIspconfigServer $fake;
    private SessionManager $session;

    protected function setUp(): void
    {
        $this->fake = new FakeIspconfigServer();
        $this->session = new SessionManager(new RestClient($this->fake), 'remote', 'secret');
    }

    public function testQuotaGetByUserReportsKilobyteMonitorValuesAndByteRawFields(): void
    {
        $clientId = $this->fake->addClient('svc1');
        $domainId = $this->fake->addWebsite($clientId, 'one.example', hdQuotaMegabytes: 1024);
        $this->fake->setDiskUsage(
            'web' . $domainId,
            usedKilobytes: 524288,
            softKilobytes: 1048576,
            hardKilobytes: 1048576,
            files: 1234
        );

        $rows = $this->session->call('quota_get_by_user', ['client_id' => $clientId]);

        self::assertIsArray($rows);
        self::assertCount(1, $rows);
        $row = $rows[0];
        self::assertIsArray($row);

        // Monitor members: repquota block counts, KILOBYTES, and stringly
        // typed because they survive an unserialize() of preg_split() output.
        self::assertSame('524288', $row['used']);
        self::assertSame('1048576', $row['soft']);
        self::assertSame('1234', $row['files']);

        // *_raw: computed in PHP after the query, so real JSON numbers, in BYTES.
        self::assertSame(536870912, $row['used_raw']);
        self::assertSame(1073741824, $row['soft_raw']);
        self::assertSame(1073741824, $row['hard_raw']);

        // web_domain columns: mysqli strings. hd_quota is MEGABYTES.
        self::assertSame('1024', $row['hd_quota']);
        self::assertSame('vhost', $row['type']);
        self::assertSame('web' . $domainId, $row['system_user']);
        self::assertSame(50, $row['used_percentage']);
    }

    public function testQuotaGetByUserLeavesMonitorMembersNullAndSoftRawMinusOneWithoutMonitorData(): void
    {
        $clientId = $this->fake->addClient('svc1');
        $this->fake->addWebsite($clientId, 'one.example', hdQuotaMegabytes: 1024);

        $rows = $this->session->call('quota_get_by_user', ['client_id' => $clientId]);

        self::assertIsArray($rows);
        $row = $rows[0];
        self::assertIsArray($row);
        self::assertNull($row['used']);
        self::assertNull($row['soft']);
        self::assertSame(0, $row['used_raw']);
        // "use -1 for instead of 0 for Unlimited" - quota_lib.inc.php.
        self::assertSame(-1, $row['soft_raw']);
        self::assertSame(-1, $row['hard_raw']);
    }

    public function testTrafficQuotaIsKeyedByDomainNameAndOmitsPeriodsWithNoRows(): void
    {
        $clientId = $this->fake->addClient('svc1');
        $withTraffic = $this->fake->addWebsite($clientId, 'one.example');
        $this->fake->addWebsite($clientId, 'quiet.example');
        $this->fake->setWebTraffic('one.example', thisMonthBytes: 2147483648);

        $traffic = $this->session->call('trafficquota_get_by_user', [
            'client_id' => $clientId,
            'lastdays' => 0,
        ]);

        self::assertIsArray($traffic);
        self::assertSame(['one.example', 'quiet.example'], array_keys($traffic));
        self::assertIsArray($traffic['one.example']);
        self::assertSame((string) $withTraffic, $traffic['one.example']['domain_id']);
        // SUM(traffic_bytes): BYTES, and a mysqli string.
        self::assertSame('2147483648', $traffic['one.example']['this_month']);
        // No web_traffic row -> the period member is absent, not zero.
        self::assertIsArray($traffic['quiet.example']);
        self::assertArrayNotHasKey('this_month', $traffic['quiet.example']);
    }

    public function testMailQuotaReportsBytesForBothQuotaAndUsed(): void
    {
        $clientId = $this->fake->addClient('svc1');
        $this->fake->addMailUser($clientId, 'info@one.example', quotaBytes: 1073741824, usedBytes: 268435456);
        $this->fake->addMailUser($clientId, 'quiet@one.example', quotaBytes: 0);

        $rows = $this->session->call('mailquota_get_by_user', ['client_id' => $clientId]);

        self::assertIsArray($rows);
        self::assertCount(2, $rows);
        self::assertIsArray($rows[0]);
        self::assertSame('1073741824', $rows[0]['quota']);
        self::assertSame('268435456', $rows[0]['used']);
        self::assertSame('268435456', $rows[0]['used_raw']);
        // quota == 0 is unlimited; the $readable branch that rewrites it to -1
        // is never taken over the remote API.
        self::assertIsArray($rows[1]);
        self::assertSame('0', $rows[1]['quota']);
        self::assertSame(0, $rows[1]['used_raw']);
    }

    public function testDatabaseQuotaExpandsMegabytesWithADecimalFactor(): void
    {
        $clientId = $this->fake->addClient('svc1');
        $this->fake->addDatabase($clientId, 'c1_shop', quotaMegabytes: 500);
        $this->fake->addDatabase($clientId, 'c1_blog', quotaMegabytes: -1);
        $this->fake->setDatabaseSize('c1_shop', bytes: 104857600);

        $rows = $this->session->call('databasequota_get_by_user', ['client_id' => $clientId]);

        self::assertIsArray($rows);
        self::assertCount(2, $rows);
        self::assertIsArray($rows[0]);
        // database_quota is MEGABYTES; quota_lib expands it with 1000 * 1000,
        // not 1024 * 1024 - an ISPConfig inconsistency we reproduce verbatim.
        self::assertSame('500', $rows[0]['database_quota']);
        self::assertSame(500000000, $rows[0]['database_quota_raw']);
        self::assertSame('104857600', $rows[0]['used_raw']);
        self::assertIsArray($rows[1]);
        self::assertSame(-1, $rows[1]['database_quota_raw']);
        self::assertSame(0, $rows[1]['used_raw']);
    }

    public function testStaleClientIdReturnsZeroRowsFromTheQuotaFunctions(): void
    {
        // clientid_to_groups_list() returns NULL for a client id with no client
        // row (functions.inc.php:687, GROUP_CONCAT over an empty set), and
        // getAuthSQL('r', '', '', NULL) then degrades to its FALLBACK clause,
        // not to '1': the '1' shortcut is gated on
        // $_SESSION['s']['user']['typ'] == 'admin' (tform_base.inc.php:1633),
        // and the only writes of typ='admin' are in loadUserProfile
        // (remoting_lib.inc.php:143/:155) - which the quota_lib remote path
        // never calls. With no session user and no groups the WHERE collapses
        // to "sys_perm_other like '%r%'", and web_domain rows carry
        // sys_perm_other = '' (web_vhost_domain.tform.php:76 auth_preset), so
        // a stale/deleted client id answers with ZERO rows - silently, with
        // code ok. The same holds for mail and database quota
        // (quota_lib.inc.php:18/:224/:290 share the scoping), and the traffic
        // call's sys_user subselect matches nothing either.
        $first = $this->fake->addClient('svc1');
        $this->fake->addWebsite($first, 'one.example');
        $second = $this->fake->addClient('svc2');
        $this->fake->addWebsite($second, 'two.example');

        $rows = $this->session->call('quota_get_by_user', ['client_id' => 999]);
        self::assertSame([], $rows);

        $traffic = $this->session->call('trafficquota_get_by_user', ['client_id' => 999, 'lastdays' => 0]);
        self::assertSame([], $traffic);

        $mail = $this->session->call('mailquota_get_by_user', ['client_id' => 999]);
        self::assertSame([], $mail);

        $databases = $this->session->call('databasequota_get_by_user', ['client_id' => 999]);
        self::assertSame([], $databases);
    }

    public function testFilteredGetsReturnListsScopedByGroupId(): void
    {
        $clientId = $this->fake->addClient('svc1');
        $domainId = $this->fake->addWebsite($clientId, 'one.example');
        $this->fake->addFtpUser($clientId, $domainId, 'svc1');
        $this->fake->addDnsZone($clientId, 'one.example');

        $other = $this->fake->addClient('svc2');
        $otherDomain = $this->fake->addWebsite($other, 'two.example');
        $this->fake->addFtpUser($other, $otherDomain, 'svc2');
        $this->fake->addDnsZone($other, 'two.example');

        $rows = $this->session->call('quota_get_by_user', ['client_id' => $clientId]);
        self::assertIsArray($rows);
        self::assertIsArray($rows[0]);
        $groupId = $rows[0]['sys_groupid'];

        $ftpUsers = $this->session->call('sites_ftp_user_get', ['primary_id' => ['sys_groupid' => $groupId]]);
        $zones = $this->session->call('dns_zone_get', ['primary_id' => ['sys_groupid' => $groupId]]);

        self::assertIsArray($ftpUsers);
        self::assertCount(1, $ftpUsers);
        self::assertIsArray($ftpUsers[0]);
        self::assertSame('svc1', $ftpUsers[0]['username']);
        self::assertIsArray($zones);
        self::assertCount(1, $zones);
        self::assertIsArray($zones[0]);
        self::assertSame('one.example.', $zones[0]['origin']);
    }

    public function testAnIntegerPrimaryIdStillReturnsASingleRecord(): void
    {
        $clientId = $this->fake->addClient('svc1');
        $domainId = $this->fake->addWebsite($clientId, 'one.example');
        $ftpUserId = $this->fake->addFtpUser($clientId, $domainId, 'svc1');

        $record = $this->session->call('sites_ftp_user_get', ['primary_id' => $ftpUserId]);

        self::assertIsArray($record);
        self::assertSame('svc1', $record['username']);
    }
}
