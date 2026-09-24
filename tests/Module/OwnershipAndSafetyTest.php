<?php

declare(strict_types=1);

namespace Ispconfigconnect\Tests\Module;

use WHMCS\Database\Capsule;
use WHMCS\Module\Server\Ispconfigconnect\Support\ServiceProperties;

/**
 * Regression cover for the post-review safety fixes: the one-service-per-client
 * invariant (a service is never bound to, and a client is never deleted or
 * whole-client-mutated through, an ISPConfig client another live service
 * holds), the cross-product property clearing behind Unlink/Terminate, the
 * admin-tab output escaping, and the SSO status gate.
 *
 * These paths read WHMCS tables through Capsule, which is disabled in the base
 * suite; each test that needs it seeds the in-memory double explicitly.
 */
final class OwnershipAndSafetyTest extends ModuleTestCase
{
    private const CLIENT_ID_FIELD = 90;

    /**
     * Seed the WHMCS-side rows that make $otherServiceId look like a live
     * service linked to $clientId on a (possibly different) product.
     */
    private function seedServiceLinkedToClient(
        int $otherServiceId,
        int $clientId,
        string $status = 'Active',
        int $userid = 88,
        int $productId = 100
    ): void {
        Capsule::seed('tblcustomfields', [
            ['id' => self::CLIENT_ID_FIELD, 'type' => 'product', 'fieldname' => ServiceProperties::KEY_CLIENT_ID, 'relid' => $productId],
        ]);
        Capsule::seed('tblcustomfieldsvalues', [
            ['fieldid' => self::CLIENT_ID_FIELD, 'relid' => $otherServiceId, 'value' => (string) $clientId],
        ]);
        Capsule::seed('tblhosting', [
            ['id' => $otherServiceId, 'userid' => $userid, 'domain' => 'other-customer.example', 'domainstatus' => $status, 'packageid' => $productId],
        ]);
    }

    // ── C1: terminate never deletes a shared client ───────────────────────

    public function testTerminateRefusesWhenAnotherLiveServiceSharesTheClient(): void
    {
        $clientId = $this->fake->addClient('sharedcustomer');
        $this->fake->addWebsite($clientId, 'shared-customer.example');
        $this->model->serviceProperties->save([ServiceProperties::KEY_CLIENT_ID => (string) $clientId]);
        // A DIFFERENT WHMCS customer's service points at the same client.
        $this->seedServiceLinkedToClient(99, $clientId, 'Active', 88);

        $result = ispconfigconnect_TerminateAccount($this->params(['serviceid' => 42]));

        self::assertStringContainsString('refused', $result);
        self::assertStringContainsString('#99', $result);
        // Refused BEFORE any panel call: the shared client is untouched.
        self::assertArrayHasKey($clientId, $this->fake->clients());
        self::assertSame([], array_filter($this->calledMethods(), static fn (string $m): bool => str_contains($m, 'delete')));
    }

    public function testTerminateStillDeletesWhenNoOtherServiceSharesTheClient(): void
    {
        $clientId = $this->fake->addClient('solocustomer');
        $this->fake->addWebsite($clientId, 'solo-customer.example');
        $this->model->serviceProperties->save([ServiceProperties::KEY_CLIENT_ID => (string) $clientId]);
        // Capsule enabled but no conflicting row: the guard must not invent one.
        Capsule::enable();

        $result = ispconfigconnect_TerminateAccount($this->params(['serviceid' => 42]));

        self::assertSame('success', $result);
        self::assertArrayNotHasKey($clientId, $this->fake->clients());
    }

    public function testTerminateIgnoresAnAlreadyTerminatedOtherService(): void
    {
        $clientId = $this->fake->addClient('customer');
        $this->fake->addWebsite($clientId, 'customer.example');
        $this->model->serviceProperties->save([ServiceProperties::KEY_CLIENT_ID => (string) $clientId]);
        // The other service that shared the client is itself Terminated, so it
        // holds no live claim and must not block this delete.
        $this->seedServiceLinkedToClient(99, $clientId, 'Terminated');

        self::assertSame('success', ispconfigconnect_TerminateAccount($this->params(['serviceid' => 42])));
        self::assertArrayNotHasKey($clientId, $this->fake->clients());
    }

    // ── H1: link never binds to a client another service holds ────────────

    public function testLinkRefusesBindingToAClientAnotherServiceAlreadyHolds(): void
    {
        $clientId = $this->fake->addClient('victimcustomer');
        $this->fake->addWebsite($clientId, 'victim.example');
        $this->seedServiceLinkedToClient(99, $clientId, 'Active', 88);

        $result = ispconfigconnect_LinkAccount($this->params([
            'serviceid' => 42,
            'username' => 'victimcustomer',
        ]));

        self::assertStringContainsString('refused', $result);
        self::assertStringContainsString('#99', $result);
        // Nothing was stored: the service is still unlinked.
        self::assertNull($this->model->serviceProperties->get(ServiceProperties::KEY_CLIENT_ID));
    }

    // ── M9: whole-client operations refuse a shared client ────────────────

    public function testChangePackageRefusesOnASharedClient(): void
    {
        $clientId = $this->fake->addClient('sharedcustomer');
        $this->model->serviceProperties->save([ServiceProperties::KEY_CLIENT_ID => (string) $clientId]);
        $this->seedServiceLinkedToClient(99, $clientId);

        $result = ispconfigconnect_ChangePackage($this->params(['serviceid' => 42]));

        self::assertStringContainsString('refused', $result);
        self::assertStringContainsString('#99', $result);
    }

    public function testChangePasswordRefusesOnASharedClient(): void
    {
        $clientId = $this->fake->addClient('sharedcustomer');
        $this->model->serviceProperties->save([ServiceProperties::KEY_CLIENT_ID => (string) $clientId]);
        $this->seedServiceLinkedToClient(99, $clientId);

        $result = ispconfigconnect_ChangePassword($this->params(['serviceid' => 42, 'password' => 'N3w!pass']));

        self::assertStringContainsString('refused', $result);
        self::assertStringContainsString('#99', $result);
    }

    public function testEntireClientSuspendRefusesOnASharedClientButWebsiteOnlyDoesNot(): void
    {
        $clientId = $this->fake->addClient('sharedcustomer');
        $siteId = $this->fake->addWebsite($clientId, 'shared.example');
        $this->model->serviceProperties->save([
            ServiceProperties::KEY_CLIENT_ID => (string) $clientId,
            ServiceProperties::KEY_DOMAIN_ID => (string) $siteId,
        ]);
        $this->seedServiceLinkedToClient(99, $clientId);

        // configoption13 on = entire-client suspend -> refused (locks whole client).
        $refused = ispconfigconnect_SuspendAccount($this->params(['serviceid' => 42, 'configoption13' => 'on']));
        self::assertStringContainsString('refused', $refused);

        // configoption13 off = website-only -> acts on this service's own site only, allowed.
        $ok = ispconfigconnect_SuspendAccount($this->params(['serviceid' => 42, 'configoption13' => '']));
        self::assertSame('success', $ok);
        self::assertSame('n', $this->fake->sites()[$siteId]['active']);
    }

    // ── H2: clear() forgets the link across every product ─────────────────

    public function testUnlinkBlanksPropertiesAcrossEveryProductRow(): void
    {
        // The service was on product 100 and moved to 101; both carry the id.
        Capsule::seed('tblhosting', [['id' => 42, 'packageid' => 101]]);
        Capsule::seed('tblcustomfields', [
            ['id' => 1, 'type' => 'product', 'fieldname' => ServiceProperties::KEY_CLIENT_ID, 'relid' => 100],
            ['id' => 2, 'type' => 'product', 'fieldname' => ServiceProperties::KEY_CLIENT_ID, 'relid' => 101],
        ]);
        Capsule::seed('tblcustomfieldsvalues', [
            ['id' => 10, 'fieldid' => 1, 'relid' => 42, 'value' => '5'],
            ['id' => 11, 'fieldid' => 2, 'relid' => 42, 'value' => '5'],
        ]);
        $this->model->serviceProperties->save([ServiceProperties::KEY_CLIENT_ID => '5']);

        self::assertSame('success', ispconfigconnect_UnlinkAccount($this->params(['serviceid' => 42])));

        // Both products' rows are blanked - not just the current product's.
        foreach (Capsule::rows('tblcustomfieldsvalues') as $row) {
            self::assertSame('', $row['value'], 'row on product ' . $row['fieldid'] . ' should be blanked');
        }
    }

    public function testFallbackReaderPrefersTheCurrentProductsValue(): void
    {
        // Model store empty, so reads fall through to the Capsule fallback.
        $this->model->serviceProperties = new ServicePropertiesStoreDouble();
        Capsule::seed('tblhosting', [['id' => 42, 'packageid' => 101]]);
        Capsule::seed('tblcustomfields', [
            ['id' => 1, 'type' => 'product', 'fieldname' => ServiceProperties::KEY_CLIENT_ID, 'relid' => 100],
            ['id' => 2, 'type' => 'product', 'fieldname' => ServiceProperties::KEY_CLIENT_ID, 'relid' => 101],
        ]);
        Capsule::seed('tblcustomfieldsvalues', [
            ['id' => 10, 'fieldid' => 1, 'relid' => 42, 'value' => '5'],  // old product
            ['id' => 11, 'fieldid' => 2, 'relid' => 42, 'value' => '7'],  // current product
        ]);

        $properties = ispconfigconnect_serviceProperties($this->params(['serviceid' => 42]));

        self::assertSame(7, $properties->clientId());
    }

    // ── H6: the admin ISPConfig Link field is HTML-escaped ────────────────

    public function testAdminTabEscapesTheLinkField(): void
    {
        $clientId = $this->fake->addClient('exampleuser');
        $this->model->serviceProperties->save([
            ServiceProperties::KEY_CLIENT_ID => (string) $clientId,
            // A panel/adoption-sourced string with markup: it must not reach the
            // admin page as live HTML.
            ServiceProperties::KEY_CLIENT_USERNAME => '<img src=x onerror=alert(1)>',
        ]);

        $fields = ispconfigconnect_AdminServicesTabFields($this->params(['serviceid' => 42]));

        self::assertArrayHasKey('ISPConfig Link', $fields);
        self::assertStringNotContainsString('<img', $fields['ISPConfig Link']);
        self::assertStringContainsString('&lt;img', $fields['ISPConfig Link']);
    }

    // ── M1: SSO is gated on an active service ──────────────────────────────

    public function testClientAreaHidesTheSsoButtonForASuspendedService(): void
    {
        self::assertSame('success', ispconfigconnect_CreateAccount($this->params()));
        $fleet = static fn (int $serverId): ?array => $serverId === 7 ? ['sso_state' => 'installed'] : null;

        $active = ispconfigconnect_ClientArea($this->params(['serverid' => 7, 'domainstatus' => 'Active']), $fleet);
        self::assertTrue($active['templateVariables']['ssoAvailable']);

        $suspended = ispconfigconnect_ClientArea($this->params(['serverid' => 7, 'domainstatus' => 'Suspended']), $fleet);
        self::assertFalse($suspended['templateVariables']['ssoAvailable']);
    }

    public function testServiceSingleSignOnRefusesASuspendedService(): void
    {
        $this->fake->installCompanion();
        self::assertSame('success', ispconfigconnect_CreateAccount($this->params()));

        $result = ispconfigconnect_ServiceSingleSignOn($this->params(['domainstatus' => 'Suspended']));

        self::assertFalse($result['success']);
        self::assertArrayHasKey('errorMsg', $result);
    }
}
