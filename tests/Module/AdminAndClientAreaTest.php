<?php

declare(strict_types=1);

namespace Ispconfigconnect\Tests\Module;

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Admin surface (AdminServicesTabFields, VerifyPermissions custom button)
 * and the client-area overview wiring - including the no-credential rule
 * for everything a customer's browser can see (design §3.6.3).
 */
final class AdminAndClientAreaTest extends ModuleTestCase
{
    public function testAdminServicesTabFieldsShowsStoredIdsAndLiveJobqueue(): void
    {
        $this->model->serviceProperties->save([
            'ispconfig_client_id' => '7',
            'ispconfig_client_username' => 'user7',
            'ispconfig_domain_id' => '3',
        ]);
        $this->fake->jobqueueCount = 5;

        $fields = ispconfigconnect_AdminServicesTabFields($this->params());

        self::assertSame('7', $fields['ISPConfig Client ID']);
        self::assertSame('user7', $fields['ISPConfig Username']);
        self::assertSame('3', $fields['ISPConfig Domain ID']);
        self::assertSame('5', $fields['ISPConfig Job Queue']);
        self::assertStringContainsString('https://panel.example.net:8080/', $fields['ISPConfig Panel']);
        self::assertStringContainsString('<a href=', $fields['ISPConfig Panel']);
        // The live probe logs out after itself.
        self::assertSame(0, $this->fake->activeSessionCount());
    }

    public function testAdminServicesTabFieldsSurvivesJobqueueFailure(): void
    {
        $this->model->serviceProperties->save(['ispconfig_client_id' => '7']);
        $this->fake->failNextWith('monitor_jobqueue_count', 'database_error SELECT');

        $fields = ispconfigconnect_AdminServicesTabFields($this->params());

        // Exception-safe: the panel still renders, depth shows n/a (spec 2.8).
        self::assertSame('n/a', $fields['ISPConfig Job Queue']);
        self::assertSame('7', $fields['ISPConfig Client ID']);
    }

    public function testAdminServicesTabFieldsWithoutModelReturnsNoFields(): void
    {
        $params = $this->params();
        unset($params['model']);

        self::assertSame([], ispconfigconnect_AdminServicesTabFields($params));
    }

    public function testAdminServicesTabFieldsShowsLiveUsageForAProvisionedService(): void
    {
        self::assertSame('success', ispconfigconnect_CreateAccount($this->params()));
        $domainId = (int) $this->model->serviceProperties->get('ispconfig_domain_id');
        $this->fake->setDiskUsage('web' . $domainId, usedKilobytes: 262144);
        $this->fake->setWebTraffic('example.com', thisMonthBytes: 1048576);

        $fields = ispconfigconnect_AdminServicesTabFields($this->params());

        // The product provisions websites with hd_quota/traffic_quota -1, so
        // both limits read as unlimited here.
        self::assertSame('256 MB of unlimited', $fields['ISPConfig Disk Usage']);
        self::assertSame('1 MB of unlimited', $fields['ISPConfig Bandwidth (this month)']);
        self::assertSame(0, $this->fake->activeSessionCount());
    }

    /**
     * The stock panel is checked FIRST because it is the default: single
     * sign-on needs the separate ispconfig-sso project, which this module does
     * not ship, so "not installed" is what a fresh install reports - and it
     * must say which project to deploy rather than a bare "no".
     *
     * Every state on this row is written as an INSTRUCTION, not a status word.
     * An admin who opens this tab is looking at one service on one server and
     * wants to know what to do next; "available" told them nothing they could
     * act on.
     */
    public function testAdminServicesTabFieldsReportsWhetherSingleSignOnIsAvailable(): void
    {
        self::assertSame('success', ispconfigconnect_CreateAccount($this->params()));

        $stock = ispconfigconnect_AdminServicesTabFields($this->params());
        self::assertStringContainsString('not installed', strtolower($stock['ISPConfig Single Sign-On']));
        self::assertStringContainsString('ispconfig-sso', $stock['ISPConfig Single Sign-On']);
        self::assertStringNotContainsString('companion/', $stock['ISPConfig Single Sign-On']);
        // What to do next, on this panel, in this order.
        self::assertStringContainsString('Servers', $stock['ISPConfig Single Sign-On']);

        $this->fake->installCompanion();
        $withSsoProject = ispconfigconnect_AdminServicesTabFields($this->params());
        self::assertStringContainsString('Available', $withSsoProject['ISPConfig Single Sign-On']);
        // Presence is not permission - the panel cannot be asked about grants,
        // so the row says where to look when a login is refused anyway.
        self::assertStringContainsString(
            'connect_sso_token_create',
            $withSsoProject['ISPConfig Single Sign-On']
        );
    }

    /**
     * "We were not allowed to look" is its own answer: reporting it as "not
     * installed" would send an operator to install a project the panel may
     * already have.
     */
    public function testAdminServicesTabFieldsSeparatesARefusedProbeFromAnAbsentAddOn(): void
    {
        self::assertSame('success', ispconfigconnect_CreateAccount($this->params()));
        $this->fake->setGrants(['client_get', 'quota_get_by_user', 'monitor_jobqueue_count']);

        $fields = ispconfigconnect_AdminServicesTabFields($this->params());

        self::assertStringContainsString('get_function_list', $fields['ISPConfig Single Sign-On']);
        self::assertStringNotContainsString('is not installed', $fields['ISPConfig Single Sign-On']);
    }

    public function testAdminServicesTabFieldsDegradesEveryLiveFigureWhenThePanelIsDown(): void
    {
        $this->model->serviceProperties->save(['ispconfig_client_id' => '7']);
        $this->fake->apiEnabled = false;

        $fields = ispconfigconnect_AdminServicesTabFields($this->params());

        self::assertSame('7', $fields['ISPConfig Client ID']);
        self::assertSame('n/a', $fields['ISPConfig Job Queue']);
        self::assertSame('n/a', $fields['ISPConfig Disk Usage']);
        self::assertSame('n/a', $fields['ISPConfig Bandwidth (this month)']);
        // An unreachable panel is not an answer about single sign-on: the row
        // must send the admin to the connection, not to the add-on.
        self::assertStringContainsStringIgnoringCase(
            'could not be reached',
            $fields['ISPConfig Single Sign-On']
        );
        self::assertStringNotContainsString('ispconfig-sso', $fields['ISPConfig Single Sign-On']);
    }

    public function testAdminServicesTabFieldsSkipsUsageForAnUnprovisionedService(): void
    {
        $fields = ispconfigconnect_AdminServicesTabFields($this->params());

        // "not linked", not "not provisioned": a service can now be managed by
        // this module without this module having created it, so the question
        // the row answers is whether a link exists - not who made the account.
        self::assertSame('not linked', $fields['ISPConfig Client ID']);
        self::assertArrayNotHasKey('ISPConfig Disk Usage', $fields);
        // No client id means nothing to ask the panel about beyond the queue.
        self::assertSame(
            [],
            array_filter($this->calledMethods(), static fn (string $m): bool => $m === 'quota_get_by_user')
        );
    }

    public function testAdminCustomButtonArrayExposesVerifyPermissions(): void
    {
        // Verify Permissions stays FIRST - it is the diagnostic button, and
        // the three that follow it are the manual link override (see
        // AdoptionTest for their behaviour).
        self::assertSame(
            [
                'Verify Permissions' => 'VerifyPermissions',
                'Link to ISPConfig Account' => 'LinkAccount',
                'Replace ISPConfig Link' => 'RelinkAccount',
                'Unlink from ISPConfig' => 'UnlinkAccount',
            ],
            ispconfigconnect_AdminCustomButtonArray()
        );
    }

    public function testVerifyPermissionsSucceedsWhenAllGrantsPresent(): void
    {
        self::assertSame('success', ispconfigconnect_VerifyPermissions($this->params()));
        self::assertSame(0, $this->fake->activeSessionCount());
    }

    public function testVerifyPermissionsListsEveryMissingGrant(): void
    {
        $this->fake->setGrants(['client_templates_get_all']);

        $result = ispconfigconnect_VerifyPermissions($this->params());

        self::assertNotSame('success', $result);
        self::assertStringContainsString('server_get', $result);
        self::assertStringContainsString('monitor_jobqueue_count', $result);
        self::assertStringNotContainsString('client_templates_get_all', $result);
        // get_function_list is missing here too - it is what the single
        // sign-on probe needs, and it is a grant this module requires.
        self::assertStringContainsString('get_function_list', $result);
    }

    /**
     * WHMCS renders ANY non-'success' return of a custom admin button as a
     * Module Command Error, so an optional add-on that is simply not installed
     * must not be reported here: it is not a permission finding, and dressing
     * it as one would make a correct setup look broken. The admin service tab
     * and Test Connection are where that state is reported.
     */
    public function testVerifyPermissionsTreatsAnAbsentSsoAddOnAsNothingToReport(): void
    {
        self::assertSame('success', ispconfigconnect_VerifyPermissions($this->params()));

        $this->fake->installCompanion();
        self::assertSame('success', ispconfigconnect_VerifyPermissions($this->params()));
    }

    /**
     * A refused probe IS a permission finding: get_function_list is a required
     * grant, and without it single sign-on cannot be determined either way.
     */
    public function testVerifyPermissionsReportsARefusedSsoProbeAsItsOwnFinding(): void
    {
        $this->fake->setGrants(['server_get', 'client_templates_get_all', 'monitor_jobqueue_count']);

        $result = ispconfigconnect_VerifyPermissions($this->params());

        self::assertNotSame('success', $result);
        self::assertStringContainsString('get_function_list', $result);
        self::assertStringContainsString('Single sign-on', $result);
        self::assertStringNotContainsString('is not installed', $result);
    }

    public function testClientAreaReturnsOverviewTemplateWithPanelUrlAndUsername(): void
    {
        $this->model->serviceProperties->save(['ispconfig_client_username' => 'user7']);

        $output = ispconfigconnect_ClientArea($this->params());

        self::assertSame('templates/overview.tpl', $output['tabOverviewReplacementTemplate']);
        self::assertSame('https://panel.example.net:8080/', $output['templateVariables']['panelUrl']);
        self::assertSame('user7', $output['templateVariables']['ispconfigUsername']);
        // The overview is read-only: no wire traffic from the client area.
        self::assertSame([], $this->fake->requestLog);
    }

    public function testClientAreaPanelUrlOverrideWins(): void
    {
        $output = ispconfigconnect_ClientArea($this->params([
            'configoption14' => 'https://panel.example.org/',
        ]));

        self::assertSame('https://panel.example.org/', $output['templateVariables']['panelUrl']);
    }

    public function testClientAreaFallsBackToServiceUsernameWithoutStoredProperty(): void
    {
        $output = ispconfigconnect_ClientArea($this->params());

        self::assertSame('exampleuser', $output['templateVariables']['ispconfigUsername']);
    }

    /**
     * A cached-metadata reader double, standing in for the per-server
     * tblservers_remote lookup a real WHMCS install runs.
     *
     * @param array<int, array<string, mixed>> $byServerId
     * @return \Closure(int): ?array<string, mixed>
     */
    private function fleet(array $byServerId): \Closure
    {
        return static fn (int $serverId): ?array => $byServerId[$serverId] ?? null;
    }

    /**
     * THE MIXED FLEET, which is the whole reason this gating exists.
     *
     * MetaData() is asked whether the MODULE supports single sign-on with no
     * server in scope, so its label is all-or-nothing across the fleet. The
     * client area is not: it renders one service at a time, and that service
     * has exactly one server. So the button is offered here, per service, and
     * only where that service's own panel reports the add-on - a customer on
     * the panel without it is offered nothing that can fail.
     */
    public function testTheLoginButtonIsOfferedOnlyForServicesOnAPanelThatHasTheAddOn(): void
    {
        self::assertSame('success', ispconfigconnect_CreateAccount($this->params()));
        $fleet = $this->fleet([
            7 => ['sso_state' => 'installed'],
            9 => ['sso_state' => 'absent'],
        ]);

        $withAddOn = ispconfigconnect_ClientArea($this->params(['serverid' => 7]), $fleet);
        $withoutAddOn = ispconfigconnect_ClientArea($this->params(['serverid' => 9]), $fleet);

        self::assertTrue($withAddOn['templateVariables']['ssoAvailable']);
        self::assertFalse($withoutAddOn['templateVariables']['ssoAvailable']);

        // The service on the panel without the add-on loses the button and
        // NOTHING else: the panel address and the login name are exactly what
        // they were before.
        self::assertSame(
            $withAddOn['templateVariables']['panelUrl'],
            $withoutAddOn['templateVariables']['panelUrl']
        );
        self::assertSame(
            $withAddOn['templateVariables']['ispconfigUsername'],
            $withoutAddOn['templateVariables']['ispconfigUsername']
        );
    }

    /**
     * The button posts back to WHMCS's own documented single sign-on entry
     * point (/clientarea.php?action=productdetails&id=N&dosinglesignon=1), so
     * the template needs the service id and never builds a panel URL itself.
     */
    public function testTheLoginButtonCarriesTheServiceItBelongsTo(): void
    {
        self::assertSame('success', ispconfigconnect_CreateAccount($this->params()));

        $output = ispconfigconnect_ClientArea(
            $this->params(['serverid' => 7]),
            $this->fleet([7 => ['sso_state' => 'installed']])
        );

        self::assertTrue($output['templateVariables']['ssoAvailable']);
        self::assertSame(42, $output['templateVariables']['ssoServiceId']);
        self::assertSame('Log in to ISPConfig', $output['templateVariables']['ssoLabel']);
    }

    /**
     * Nothing has been probed yet (a fresh install, or a server WHMCS has never
     * rendered the servers page for). Unknown must not render a button: the
     * only honest default is the one that cannot fail.
     */
    public function testAnUnprobedServerOffersNoLoginButton(): void
    {
        self::assertSame('success', ispconfigconnect_CreateAccount($this->params()));

        $output = ispconfigconnect_ClientArea($this->params(['serverid' => 7]), $this->fleet([]));

        self::assertFalse($output['templateVariables']['ssoAvailable']);
    }

    /**
     * The panel has the add-on, but this service was never provisioned by the
     * module - there is no ISPConfig client to log into, so the button would
     * fail for a reason that has nothing to do with the panel.
     */
    public function testAnUnprovisionedServiceOffersNoLoginButtonEvenWhereTheAddOnExists(): void
    {
        $output = ispconfigconnect_ClientArea(
            $this->params(['serverid' => 7]),
            $this->fleet([7 => ['sso_state' => 'installed']])
        );

        self::assertFalse($output['templateVariables']['ssoAvailable']);
    }

    /** Older cached rows carry the boolean only; it still has to be read. */
    public function testTheLegacyBooleanFlagIsStillUnderstood(): void
    {
        self::assertSame('success', ispconfigconnect_CreateAccount($this->params()));

        $output = ispconfigconnect_ClientArea(
            $this->params(['serverid' => 7]),
            $this->fleet([7 => ['sso_available' => true]])
        );

        self::assertTrue($output['templateVariables']['ssoAvailable']);
    }

    public function testTheClientAreaStillMakesNoApiCallWhileDecidingAboutTheButton(): void
    {
        self::assertSame('success', ispconfigconnect_CreateAccount($this->params()));
        $mark = $this->wireMark();

        ispconfigconnect_ClientArea(
            $this->params(['serverid' => 7]),
            $this->fleet([7 => ['sso_state' => 'installed']])
        );

        self::assertSame([], $this->methodsSince($mark));
    }

    /**
     * The overview template is the only thing a customer actually sees, so the
     * gate has to be IN it: a variable that nothing renders would be a silent
     * no-op, and a button rendered unconditionally would defeat the gate.
     */
    public function testTheOverviewTemplateRendersTheButtonOnlyBehindThatGate(): void
    {
        $template = (string) file_get_contents(
            __DIR__ . '/../../modules/servers/ispconfigconnect/templates/overview.tpl'
        );

        self::assertStringContainsString('{if $ssoAvailable}', $template);
        self::assertStringContainsString('dosinglesignon=1', $template);
        self::assertStringContainsString('$ssoServiceId', $template);
        // The panel link and login name stay outside the gate: they are what a
        // customer without single sign-on uses.
        $beforeGate = substr($template, 0, (int) strpos($template, '{if $ssoAvailable}'));
        self::assertStringContainsString('$ispconfigUsername', $beforeGate);
        self::assertStringContainsString('$panelUrl', $beforeGate);
    }

    // ---- an unusable server record never breaks either page ----------------
    //
    // ServerConfig refuses an empty or malformed Hostname/IP by throwing: that
    // value addresses the POST carrying the remote-API password, so it must be
    // exact. But a customer's product page and an admin's service tab are the
    // wrong places for that exception to surface - a service whose server was
    // removed, or never set, must still render.

    /** @return array<string, array{0: array<string, string>}> */
    public static function unusableServerRecords(): array
    {
        return [
            'no hostname and no IP' => [['serverhostname' => '', 'serverip' => '']],
            'hostname with a port' => [['serverhostname' => 'panel.example.net:8080']],
            'hostname with a scheme' => [['serverhostname' => 'https://panel.example.net']],
        ];
    }

    /** @param array<string, string> $server */
    #[DataProvider('unusableServerRecords')]
    public function testTheClientAreaRendersWithoutPanelLinkOrLoginButtonOnAnUnusableServer(array $server): void
    {
        self::assertSame('success', ispconfigconnect_CreateAccount($this->params()));

        $output = ispconfigconnect_ClientArea(
            $this->params(['serverid' => 7, 'status' => 'Active'] + $server),
            $this->fleet([7 => ['sso_state' => 'installed']])
        );

        self::assertSame('templates/overview.tpl', $output['tabOverviewReplacementTemplate']);
        self::assertSame('', $output['templateVariables']['panelUrl']);
        self::assertSame('exampleuser', $output['templateVariables']['ispconfigUsername']);
        // The login URL is built from the same server record, so the button
        // could only fail - it is not offered.
        self::assertFalse($output['templateVariables']['ssoAvailable']);
    }

    public function testAValidPanelUrlOverrideStillShowsWhenTheServerRecordIsUnusable(): void
    {
        self::assertSame('success', ispconfigconnect_CreateAccount($this->params()));

        $output = ispconfigconnect_ClientArea(
            $this->params([
                'serverid' => 7,
                'serverhostname' => '',
                'serverip' => '',
                'configoption14' => 'https://panel.example.org/',
            ]),
            $this->fleet([7 => ['sso_state' => 'installed']])
        );

        self::assertSame('https://panel.example.org/', $output['templateVariables']['panelUrl']);
        self::assertFalse($output['templateVariables']['ssoAvailable']);
    }

    /** @param array<string, string> $server */
    #[DataProvider('unusableServerRecords')]
    public function testTheAdminTabKeepsEveryOtherRowAndExplainsThePanelRowOnAnUnusableServer(array $server): void
    {
        $this->model->serviceProperties->save([
            'ispconfig_client_id' => '7',
            'ispconfig_client_username' => 'user7',
            'ispconfig_domain_id' => '3',
        ]);

        $fields = ispconfigconnect_AdminServicesTabFields($this->params($server));

        // The rows an admin needs MOST when the server record is broken.
        self::assertArrayHasKey('ISPConfig Link', $fields);
        self::assertSame('7', $fields['ISPConfig Client ID']);
        self::assertSame('user7', $fields['ISPConfig Username']);
        self::assertSame('3', $fields['ISPConfig Domain ID']);
        self::assertSame('n/a', $fields['ISPConfig Job Queue']);
        // The panel row says what is wrong and where to fix it - no dead link.
        self::assertStringStartsWith('Not available:', $fields['ISPConfig Panel']);
        self::assertStringContainsString('Server Hostname/IP', $fields['ISPConfig Panel']);
        self::assertStringNotContainsString('<a ', $fields['ISPConfig Panel']);
        // A broken host is never even dialled.
        self::assertSame([], $this->fake->requestLog);
    }

    public function testClientAreaVariablesNeverContainAPassword(): void
    {
        $params = $this->params();
        $output = ispconfigconnect_ClientArea($params);

        $flattened = [];
        array_walk_recursive(
            $output['templateVariables'],
            static function (mixed $value, string|int $key) use (&$flattened): void {
                $flattened[] = (string) $key . '=' . (is_scalar($value) ? (string) $value : '');
            }
        );
        $rendered = implode(' ', $flattened);

        self::assertStringNotContainsString((string) $params['password'], $rendered);
        self::assertStringNotContainsString((string) $params['serverpassword'], $rendered);
        self::assertStringNotContainsStringIgnoringCase('password', $rendered);
    }
}
