<?php

declare(strict_types=1);

namespace Ispconfigconnect\Tests\Unit\Usage;

use Ispconfigconnect\Tests\Support\FakeIspconfigServer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WHMCS\Module\Server\Ispconfigconnect\Api\RestClient;
use WHMCS\Module\Server\Ispconfigconnect\Api\SessionManager;
use WHMCS\Module\Server\Ispconfigconnect\Usage\Collector;
use WHMCS\Module\Server\Ispconfigconnect\Usage\UsageException;

/**
 * When a client owns no website, mailbox or database (a DNS-only or FTP-only
 * product), no quota record carries its sys_groupid, so the group that scopes
 * the FTP and DNS listings is resolved from the USERNAME via
 * client_get_by_username. That lookup keys on the username alone (review U6): if
 * the stored username and client id disagree - a panel-side rename, or a
 * fallback to tblhosting.username - the sys_user row returned belongs to another
 * client, and its default_group would bill that client's zones and FTP users to
 * this service. The resolved row's client_id must therefore be checked against
 * the one stored for the service, and a mismatch refused.
 */
#[CoversClass(Collector::class)]
final class CollectorGroupResolutionTest extends TestCase
{
    private FakeIspconfigServer $fake;

    protected function setUp(): void
    {
        $this->fake = new FakeIspconfigServer();
    }

    private function collector(): Collector
    {
        return new Collector(new SessionManager(new RestClient($this->fake), 'remote', 'secret'));
    }

    public function testGroupResolutionRefusesAUsernameThatNamesADifferentClient(): void
    {
        // The service stores client #X (owns nothing), but its stored username
        // belongs to client #Y, who owns five DNS zones. Trusting the username
        // would report Y's five zones against X's service. It must throw.
        $lonely = $this->fake->addClient('lonely');           // #X, owns nothing
        $other = $this->fake->addClient('other');             // #Y
        for ($i = 1; $i <= 5; $i++) {
            $this->fake->addDnsZone($other, "zone{$i}.example");
        }

        try {
            $this->collector()->metricsFor($lonely, 'other');
            self::fail('a username naming a different client must be refused');
        } catch (UsageException $refused) {
            self::assertStringContainsString('resolves to client #' . $other, $refused->getMessage());
            self::assertStringContainsString('not the #' . $lonely, $refused->getMessage());
        }
    }

    public function testGroupResolutionAcceptsAUsernameThatNamesTheStoredClient(): void
    {
        // The honest DNS-only case: username and client id agree, so the group
        // resolves and the client's own zones are counted. This proves the
        // client-id check is not over-tight - it only fires on a mismatch.
        $dnsOnly = $this->fake->addClient('dnsonly');
        $this->fake->addDnsZone($dnsOnly, 'zone.example');
        $this->fake->addDnsZone($dnsOnly, 'other.example');

        $usage = $this->collector()->metricsFor($dnsOnly, 'dnsonly');

        self::assertSame(2, $usage->dnsZoneCount);
    }
}
