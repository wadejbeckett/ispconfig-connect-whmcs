<?php

declare(strict_types=1);

namespace Ispconfigconnect\Tests\Unit\Sso;

use Ispconfigconnect\Tests\Stubs\ModuleCallLog;
use Ispconfigconnect\Tests\Support\FakeIspconfigServer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WHMCS\Module\Server\Ispconfigconnect\Api\Exception\ApiDisabledException;
use WHMCS\Module\Server\Ispconfigconnect\Api\Exception\LockoutException;
use WHMCS\Module\Server\Ispconfigconnect\Api\Exception\RemoteFaultException;
use WHMCS\Module\Server\Ispconfigconnect\Api\RestClient;
use WHMCS\Module\Server\Ispconfigconnect\Api\ServerConfig;
use WHMCS\Module\Server\Ispconfigconnect\Api\SessionManager;
use WHMCS\Module\Server\Ispconfigconnect\Sso\CompanionClient;
use WHMCS\Module\Server\Ispconfigconnect\Sso\Exception\CompanionGrantMissingException;
use WHMCS\Module\Server\Ispconfigconnect\Sso\Exception\CompanionNotInstalledException;
use WHMCS\Module\Server\Ispconfigconnect\Sso\Exception\CompanionResponseException;
use WHMCS\Module\Server\Ispconfigconnect\Sso\SsoTicket;
use WHMCS\Module\Server\Ispconfigconnect\Support\Logging;

/**
 * CompanionClient runs through the whole real stack - CompanionClient ->
 * SessionManager -> RestClient -> FakeIspconfigServer - so every assertion
 * here is a valid remote/json.php round trip against the behavioral double.
 *
 * The panel side it talks to is the SEPARATE ispconfig-sso project, which this
 * module does not ship. Its absence is therefore the default state of every
 * panel (the invalid_method envelope), and the fake starts in exactly that
 * state; the tests that need it present opt in with installCompanion().
 */
#[CoversClass(CompanionClient::class)]
#[CoversClass(SsoTicket::class)]
#[CoversClass(CompanionNotInstalledException::class)]
#[CoversClass(CompanionGrantMissingException::class)]
#[CoversClass(CompanionResponseException::class)]
final class CompanionClientTest extends TestCase
{
    private FakeIspconfigServer $fake;

    protected function setUp(): void
    {
        ModuleCallLog::reset();
        $this->fake = new FakeIspconfigServer();
    }

    protected function tearDown(): void
    {
        ModuleCallLog::reset();
    }

    private function config(bool $secure = true, int $port = 8080): ServerConfig
    {
        return new ServerConfig('panel.example.net', 'remote', 'secret', $port, $secure);
    }

    private function client(?string $panelBaseUrl = null, bool $withLogger = false): CompanionClient
    {
        $rest = $withLogger
            ? new RestClient($this->fake, Logging::wire('ispconfigconnect'))
            : new RestClient($this->fake);

        return new CompanionClient(
            new SessionManager($rest, 'remote', 'secret'),
            $this->config(),
            $panelBaseUrl
        );
    }

    // ---- detection --------------------------------------------------------

    /**
     * The default answer for a fresh install: no ispconfig-sso project on the
     * panel, reported as a plain "no" rather than an exception, because there
     * is nothing wrong with such a panel.
     */
    public function testIsInstalledIsFalseOnAStockPanel(): void
    {
        self::assertFalse($this->client()->isInstalled());
    }

    public function testIsInstalledIsTrueOnceTheCompanionIsPresent(): void
    {
        $this->fake->installCompanion();

        self::assertTrue($this->client()->isInstalled());
    }

    public function testDetectionCostsExactlyOneApiCallBeyondLogin(): void
    {
        $this->fake->installCompanion();
        $this->client()->isInstalled();

        $methods = array_column($this->fake->requestLog, 'method');
        self::assertSame(['login', 'get_function_list'], $methods);
    }

    public function testIsInstalledIsFalseWhenGetFunctionListIsNotGranted(): void
    {
        $this->fake->installCompanion();
        $this->fake->setGrants(['client_get']);

        // The probe cannot see the function list, so it cannot promise a
        // working SSO button - report "not available" rather than guess.
        self::assertFalse($this->client()->isInstalled());
    }

    /**
     * A refused probe must NOT be reported as "the project is not installed":
     * here it demonstrably IS installed, and saying otherwise would send an
     * operator to install software they already have. The ispconfig-sso
     * project's integration guide asks consumers to keep the two apart.
     */
    public function testARefusedProbeIsUnknownRatherThanAbsent(): void
    {
        $this->fake->installCompanion();
        $this->fake->setGrants(['client_get']);

        self::assertSame(CompanionClient::STATUS_UNKNOWN, $this->client()->status());
    }

    public function testStatusDistinguishesInstalledFromAbsent(): void
    {
        self::assertSame(CompanionClient::STATUS_ABSENT, $this->client()->status());

        $this->fake->installCompanion();
        self::assertSame(CompanionClient::STATUS_INSTALLED, $this->client()->status());
    }

    public function testIsInstalledPropagatesInfrastructureFailures(): void
    {
        $this->fake->apiEnabled = false;

        // A panel we cannot reach is NOT the same as a panel without the
        // companion; the entry file must be able to report the real problem.
        $this->expectException(ApiDisabledException::class);
        $this->client()->isInstalled();
    }

    // ---- ticket minting ---------------------------------------------------

    public function testTicketReturnsATokenAndARedirectUrl(): void
    {
        $this->fake->installCompanion();

        $ticket = $this->client()->ticket(7);

        self::assertInstanceOf(SsoTicket::class, $ticket);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $ticket->token);
        self::assertSame(
            'https://panel.example.net:8080/connectsso/?token=' . $ticket->token,
            $ticket->url
        );
        self::assertSame(45, $ticket->expiresInSeconds);
    }

    public function testTicketSendsTheClientIdAndAnEmptyBindIpByDefault(): void
    {
        $this->fake->installCompanion();
        $this->client()->ticket(7);

        $call = end($this->fake->requestLog);
        self::assertSame('connect_sso_token_create', $call['method']);
        self::assertSame(7, $call['params']['client_id']);
        self::assertSame('', $call['params']['bind_ip']);
    }

    public function testTicketForwardsTheBrowserIpWhenBindingIsRequested(): void
    {
        $this->fake->installCompanion();
        $this->client()->ticket(7, '203.0.113.9');

        $call = end($this->fake->requestLog);
        self::assertSame('203.0.113.9', $call['params']['bind_ip']);
    }

    public function testTicketAcceptsIpv6BindAddresses(): void
    {
        $this->fake->installCompanion();
        $this->client()->ticket(7, '2001:db8::1');

        $call = end($this->fake->requestLog);
        self::assertSame('2001:db8::1', $call['params']['bind_ip']);
    }

    public function testTicketRejectsANonIpBindValueWithoutTouchingTheWire(): void
    {
        $this->fake->installCompanion();

        try {
            $this->client()->ticket(7, 'evil.example.com');
            self::fail('expected an InvalidArgumentException');
        } catch (\InvalidArgumentException) {
            self::assertSame([], $this->fake->requestLog);
        }
    }

    public function testTicketRejectsANonPositiveClientIdWithoutTouchingTheWire(): void
    {
        $this->fake->installCompanion();

        try {
            $this->client()->ticket(0);
            self::fail('expected an InvalidArgumentException');
        } catch (\InvalidArgumentException) {
            self::assertSame([], $this->fake->requestLog);
        }
    }

    public function testLoginUrlIsTheTicketUrl(): void
    {
        $this->fake->installCompanion();

        $url = $this->client()->loginUrl(7);

        self::assertStringStartsWith('https://panel.example.net:8080/connectsso/?token=', $url);
    }

    public function testPanelUrlOverrideReplacesTheApiHostAndPort(): void
    {
        $this->fake->installCompanion();

        $url = $this->client('https://cp.example.net/')->loginUrl(7);

        self::assertStringStartsWith('https://cp.example.net/connectsso/?token=', $url);
    }

    public function testPanelUrlOverrideKeepsASubPathPrefix(): void
    {
        $this->fake->installCompanion();

        $url = $this->client('https://example.net/ispconfig')->loginUrl(7);

        self::assertStringStartsWith('https://example.net/ispconfig/connectsso/?token=', $url);
    }

    public function testPlainHttpServerConfigProducesAPlainHttpUrl(): void
    {
        $this->fake->installCompanion();
        $client = new CompanionClient(
            new SessionManager(new RestClient($this->fake), 'remote', 'secret'),
            $this->config(false, 8080)
        );

        self::assertStringStartsWith('http://panel.example.net:8080/connectsso/?token=', $client->loginUrl(7));
    }

    public function testANonHttpPanelUrlOverrideIsRejectedAtConstruction(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->client('javascript:alert(1)');
    }

    public function testAPanelUrlOverrideWithoutAHostIsRejectedAtConstruction(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->client('https:///connectsso');
    }

    // ---- the default: SSO project absent, or present but not granted ------

    public function testTicketThrowsNotInstalledOnAStockPanel(): void
    {
        $this->expectException(CompanionNotInstalledException::class);
        $this->client()->ticket(7);
    }

    /**
     * The message an operator reads when SSO is attempted on a panel that does
     * not have it must name the SEPARATE project to install. It must NOT name a
     * path inside this repository: the panel side is not shipped here, and an
     * instruction to run something out of the WHMCS module tree would be dead.
     */
    public function testTheAbsenceMessageNamesTheSeparateSsoProject(): void
    {
        try {
            $this->client()->ticket(7);
            self::fail('expected a CompanionNotInstalledException');
        } catch (CompanionNotInstalledException $absent) {
            self::assertStringContainsString('ispconfig-sso', $absent->getMessage());
            self::assertStringNotContainsString('companion/', $absent->getMessage());
        }
    }

    public function testTicketThrowsGrantMissingAndNamesTheGrant(): void
    {
        $this->fake->installCompanion();
        $this->fake->setGrants(['get_function_list']);

        try {
            $this->client()->ticket(7);
            self::fail('expected a CompanionGrantMissingException');
        } catch (CompanionGrantMissingException $failure) {
            self::assertStringContainsString('connect_sso_token_create', $failure->getMessage());
        }
    }

    public function testTicketOrNullSwallowsAbsenceAndMissingGrants(): void
    {
        self::assertNull($this->client()->ticketOrNull(7));

        $this->fake->installCompanion();
        $this->fake->setGrants(['get_function_list']);
        self::assertNull($this->client()->ticketOrNull(7));
    }

    public function testLoginUrlOrNullSwallowsAbsence(): void
    {
        self::assertNull($this->client()->loginUrlOrNull(7));
    }

    public function testLoginUrlOrNullReturnsTheUrlWhenTheCompanionIsPresent(): void
    {
        $this->fake->installCompanion();

        self::assertIsString($this->client()->loginUrlOrNull(7));
    }

    public function testTicketOrNullPropagatesInfrastructureFailures(): void
    {
        $this->fake->installCompanion();
        $this->fake->failNextWith('login', 'The login failure limit has been reached.');

        $this->expectException(LockoutException::class);
        $this->client()->ticketOrNull(7);
    }

    public function testTicketOrNullPropagatesCompanionSideRefusals(): void
    {
        $this->fake->installCompanion();
        $this->fake->failNextWith(
            'connect_sso_token_create',
            'There is no panel user for this client id.'
        );

        // Not an availability problem: the operator must see WHY the panel
        // refused, so this is never turned into a hidden button.
        $this->expectException(RemoteFaultException::class);
        $this->client()->ticketOrNull(7);
    }

    // ---- malformed companion responses ------------------------------------

    /** @return iterable<string, array{mixed}> */
    public static function malformedResponses(): iterable
    {
        yield 'not an array' => ['nope'];
        yield 'no token' => [['path' => '/connectsso/', 'expires_in' => 45]];
        yield 'empty token' => [['token' => '', 'path' => '/connectsso/']];
        yield 'token too short' => [['token' => 'abcd', 'path' => '/connectsso/']];
        yield 'token with a query separator' => [
            [
                'token' => str_repeat('a', 32) . '&redirect=https://evil.example',
                'path' => '/connectsso/',
            ],
        ];
        yield 'token with a fragment' => [
            [
                'token' => str_repeat('a', 32) . '#x',
                'path' => '/connectsso/',
            ],
        ];
        yield 'protocol-relative path' => [
            [
                'token' => str_repeat('a', 64),
                'path' => '//evil.example/',
            ],
        ];
        yield 'absolute url path' => [
            [
                'token' => str_repeat('a', 64),
                'path' => 'https://evil.example/',
            ],
        ];
        yield 'traversal path' => [
            [
                'token' => str_repeat('a', 64),
                'path' => '/connectsso/../../evil',
            ],
        ];
        yield 'backslash path' => [
            [
                'token' => str_repeat('a', 64),
                'path' => '/\\evil.example/',
            ],
        ];
        yield 'relative path' => [
            [
                'token' => str_repeat('a', 64),
                'path' => 'connectsso/',
            ],
        ];
        yield 'path carrying its own query' => [
            [
                'token' => str_repeat('a', 64),
                'path' => '/connectsso/?next=https://evil.example',
            ],
        ];
    }

    /**
     * @param mixed $response
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('malformedResponses')]
    public function testMalformedCompanionResponsesAreRejected($response): void
    {
        $this->fake->installCompanion();
        $this->fake->companionResponseOverride = $response;

        $this->expectException(CompanionResponseException::class);
        $this->client()->ticket(7);
    }

    public function testMalformedResponsesAreNullNotAnExceptionInTheSafeAccessor(): void
    {
        $this->fake->installCompanion();
        $this->fake->companionResponseOverride = ['token' => 'short'];

        self::assertNull($this->client()->loginUrlOrNull(7));
    }

    public function testAnAbsentPathFallsBackToTheDefaultEndpoint(): void
    {
        $this->fake->installCompanion();
        $token = str_repeat('b', 64);
        $this->fake->companionResponseOverride = ['token' => $token];

        self::assertSame(
            'https://panel.example.net:8080/connectsso/?token=' . $token,
            $this->client()->loginUrl(7)
        );
    }

    public function testAnAbsentExpiryIsReportedAsUnknown(): void
    {
        $this->fake->installCompanion();
        $this->fake->companionResponseOverride = ['token' => str_repeat('b', 64)];

        self::assertSame(0, $this->client()->ticket(7)->expiresInSeconds);
    }

    public function testTheCompanionUsernameIsCarriedThroughWhenSupplied(): void
    {
        $this->fake->installCompanion();

        self::assertSame('client7', $this->client()->ticket(7)->username);
    }

    // ---- secrecy ----------------------------------------------------------

    public function testTheSsoTokenNeverReachesTheModuleLogUnmasked(): void
    {
        $this->fake->installCompanion();

        $ticket = $this->client(null, true)->ticket(7);

        self::assertNotSame([], ModuleCallLog::entries(), 'the call must still be logged');
        self::assertSame(
            [],
            ModuleCallLog::entriesLeaking($ticket->token),
            'the one-time SSO token leaked into tblmodulelog'
        );
    }

    public function testTheTicketUrlIsNotPartOfTheLoggedResponse(): void
    {
        $this->fake->installCompanion();

        $ticket = $this->client(null, true)->ticket(7);

        foreach (ModuleCallLog::entries() as $entry) {
            self::assertStringNotContainsString(
                $ticket->url,
                print_r($entry['request'], true) . print_r($entry['response'], true)
            );
        }
    }
}
