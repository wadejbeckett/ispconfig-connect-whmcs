<?php

declare(strict_types=1);

namespace Ispconfigconnect\Tests\Unit\Sso;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WHMCS\Module\Server\Ispconfigconnect\Api\Permissions;
use WHMCS\Module\Server\Ispconfigconnect\Sso\CompanionClient;

/**
 * The SSO companion adds ONE remote function, and it is optional: it exists
 * only on panels where companion/ispconfig-sso is installed. It must therefore
 * be resolvable through Permissions (the single source of truth for grant
 * names) WITHOUT appearing in requiredGrants(), which documents the grants a
 * stock panel must give the module.
 */
#[CoversClass(Permissions::class)]
final class CompanionPermissionsTest extends TestCase
{
    public function testTheCompanionFunctionChecksItsOwnName(): void
    {
        // connect_sso_token_create calls checkPerm($session_id,
        // 'connect_sso_token_create') - companion/ispconfig-sso remote.d class.
        self::assertSame(
            'connect_sso_token_create',
            Permissions::grantFor(CompanionClient::REMOTE_FUNCTION)
        );
    }

    public function testTheCompanionGrantIsOptionalNotRequired(): void
    {
        self::assertContains(CompanionClient::REMOTE_FUNCTION, Permissions::optionalGrants());
        self::assertNotContains(CompanionClient::REMOTE_FUNCTION, Permissions::requiredGrants());
    }

    public function testOptionalGrantsAreUniqueAndSorted(): void
    {
        $grants = Permissions::optionalGrants();

        self::assertSame(array_values(array_unique($grants)), $grants);
        $sorted = $grants;
        sort($sorted);
        self::assertSame($sorted, $grants);
    }

    public function testOptionalGrantsDoNotOverlapRequiredOnes(): void
    {
        self::assertSame(
            [],
            array_intersect(Permissions::optionalGrants(), Permissions::requiredGrants())
        );
    }
}
