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
 * advertised only where the project was actually detected, and both entry
 * points must explain its absence rather than fail cryptically. The fake panel
 * models the same default - FakeIspconfigServer::$companionInstalled is false
 * until a test calls installCompanion().
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
     * A stock panel is the default, so pressing the button anyway (an admin
     * following a stale link, several servers where only some have the project)
     * must name the thing to install - and name it as the SEPARATE project it
     * is. It must never point at a path inside this repository: this module
     * does not ship the panel side and cannot install it.
     */
    public function testAStockPanelExplainsHowToEnableSsoInsteadOfFailingCryptically(): void
    {
        $params = $this->provisionedParams();

        $result = ispconfigconnect_ServiceSingleSignOn($params);

        self::assertFalse($result['success']);
        self::assertStringContainsString('ispconfig-sso', $result['errorMsg']);
        self::assertStringContainsString('connect_sso_token_create', $result['errorMsg']);
        self::assertStringNotContainsString('companion/', $result['errorMsg']);
        self::assertArrayNotHasKey('redirectTo', $result);
        self::assertSame(0, $this->fake->activeSessionCount());
    }

    public function testAMissingCompanionGrantNamesTheGrantToTick(): void
    {
        $this->fake->installCompanion();
        $params = $this->provisionedParams();
        $this->fake->setGrants(['client_get', 'get_function_list']);

        $result = ispconfigconnect_ServiceSingleSignOn($params);

        self::assertFalse($result['success']);
        self::assertStringContainsString('connect_sso_token_create', $result['errorMsg']);
    }

    public function testAnUnprovisionedServiceSaysSoRatherThanCallingThePanel(): void
    {
        $this->fake->installCompanion();

        $result = ispconfigconnect_ServiceSingleSignOn($this->params());

        self::assertFalse($result['success']);
        self::assertStringContainsString('ispconfig_client_id', $result['errorMsg']);
        self::assertSame([], $this->calledMethods());
    }

    public function testASuspendedClientsRefusalIsPassedThroughVerbatim(): void
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
        self::assertStringContainsString('locked', $result['errorMsg']);
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
