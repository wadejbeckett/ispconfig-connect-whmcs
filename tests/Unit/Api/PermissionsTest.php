<?php

declare(strict_types=1);

namespace Ispconfigconnect\Tests\Unit\Api;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WHMCS\Module\Server\Ispconfigconnect\Api\Permissions;

#[CoversClass(Permissions::class)]
final class PermissionsTest extends TestCase
{
    /**
     * function => checkPerm() grant, verified against ISPConfig 3.3.1p1:
     *  - server_get_all -> 'server_get'          remoting.inc.php (server_get_all body)
     *  - client_template_additional_add -> 'client_update'    remote.d/client.inc.php:298ff
     *  - client_template_additional_delete -> 'client_update' remote.d/client.inc.php:336ff
     * All others check their own name (grepped per function).
     */
    private const VERIFIED_GRANTS = [
        'server_get_all' => 'server_get',
        'client_template_additional_add' => 'client_update',
        'client_template_additional_delete' => 'client_update',
        'client_add' => 'client_add',
        'client_update' => 'client_update',
        'client_change_password' => 'client_change_password',
        'client_delete_everything' => 'client_delete_everything',
        'client_templates_get_all' => 'client_templates_get_all',
        'client_get' => 'client_get',
        'client_get_by_username' => 'client_get_by_username',
        'client_get_sites_by_user' => 'client_get_sites_by_user',
        'monitor_jobqueue_count' => 'monitor_jobqueue_count',
        'get_function_list' => 'get_function_list',
        'sites_web_domain_add' => 'sites_web_domain_add',
        'sites_web_domain_get' => 'sites_web_domain_get',
        'sites_web_domain_set_status' => 'sites_web_domain_set_status',
        'sites_ftp_user_add' => 'sites_ftp_user_add',
        'sites_database_user_add' => 'sites_database_user_add',
        'sites_database_add' => 'sites_database_add',
        'mail_domain_add' => 'mail_domain_add',
        'dns_templatezone_add' => 'dns_templatezone_add',
        'dns_zone_get_id' => 'dns_zone_get_id',
        'quota_get_by_user' => 'quota_get_by_user',
        'trafficquota_get_by_user' => 'trafficquota_get_by_user',
    ];

    public function testGrantForMatchesEverySourceVerifiedPair(): void
    {
        foreach (self::VERIFIED_GRANTS as $function => $grant) {
            self::assertSame($grant, Permissions::grantFor($function), $function);
        }
    }

    public function testGrantForRejectsFunctionsTheModuleDoesNotCall(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Permissions::grantFor('openvz_vm_start');
    }

    public function testGrantForRejectsLoginBecauseItNeedsNoGrant(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Permissions::grantFor('login');
    }

    public function testRequiredGrantsCoverEveryVerifiedGrantPlusClientDelete(): void
    {
        $grants = Permissions::requiredGrants();

        foreach (array_unique(array_values(self::VERIFIED_GRANTS)) as $grant) {
            self::assertContains($grant, $grants);
        }
        // client_delete_everything re-checks 'client_delete' after the
        // cascade (remote.d/client.inc.php) - both must be granted.
        self::assertContains('client_delete', $grants);
        self::assertContains('client_delete_everything', $grants);
    }

    public function testRequiredGrantsAreUniqueAndSorted(): void
    {
        $grants = Permissions::requiredGrants();

        self::assertSame(array_values(array_unique($grants)), $grants);
        $sorted = $grants;
        sort($sorted);
        self::assertSame($sorted, $grants);
    }

    public function testReadProbesAreConsistentWithGrantFor(): void
    {
        $probes = Permissions::readProbes();

        self::assertSame(
            ['server_get_all', 'client_templates_get_all', 'monitor_jobqueue_count'],
            array_keys($probes)
        );
        foreach ($probes as $method => $probe) {
            self::assertSame(Permissions::grantFor($method), $probe['grant'], $method);
            self::assertIsArray($probe['params']);
        }
        self::assertSame(['server_id' => 0], $probes['monitor_jobqueue_count']['params']);
    }
}
