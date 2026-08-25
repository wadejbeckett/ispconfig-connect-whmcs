<?php

declare(strict_types=1);

namespace Ispconfigconnect\Tests\Unit\Sync;

use Ispconfigconnect\Tests\Support\FakeIspconfigServer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WHMCS\Module\Server\Ispconfigconnect\Api\Exception\PermissionDeniedException;
use WHMCS\Module\Server\Ispconfigconnect\Api\RestClient;
use WHMCS\Module\Server\Ispconfigconnect\Api\SessionManager;
use WHMCS\Module\Server\Ispconfigconnect\Sync\AccountLister;
use WHMCS\Module\Server\Ispconfigconnect\Sync\RemoteAccount;

/**
 * AccountLister runs through the full real stack - SessionManager ->
 * RestClient -> FakeIspconfigServer (the executable 3.3.1p1 wire contract) -
 * so every request here is a valid remote/json.php round trip.
 */
#[CoversClass(AccountLister::class)]
#[CoversClass(RemoteAccount::class)]
final class AccountListerTest extends TestCase
{
    private FakeIspconfigServer $fake;
    private SessionManager $session;

    protected function setUp(): void
    {
        $this->fake = new FakeIspconfigServer('remote', 'secret');
        $this->session = new SessionManager(new RestClient($this->fake), 'remote', 'secret');
    }

    private function lister(string $primaryIp = '203.0.113.10'): AccountLister
    {
        // A fixed zone keeps created timestamps deterministic regardless of
        // the machine's date.timezone.
        return new AccountLister($this->session, $primaryIp, new \DateTimeZone('UTC'));
    }

    /** @param array<string, mixed> $extra */
    private function addClient(string $username, array $extra = []): int
    {
        return (int) $this->session->call('client_add', [
            'reseller_id' => 0,
            'params' => $extra + [
                'company_name' => 'ACME',
                'contact_name' => 'Jane Doe',
                'username' => $username,
                'password' => 'Str0ngPass!',
                'email' => $username . '@example.com',
                'language' => 'en',
                'usertheme' => 'default',
                'web_php_options' => 'no,fast-cgi,cgi,mod,suphp,php-fpm,hhvm',
                'ssh_chroot' => 'no,jailkit',
            ],
        ]);
    }

    /** @param array<string, mixed> $extra */
    private function addSite(int $clientId, string $domain, array $extra = []): int
    {
        return (int) $this->session->call('sites_web_domain_add', [
            'client_id' => $clientId,
            'params' => $extra + [
                'server_id' => 1,
                'domain' => $domain,
                'type' => 'vhost',
                'hd_quota' => -1,
                'traffic_quota' => -1,
                'pm_process_idle_timeout' => 10,
                'pm_max_requests' => 0,
                'http_port' => 80,
                'https_port' => 443,
            ],
            'readonly' => false,
        ]);
    }

    /**
     * client.inc.php client_update() merges the stored record into $params, so
     * a one-field update is enough - and it applies no NOTEMPTY validation,
     * which is exactly how a real panel ends up with an unusable username.
     */
    private function setUsername(int $clientId, string $username): void
    {
        $this->session->call('client_update', [
            'client_id' => $clientId,
            'reseller_id' => 0,
            // _ispconfig_pw_crypted keeps the merge from re-crypting the
            // already-hashed password (see FakeIspconfigServer).
            'params' => ['username' => $username, '_ispconfig_pw_crypted' => 1],
        ]);
    }

    /** @return list<string> every remote method name the fake saw, in order */
    private function methods(): array
    {
        return array_map(
            static fn (array $entry): string => $entry['method'],
            $this->fake->requestLog
        );
    }

    private function countCalls(string $method): int
    {
        return count(array_filter($this->methods(), static fn (string $m): bool => $m === $method));
    }

    // ---- listing ----------------------------------------------------------

    public function testListsEveryClientWithItsPrimaryDomainTemplateAndStatus(): void
    {
        $basic = $this->fake->addClientTemplate('Basic');
        $pro = $this->fake->addClientTemplate('Pro');

        $alice = $this->addClient('alice', ['template_master' => $basic]);
        $this->addSite($alice, 'alice.example');

        $bob = $this->addClient('bob', ['template_master' => $pro, 'locked' => 'y']);
        $this->addSite($bob, 'bob.example');

        $accounts = $this->lister()->listAccounts();

        self::assertCount(2, $accounts);

        self::assertSame($alice, $accounts[0]->clientId);
        self::assertSame('alice', $accounts[0]->username);
        self::assertSame('alice@example.com', $accounts[0]->email);
        self::assertSame('alice.example', $accounts[0]->domain);
        self::assertSame('alice.example', $accounts[0]->uniqueIdentifier);
        self::assertSame((string) $basic, $accounts[0]->product);
        self::assertSame('Basic', $accounts[0]->templateName);
        self::assertSame('203.0.113.10', $accounts[0]->primaryIp);
        self::assertSame('2023-11-14 22:13:20', $accounts[0]->created);
        self::assertFalse($accounts[0]->suspended);

        self::assertSame($bob, $accounts[1]->clientId);
        self::assertSame('bob.example', $accounts[1]->domain);
        self::assertSame((string) $pro, $accounts[1]->product);
        self::assertSame('Pro', $accounts[1]->templateName);
        // client.locked = 'y' is ISPConfig's whole-client suspension.
        self::assertTrue($accounts[1]->suspended);
    }

    public function testEmptyPanelReturnsNoAccounts(): void
    {
        self::assertSame([], $this->lister()->listAccounts());
        self::assertSame([], AccountLister::toWhmcsAccounts($this->lister()->listAccounts()));
    }

    public function testClientWithoutWebsiteGetsASyntheticIdentifierWhmcsCanMatchOn(): void
    {
        $basic = $this->fake->addClientTemplate('Basic');
        $clientId = $this->addClient('nosite', ['template_master' => $basic]);

        $accounts = $this->lister()->listAccounts();

        self::assertCount(1, $accounts);
        // The panel fact stays honest: this client owns no website.
        self::assertSame('', $accounts[0]->domain);
        self::assertNull($accounts[0]->domainId);
        // ... but the value WHMCS matches on must never be empty, because
        // ListAccountsUniqueIdentifierField is 'domain' and an empty domain
        // would match every WHMCS service that has no domain of its own.
        self::assertSame(
            'client-' . $clientId . '.no-domain.invalid',
            $accounts[0]->uniqueIdentifier
        );
        self::assertFalse($accounts[0]->suspended);
    }

    public function testUnknownTemplateIdKeepsTheIdAsProductWithoutAName(): void
    {
        $this->fake->addClientTemplate('Basic'); // id 1, deliberately not used
        $this->addClient('orphan', ['template_master' => 4242]);

        $accounts = $this->lister()->listAccounts();

        // The id still matches configoption1 even when the template row is gone.
        self::assertSame('4242', $accounts[0]->product);
        self::assertSame('', $accounts[0]->templateName);
    }

    public function testClientWithoutATemplateHasAnEmptyProduct(): void
    {
        $this->addClient('templateless');

        $accounts = $this->lister()->listAccounts();

        self::assertSame('', $accounts[0]->product);
        self::assertSame('', $accounts[0]->templateName);
    }

    public function testTheOldestWebsiteIsThePrimaryDomain(): void
    {
        $clientId = $this->addClient('multi');
        $first = $this->addSite($clientId, 'first.example');
        $this->addSite($clientId, 'second.example');

        $accounts = $this->lister()->listAccounts();

        self::assertSame('first.example', $accounts[0]->domain);
        self::assertSame($first, $accounts[0]->domainId);
    }

    public function testWebsitesOfOtherClientsAreNotMixedIn(): void
    {
        $one = $this->addClient('one');
        $this->addSite($one, 'one.example');
        $two = $this->addClient('two');
        $this->addSite($two, 'two.example');

        $accounts = $this->lister()->listAccounts();

        self::assertSame('one.example', $accounts[0]->domain);
        self::assertSame('two.example', $accounts[1]->domain);
    }

    public function testAnInactivePrimaryWebsiteAlsoCountsAsSuspended(): void
    {
        $clientId = $this->addClient('websuspended');
        $domainId = $this->addSite($clientId, 'websuspended.example');

        // Website-only suspend mode (config option 13 unchecked) never touches
        // client.locked - it only deactivates the vhost.
        $this->session->call('sites_web_domain_set_status', [
            'primary_id' => $domainId,
            'status' => 'inactive',
        ]);

        $accounts = $this->lister()->listAccounts();

        self::assertSame('n', $this->fake->clients()[$clientId]['locked']);
        self::assertTrue($accounts[0]->suspended);
    }

    public function testCreatedIsEmptyWhenIspconfigNeverStampedTheClient(): void
    {
        $clientId = $this->addClient('nostamp');
        $this->session->call('client_update', [
            'client_id' => $clientId,
            'reseller_id' => 0,
            'params' => ['created_at' => null, '_ispconfig_pw_crypted' => 1],
        ]);

        $accounts = $this->lister()->listAccounts();

        self::assertSame('', $accounts[0]->created);
    }

    public function testCreatedIsRenderedInTheGivenTimezone(): void
    {
        $this->addClient('tz');

        $lister = new AccountLister($this->session, '', new \DateTimeZone('Europe/Berlin'));

        // 1700000000 == 2023-11-14 22:13:20 UTC == 23:13:20 in Berlin (CET).
        self::assertSame('2023-11-14 23:13:20', $lister->listAccounts()[0]->created);
    }

    // ---- the 'domain' identifier contract ---------------------------------
    //
    // WHMCS matches sync rows through ListAccountsUniqueIdentifierField, which
    // core only accepts as 'domain' (live-verified, WHMCS 9.0.6). Whichever of
    // the two keys core reads - 'domain' or 'uniqueIdentifier' - it must get
    // the same value, and that value must be present and unique on every row.

    public function testEveryRowCarriesTheSameNonEmptyValueInBothIdentifierKeys(): void
    {
        $this->addSite($this->addClient('withsite'), 'withsite.example');
        $this->addClient('nosite');
        $this->addClient('alsonosite');

        $rows = AccountLister::toWhmcsAccounts($this->lister()->listAccounts());

        self::assertCount(3, $rows);
        foreach ($rows as $row) {
            self::assertNotSame('', $row['domain']);
            self::assertSame($row['domain'], $row['uniqueIdentifier']);
        }
    }

    public function testIdentifiersAreUniqueAcrossWebsitelessClients(): void
    {
        $first = $this->addClient('one');
        $second = $this->addClient('two');

        $rows = AccountLister::toWhmcsAccounts($this->lister()->listAccounts());

        self::assertSame('client-' . $first . '.no-domain.invalid', $rows[0]['domain']);
        self::assertSame('client-' . $second . '.no-domain.invalid', $rows[1]['domain']);
        self::assertNotSame($rows[0]['domain'], $rows[1]['domain']);
    }

    public function testASecondClientOnTheSameDomainDoesNotCollideWithTheFirst(): void
    {
        // web_domain.domain uniqueness is an interface validator, not a
        // database constraint, so the remote API can leave two clients holding
        // the same name. Two identical identifiers would be an ambiguous match
        // for core, which is worse than one unmatched row.
        $this->addSite($this->addClient('first'), 'shared.example');
        $second = $this->addClient('second');
        $this->addSite($second, 'shared.example');

        $accounts = $this->lister()->listAccounts();

        self::assertSame('shared.example', $accounts[0]->uniqueIdentifier);
        self::assertSame('shared.example', $accounts[1]->domain, 'the panel fact is still reported');
        self::assertSame('client-' . $second . '.no-domain.invalid', $accounts[1]->uniqueIdentifier);
    }

    public function testDomainCollisionsAreDetectedRegardlessOfCase(): void
    {
        $this->addSite($this->addClient('upper'), 'Mixed.Example');
        $second = $this->addClient('lower');
        $this->addSite($second, 'mixed.example');

        $accounts = $this->lister()->listAccounts();

        self::assertSame('Mixed.Example', $accounts[0]->uniqueIdentifier);
        self::assertSame('client-' . $second . '.no-domain.invalid', $accounts[1]->uniqueIdentifier);
    }

    public function testASyntheticIdentifierThatIsItselfTakenGetsDisambiguated(): void
    {
        // Pathological, but the identifier is a matching key: it has to stay
        // unique even when an earlier client hosts a website named exactly
        // like a later client's synthetic identifier.
        $squatter = $this->addClient('squatter');
        $websiteless = $this->addClient('websiteless');
        $this->addSite($squatter, 'client-' . $websiteless . '.no-domain.invalid');

        $accounts = $this->lister()->listAccounts();

        self::assertSame('client-' . $websiteless . '.no-domain.invalid', $accounts[0]->uniqueIdentifier);
        self::assertSame('client-' . $websiteless . '-2.no-domain.invalid', $accounts[1]->uniqueIdentifier);
    }

    public function testTheSyntheticIdentifierUsesAReservedTldSoItCannotMatchARealService(): void
    {
        $clientId = $this->addClient('nosite');

        $identifier = $this->lister()->listAccounts()[0]->uniqueIdentifier;

        // RFC 2606 s2 reserves .invalid: no WHMCS service can legitimately
        // carry this domain, so a synthetic row can never be paired with the
        // wrong service - it is reported as an unmatched account instead.
        self::assertStringEndsWith('.invalid', $identifier);
        self::assertStringContainsString((string) $clientId, $identifier);
    }

    public function testAClientWithNoUsernameAtAllStillGetsAMatchableIdentifier(): void
    {
        // No username means no sys_user to resolve site ownership with, so the
        // domain lookup is skipped entirely - the row would otherwise reach
        // WHMCS with nothing in either identifier key.
        $blank = $this->addClient('tobeblanked');
        $this->setUsername($blank, '');
        $named = $this->addClient('named');

        $accounts = $this->lister()->listAccounts();

        self::assertSame('', $accounts[0]->username);
        self::assertSame('client-' . $blank . '.no-domain.invalid', $accounts[0]->uniqueIdentifier);
        self::assertSame('client-' . $named . '.no-domain.invalid', $accounts[1]->uniqueIdentifier);
    }

    // ---- efficiency & call shape -----------------------------------------

    public function testTemplatesAndClientsAreFetchedOnceForTheWholePanel(): void
    {
        $this->fake->addClientTemplate('Basic');
        foreach (['a1', 'a2', 'a3'] as $username) {
            $this->addSite($this->addClient($username), $username . '.example');
        }
        $mark = count($this->fake->requestLog);

        $this->lister()->listAccounts();

        $methods = array_slice($this->methods(), $mark);
        self::assertSame(1, $this->countCalls('client_templates_get_all'));
        // One client_get for the whole table (client_id = -1), not one per client.
        self::assertSame(1, $this->countCalls('client_get'));
        self::assertSame(3, $this->countCalls('client_get_by_username'));
        self::assertSame(3, $this->countCalls('client_get_sites_by_user'));
        // ... and exactly one login for the batch.
        self::assertSame(['client_templates_get_all', 'client_get'], array_slice($methods, 0, 2));
    }

    public function testAllClientsAreReadWithASingleWildcardClientGet(): void
    {
        $this->addClient('wild');
        $mark = count($this->fake->requestLog);

        $this->lister()->listAccounts();

        $clientGet = null;
        foreach (array_slice($this->fake->requestLog, $mark) as $entry) {
            if ($entry['method'] === 'client_get') {
                $clientGet = $entry['params'];
            }
        }

        self::assertIsArray($clientGet);
        // remoting_lib.inc.php getDataRecord(): -1 means "all records".
        self::assertSame(-1, $clientGet['client_id']);
    }

    // ---- fault handling ---------------------------------------------------

    public function testAClientWhoseSysUserIsMissingStillGetsListed(): void
    {
        $ghost = $this->addClient('ghostuser');
        $this->addSite($this->addClient('healthy'), 'healthy.example');

        // remote.d/client.inc.php client_get_by_username() throws when the
        // sys_user row is gone (half-deleted client).
        $this->fake->failNextWith('client_get_by_username', 'There is no user account for this user name.');

        $accounts = $this->lister()->listAccounts();

        self::assertCount(2, $accounts);
        self::assertSame('ghostuser', $accounts[0]->username);
        self::assertSame('', $accounts[0]->domain);
        // Unreadable is not the same as unlisted: the row still gets a
        // matchable, unique identifier so the sync reports it.
        self::assertSame('client-' . $ghost . '.no-domain.invalid', $accounts[0]->uniqueIdentifier);
        // The healthy client after it is unaffected.
        self::assertSame('healthy.example', $accounts[1]->domain);
    }

    public function testASiteLookupFaultDoesNotSinkTheListing(): void
    {
        $this->addClient('faulty');
        $this->fake->failNextWith('client_get_sites_by_user', 'There is no site for this user');

        $accounts = $this->lister()->listAccounts();

        self::assertCount(1, $accounts);
        self::assertSame('', $accounts[0]->domain);
    }

    public function testAMissingGrantIsReportedInsteadOfSilentlyEmptyDomains(): void
    {
        $this->addClient('granted');
        $this->fake->setGrants([
            'client_add', 'client_get', 'client_templates_get_all',
            'client_get_by_username', 'sites_web_domain_add',
            // client_get_sites_by_user deliberately withheld
        ]);
        $this->session->close(); // force a fresh login that picks up the grants

        $this->expectException(PermissionDeniedException::class);
        $this->lister()->listAccounts();
    }

    // ---- WHMCS mapping ----------------------------------------------------

    public function testToWhmcsArrayProducesExactlyTheDocumentedKeys(): void
    {
        $basic = $this->fake->addClientTemplate('Basic');
        $clientId = $this->addClient('mapme', ['template_master' => $basic]);
        $this->addSite($clientId, 'mapme.example');

        $rows = AccountLister::toWhmcsAccounts($this->lister()->listAccounts());

        self::assertSame([
            [
                'email' => 'mapme@example.com',
                'username' => 'mapme',
                'domain' => 'mapme.example',
                'uniqueIdentifier' => 'mapme.example',
                'product' => (string) $basic,
                'primaryip' => '203.0.113.10',
                'created' => '2023-11-14 22:13:20',
                'status' => 'Active',
            ],
        ], $rows);
        self::assertSame(
            ['email', 'username', 'domain', 'uniqueIdentifier', 'product', 'primaryip', 'created', 'status'],
            array_keys($rows[0])
        );
    }

    public function testStatusStringsAreOverridable(): void
    {
        $this->addClient('locked', ['locked' => 'y']);
        $this->addClient('open');

        $rows = AccountLister::toWhmcsAccounts(
            $this->lister()->listAccounts(),
            'ACTIVE-CONST',
            'SUSPENDED-CONST'
        );

        self::assertSame('SUSPENDED-CONST', $rows[0]['status']);
        self::assertSame('ACTIVE-CONST', $rows[1]['status']);
    }

    public function testMetaDataKeysDescribeWhatTheMappingActuallyEmits(): void
    {
        self::assertSame(
            [
                'ListAccountsUniqueIdentifierDisplayName' => 'Domain',
                // Core only accepts field names it can match a service on;
                // 'uniqueIdentifier' is rejected outright (live-verified).
                'ListAccountsUniqueIdentifierField' => 'domain',
                'ListAccountsProductField' => 'configoption1',
            ],
            AccountLister::WHMCS_METADATA
        );

        $this->addClient('meta');
        $row = AccountLister::toWhmcsAccounts($this->lister()->listAccounts())[0];

        // The named identifier field must exist in what the mapping returns,
        // and carry a value - even for this client, which owns no website.
        $field = AccountLister::WHMCS_METADATA['ListAccountsUniqueIdentifierField'];
        self::assertArrayHasKey($field, $row);
        self::assertNotSame('', $row[$field]);
    }

    public function testTheMappingSendsTheIdentifierAsTheDomainForAWebsitelessClient(): void
    {
        $clientId = $this->addClient('nosite');

        $row = AccountLister::toWhmcsAccounts($this->lister()->listAccounts())[0];

        self::assertSame([
            'email' => 'nosite@example.com',
            'username' => 'nosite',
            'domain' => 'client-' . $clientId . '.no-domain.invalid',
            'uniqueIdentifier' => 'client-' . $clientId . '.no-domain.invalid',
            'product' => '',
            'primaryip' => '203.0.113.10',
            'created' => '2023-11-14 22:13:20',
            'status' => 'Active',
        ], $row);
    }

    public function testDefaultStatusStringsAreTheWhmcsServiceStatuses(): void
    {
        self::assertSame('Active', RemoteAccount::STATUS_ACTIVE);
        self::assertSame('Suspended', RemoteAccount::STATUS_SUSPENDED);

        $this->addClient('single');
        $account = $this->lister()->listAccounts()[0];

        self::assertSame('Active', $account->toWhmcsArray()['status']);
    }
}
