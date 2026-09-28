<?php

declare(strict_types=1);

namespace Ispconfigconnect\Tests\Module;

use Ispconfigconnect\Tests\Stubs\ModuleCallLog;
use WHMCS\Database\Capsule;
use WHMCS\Module\Server\Ispconfigconnect\Api\SessionManager;
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

    /** @return array{action: string, request: mixed, response: array<string, mixed>} the last module-log entry for $action */
    private function lastLog(string $action): array
    {
        $entries = array_values(array_filter(
            ModuleCallLog::entries(),
            static fn (array $entry): bool => $entry['action'] === $action
        ));
        self::assertNotSame([], $entries, $action . ' must write a module-log entry');
        $last = $entries[count($entries) - 1];
        self::assertIsArray($last['response']);

        /** @var array{action: string, request: mixed, response: array<string, mixed>} $last */
        return $last;
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

    public function testWebsiteOnlySuspendWithoutAWebsiteIdNeverLocksASharedClient(): void
    {
        // The escalation this pins shut: website-only mode skips the shared
        // guard (it only ever touches this service's own site), so falling back
        // to the whole-client lock took the OTHER service's hosting down too.
        $clientId = $this->fake->addClient('sharedcustomer');
        $ourSite = $this->fake->addWebsite($clientId, 'example.com');
        $theirSite = $this->fake->addWebsite($clientId, 'other-customer.example');
        $this->model->serviceProperties->save([ServiceProperties::KEY_CLIENT_ID => (string) $clientId]);
        $this->seedServiceLinkedToClient(99, $clientId);

        $mark = $this->wireMark();
        $result = ispconfigconnect_SuspendAccount($this->params(['serviceid' => 42, 'configoption13' => '']));

        self::assertStringStartsWith('Suspend refused:', $result);
        self::assertSame([], $this->methodsSince($mark));
        self::assertSame('n', $this->fake->clients()[$clientId]['locked']);
        self::assertSame('y', $this->fake->sites()[$ourSite]['active']);
        self::assertSame('y', $this->fake->sites()[$theirSite]['active']);
    }

    // ── Unsuspend on a shared client ──────────────────────────────────────

    /**
     * A client an older build locked while another service shared it. The
     * unlock may go ahead when nobody else on the client is suspended - it
     * restores nothing anyone is not entitled to - so a paying customer is not
     * stranded behind the guard.
     */
    public function testEntireClientUnsuspendOfASharedClientProceedsWhenEveryOtherServiceIsActive(): void
    {
        Capsule::enable();
        self::assertSame('success', ispconfigconnect_CreateAccount($this->params(['serviceid' => 42])));
        $clientId = (int) array_key_first($this->fake->clients());
        $siteId = (int) array_key_first($this->fake->sites());
        self::assertSame('success', ispconfigconnect_SuspendAccount($this->params(['serviceid' => 42])));
        self::assertSame('y', $this->fake->clients()[$clientId]['locked']);
        // Only now does the other (Active) service appear on the same client.
        $this->seedServiceLinkedToClient(99, $clientId, 'Active');

        $result = ispconfigconnect_UnsuspendAccount($this->params(['serviceid' => 42]));

        self::assertSame('success', $result);
        self::assertSame('n', $this->fake->clients()[$clientId]['locked']);
        self::assertSame('y', $this->fake->sites()[$siteId]['active']);
        // Allowed, but not silently: the log names the other service.
        $decision = $this->lastLog('UnsuspendAccount');
        self::assertTrue($decision['response']['shared_client']);
        self::assertStringContainsString('#99', (string) $decision['response']['other_services']);
    }

    public function testEntireClientUnsuspendRefusesWhileAnotherServiceOnTheClientIsSuspended(): void
    {
        Capsule::enable();
        self::assertSame('success', ispconfigconnect_CreateAccount($this->params(['serviceid' => 42])));
        $clientId = (int) array_key_first($this->fake->clients());
        $siteId = (int) array_key_first($this->fake->sites());
        self::assertSame('success', ispconfigconnect_SuspendAccount($this->params(['serviceid' => 42])));
        $this->seedServiceLinkedToClient(99, $clientId, 'Suspended');

        $mark = $this->wireMark();
        $result = ispconfigconnect_UnsuspendAccount($this->params(['serviceid' => 42]));

        // Unlocking would bring service #99 - which WHMCS has suspended - back
        // online with this one.
        self::assertStringStartsWith('Unsuspend refused:', $result);
        self::assertStringContainsString('#99', $result);
        self::assertStringContainsString('Suspended', $result);
        self::assertSame([], $this->methodsSince($mark));
        self::assertSame('y', $this->fake->clients()[$clientId]['locked']);
        self::assertSame('n', $this->fake->sites()[$siteId]['active']);
    }

    // ── The guard never fails open silently ───────────────────────────────
    //
    // The Capsule double is left DISABLED in these tests, so every query the
    // guard makes throws - exactly what a failing WHMCS database does.

    public function testTerminateRefusesWhenTheSharedClientCheckCannotRun(): void
    {
        $clientId = $this->fake->addClient('solocustomer');
        $this->fake->addWebsite($clientId, 'solo-customer.example');
        $this->model->serviceProperties->save([ServiceProperties::KEY_CLIENT_ID => (string) $clientId]);

        $mark = $this->wireMark();
        $result = ispconfigconnect_TerminateAccount($this->params(['serviceid' => 42]));

        self::assertStringStartsWith(
            'Terminate refused: the module could not verify that no other WHMCS service shares ISPConfig client #'
            . $clientId,
            $result
        );
        // Irreversible, so nothing was attempted: no session, no delete.
        self::assertSame([], $this->methodsSince($mark));
        self::assertArrayHasKey($clientId, $this->fake->clients());
        self::assertSame((string) $clientId, $this->model->serviceProperties->get(ServiceProperties::KEY_CLIENT_ID));

        $guard = $this->lastLog('SharedClientGuard');
        self::assertFalse($guard['response']['checked']);
        self::assertStringContainsString('Capsule is unavailable', (string) $guard['response']['error']);
    }

    public function testOtherOperationsProceedWhenTheSharedClientCheckCannotRunButSaySoInTheLog(): void
    {
        self::assertSame('success', ispconfigconnect_CreateAccount($this->params(['serviceid' => 42])));
        $clientId = (int) array_key_first($this->fake->clients());

        self::assertSame('success', ispconfigconnect_SuspendAccount($this->params(['serviceid' => 42])));
        self::assertSame('y', $this->fake->clients()[$clientId]['locked']);
        self::assertSame(
            'success',
            ispconfigconnect_ChangePassword($this->params(['serviceid' => 42, 'password' => 'N3w!pass']))
        );

        $guardLines = array_values(array_filter(
            ModuleCallLog::entries(),
            static fn (array $entry): bool => $entry['action'] === 'SharedClientGuard'
        ));
        self::assertCount(2, $guardLines, 'each operation that ran unchecked must say so');
    }

    public function testLinkProceedsWhenTheSharedClientCheckCannotRun(): void
    {
        $clientId = $this->fake->addClient('paigemurray');

        self::assertSame('success', ispconfigconnect_LinkAccount($this->params(['username' => 'paigemurray'])));

        self::assertSame((string) $clientId, $this->model->serviceProperties->get(ServiceProperties::KEY_CLIENT_ID));
        self::assertFalse($this->lastLog('SharedClientGuard')['response']['checked']);
    }

    public function testAutomaticAdoptionLeavesAServiceUnlinkedWhenTheSharedClientCheckCannotRun(): void
    {
        // Nobody asked for an automatic link, so an unverified one is not made
        // (adoption rule 1) - on the admin tab...
        $clientId = $this->fake->addClient('acme');
        $this->fake->addWebsite($clientId, 'acme.example');
        $resolver = static fn (SessionManager $session, array $services): array => [42 => [
            'reason' => 'matched',
            'clientid' => $clientId,
            'clientusername' => 'acme',
            'evidence' => 'service domain acme.example matched ISPConfig client #' . $clientId,
        ]];

        $fields = ispconfigconnect_AdminServicesTabFields($this->params(['domain' => 'acme.example']), $resolver);

        self::assertStringContainsString('could not run', $fields['ISPConfig Link']);
        self::assertNull($this->model->serviceProperties->get(ServiceProperties::KEY_CLIENT_ID));

        // ...and in the nightly sweep.
        $written = [];
        ispconfigconnect_UsageUpdate(
            $this->params(['serverid' => 3]),
            static fn (int $serverId): array => [[
                'id' => 42,
                'username' => 'oldpleskname',
                'domain' => 'acme.example',
                'ispconfig_client_id' => '',
                'ispconfig_client_username' => '',
            ]],
            static function (int $serviceId, array $columns): void {
            },
            $resolver,
            static function (int $serviceId, array $values) use (&$written): void {
                $written[$serviceId] = $values;
            }
        );

        self::assertSame([], $written);
        $summary = $this->lastLog('UsageUpdate');
        self::assertStringContainsString('could not run', (string) $summary['response']['unlinked'][42]);
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
    //
    // Keyed on 'status', the parameter WHMCS actually sends (core's
    // buildServiceParams: $params["status"] = $serviceData->domainStatus).
    // These two tests used 'domainstatus' - the tblhosting column name, which
    // WHMCS never passes - and so passed while the gate was dead in production.
    // ServiceStatusSourceTest covers the model fallback and the precedence.

    public function testClientAreaHidesTheSsoButtonForASuspendedService(): void
    {
        self::assertSame('success', ispconfigconnect_CreateAccount($this->params()));
        $fleet = static fn (int $serverId): ?array => $serverId === 7 ? ['sso_state' => 'installed'] : null;

        $active = ispconfigconnect_ClientArea($this->params(['serverid' => 7, 'status' => 'Active']), $fleet);
        self::assertTrue($active['templateVariables']['ssoAvailable']);

        $suspended = ispconfigconnect_ClientArea($this->params(['serverid' => 7, 'status' => 'Suspended']), $fleet);
        self::assertFalse($suspended['templateVariables']['ssoAvailable']);
    }

    public function testServiceSingleSignOnRefusesASuspendedService(): void
    {
        $this->fake->installCompanion();
        self::assertSame('success', ispconfigconnect_CreateAccount($this->params()));

        $result = ispconfigconnect_ServiceSingleSignOn($this->params(['status' => 'Suspended']));

        self::assertFalse($result['success']);
        self::assertArrayHasKey('errorMsg', $result);
    }
    // ── product addons: WHMCS passes the PARENT service's id ─────────────

    public function testEveryOperationOnAProductAddonOfALinkedParentRefusesAndLeavesTheParentAlone(): void
    {
        // Parent service 42 is linked to a client (its product custom field).
        // WHMCS calls the module for an addon on it with serviceid = 42 and
        // addonId = 7; the addon's own, addon-scoped properties are empty.
        $clientId = $this->fake->addClient('parentcustomer');
        $this->fake->addWebsite($clientId, 'parent.example');
        $this->seedServiceLinkedToClient(42, $clientId);
        $addon = $this->params(['serviceid' => 42, 'addonId' => 7]);

        $mark = $this->wireMark();
        foreach ([
            'Create Account' => ispconfigconnect_CreateAccount($addon),
            'Suspend' => ispconfigconnect_SuspendAccount($addon),
            'Unsuspend' => ispconfigconnect_UnsuspendAccount($addon),
            'Change Password' => ispconfigconnect_ChangePassword($addon),
            'Change Package' => ispconfigconnect_ChangePackage($addon),
            'Terminate' => ispconfigconnect_TerminateAccount($addon),
        ] as $operation => $result) {
            self::assertStringContainsString(
                'cannot run on this product addon: its parent service #42 is itself linked to ISPConfig client #' . $clientId,
                $result,
                $operation
            );
        }

        // Nothing reached the panel, and the parent's client and its stored
        // link are untouched.
        self::assertSame([], $this->methodsSince($mark));
        self::assertArrayHasKey($clientId, $this->fake->clients());
        self::assertSame(
            [(string) $clientId],
            array_column(array_filter(Capsule::rows('tblcustomfieldsvalues'), static fn (array $r): bool => $r['relid'] === 42), 'value')
        );
    }

    public function testAProductAddonOfAnUnlinkedParentRunsOnItsOwnProperties(): void
    {
        // The parent (42) is not an ISPConfig service; the addon (7) carries
        // its own client in its addon-scoped properties, as earlier builds let
        // it provision.
        Capsule::seed('tblhosting', [['id' => 42, 'packageid' => 300, 'domainstatus' => 'Active']]);
        $clientId = $this->fake->addClient('addoncustomer');
        $this->fake->addWebsite($clientId, 'addon.example');
        $this->model->serviceProperties->save([ServiceProperties::KEY_CLIENT_ID => (string) $clientId]);
        $addon = $this->params(['serviceid' => 42, 'addonId' => 7]);

        self::assertSame('success', ispconfigconnect_SuspendAccount($addon));
        self::assertSame('y', $this->fake->clients()[$clientId]['locked']);
        self::assertSame('success', ispconfigconnect_UnsuspendAccount($addon));
        self::assertSame('n', $this->fake->clients()[$clientId]['locked']);
    }

    public function testAProductAddonIsRefusedWhenItsParentCannotBeChecked(): void
    {
        // The Capsule double is left disabled, so every query throws - the
        // parent's link cannot be read, which must not read as "not linked".
        $result = ispconfigconnect_SuspendAccount($this->params(['serviceid' => 42, 'addonId' => 7]));

        self::assertStringContainsString('could not be checked', $result);
    }
}
