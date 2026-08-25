<?php

declare(strict_types=1);

namespace Ispconfigconnect\Tests\Module;

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

    public function testAdminServicesTabFieldsReportsWhetherSingleSignOnIsAvailable(): void
    {
        self::assertSame('success', ispconfigconnect_CreateAccount($this->params()));

        $stock = ispconfigconnect_AdminServicesTabFields($this->params());
        self::assertStringContainsString('companion/ispconfig-sso', $stock['ISPConfig Single Sign-On']);

        $this->fake->installCompanion();
        $withCompanion = ispconfigconnect_AdminServicesTabFields($this->params());
        self::assertSame('available', $withCompanion['ISPConfig Single Sign-On']);
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
        self::assertSame('unknown', $fields['ISPConfig Single Sign-On']);
    }

    public function testAdminServicesTabFieldsSkipsUsageForAnUnprovisionedService(): void
    {
        $fields = ispconfigconnect_AdminServicesTabFields($this->params());

        self::assertSame('not provisioned', $fields['ISPConfig Client ID']);
        self::assertArrayNotHasKey('ISPConfig Disk Usage', $fields);
        // No client id means nothing to ask the panel about beyond the queue.
        self::assertSame(
            [],
            array_filter($this->calledMethods(), static fn (string $m): bool => $m === 'quota_get_by_user')
        );
    }

    public function testAdminCustomButtonArrayExposesVerifyPermissions(): void
    {
        self::assertSame(
            ['Verify Permissions' => 'VerifyPermissions'],
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
