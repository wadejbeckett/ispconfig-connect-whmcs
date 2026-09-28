<?php

declare(strict_types=1);

namespace Ispconfigconnect\Tests\Module;

use Ispconfigconnect\Tests\Stubs\ModuleCallLog;
use WHMCS\Module\Server\Ispconfigconnect\Api\HttpTransportInterface;
use WHMCS\Module\Server\Ispconfigconnect\Api\Permissions;
use WHMCS\Module\Server\Ispconfigconnect\Api\ServerConfig;
use WHMCS\Module\Server\Ispconfigconnect\Support\Factory;
use WHMCS\Module\Server\Ispconfigconnect\Support\ServiceProperties;

/**
 * WEBSITE-ONLY SUSPENSION NEVER LOCKS THE WHOLE CLIENT.
 *
 * "Suspend Entire Client" unchecked tells the admin, in the product settings,
 * that only this service's website goes inactive and mail, FTP and databases
 * keep working. That promise needs the ISPConfig website id, and a service
 * linked by name used to store the client id alone. The earlier answer - fall
 * back to the whole-client lock - broke the promise for every linked service
 * and, on a client shared by several services, took the OTHER services down
 * too.
 *
 * Now: without a website id, website-only Suspend/Unsuspend refuse before any
 * panel call and say how to fix it; and Link/Replace resolve the website id
 * from the service domain, so a linked service can be suspended website-only.
 */
final class WebsiteOnlySuspensionTest extends ModuleTestCase
{
    private const WEBSITE_ONLY = ['configoption13' => ''];

    /**
     * Put a response rewriter between the module and the fake panel, for the
     * panel answers the fake cannot produce on its own (a reseller's group
     * list, a website filed under a sub-client's group).
     *
     * @param \Closure(string, mixed): mixed $rewrite (method, response) => response
     */
    private function rewriteResponses(\Closure $rewrite): void
    {
        $inner = $this->fake;
        Factory::$transportFactory = static fn (ServerConfig $config): HttpTransportInterface => new class ($inner, $rewrite) implements HttpTransportInterface {
            public function __construct(
                private readonly HttpTransportInterface $inner,
                private readonly \Closure $rewrite,
            ) {
            }

            public function post(string $method, string $jsonBody): string
            {
                $body = $this->inner->post($method, $jsonBody);
                $envelope = json_decode($body, true);
                if (!is_array($envelope) || ($envelope['code'] ?? '') !== 'ok') {
                    return $body;
                }
                $envelope['response'] = ($this->rewrite)($method, $envelope['response']);

                return json_encode($envelope, JSON_THROW_ON_ERROR);
            }
        };
    }

    /** @return array<string, mixed> the response of the last module-log entry for $action */
    private static function lastLogResponse(string $action): array
    {
        $entries = array_values(array_filter(
            ModuleCallLog::entries(),
            static fn (array $entry): bool => $entry['action'] === $action
        ));
        self::assertNotSame([], $entries, $action . ' must write a module-log entry');
        $response = $entries[count($entries) - 1]['response'];
        self::assertIsArray($response);

        /** @var array<string, mixed> $response */
        return $response;
    }

    private function storedWebsiteId(): ?string
    {
        $value = $this->model->serviceProperties->get(ServiceProperties::KEY_DOMAIN_ID);

        return $value === '' ? null : $value;
    }

    // ---- no website id: refuse, touch nothing ------------------------------

    public function testWebsiteOnlySuspendOfALinkedServiceWithoutAWebsiteIdRefusesAndTouchesNothing(): void
    {
        $clientId = $this->fake->addClient('paigemurray');
        $siteId = $this->fake->addWebsite($clientId, 'example.com');
        $ftpId = $this->fake->addFtpUser($clientId, $siteId, 'paigeftp');
        // What a link made before this fix stored: the client, nothing else.
        $this->model->serviceProperties->save([
            ServiceProperties::KEY_CLIENT_ID => (string) $clientId,
            ServiceProperties::KEY_CLIENT_USERNAME => 'paigemurray',
        ]);

        $mark = $this->wireMark();
        $result = ispconfigconnect_SuspendAccount($this->params(self::WEBSITE_ONLY));

        self::assertStringStartsWith('Suspend refused: this product suspends the website only', $result);
        self::assertStringContainsString('Replace ISPConfig Link', $result);
        self::assertStringContainsString('Suspend Entire Client', $result);
        self::assertStringContainsString('Nothing has been changed', $result);
        // Refused before a session was even opened: no login, no write.
        self::assertSame([], $this->methodsSince($mark));
        self::assertSame('n', $this->fake->clients()[$clientId]['locked']);
        self::assertSame('y', $this->fake->sites()[$siteId]['active']);
        self::assertSame('y', $this->fake->ftpUsers()[$ftpId]['active']);
    }

    public function testWebsiteOnlyUnsuspendWithoutAWebsiteIdRefusesAndTouchesNothing(): void
    {
        $clientId = $this->fake->addClient('paigemurray');
        $siteId = $this->fake->addWebsite($clientId, 'example.com', active: 'n');
        $this->model->serviceProperties->save([ServiceProperties::KEY_CLIENT_ID => (string) $clientId]);

        $mark = $this->wireMark();
        $result = ispconfigconnect_UnsuspendAccount($this->params(self::WEBSITE_ONLY));

        self::assertStringStartsWith('Unsuspend refused: this product suspends the website only', $result);
        self::assertStringContainsString('nothing safe to unsuspend', $result);
        self::assertSame([], $this->methodsSince($mark));
        self::assertSame('n', $this->fake->sites()[$siteId]['active']);
    }

    // ---- Link resolves the website id --------------------------------------

    public function testLinkStoresTheWebsiteIdAndWebsiteOnlySuspendThenActsOnThatSiteAlone(): void
    {
        $clientId = $this->fake->addClient('paigemurray');
        $serviceSite = $this->fake->addWebsite($clientId, 'example.com');
        $otherSite = $this->fake->addWebsite($clientId, 'blog.example.org');
        $ftpId = $this->fake->addFtpUser($clientId, $serviceSite, 'paigeftp');

        // WHMCS may hold the domain in any case; the panel stores it lower-case.
        $link = ispconfigconnect_LinkAccount($this->params(['username' => 'paigemurray', 'domain' => 'Example.COM']));

        self::assertSame('success', $link);
        self::assertSame((string) $clientId, $this->model->serviceProperties->get(ServiceProperties::KEY_CLIENT_ID));
        self::assertSame((string) $serviceSite, $this->storedWebsiteId());
        $logged = self::lastLogResponse('LinkAccount');
        self::assertSame($serviceSite, $logged['ispconfig_domain_id']);
        self::assertStringContainsString('stored', (string) $logged['website']);

        self::assertSame('success', ispconfigconnect_SuspendAccount($this->params(self::WEBSITE_ONLY)));

        self::assertSame('n', $this->fake->sites()[$serviceSite]['active']);
        // Only that site: the client's other website, its FTP user and the
        // client itself are untouched.
        self::assertSame('y', $this->fake->sites()[$otherSite]['active']);
        self::assertSame('y', $this->fake->ftpUsers()[$ftpId]['active']);
        self::assertSame('n', $this->fake->clients()[$clientId]['locked']);

        self::assertSame('success', ispconfigconnect_UnsuspendAccount($this->params(self::WEBSITE_ONLY)));
        self::assertSame('y', $this->fake->sites()[$serviceSite]['active']);
    }

    public function testLinkWithNoMatchingWebsiteLeavesTheWebsiteIdUnsetAndSaysWhy(): void
    {
        $clientId = $this->fake->addClient('paigemurray');
        $this->fake->addWebsite($clientId, 'something-else.example');

        $result = ispconfigconnect_LinkAccount($this->params(['username' => 'paigemurray', 'domain' => 'example.com']));

        // The client link is still made: only the website id is missing.
        self::assertSame('success', $result);
        self::assertSame((string) $clientId, $this->model->serviceProperties->get(ServiceProperties::KEY_CLIENT_ID));
        self::assertNull($this->storedWebsiteId());
        $logged = self::lastLogResponse('LinkAccount');
        self::assertNull($logged['ispconfig_domain_id']);
        self::assertStringContainsString('example.com', (string) $logged['website']);
        self::assertStringContainsString('not stored', (string) $logged['website']);
    }

    public function testSeveralWebsitesWithTheServiceDomainStoreNone(): void
    {
        $clientId = $this->fake->addClient('paigemurray');
        $first = $this->fake->addWebsite($clientId, 'example.com');
        $second = $this->fake->addWebsite($clientId, 'example.com');

        self::assertSame('success', ispconfigconnect_LinkAccount($this->params(['username' => 'paigemurray'])));

        self::assertNull($this->storedWebsiteId());
        $note = (string) self::lastLogResponse('LinkAccount')['website'];
        self::assertStringContainsString('#' . $first, $note);
        self::assertStringContainsString('#' . $second, $note);
    }

    public function testReplaceOnTheAccountAlreadyLinkedFillsTheMissingWebsiteId(): void
    {
        // The refusal above tells the admin to press "Replace ISPConfig Link";
        // on a service already linked to the right account that must store the
        // website id - not be a silent no-op.
        $clientId = $this->fake->addClient('paigemurray');
        $siteId = $this->fake->addWebsite($clientId, 'example.com');
        $this->model->serviceProperties->save([
            ServiceProperties::KEY_CLIENT_ID => (string) $clientId,
            ServiceProperties::KEY_CLIENT_USERNAME => 'paigemurray',
        ]);

        $result = ispconfigconnect_RelinkAccount($this->params(['username' => 'paigemurray']));

        self::assertSame('success', $result);
        self::assertSame((string) $clientId, $this->model->serviceProperties->get(ServiceProperties::KEY_CLIENT_ID));
        self::assertSame((string) $siteId, $this->storedWebsiteId());
        self::assertTrue(self::lastLogResponse('RelinkAccount')['already_linked']);

        // ...after which website-only suspension works.
        self::assertSame('success', ispconfigconnect_SuspendAccount($this->params(self::WEBSITE_ONLY)));
        self::assertSame('n', $this->fake->sites()[$siteId]['active']);
    }

    public function testRelinkingTheSameAccountNeverOverwritesAStoredWebsiteId(): void
    {
        $clientId = $this->fake->addClient('paigemurray');
        $this->fake->addWebsite($clientId, 'example.com');
        $this->model->serviceProperties->save([
            ServiceProperties::KEY_CLIENT_ID => (string) $clientId,
            ServiceProperties::KEY_DOMAIN_ID => '777',
        ]);

        $mark = $this->wireMark();
        self::assertSame('success', ispconfigconnect_LinkAccount($this->params(['username' => 'paigemurray'])));

        // Rule 4: a stored value is never overwritten, and with nothing to
        // fill the panel is not even asked about websites.
        self::assertSame('777', $this->storedWebsiteId());
        self::assertNotContains('client_get_sites_by_user', $this->methodsSince($mark));
    }

    public function testReplaceWithADifferentAccountClearsTheOldIdsBeforeStoringTheNewWebsiteId(): void
    {
        $old = $this->fake->addClient('wrongcustomer');
        $oldSite = $this->fake->addWebsite($old, 'example.com');
        $this->model->serviceProperties->save([
            ServiceProperties::KEY_CLIENT_ID => (string) $old,
            ServiceProperties::KEY_DOMAIN_ID => (string) $oldSite,
            ServiceProperties::KEY_FTP_USER_ID => '55',
        ]);
        $new = $this->fake->addClient('rightcustomer');
        $newSite = $this->fake->addWebsite($new, 'example.org');

        $result = ispconfigconnect_RelinkAccount($this->params(['username' => 'rightcustomer', 'domain' => 'example.org']));

        self::assertSame('success', $result);
        self::assertSame((string) $new, $this->model->serviceProperties->get(ServiceProperties::KEY_CLIENT_ID));
        self::assertSame((string) $newSite, $this->storedWebsiteId());
        // The old client's other ids went with it.
        self::assertSame('', $this->model->serviceProperties->get(ServiceProperties::KEY_FTP_USER_ID));
    }

    // ---- what the lookup asks, and what it trusts --------------------------

    public function testTheLookupAsksForTheClientsOwnGroupOnlyNeverTheResellerGroupList(): void
    {
        $clientId = $this->fake->addClient('reseller');
        $this->fake->addWebsite($clientId, 'example.com');
        // A reseller's sys_user.groups also lists its sub-clients' groups.
        $this->rewriteResponses(static function (string $method, mixed $response): mixed {
            if ($method === 'client_get_by_username' && is_array($response)) {
                $response['groups'] = $response['default_group'] . ',91,92';
            }

            return $response;
        });

        self::assertSame('success', ispconfigconnect_LinkAccount($this->params(['username' => 'reseller'])));

        $lookups = array_values(array_filter(
            $this->fake->requestLog,
            static fn (array $request): bool => $request['method'] === 'client_get_sites_by_user'
        ));
        self::assertCount(1, $lookups);
        // The fake's sys_user ids and groups are client id + 1.
        self::assertSame((string) ($clientId + 1), $lookups[0]['params']['sys_groupid']);
        self::assertSame($clientId + 1, $lookups[0]['params']['sys_userid']);
    }

    public function testAMatchNotFiledUnderTheClientsOwnGroupIsNotStored(): void
    {
        // client_get_sites_by_user also matches the website's CREATOR, so a
        // site a reseller made for a sub-client comes back for the reseller.
        // The read-back of the single match catches it.
        $clientId = $this->fake->addClient('reseller');
        $siteId = $this->fake->addWebsite($clientId, 'example.com');
        $this->rewriteResponses(static function (string $method, mixed $response): mixed {
            if ($method === 'sites_web_domain_get' && is_array($response)) {
                $response['sys_groupid'] = '92';
            }

            return $response;
        });

        self::assertSame('success', ispconfigconnect_LinkAccount($this->params(['username' => 'reseller'])));

        self::assertSame((string) $clientId, $this->model->serviceProperties->get(ServiceProperties::KEY_CLIENT_ID));
        self::assertNull($this->storedWebsiteId());
        $note = (string) self::lastLogResponse('LinkAccount')['website'];
        self::assertStringContainsString('#' . $siteId, $note);
        self::assertStringContainsString('own group', $note);
    }

    public function testARefusedWebsiteLookupStillLinksTheClientAndSaysWhy(): void
    {
        $this->fake->setGrants(array_values(array_diff(Permissions::requiredGrants(), ['client_get_sites_by_user'])));
        $clientId = $this->fake->addClient('paigemurray');
        $this->fake->addWebsite($clientId, 'example.com');

        self::assertSame('success', ispconfigconnect_LinkAccount($this->params(['username' => 'paigemurray'])));

        self::assertSame((string) $clientId, $this->model->serviceProperties->get(ServiceProperties::KEY_CLIENT_ID));
        self::assertNull($this->storedWebsiteId());
        self::assertStringContainsString(
            'client_get_sites_by_user',
            (string) self::lastLogResponse('LinkAccount')['website']
        );
    }

    public function testAConnectionFailureDuringTheWebsiteLookupLinksNothing(): void
    {
        $clientId = $this->fake->addClient('paigemurray');
        $this->fake->addWebsite($clientId, 'example.com');
        $this->fake->failNextWith('client_get_sites_by_user', 'The login failure limit has been reached.');

        $result = ispconfigconnect_LinkAccount($this->params(['username' => 'paigemurray']));

        self::assertStringStartsWith('Link failed:', $result);
        self::assertNull($this->model->serviceProperties->get(ServiceProperties::KEY_CLIENT_ID));
        self::assertNull($this->storedWebsiteId());
    }
    public function testReplacingTheSameAccountSaysWhyNoWebsiteIdWasStored(): void
    {
        // Linked to a client whose websites do not include the service domain:
        // the refusal says to press Replace ISPConfig Link, so that button must
        // not answer 'success' while storing nothing.
        $clientId = $this->fake->addClient('customer');
        $this->fake->addWebsite($clientId, 'another-site.example');
        $this->model->serviceProperties->save([
            ServiceProperties::KEY_CLIENT_ID => (string) $clientId,
            ServiceProperties::KEY_CLIENT_USERNAME => 'customer',
        ]);

        $result = ispconfigconnect_RelinkAccount($this->params(self::WEBSITE_ONLY + ['username' => 'customer']));

        self::assertStringStartsWith(
            'Replace ISPConfig Link kept the existing link to ISPConfig client #' . $clientId,
            $result
        );
        self::assertStringContainsString('could not store the website id this product needs to suspend', $result);
        self::assertStringNotContainsString('website id not stored', $result);
        self::assertNull($this->model->serviceProperties->get(ServiceProperties::KEY_DOMAIN_ID));
    }
}
