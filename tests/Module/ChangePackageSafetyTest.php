<?php

declare(strict_types=1);

namespace Ispconfigconnect\Tests\Module;

/**
 * CHANGE PACKAGE: ONE client_update OR NONE, AND NO LOCK FLIP.
 *
 * ISPConfig's client_update merges the stored record into its params and
 * re-runs func_client_lock() on the merged 'locked' value
 * (remote.d/client.inc.php client_update). Two consequences shape this entry
 * point:
 *
 *  - on a LOCKED client the update would re-take the lock snapshot as
 *    "everything inactive", and a later unsuspend could restore nothing - so a
 *    locked client is refused with no client_update at all;
 *  - on an UNLOCKED client the update re-activates the client's objects - so a
 *    service suspended website-only has its website suspended again, in the
 *    same session, straight after the update.
 *
 * The previous answer flipped the lock off and on around the update. That
 * disabled and re-enabled every object of an active client on every package
 * change, and a failure between its two calls stranded the client.
 */
final class ChangePackageSafetyTest extends ModuleTestCase
{
    private const WEBSITE_ONLY = ['configoption13' => ''];

    /** @return list<array{method: string, params: array<mixed>}> the client_update requests since $mark */
    private function clientUpdatesSince(int $mark): array
    {
        return array_values(array_filter(
            array_slice($this->fake->requestLog, $mark),
            static fn (array $request): bool => $request['method'] === 'client_update'
        ));
    }

    public function testAWebsiteOnlySuspendedServiceKeepsItsSiteInactiveAfterAChangeOfPackage(): void
    {
        self::assertSame('success', ispconfigconnect_CreateAccount($this->params(self::WEBSITE_ONLY)));
        $clientId = (int) array_key_first($this->fake->clients());
        $siteId = (int) array_key_first($this->fake->sites());
        $ftpId = (int) array_key_first($this->fake->ftpUsers());
        self::assertSame('success', ispconfigconnect_SuspendAccount($this->params(self::WEBSITE_ONLY)));
        self::assertSame('n', $this->fake->sites()[$siteId]['active']);

        $mark = $this->wireMark();
        $result = ispconfigconnect_ChangePackage($this->params(self::WEBSITE_ONLY + [
            'status' => 'Suspended',
            'configoption1' => (string) $this->proTemplateId,
        ]));

        self::assertSame('success', $result);
        self::assertSame($this->proTemplateId, (int) $this->fake->clients()[$clientId]['template_master']);
        // The suspension survived the package change...
        self::assertSame('n', $this->fake->sites()[$siteId]['active']);
        // ...without ever locking the client: one client_update, carrying no
        // 'locked' at all, then the website suspended again.
        self::assertSame(
            ['login', 'client_get', 'client_update', 'sites_web_domain_set_status', 'logout'],
            $this->methodsSince($mark)
        );
        $updates = $this->clientUpdatesSince($mark);
        self::assertCount(1, $updates);
        self::assertArrayNotHasKey('locked', (array) $updates[0]['params']['params']);
        self::assertSame([], $this->fake->clientTmpData($clientId), 'no lock snapshot was ever taken');
        self::assertSame('n', $this->fake->clients()[$clientId]['locked']);
        // Mail/FTP of a website-only suspension never went down.
        self::assertSame('y', $this->fake->ftpUsers()[$ftpId]['active']);
    }

    public function testAnActiveServiceGetsNoWebsiteStatusCallAfterAChangeOfPackage(): void
    {
        self::assertSame('success', ispconfigconnect_CreateAccount($this->params(self::WEBSITE_ONLY)));
        $siteId = (int) array_key_first($this->fake->sites());

        $mark = $this->wireMark();
        self::assertSame('success', ispconfigconnect_ChangePackage($this->params(self::WEBSITE_ONLY + [
            'status' => 'Active',
            'configoption1' => (string) $this->proTemplateId,
        ])));

        self::assertNotContains('sites_web_domain_set_status', $this->methodsSince($mark));
        self::assertSame('y', $this->fake->sites()[$siteId]['active']);
    }

    public function testAnEntireClientSuspendedServiceIsRefusedAndTheClientIsUntouched(): void
    {
        self::assertSame('success', ispconfigconnect_CreateAccount($this->params()));
        $clientId = (int) array_key_first($this->fake->clients());
        $siteId = (int) array_key_first($this->fake->sites());
        self::assertSame('success', ispconfigconnect_SuspendAccount($this->params()));
        $snapshot = $this->fake->clientTmpData($clientId);

        $mark = $this->wireMark();
        $result = ispconfigconnect_ChangePackage($this->params([
            'status' => 'Suspended',
            'configoption1' => (string) $this->proTemplateId,
        ]));

        self::assertStringStartsWith(
            'Change Package refused: this service\'s ISPConfig client is locked (suspended).',
            $result
        );
        self::assertStringContainsString('Unsuspend the service first', $result);
        self::assertStringContainsString('Nothing has been changed', $result);
        // Only the lock check reached the panel.
        self::assertSame([], $this->clientUpdatesSince($mark));
        self::assertSame($this->masterTemplateId, (int) $this->fake->clients()[$clientId]['template_master']);
        self::assertSame('y', $this->fake->clients()[$clientId]['locked']);
        self::assertSame($snapshot, $this->fake->clientTmpData($clientId));

        // The snapshot is intact, so unsuspend still restores everything - and
        // the package can be changed afterwards.
        self::assertSame('success', ispconfigconnect_UnsuspendAccount($this->params()));
        self::assertSame('y', $this->fake->sites()[$siteId]['active']);
        self::assertSame('success', ispconfigconnect_ChangePackage($this->params([
            'status' => 'Active',
            'configoption1' => (string) $this->proTemplateId,
        ])));
        self::assertSame($this->proTemplateId, (int) $this->fake->clients()[$clientId]['template_master']);
    }

    public function testAFailedReSuspensionIsReportedWithTheWebsiteNamedAsLive(): void
    {
        self::assertSame('success', ispconfigconnect_CreateAccount($this->params(self::WEBSITE_ONLY)));
        $clientId = (int) array_key_first($this->fake->clients());
        $siteId = (int) array_key_first($this->fake->sites());
        self::assertSame('success', ispconfigconnect_SuspendAccount($this->params(self::WEBSITE_ONLY)));
        $this->fake->failNextWith('sites_web_domain_set_status', 'database_error UPDATE web_domain');

        $result = ispconfigconnect_ChangePackage($this->params(self::WEBSITE_ONLY + [
            'status' => 'Suspended',
            'configoption1' => (string) $this->proTemplateId,
        ]));

        // The package did change; what failed is putting the website back
        // offline, and that must not read as 'success'.
        self::assertNotSame('success', $result);
        self::assertStringContainsString('applied the new package', $result);
        self::assertStringContainsString('#' . $siteId, $result);
        self::assertStringContainsString('LIVE', $result);
        self::assertStringContainsString('database_error UPDATE web_domain', $result);
        self::assertSame($this->proTemplateId, (int) $this->fake->clients()[$clientId]['template_master']);
        self::assertSame('y', $this->fake->sites()[$siteId]['active']);
        self::assertSame(0, $this->fake->activeSessionCount());
    }
    public function testAServiceSuspendedWebsiteOnlyAndMovedToAWholeClientProductIsLockedNotLeftLive(): void
    {
        self::assertSame('success', ispconfigconnect_CreateAccount($this->params(self::WEBSITE_ONLY)));
        $clientId = (int) array_key_first($this->fake->clients());
        $siteId = (int) array_key_first($this->fake->sites());
        self::assertSame('success', ispconfigconnect_SuspendAccount($this->params(self::WEBSITE_ONLY)));
        self::assertSame('n', $this->fake->clients()[$clientId]['locked']);

        // The new product locks the whole client on suspension.
        $mark = $this->wireMark();
        $result = ispconfigconnect_ChangePackage($this->params([
            'status' => 'Suspended',
            'configoption1' => (string) $this->proTemplateId,
        ]));

        self::assertSame('success', $result);
        self::assertSame($this->proTemplateId, (int) $this->fake->clients()[$clientId]['template_master']);
        // Suspended the way the NEW product suspends: the client is locked.
        self::assertSame('y', $this->fake->clients()[$clientId]['locked']);
        self::assertSame('n', $this->fake->sites()[$siteId]['active']);
        self::assertNotContains('sites_web_domain_set_status', $this->methodsSince($mark));

        // Unsuspend under the new product unlocks it, and the site comes back.
        self::assertSame('success', ispconfigconnect_UnsuspendAccount($this->params()));
        self::assertSame('n', $this->fake->clients()[$clientId]['locked']);
        self::assertSame('y', $this->fake->sites()[$siteId]['active']);
    }
}
