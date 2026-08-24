<?php

declare(strict_types=1);

namespace Ispconfigconnect\Tests\Module;

/**
 * TestConnection: login plus the three read probes (server_get_all,
 * client_templates_get_all, monitor_jobqueue_count), with an actionable
 * error message for every verified failure mode of the 3.3.1p1 wire contract.
 */
final class TestConnectionTest extends ModuleTestCase
{
    public function testSuccessRunsAllProbesAndLogsOut(): void
    {
        $result = ispconfigconnect_TestConnection($this->params());

        self::assertTrue($result['success']);
        self::assertSame('', $result['error']);
        self::assertSame(
            [
                'login',
                'server_get_all',
                'client_templates_get_all',
                'monitor_jobqueue_count',
                'logout',
            ],
            $this->calledMethods()
        );
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
}
