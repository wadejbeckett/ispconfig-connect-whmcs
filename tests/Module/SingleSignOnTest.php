<?php

declare(strict_types=1);

namespace Ispconfigconnect\Tests\Module;

use Ispconfigconnect\Tests\Stubs\ModuleCallLog;

/**
 * ispconfigconnect_ServiceSingleSignOn / _AdminSingleSignOn and the MetaData
 * label that decides whether WHMCS renders the button at all
 * (developers.whmcs.com/provisioning-modules/single-sign-on/ and
 * .../meta-data-params/).
 *
 * SSO depends on a panel add-on this module does NOT ship: the separate
 * ispconfig-sso project (same author, GPLv3, usable by any billing system).
 * ISPConfig core has no one-time-login API, so a panel without that project -
 * which is every panel until an operator installs it - offers no single
 * sign-on at all.
 *
 * That makes "not installed" the DEFAULT this class asserts first: the label is
 * advertised only where the project was actually detected, and neither entry
 * point may fail cryptically. The fake panel models the same default -
 * FakeIspconfigServer::$companionInstalled is false until a test calls
 * installCompanion().
 *
 * TWO AUDIENCES. ServiceSingleSignOn renders into the client area, so its
 * errorMsg is one customer sentence carrying no hostname, grant name, service
 * property or panel diagnosis; the operator-grade reason - including WHICH of
 * the SSO states caused it - goes to the module log, and that is what these
 * tests assert on for the detail. The admin path keeps the detail in hand.
 *
 * MIXED FLEET. MetaData()'s label is fleet-wide (WHMCS asks with no server in
 * scope), so the per-service answer is given where server context exists: the
 * client-area gate and this file's cached per-server short-circuit.
 */
final class SingleSignOnTest extends ModuleTestCase
{
    /**
     * A cached-metadata reader double, standing in for the tblservers_remote
     * query the module runs in a real WHMCS install.
     *
     * @return \Closure(): list<array<string, mixed>>
     */
    private function ssoSeenOnSomeServer(bool $available): \Closure
    {
        return static fn (): array => [['ispconfig_version' => '3.3.1p1', 'sso_available' => $available]];
    }

    /** @return array<string, mixed> */
    private function provisionedParams(): array
    {
        self::assertSame('success', ispconfigconnect_CreateAccount($this->params()));

        return $this->params();
    }

    /**
     * A per-server cached-metadata reader double: the tblservers_remote row
     * GetRemoteMetaData filled for ONE server, which is the only place a
     * per-service answer can come from.
     *
     * @param array<int, array<string, mixed>> $byServerId
     * @return \Closure(int): ?array<string, mixed>
     */
    private function fleet(array $byServerId): \Closure
    {
        return static fn (int $serverId): ?array => $byServerId[$serverId] ?? null;
    }

    /** @return list<array<string, mixed>> the module-log entries for one action */
    private function logEntriesFor(string $action): array
    {
        return array_values(array_filter(
            ModuleCallLog::entries(),
            static fn (array $entry): bool => $entry['action'] === $action
        ));
    }

    /** Everything an operator may read but a customer may not. */
    private function assertCustomerSafe(string $message): void
    {
        self::assertNotSame('', trim($message));
        foreach (
            [
                'ispconfig-sso',
                'connect_sso_token_create',
                'get_function_list',
                'ispconfig_client_id',
                'remote user',
                'grant',
                'System >',
                'panel.example.net',
                'http',
            ] as $forbidden
        ) {
            self::assertStringNotContainsStringIgnoringCase(
                $forbidden,
                $message,
                sprintf('a customer must never be shown "%s"', $forbidden)
            );
        }
    }

    // ---- the default: no SSO project on the panel -------------------------

    /**
     * THE DEFAULT OF A FRESH INSTALL, asserted end to end on an untouched fake
     * panel: the probe GetRemoteMetaData runs answers "no", that "no" is what
     * gets cached in tblservers_remote, and MetaData() reading it back offers
     * no label - so WHMCS renders no button anywhere in the client area.
     *
     * Nothing here calls installCompanion(): a stock ISPConfig does not have
     * the ispconfig-sso project, and the module must be complete and correct on
     * such a panel, because that is what almost every install is.
     */
    public function testAFreshInstallAdvertisesNoSsoBecauseTheProjectIsNotOnThePanel(): void
    {
        $meta = ispconfigconnect_GetRemoteMetaData($this->params());

        self::assertFalse($meta['sso_available'], 'a stock panel must probe as "no SSO"');
        self::assertContains('get_function_list', $this->calledMethods(), 'the probe must actually run');
        self::assertArrayNotHasKey(
            'ServiceSingleSignOnLabel',
            ispconfigconnect_MetaData(static fn (): array => [$meta])
        );
    }

    /**
     * Absence must cost nothing beyond the probe: no mint attempt, no dangling
     * session, and no failure bubbling into the servers page.
     */
    public function testDetectingTheAbsenceIsCheapAndLeavesNoOpenSession(): void
    {
        ispconfigconnect_GetRemoteMetaData($this->params());

        self::assertNotContains('connect_sso_token_create', $this->calledMethods());
        self::assertSame(0, $this->fake->activeSessionCount());
    }

    // ---- the label -------------------------------------------------------

    public function testNoSsoLabelIsAdvertisedWhenNoPanelHasTheCompanion(): void
    {
        $meta = ispconfigconnect_MetaData($this->ssoSeenOnSomeServer(false));

        self::assertArrayNotHasKey('ServiceSingleSignOnLabel', $meta);
    }

    public function testNoSsoLabelIsAdvertisedWhenNothingHasBeenProbedYet(): void
    {
        // Default reader: no WHMCS database in the test runtime, so nothing is
        // known - and "unknown" must not render a button.
        self::assertArrayNotHasKey('ServiceSingleSignOnLabel', ispconfigconnect_MetaData());
    }

    public function testTheServiceLabelAppearsOnceTheCompanionHasBeenDetected(): void
    {
        $meta = ispconfigconnect_MetaData($this->ssoSeenOnSomeServer(true));

        self::assertSame('Log in to ISPConfig', $meta['ServiceSingleSignOnLabel']);
    }

    public function testTheAdminServerLabelIsNeverAdvertised(): void
    {
        // AdminSingleSignOnLabel is the label for a SERVER-level login
        // ("Login to Panel as Admin"). The companion refuses to mint tokens
        // for administrator accounts by design, so that button could only
        // ever fail - and a dead button is worse than none.
        self::assertArrayNotHasKey('AdminSingleSignOnLabel', ispconfigconnect_MetaData($this->ssoSeenOnSomeServer(true)));
    }

    // ---- ServiceSingleSignOn ---------------------------------------------

    public function testServiceSingleSignOnRedirectsThroughTheCompanionsConsumerPage(): void
    {
        $this->fake->installCompanion();
        $params = $this->provisionedParams();

        $result = ispconfigconnect_ServiceSingleSignOn($params);

        self::assertTrue($result['success']);
        self::assertMatchesRegularExpression(
            '~^https://panel\.example\.net:8080/connectsso/\?token=[A-Za-z0-9_-]{32,}$~',
            $result['redirectTo']
        );
        self::assertSame(0, $this->fake->activeSessionCount());
    }

    public function testTheSingleUseTokenNeverReachesTheModuleLogInTheClear(): void
    {
        $this->fake->installCompanion();
        $this->fake->companionResponseOverride = [
            'token' => str_repeat('a1b2c3d4', 8),
            'path' => '/connectsso/',
            'expires_in' => 45,
        ];
        $params = $this->provisionedParams();

        ispconfigconnect_ServiceSingleSignOn($params);

        $this->assertNoCredentialLeak(str_repeat('a1b2c3d4', 8));
    }

    public function testThePanelUrlOverrideDecidesWhereTheCustomerLands(): void
    {
        $this->fake->installCompanion();
        $this->provisionedParams();

        $result = ispconfigconnect_ServiceSingleSignOn(
            $this->params(['configoption14' => 'https://cp.example.org'])
        );

        self::assertStringStartsWith('https://cp.example.org/connectsso/?token=', $result['redirectTo']);
    }

    /**
     * A stock panel is the default, so pressing the button anyway (a stale
     * link, a mixed fleet where only some panels have the project) must fail
     * SAFELY. The customer gets one sentence they can act on; the reason - the
     * separate project to install, named as the separate project it is - goes
     * to the module log, which is where an operator looks.
     */
    public function testAStockPanelFailsWithACustomerSentenceAndAnOperatorLogLine(): void
    {
        $params = $this->provisionedParams();

        $result = ispconfigconnect_ServiceSingleSignOn($params);

        self::assertFalse($result['success']);
        self::assertArrayNotHasKey('redirectTo', $result);
        $this->assertCustomerSafe($result['errorMsg']);
        self::assertSame(0, $this->fake->activeSessionCount());

        $logged = print_r($this->logEntriesFor('ServiceSingleSignOn'), true);
        self::assertStringContainsString('ispconfig-sso', $logged);
        self::assertStringContainsString('connect_sso_token_create', $logged);
        // Never a path inside this repository: the panel side is not shipped
        // here and cannot be installed from here.
        self::assertStringNotContainsString('companion/', $logged);
    }

    /**
     * THE COMMON MISCONFIGURATION: the operator installed the panel add-on and
     * forgot to tick its checkbox. It is a DIFFERENT fix from "not installed",
     * so it must be recorded as its own case rather than folded into that one.
     */
    public function testAnInstalledAddOnWithoutItsGrantIsLoggedAsItsOwnCase(): void
    {
        $this->fake->installCompanion();
        $params = $this->provisionedParams();
        $this->fake->setGrants(['client_get', 'get_function_list']);

        $result = ispconfigconnect_ServiceSingleSignOn($params);

        self::assertFalse($result['success']);
        $this->assertCustomerSafe($result['errorMsg']);

        $logged = print_r($this->logEntriesFor('ServiceSingleSignOn'), true);
        self::assertStringContainsString('connect_sso_token_create', $logged);
        self::assertStringContainsString('not_granted', $logged);
        self::assertStringNotContainsString('install the separate', $logged);
    }

    public function testAnUnprovisionedServiceSaysSoRatherThanCallingThePanel(): void
    {
        $this->fake->installCompanion();

        $result = ispconfigconnect_ServiceSingleSignOn($this->params());

        self::assertFalse($result['success']);
        $this->assertCustomerSafe($result['errorMsg']);
        self::assertSame([], $this->calledMethods());
        // The operator still gets the real reason.
        self::assertStringContainsString(
            'ispconfig_client_id',
            print_r($this->logEntriesFor('ServiceSingleSignOn'), true)
        );
    }

    public function testASuspendedClientsRefusalReachesTheLogButNotTheCustomer(): void
    {
        $this->fake->installCompanion();
        $params = $this->provisionedParams();
        self::assertSame('success', ispconfigconnect_SuspendAccount($params));
        $this->fake->failNextWith(
            'connect_sso_token_create',
            'The client account is locked; single sign-on is refused.'
        );

        $result = ispconfigconnect_ServiceSingleSignOn($params);

        self::assertFalse($result['success']);
        $this->assertCustomerSafe($result['errorMsg']);
        self::assertStringContainsString(
            'locked',
            print_r($this->logEntriesFor('ServiceSingleSignOn'), true)
        );
    }

    /**
     * MIXED FLEET, the SSO path. The button is not rendered for a service whose
     * own panel has no add-on, so a click can only come from a stale link or a
     * hand-typed URL - and the cached per-server answer already settles it. No
     * login, no mint attempt, no panel traffic at all: the customer gets the
     * same sentence either way.
     */
    public function testAServiceOnAPanelWithoutTheAddOnIsRefusedWithoutTouchingThePanel(): void
    {
        $params = $this->provisionedParams();
        $mark = $this->wireMark();

        $result = ispconfigconnect_ServiceSingleSignOn(
            $params + ['serverid' => 9],
            $this->fleet([9 => ['sso_state' => 'absent']])
        );

        self::assertFalse($result['success']);
        $this->assertCustomerSafe($result['errorMsg']);
        self::assertSame([], $this->methodsSince($mark), 'a cached "absent" needs no login');
    }

    /**
     * The same fleet, the other panel: a cached "installed" must still go to
     * the panel and mint a real token.
     */
    public function testAServiceOnAPanelWithTheAddOnStillMints(): void
    {
        $this->fake->installCompanion();
        $params = $this->provisionedParams();

        $result = ispconfigconnect_ServiceSingleSignOn(
            $params + ['serverid' => 7],
            $this->fleet([7 => ['sso_state' => 'installed'], 9 => ['sso_state' => 'absent']])
        );

        self::assertTrue($result['success']);
        self::assertStringContainsString('/connectsso/?token=', $result['redirectTo']);
    }

    /**
     * A stale cache must never be able to withhold a login the panel would
     * actually grant: only a DEFINITE cached "absent" short-circuits, and a
     * server nobody has probed yet is asked.
     */
    public function testAnUnprobedServerIsAskedRatherThanAssumedToHaveNoSso(): void
    {
        $this->fake->installCompanion();
        $params = $this->provisionedParams();

        $result = ispconfigconnect_ServiceSingleSignOn($params, $this->fleet([]));

        self::assertTrue($result['success']);
    }

    /**
     * The admin path keeps every diagnostic: an admin pressing the panel button
     * on a service whose panel has no add-on lands on the panel's own login
     * page, and the module log carries the operator-grade reason.
     */
    public function testTheAdminPathKeepsTheOperatorDetail(): void
    {
        $params = $this->provisionedParams();

        $result = ispconfigconnect_AdminSingleSignOn($params);

        self::assertTrue($result['success']);
        self::assertSame('https://panel.example.net:8080/', $result['redirectTo']);
        $logged = print_r($this->logEntriesFor('AdminSingleSignOn'), true);
        self::assertStringContainsString('ispconfig-sso', $logged);
    }

    public function testTheOutcomeIsRecordedInTheModuleLog(): void
    {
        $this->fake->installCompanion();
        $params = $this->provisionedParams();

        ispconfigconnect_ServiceSingleSignOn($params);

        $entries = array_values(array_filter(
            ModuleCallLog::entries(),
            static fn (array $entry): bool => $entry['action'] === 'ServiceSingleSignOn'
        ));
        self::assertCount(1, $entries);
        self::assertTrue($entries[0]['response']['success']);
        // The redirect URL carries the one-time token in its query string, so
        // it must never be written to a log an admin can read later.
        self::assertStringNotContainsString('token=', print_r($entries[0]['response'], true));
    }

    // ---- AdminSingleSignOn -----------------------------------------------

    public function testAdminSingleSignOnLogsIntoTheClientPanelWhenAServiceIsInScope(): void
    {
        $this->fake->installCompanion();
        $params = $this->provisionedParams();

        $result = ispconfigconnect_AdminSingleSignOn($params);

        self::assertTrue($result['success']);
        self::assertStringContainsString('/connectsso/?token=', $result['redirectTo']);
    }

    /**
     * WHMCS shows "Login to Control Panel" for any module defining
     * AdminSingleSignOn, so the SERVER-level press (no service in scope) must
     * still land somewhere useful. ISPConfig has no admin-level SSO - minting
     * an admin panel session is refused by the companion because it would be a
     * full takeover - so the admin is handed the panel's own login page, and
     * no API call is made at all.
     */
    public function testAdminSingleSignOnForABareServerOpensThePanelLoginPage(): void
    {
        $this->fake->installCompanion();
        $serverOnly = $this->params();
        unset($serverOnly['model'], $serverOnly['serviceid']);

        $result = ispconfigconnect_AdminSingleSignOn($serverOnly);

        self::assertTrue($result['success']);
        self::assertSame('https://panel.example.net:8080/', $result['redirectTo']);
        self::assertStringNotContainsString('token=', $result['redirectTo']);
        self::assertSame([], $this->calledMethods());
    }

    public function testAdminSingleSignOnForABareServerHonoursThePanelUrlOverride(): void
    {
        $serverOnly = $this->params();
        unset($serverOnly['model'], $serverOnly['serviceid']);
        $serverOnly['configoption14'] = 'https://panel.example.net/custom/';

        $result = ispconfigconnect_AdminSingleSignOn($serverOnly);

        self::assertTrue($result['success']);
        self::assertSame('https://panel.example.net/custom/', $result['redirectTo']);
    }
}
