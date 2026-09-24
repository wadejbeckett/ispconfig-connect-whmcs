<?php

declare(strict_types=1);

namespace Ispconfigconnect\Tests\Module;

/**
 * TestConnection: login plus the read probes (the two dropdown-loader grants,
 * the job-queue reachability probe, and the safe client/site/usage reads every
 * lifecycle and sweep operation depends on), with an actionable error message
 * for every verified failure mode of the 3.3.1p1 wire contract.
 */
final class TestConnectionTest extends ModuleTestCase
{
    public function testSuccessRunsAllProbesAndLogsOut(): void
    {
        $result = ispconfigconnect_TestConnection($this->params());

        self::assertTrue($result['success']);
        self::assertSame(
            [
                'login',
                'server_get_all',
                'client_templates_get_all',
                'monitor_jobqueue_count',
                // Safe read probes: each uses an id/name that matches nothing,
                // so a present grant answers "not found" and only a missing
                // grant is refused (spec 3.6 least-privilege verification).
                'client_get',
                'client_get_all',
                'client_get_by_username',
                'client_get_sites_by_user',
                'sites_web_domain_get',
                'sites_ftp_user_get',
                'dns_zone_get',
                'quota_get_by_user',
                'mailquota_get_by_user',
                'databasequota_get_by_user',
                // The SSO presence probe rides the SAME session as the grant
                // probes: Test Connection is the moment an admin is looking at
                // this server, so it is where single sign-on gets reported.
                'get_function_list',
                'logout',
            ],
            $this->calledMethods()
        );
    }

    /**
     * SSO IS OPTIONAL. A stock ISPConfig has no ispconfig-sso add-on, which is
     * what almost every panel is, and that must read as a fact about the panel -
     * not as a failed connection test.
     */
    public function testAStockPanelReportsSingleSignOnWithoutFailingTheTest(): void
    {
        $result = ispconfigconnect_TestConnection($this->params());

        self::assertTrue($result['success'], 'an absent optional add-on is not a connection failure');
        self::assertStringContainsString('Single sign-on', $result['error']);
        self::assertStringContainsString('ispconfig-sso', $result['error']);
        self::assertStringContainsString('not installed', strtolower($result['error']));
    }

    public function testAnInstalledAddOnIsReportedAsAvailable(): void
    {
        $this->fake->installCompanion();

        $result = ispconfigconnect_TestConnection($this->params());

        self::assertTrue($result['success']);
        self::assertStringContainsString('Available', $result['error']);
        // Presence is not permission: the panel never reports grants, so the
        // one thing detection cannot see has to be named here.
        self::assertStringContainsString('connect_sso_token_create', $result['error']);
    }

    /**
     * The probe itself is grant-checked. Being refused means "we were not
     * allowed to look", which is a DIFFERENT fix from "the add-on is not there"
     * - and get_function_list is a grant this module requires anyway, so the
     * test must name it rather than shrug.
     */
    public function testARefusedProbeIsReportedAsUndeterminableAndNamesTheGrant(): void
    {
        // Every required grant EXCEPT the optional SSO probe (get_function_list)
        // is present, so the only finding is that one probe grant.
        $this->fake->setGrants(array_values(array_diff(
            \WHMCS\Module\Server\Ispconfigconnect\Api\Permissions::requiredGrants(),
            ['get_function_list']
        )));

        $result = ispconfigconnect_TestConnection($this->params());

        self::assertTrue($result['success'], 'a missing SSO probe grant is not a connection failure');
        self::assertStringContainsString('get_function_list', $result['error']);
        self::assertStringNotContainsString('is not installed', $result['error']);
    }

    /**
     * When grants ARE missing the existing advice must survive intact - the SSO
     * line is only allowed to add the one finding that is genuinely actionable
     * (the probe grant), never to bury the grant list.
     */
    public function testTheSsoLineNeverDisplacesTheMissingGrantAdvice(): void
    {
        $this->fake->setGrants(['client_templates_get_all']);

        $result = ispconfigconnect_TestConnection($this->params());

        self::assertFalse($result['success']);
        self::assertStringContainsString('server_get', $result['error']);
        self::assertStringContainsString('monitor_jobqueue_count', $result['error']);
        self::assertStringContainsString('docs/REMOTE-USER.md', $result['error']);
        // get_function_list is missing here too, and it is what the SSO probe
        // needs - so it is named as its own finding.
        self::assertStringContainsString('get_function_list', $result['error']);
    }

    /**
     * A panel that never answered cannot be reported as "no SSO here": the
     * failure advice is the whole answer.
     */
    public function testAnUnreachablePanelReportsNothingAboutSingleSignOn(): void
    {
        $this->fake->apiEnabled = false;

        $result = ispconfigconnect_TestConnection($this->params());

        self::assertFalse($result['success']);
        self::assertStringNotContainsString('ispconfig-sso', $result['error']);
        self::assertStringNotContainsString('Single sign-on', $result['error']);
    }

    public function testBadPasswordExplainsWhichCredentialsToCheck(): void
    {
        $result = ispconfigconnect_TestConnection($this->params(['serverpassword' => 'wrong-password']));

        self::assertFalse($result['success']);
        self::assertStringContainsString('The login failed. Username or password wrong.', $result['error']);
        // Actionable: which WHMCS fields, and that it is a REMOTE user login.
        self::assertStringContainsString('Username and Password', $result['error']);
        self::assertStringContainsString('Remote Users', $result['error']);
    }

    public function testMissingGrantNamesEveryAbsentGrant(): void
    {
        // Grant only one of the three probes: the error must name BOTH missing
        // grant names (grant 'server_get' covers server_get_all - §1.5).
        $this->fake->setGrants(['client_templates_get_all']);

        $result = ispconfigconnect_TestConnection($this->params());

        self::assertFalse($result['success']);
        self::assertStringContainsString('server_get', $result['error']);
        self::assertStringContainsString('monitor_jobqueue_count', $result['error']);
        self::assertStringContainsString('Remote Users', $result['error']);
    }

    public function testLockoutAdvisesBackoff(): void
    {
        $this->fake->failNextWith('login', 'The login failure limit has been reached.');

        $result = ispconfigconnect_TestConnection($this->params());

        self::assertFalse($result['success']);
        // remoting.inc.php: 10 failures per 5-minute window, and every further
        // attempt extends the window - the only fix is to stop trying.
        self::assertStringContainsString('5 minutes', $result['error']);
        self::assertStringContainsStringIgnoringCase('wait', $result['error']);
    }

    public function testApiDisabledPointsAtThePanelSetting(): void
    {
        $this->fake->apiEnabled = false;

        $result = ispconfigconnect_TestConnection($this->params());

        self::assertFalse($result['success']);
        // The switch is remote_api_allowed in security_settings.ini
        // (interface/web/remote/json.php + install/tpl/security_settings.ini.master).
        self::assertStringContainsString('remote_api_allowed', $result['error']);
        self::assertStringContainsString('security_settings.ini', $result['error']);
    }

    public function testIpNotAllowedNamesTheIpToAllowlist(): void
    {
        // remoting.inc.php appends $_SERVER['REMOTE_ADDR'] to the fault text.
        $this->fake->failNextWith('login', 'The login is not allowed from 198.51.100.7');

        $result = ispconfigconnect_TestConnection($this->params());

        self::assertFalse($result['success']);
        self::assertStringContainsString('198.51.100.7', $result['error']);
        // The remote user's allowlist field is 'Remote Access IPs'
        // (admin/form/remote_user.tform.php remote_ips).
        self::assertStringContainsString('Remote Access IPs', $result['error']);
    }

    public function testMaintenanceModeIsReportedAsTemporary(): void
    {
        $this->fake->maintenanceMode = true;

        $result = ispconfigconnect_TestConnection($this->params());

        self::assertFalse($result['success']);
        self::assertStringContainsStringIgnoringCase('maintenance', $result['error']);
    }

    public function testTlsMismatchAgainstBareIpAdvisesFillingTheHostnameField(): void
    {
        \WHMCS\Module\Server\Ispconfigconnect\Support\Factory::$transportFactory =
            static fn (): \WHMCS\Module\Server\Ispconfigconnect\Api\HttpTransportInterface =>
                new class implements \WHMCS\Module\Server\Ispconfigconnect\Api\HttpTransportInterface {
                    public function post(string $method, string $jsonBody): string
                    {
                        throw new \WHMCS\Module\Server\Ispconfigconnect\Api\Exception\TransportException(
                            'curl error 60 while calling https://198.51.100.7:8080/remote/json.php?login:'
                            . " SSL: no alternative certificate subject name matches target host name '198.51.100.7'"
                        );
                    }
                };

        $result = ispconfigconnect_TestConnection($this->params());

        self::assertFalse($result['success']);
        self::assertStringContainsString('Hostname field', $result['error']);
    }
}
