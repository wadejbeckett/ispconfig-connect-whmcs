<?php

declare(strict_types=1);

namespace Ispconfigconnect\Tests\Module;

use WHMCS\Database\Capsule;
use WHMCS\Module\Server\Ispconfigconnect\Support\ServiceProperties;

/**
 * UNSUSPEND REVERSES THE SUSPENSION THAT WAS APPLIED, NOT THE PRODUCT'S
 * CURRENT SETTING.
 *
 * WHMCS builds the module's config options from the service's CURRENT product
 * (decoded 8.12.1 Server::buildParams), and the "Suspend Entire Client" box
 * can change - or the service can move to another product - while it is
 * suspended. Unsuspending in the current mode then undoes the wrong thing:
 *
 *  - suspended by the whole-client lock, now website-only: only the website
 *    came back, and the client stayed locked (mail, FTP, databases off) while
 *    WHMCS showed Active - with Change Package refusing "unsuspend first";
 *  - suspended website-only, now whole-client: the client was not locked, so
 *    the unlock was a no-op and the website stayed off.
 *
 * Suspend now records the mode it used (ServiceProperties::KEY_SUSPENDED_AS),
 * and Unsuspend reverses that.
 */
final class SuspensionModeTest extends ModuleTestCase
{
    private const WEBSITE_ONLY = ['configoption13' => ''];
    private const ENTIRE = ['configoption13' => 'on'];

    /**
     * @param array<string, string> $product
     * @return array{0: int, 1: int, 2: int} client id, website id, FTP user id
     */
    private function created(array $product): array
    {
        self::assertSame('success', ispconfigconnect_CreateAccount($this->params($product)));

        return [
            (int) array_key_first($this->fake->clients()),
            (int) array_key_first($this->fake->sites()),
            (int) array_key_first($this->fake->ftpUsers()),
        ];
    }

    private function recorded(): mixed
    {
        return $this->model->serviceProperties->get(ServiceProperties::KEY_SUSPENDED_AS);
    }

    public function testSuspendRecordsTheModeAndUnsuspendClearsIt(): void
    {
        $this->created(self::ENTIRE);
        self::assertSame('success', ispconfigconnect_SuspendAccount($this->params(self::ENTIRE)));
        self::assertSame(ServiceProperties::SUSPENDED_AS_CLIENT, $this->recorded());
        self::assertSame('success', ispconfigconnect_UnsuspendAccount($this->params(self::ENTIRE)));
        self::assertSame('', $this->recorded());

        self::assertSame('success', ispconfigconnect_SuspendAccount($this->params(self::WEBSITE_ONLY)));
        self::assertSame(ServiceProperties::SUSPENDED_AS_WEBSITE, $this->recorded());
        self::assertSame('success', ispconfigconnect_UnsuspendAccount($this->params(self::WEBSITE_ONLY)));
        self::assertSame('', $this->recorded());
    }

    public function testAClientLockedUnderAWholeClientProductIsUnlockedAfterTheProductTurnsWebsiteOnly(): void
    {
        [$clientId, $siteId, $ftpId] = $this->created(self::ENTIRE);
        self::assertSame('success', ispconfigconnect_SuspendAccount($this->params(self::ENTIRE)));
        self::assertSame('y', $this->fake->clients()[$clientId]['locked']);

        // The product is now website-only. Change Package still refuses the
        // locked client and sends the admin to Unsuspend...
        $websiteOnlyPro = self::WEBSITE_ONLY + ['configoption1' => (string) $this->proTemplateId];
        self::assertStringStartsWith(
            'Change Package refused',
            ispconfigconnect_ChangePackage($this->params($websiteOnlyPro + ['status' => 'Suspended']))
        );

        // ...and Unsuspend now lifts the lock it recorded.
        self::assertSame('success', ispconfigconnect_UnsuspendAccount($this->params($websiteOnlyPro + ['status' => 'Suspended'])));
        self::assertSame('n', $this->fake->clients()[$clientId]['locked']);
        self::assertSame('y', $this->fake->sites()[$siteId]['active']);
        self::assertSame('y', $this->fake->ftpUsers()[$ftpId]['active']);

        // The package change then goes through.
        self::assertSame('success', ispconfigconnect_ChangePackage($this->params($websiteOnlyPro + ['status' => 'Active'])));
        self::assertSame($this->proTemplateId, (int) $this->fake->clients()[$clientId]['template_master']);
    }

    public function testAWebsiteSuspendedWebsiteOnlyComesBackAfterTheProductTurnsWholeClient(): void
    {
        [$clientId, $siteId] = $this->created(self::WEBSITE_ONLY);
        self::assertSame('success', ispconfigconnect_SuspendAccount($this->params(self::WEBSITE_ONLY)));
        self::assertSame('n', $this->fake->sites()[$siteId]['active']);

        $mark = $this->wireMark();
        self::assertSame('success', ispconfigconnect_UnsuspendAccount($this->params(self::ENTIRE + ['status' => 'Suspended'])));

        self::assertSame('y', $this->fake->sites()[$siteId]['active']);
        self::assertSame('n', $this->fake->clients()[$clientId]['locked']);
        // The recorded website-only suspension is reversed as such: no
        // client_update, so nothing an admin disabled by hand is re-enabled.
        self::assertNotContains('client_update', $this->methodsSince($mark));
    }

    public function testAnUnrecordedWebsiteOnlySuspensionIsStillReversedUnderAWholeClientProduct(): void
    {
        // A suspension made by an earlier build has no recorded mode.
        [$clientId, $siteId] = $this->created(self::WEBSITE_ONLY);
        self::assertSame('success', ispconfigconnect_SuspendAccount($this->params(self::WEBSITE_ONLY)));
        $this->model->serviceProperties->save([ServiceProperties::KEY_SUSPENDED_AS => '']);

        $mark = $this->wireMark();
        self::assertSame('success', ispconfigconnect_UnsuspendAccount($this->params(self::ENTIRE + ['status' => 'Suspended'])));

        self::assertSame('y', $this->fake->sites()[$siteId]['active']);
        self::assertSame('n', $this->fake->clients()[$clientId]['locked']);
        self::assertSame(
            ['login', 'client_get', 'sites_web_domain_get', 'sites_web_domain_set_status', 'logout'],
            $this->methodsSince($mark)
        );
    }

    public function testAWebsiteOnlySuspensionLeavesALockSetByHandInIspconfigAlone(): void
    {
        [$clientId, $siteId] = $this->created(self::WEBSITE_ONLY);
        self::assertSame('success', ispconfigconnect_SuspendAccount($this->params(self::WEBSITE_ONLY)));
        // An admin locks the client by hand in ISPConfig (abuse handling, say).
        $this->fake->lockClient($clientId);

        self::assertSame('success', ispconfigconnect_UnsuspendAccount($this->params(self::WEBSITE_ONLY)));

        // The module lifts only what it applied: the website suspension.
        self::assertSame('y', $this->fake->clients()[$clientId]['locked']);
    }

    public function testSuspendingAgainUnderAWholeClientProductDoesNotLockTheWebsiteOff(): void
    {
        [$clientId, $siteId, $ftpId] = $this->created(self::WEBSITE_ONLY);
        self::assertSame('success', ispconfigconnect_SuspendAccount($this->params(self::WEBSITE_ONLY)));

        // The product turns whole-client and Suspend is pressed again. The
        // website is put back first, so the lock records it as active...
        self::assertSame('success', ispconfigconnect_SuspendAccount($this->params(self::ENTIRE + ['status' => 'Suspended'])));
        self::assertSame('y', $this->fake->clients()[$clientId]['locked']);
        self::assertSame('n', $this->fake->sites()[$siteId]['active']);
        self::assertSame(ServiceProperties::SUSPENDED_AS_CLIENT, $this->recorded());

        // ...and the unlock brings everything back, the website included.
        self::assertSame('success', ispconfigconnect_UnsuspendAccount($this->params(self::ENTIRE + ['status' => 'Suspended'])));
        self::assertSame('n', $this->fake->clients()[$clientId]['locked']);
        self::assertSame('y', $this->fake->sites()[$siteId]['active']);
        self::assertSame('y', $this->fake->ftpUsers()[$ftpId]['active']);
    }

    public function testSuspendingAgainAfterALockKeepsTheLockAndItsSnapshot(): void
    {
        [$clientId, $siteId] = $this->created(self::ENTIRE);
        self::assertSame('success', ispconfigconnect_SuspendAccount($this->params(self::ENTIRE)));
        $snapshot = $this->fake->clientTmpData($clientId);

        // The product turned website-only in between; the lock still stands
        // for this service, so Suspend stays in whole-client mode.
        $mark = $this->wireMark();
        self::assertSame('success', ispconfigconnect_SuspendAccount($this->params(self::WEBSITE_ONLY + ['status' => 'Suspended'])));
        self::assertSame(['login', 'client_get', 'logout'], $this->methodsSince($mark));
        self::assertSame($snapshot, $this->fake->clientTmpData($clientId));
        self::assertSame(ServiceProperties::SUSPENDED_AS_CLIENT, $this->recorded());

        self::assertSame('success', ispconfigconnect_UnsuspendAccount($this->params(self::WEBSITE_ONLY)));
        self::assertSame('n', $this->fake->clients()[$clientId]['locked']);
        self::assertSame('y', $this->fake->sites()[$siteId]['active']);
    }

    public function testChangePackageRecordsTheModeItSuspendsAgainIn(): void
    {
        [$clientId, $siteId] = $this->created(self::WEBSITE_ONLY);
        self::assertSame('success', ispconfigconnect_SuspendAccount($this->params(self::WEBSITE_ONLY)));

        // Moved to a whole-client product while Suspended: Change Package
        // locks the client and records that...
        self::assertSame('success', ispconfigconnect_ChangePackage($this->params(self::ENTIRE + [
            'status' => 'Suspended',
            'configoption1' => (string) $this->proTemplateId,
        ])));
        self::assertSame('y', $this->fake->clients()[$clientId]['locked']);
        self::assertSame(ServiceProperties::SUSPENDED_AS_CLIENT, $this->recorded());

        // ...so the unlock is what Unsuspend reverses.
        self::assertSame('success', ispconfigconnect_UnsuspendAccount($this->params(self::ENTIRE)));
        self::assertSame('n', $this->fake->clients()[$clientId]['locked']);
        self::assertSame('y', $this->fake->sites()[$siteId]['active']);
    }

    public function testTerminateForgetsTheRecordedMode(): void
    {
        $this->created(self::ENTIRE);
        self::assertSame('success', ispconfigconnect_SuspendAccount($this->params(self::ENTIRE)));

        self::assertContains(ServiceProperties::KEY_SUSPENDED_AS, ServiceProperties::KEYS);
        // Terminate refuses unless the shared-client check can run.
        Capsule::enable();
        self::assertSame('success', ispconfigconnect_TerminateAccount($this->params(self::ENTIRE)));
        self::assertSame('', $this->recorded());
    }
    public function testARefusedWebsiteReadNeverFailsAnUnsuspendThatHasNothingElseToDo(): void
    {
        // An unrecorded suspension under a whole-client product, client not
        // locked: the website check is extra, so a panel refusal of it is
        // logged and the Unsuspend still succeeds.
        $this->created(self::ENTIRE);
        $this->fake->failNextWith('sites_web_domain_get', 'You do not have the permissions to access this function.');

        self::assertSame('success', ispconfigconnect_UnsuspendAccount($this->params(self::ENTIRE)));
    }
}
