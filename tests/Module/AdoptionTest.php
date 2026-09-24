<?php

declare(strict_types=1);

namespace Ispconfigconnect\Tests\Module;

use Ispconfigconnect\Tests\Stubs\ModuleCallLog;
use PHPUnit\Framework\Attributes\DataProvider;
use WHMCS\Module\Server\Ispconfigconnect\Api\SessionManager;

/**
 * ADOPTION OF PRE-EXISTING ACCOUNTS.
 *
 * The module only ever recognised a service as its own when that service
 * carried a stored ispconfig_client_id, and that property was written in
 * exactly one place: CreateAccount. A customer migrated onto ISPConfig from
 * another panel therefore had a live WHMCS service and a live ISPConfig
 * account that the module refused to connect - suspend, terminate, usage and
 * single sign-on all answered "no stored ispconfig_client_id".
 *
 * These tests pin the behaviour that replaces the hand-typed workaround:
 * the module links the service ITSELF, and every rule that keeps that safe.
 * The five rules are load-bearing, because this code acts on real customers'
 * hosting:
 *
 *   1. Link only on an exact, unambiguous match - zero or several candidates
 *      means DO NOT LINK, and say why.
 *   2. Never auto-link inside a destructive action. Terminate on an unlinked
 *      service refuses; it does not resolve-then-delete.
 *   3. Every link written is logged with the evidence that justified it.
 *   4. Adoption is idempotent and never overwrites a stored id.
 *   5. A stored id that no longer resolves is reported STALE, never silently
 *      re-pointed at another client.
 *
 * THE RESOLVER IS A SEAM. Matching lives in Adopt\Resolver (a separate lane);
 * this file drives the plain-data seam the entry file consumes it through,
 * exactly as the existing $rowProvider / $writer / $ssoStateReader seams are
 * driven. Nothing here depends on that class existing.
 */
final class AdoptionTest extends ModuleTestCase
{
    /** @var array<int, array<string, mixed>> serviceId => written tblhosting columns */
    private array $writes = [];

    /** @var array<int, array<string, string>> serviceId => written service properties */
    private array $properties = [];

    /** @var list<list<array<string, mixed>>> one entry per resolver invocation */
    private array $resolverCalls = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->writes = [];
        $this->properties = [];
        $this->resolverCalls = [];
    }

    // ---- seams -----------------------------------------------------------

    private function usageWriter(): \Closure
    {
        return function (int $serviceId, array $columns): void {
            $this->writes[$serviceId] = $columns;
        };
    }

    private function propertyWriter(): \Closure
    {
        return function (int $serviceId, array $values): void {
            $this->properties[$serviceId] = array_merge($this->properties[$serviceId] ?? [], $values);
        };
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return \Closure(int): list<array<string, mixed>>
     */
    private function rows(array $rows): \Closure
    {
        return static fn (int $serverId): array => $rows;
    }

    /** @return array<string, mixed> */
    private function row(int $serviceId, int $clientId, string $username, string $domain = ''): array
    {
        return [
            'id' => $serviceId,
            'username' => $username,
            'domain' => $domain,
            'ispconfig_client_id' => $clientId > 0 ? (string) $clientId : '',
            'ispconfig_client_username' => $clientId > 0 ? $username : '',
        ];
    }

    /**
     * A resolver seam that answers from a fixed map and records what it was
     * asked, so the tests can assert BOTH the outcome and the call pattern
     * (one batch per sweep, and never a call at all from a destructive path).
     *
     * $answers is deliberately typed `mixed` per service: the seam's contract
     * is that ANY value can come back, and refusing to link on a malformed one
     * is behaviour under test (see the trusted-answer test below).
     *
     * @param array<int, mixed> $answers serviceId => outcome
     */
    private function resolver(array $answers): \Closure
    {
        return function (SessionManager $session, array $services) use ($answers): array {
            $this->resolverCalls[] = $services;

            $resolved = [];
            foreach ($services as $service) {
                $serviceId = (int) $service['serviceid'];
                if (isset($answers[$serviceId])) {
                    $resolved[$serviceId] = $answers[$serviceId];
                }
            }

            return $resolved;
        };
    }

    /**
     * @return array<string, mixed>
     */
    private static function matched(int $clientId, string $username, string $evidence): array
    {
        return [
            'reason' => 'matched',
            'clientid' => $clientId,
            'clientusername' => $username,
            'evidence' => $evidence,
        ];
    }

    // ---- rule 1: link only on an exact, unambiguous match -----------------

    public function testTheNightlySweepAdoptsAnUnlinkedServiceAndBillsItInTheSameRun(): void
    {
        // The migration case: the panel account exists, the WHMCS service
        // exists, and nothing has ever connected them.
        $clientId = $this->fake->addClient('globex');
        $domainId = $this->fake->addWebsite($clientId, 'globex.example', hdQuotaMegabytes: 2048, trafficQuotaMegabytes: 20480);
        $this->fake->setDiskUsage('web' . $domainId, usedKilobytes: 1048576);
        $this->fake->setWebTraffic('globex.example', thisMonthBytes: 1073741824);

        ispconfigconnect_UsageUpdate(
            $this->params(),
            $this->rows([$this->row(1176, 0, 'legacyuser', 'globex.example')]),
            $this->usageWriter(),
            $this->resolver([1176 => self::matched(
                $clientId,
                'globex',
                'service domain globex.example matched ISPConfig client #' . $clientId
                    . ' (globex) - sole owner of that web domain'
            )]),
            $this->propertyWriter()
        );

        // Linked...
        self::assertSame((string) $clientId, $this->properties[1176]['ispconfig_client_id']);
        self::assertSame('globex', $this->properties[1176]['ispconfig_client_username']);
        // ...and billed in the SAME sweep, not the next one.
        self::assertSame(
            ['diskusage' => 1024, 'disklimit' => 2048, 'bwusage' => 1024, 'bwlimit' => 20480],
            $this->writes[1176]
        );
    }

    public function testAnAmbiguousMatchIsNeverLinkedAndTheCandidatesAreNamed(): void
    {
        // Two panel clients could own this domain. Picking one would act on a
        // DIFFERENT customer's hosting, so the module picks neither.
        ispconfigconnect_UsageUpdate(
            $this->params(),
            $this->rows([$this->row(1176, 0, 'legacy', 'shared.example')]),
            $this->usageWriter(),
            $this->resolver([1176 => [
                'reason' => 'ambiguous',
                'evidence' => 'service domain shared.example is owned by more than one ISPConfig client',
                'candidates' => ['#2 (acme)', '#3 (globex)'],
            ]]),
            $this->propertyWriter()
        );

        self::assertSame([], $this->properties, 'an ambiguous match must never be written');

        $summary = self::lastLog('UsageUpdate');
        $unlinked = $summary['response']['unlinked'];
        self::assertIsArray($unlinked);
        self::assertStringContainsString('more than one', (string) $unlinked[1176]);
        // The candidates must be NAMED - "ambiguous" alone leaves the admin
        // with nothing to act on.
        self::assertStringContainsString('acme', (string) $unlinked[1176]);
        self::assertStringContainsString('globex', (string) $unlinked[1176]);
    }

    public function testNoMatchIsNeverLinkedAndSaysSo(): void
    {
        ispconfigconnect_UsageUpdate(
            $this->params(),
            $this->rows([$this->row(1176, 0, 'legacy', 'nowhere.example')]),
            $this->usageWriter(),
            $this->resolver([1176 => [
                'reason' => 'no_match',
                'evidence' => 'no ISPConfig client owns the web domain nowhere.example',
            ]]),
            $this->propertyWriter()
        );

        self::assertSame([], $this->properties);
        self::assertSame([], $this->writes);
        $summary = self::lastLog('UsageUpdate');
        self::assertStringContainsString('nowhere.example', (string) $summary['response']['unlinked'][1176]);
    }

    public function testAResolverAnswerThatCannotBeTrustedNeverProducesALink(): void
    {
        // Everything that is not an explicit, well-formed "matched" with a
        // usable positive client id is refused HERE, in the entry file,
        // independently of whatever the resolver believes it returned.
        $rows = [
            $this->row(1, 0, 'a', 'a.example'),
            $this->row(2, 0, 'b', 'b.example'),
            $this->row(3, 0, 'c', 'c.example'),
            $this->row(4, 0, 'd', 'd.example'),
        ];

        ispconfigconnect_UsageUpdate(
            $this->params(),
            $this->rows($rows),
            $this->usageWriter(),
            $this->resolver([
                // "matched" with no id at all.
                1 => ['reason' => 'matched', 'evidence' => 'looked right'],
                // "matched" with a non-positive id: ISPConfig answers a falsy
                // client id with somebody else's numbers rather than an error.
                2 => ['reason' => 'matched', 'clientid' => 0, 'evidence' => 'zero'],
                // A reason nobody defined.
                3 => ['reason' => 'probably', 'clientid' => 5, 'evidence' => 'hunch'],
                // Not even an array.
                4 => 'yes',
            ]),
            $this->propertyWriter()
        );

        self::assertSame([], $this->properties, 'only an explicit, well-formed match may be written');
    }

    public function testAPanelThatCannotBeReadLeavesEveryServiceUnlinked(): void
    {
        ispconfigconnect_UsageUpdate(
            $this->params(),
            $this->rows([$this->row(1176, 0, 'legacy', 'globex.example')]),
            $this->usageWriter(),
            $this->resolver([1176 => [
                'reason' => 'unreadable',
                'evidence' => 'the panel refused the web domain listing',
            ]]),
            $this->propertyWriter()
        );

        self::assertSame([], $this->properties);
    }

    // ---- rule 4: idempotent, never overwrites ----------------------------

    public function testAnAlreadyLinkedServiceIsNeverEvenOfferedToTheResolver(): void
    {
        $clientId = $this->fake->addClient('noiz');
        $this->fake->addWebsite($clientId, 'noiz.co.za');

        ispconfigconnect_UsageUpdate(
            $this->params(),
            $this->rows([$this->row(101, $clientId, 'noiz', 'noiz.co.za')]),
            $this->usageWriter(),
            $this->resolver([101 => self::matched(999, 'somebodyelse', 'should never be consulted')]),
            $this->propertyWriter()
        );

        // Rule 4: a stored id is never re-decided, so the resolver is not even
        // asked about it - the cheapest possible guarantee that it cannot be
        // overwritten.
        self::assertSame([], $this->resolverCalls);
        self::assertSame([], $this->properties);
        self::assertArrayHasKey(101, $this->writes);
    }

    public function testOnlyTheUnlinkedServicesGoToTheResolverAndTheyGoInOneBatch(): void
    {
        $linked = $this->fake->addClient('noiz');
        $this->fake->addWebsite($linked, 'noiz.co.za');
        $acme = $this->fake->addClient('acme');
        $this->fake->addWebsite($acme, 'acme.example');

        ispconfigconnect_UsageUpdate(
            $this->params(),
            $this->rows([
                $this->row(101, $linked, 'noiz', 'noiz.co.za'),
                $this->row(102, 0, 'oldpleskname', 'acme.example'),
                $this->row(103, 0, 'anotherold', 'nowhere.example'),
            ]),
            $this->usageWriter(),
            $this->resolver([102 => self::matched($acme, 'acme', 'domain acme.example -> client #' . $acme)]),
            $this->propertyWriter()
        );

        // ONE batch call for the whole sweep - the panel is read once, not
        // once per service.
        self::assertCount(1, $this->resolverCalls);
        self::assertSame([102, 103], array_column($this->resolverCalls[0], 'serviceid'));
        // And the batch carries the domain, which is the key that actually
        // matches - the username is Plesk-era on a migrated service.
        self::assertSame('acme.example', $this->resolverCalls[0][0]['domain']);
    }

    // ---- rule 3: every link is logged with its evidence -------------------

    public function testEveryAutomaticLinkIsLoggedWithTheEvidenceThatJustifiedIt(): void
    {
        $clientId = $this->fake->addClient('acme');
        $this->fake->addWebsite($clientId, 'initech.example');

        ispconfigconnect_UsageUpdate(
            $this->params(),
            $this->rows([$this->row(1176, 0, 'oldname', 'initech.example')]),
            $this->usageWriter(),
            $this->resolver([1176 => self::matched(
                $clientId,
                'acme',
                'service domain initech.example matched ISPConfig client #' . $clientId
                    . ' (acme) - sole owner of that web domain'
            )]),
            $this->propertyWriter()
        );

        $summary = self::lastLog('UsageUpdate');
        $adopted = $summary['response']['adopted'];
        self::assertIsArray($adopted);
        $evidence = (string) $adopted[1176];
        self::assertStringContainsString('initech.example', $evidence);
        self::assertStringContainsString('sole owner', $evidence);
        self::assertStringContainsString((string) $clientId, $evidence);
    }

    public function testAFailedPropertyWriteLeavesTheServiceUnlinkedAndSaysWhy(): void
    {
        $clientId = $this->fake->addClient('acme');
        $this->fake->addWebsite($clientId, 'acme.example');

        ispconfigconnect_UsageUpdate(
            $this->params(),
            $this->rows([$this->row(1176, 0, 'oldname', 'acme.example')]),
            $this->usageWriter(),
            $this->resolver([1176 => self::matched($clientId, 'acme', 'domain acme.example -> #' . $clientId)]),
            static function (int $serviceId, array $values): void {
                throw new \RuntimeException('custom field row is missing for this product');
            }
        );

        $summary = self::lastLog('UsageUpdate');
        self::assertArrayNotHasKey(1176, $summary['response']['adopted'] ?? []);
        self::assertStringContainsString(
            'custom field row is missing',
            (string) ($summary['response']['unlinked'][1176] ?? '')
        );
    }

    public function testWithNoResolverInstalledTheSweepSaysSoInsteadOfFailing(): void
    {
        $clientId = $this->fake->addClient('noiz');
        $this->fake->addWebsite($clientId, 'noiz.co.za');

        // Third argument omitted entirely: exactly what happens on an install
        // where the resolver class is absent.
        ispconfigconnect_UsageUpdate(
            $this->params(),
            $this->rows([
                $this->row(101, $clientId, 'noiz', 'noiz.co.za'),
                $this->row(102, 0, 'legacy', 'globex.example'),
            ]),
            $this->usageWriter()
        );

        // The linked service is still billed - adoption being unavailable
        // must not cost the rest of the sweep.
        self::assertArrayHasKey(101, $this->writes);
        $summary = self::lastLog('UsageUpdate');
        self::assertStringContainsString(
            'unavailable',
            (string) ($summary['response']['adoption'] ?? '')
        );
    }

    // ---- rule 2: destructive actions never adopt -------------------------

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function destructiveOperations(): array
    {
        return [
            // key => [key, the operation label that must open the refusal]
            'terminate' => ['terminate', 'Terminate'],
            'suspend' => ['suspend', 'Suspend'],
            'unsuspend' => ['unsuspend', 'Unsuspend'],
            'change password' => ['change password', 'Change Password'],
            'change package' => ['change package', 'Change Package'],
        ];
    }

    /** @param array<string, mixed> $params */
    private static function runDestructive(string $key, array $params): string
    {
        return match ($key) {
            'terminate' => ispconfigconnect_TerminateAccount($params),
            'suspend' => ispconfigconnect_SuspendAccount($params),
            'unsuspend' => ispconfigconnect_UnsuspendAccount($params),
            'change password' => ispconfigconnect_ChangePassword($params),
            'change package' => ispconfigconnect_ChangePackage($params),
            default => self::fail('unknown destructive operation ' . $key),
        };
    }

    /**
     * Rule 2. Every one of these writes to, or deletes, panel state. An
     * unlinked service must be REFUSED, never resolved-then-acted-on: a
     * resolver mistake inside TerminateAccount deletes a live customer's
     * hosting, and no amount of match confidence makes that recoverable.
     */
    #[DataProvider('destructiveOperations')]
    public function testADestructiveOperationRefusesAnUnlinkedServiceInsteadOfAdoptingIt(
        string $key,
        string $label
    ): void {
        // A panel account that WOULD match, to prove the refusal is a policy
        // and not merely the absence of a candidate.
        $clientId = $this->fake->addClient('globex');
        $this->fake->addWebsite($clientId, 'globex.example');

        $mark = $this->wireMark();
        $result = self::runDestructive($key, $this->params(['domain' => 'globex.example', 'username' => 'legacyuser']));

        self::assertStringContainsString($label . ' ', $result);
        self::assertNotSame('success', $result);
        // Nothing was written to the panel, and nothing was stored.
        self::assertSame([], $this->methodsSince($mark));
        self::assertNull($this->model->serviceProperties->get('ispconfig_client_id'));
    }

    /**
     * The refusal has to be actionable. "No stored ispconfig_client_id" tells
     * an admin what the module lacks, not what to do about it.
     */
    #[DataProvider('destructiveOperations')]
    public function testADestructiveRefusalTellsTheAdminExactlyWhatToDo(string $key, string $label): void
    {
        $result = self::runDestructive($key, $this->params(['domain' => 'globex.example']));

        // Opens by naming the operation that refused (WHMCS renders this as a
        // Module Command Error with no other context).
        self::assertStringStartsWith($label . ' ', $result);
        // Names the service's domain, so the admin can confirm the key that
        // automatic adoption will use.
        self::assertStringContainsString('globex.example', $result);
        // Names both routes out: the manual button, and the automatic sweep.
        self::assertStringContainsString('Link to ISPConfig Account', $result);
        // And states plainly that it refused rather than guessed.
        self::assertMatchesRegularExpression('/not linked|no linked|unlinked/i', $result);
    }

    public function testTerminateRefusesAStaleLinkRatherThanDeletingWhateverHoldsThatIdNow(): void
    {
        // Rule 5, at its sharpest: the stored id no longer resolves. The
        // module must not delete on it and must not re-point it.
        $this->model->serviceProperties->save([
            'ispconfig_client_id' => '9999',
            'ispconfig_client_username' => 'gone',
        ]);
        $survivor = $this->fake->addClient('globex');
        $this->fake->addWebsite($survivor, 'globex.example');

        $result = ispconfigconnect_TerminateAccount($this->params(['domain' => 'globex.example']));

        self::assertNotSame('success', $result);
        self::assertStringContainsString('9999', $result);
        self::assertMatchesRegularExpression('/stale|no longer exists/i', $result);
        // Never deleted anything, and never re-pointed the stored id.
        self::assertSame([], array_filter(
            $this->calledMethods(),
            static fn (string $m): bool => str_contains($m, 'delete')
        ));
        self::assertSame('9999', $this->model->serviceProperties->get('ispconfig_client_id'));
    }

    // ---- the manual override ---------------------------------------------

    public function testTheAdminButtonsExposeLinkReplaceAndUnlink(): void
    {
        $buttons = ispconfigconnect_AdminCustomButtonArray();

        self::assertSame('VerifyPermissions', $buttons['Verify Permissions']);
        self::assertSame('LinkAccount', $buttons['Link to ISPConfig Account']);
        self::assertSame('RelinkAccount', $buttons['Replace ISPConfig Link']);
        self::assertSame('UnlinkAccount', $buttons['Unlink from ISPConfig']);
    }

    public function testLinkAccountLinksThisServiceToTheNamedPanelAccount(): void
    {
        $clientId = $this->fake->addClient('paigemurray');
        $this->fake->addWebsite($clientId, 'umbrella.example');

        $result = ispconfigconnect_LinkAccount($this->params([
            'username' => 'paigemurray',
            'domain' => 'umbrella.example',
        ]));

        self::assertSame('success', $result);
        self::assertSame((string) $clientId, $this->model->serviceProperties->get('ispconfig_client_id'));
        self::assertSame('paigemurray', $this->model->serviceProperties->get('ispconfig_client_username'));
    }

    public function testLinkAccountVerifiesTheAccountExistsBeforeWritingAnything(): void
    {
        // Nothing named this on the panel. The old workaround - typing an
        // internal id into a custom field - had no verification at all.
        $result = ispconfigconnect_LinkAccount($this->params([
            'username' => 'legacyuser',
            'domain' => 'globex.example',
        ]));

        self::assertNotSame('success', $result);
        self::assertNull($this->model->serviceProperties->get('ispconfig_client_id'));

        // The failure text must survive the migration case that produces it:
        // a Plesk-era WHMCS username searched against ISPConfig.
        self::assertStringContainsString('legacyuser', $result);
        self::assertStringContainsString('globex.example', $result);
        // Route 1: correct the Username field.
        self::assertStringContainsString('Username', $result);
        // This build ships no adoption resolver, so the message must NOT promise
        // a nightly sweep that will never run - it says manual linking by name
        // is the only route.
        self::assertDoesNotMatchRegularExpression('/nightly|usage sweep/i', $result);
        self::assertStringContainsString('only route', $result);
    }

    public function testLinkAccountRefusesToOverwriteADifferentStoredId(): void
    {
        $this->model->serviceProperties->save([
            'ispconfig_client_id' => '4',
            'ispconfig_client_username' => 'paigemurray',
        ]);
        $other = $this->fake->addClient('acme');

        $result = ispconfigconnect_LinkAccount($this->params(['username' => 'acme']));

        self::assertNotSame('success', $result);
        // Names both ids, so the admin can see what would have changed...
        self::assertStringContainsString('4', $result);
        self::assertStringContainsString((string) $other, $result);
        // ...and names the explicit way to do it on purpose.
        self::assertStringContainsString('Replace ISPConfig Link', $result);
        self::assertSame('4', $this->model->serviceProperties->get('ispconfig_client_id'));
    }

    public function testLinkAccountIsIdempotentWhenTheSameAccountIsAlreadyStored(): void
    {
        $clientId = $this->fake->addClient('noiz');
        $this->model->serviceProperties->save([
            'ispconfig_client_id' => (string) $clientId,
            'ispconfig_client_username' => 'noiz',
        ]);

        self::assertSame('success', ispconfigconnect_LinkAccount($this->params(['username' => 'noiz'])));
        self::assertSame((string) $clientId, $this->model->serviceProperties->get('ispconfig_client_id'));
    }

    public function testLinkAccountRefusesAnEmptyUsernameInsteadOfGuessing(): void
    {
        $result = ispconfigconnect_LinkAccount($this->params(['username' => '   ']));

        self::assertNotSame('success', $result);
        self::assertStringContainsString('Username', $result);
        self::assertSame([], $this->calledMethods(), 'an empty name must not reach the panel');
    }

    public function testTheManualLinkIsLoggedWithItsEvidence(): void
    {
        $clientId = $this->fake->addClient('paigemurray');

        ispconfigconnect_LinkAccount($this->params([
            'username' => 'paigemurray',
            'domain' => 'umbrella.example',
        ]));

        $entry = self::lastLog('LinkAccount');
        $evidence = print_r($entry['response'], true);
        self::assertStringContainsString('paigemurray', $evidence);
        self::assertStringContainsString((string) $clientId, $evidence);
    }

    public function testLinkAccountNeverWritesToThePanel(): void
    {
        $this->fake->addClient('paigemurray');

        $mark = $this->wireMark();
        ispconfigconnect_LinkAccount($this->params(['username' => 'paigemurray']));

        foreach ($this->methodsSince($mark) as $method) {
            self::assertDoesNotMatchRegularExpression(
                '/_(add|update|delete|set_status|change_password)$/',
                $method,
                'the manual link may only READ the panel'
            );
        }
    }

    public function testReplaceLinkOverwritesAStoredIdAndRecordsBoth(): void
    {
        $this->model->serviceProperties->save([
            'ispconfig_client_id' => '4',
            'ispconfig_client_username' => 'paigemurray',
        ]);
        $correct = $this->fake->addClient('globex');

        $result = ispconfigconnect_RelinkAccount($this->params(['username' => 'globex']));

        self::assertSame('success', $result);
        self::assertSame((string) $correct, $this->model->serviceProperties->get('ispconfig_client_id'));
        self::assertSame('globex', $this->model->serviceProperties->get('ispconfig_client_username'));

        // The old id has to be recoverable from the log - it is the only
        // record that the service ever pointed anywhere else.
        $logged = print_r(self::lastLog('RelinkAccount')['response'], true);
        self::assertStringContainsString('4', $logged);
        self::assertStringContainsString((string) $correct, $logged);
    }

    public function testReplaceLinkStillVerifiesTheNewAccountExists(): void
    {
        $this->model->serviceProperties->save(['ispconfig_client_id' => '4']);

        $result = ispconfigconnect_RelinkAccount($this->params(['username' => 'nosuchaccount']));

        self::assertNotSame('success', $result);
        self::assertSame('4', $this->model->serviceProperties->get('ispconfig_client_id'), 'a failed replace must not clear');
    }

    public function testUnlinkClearsTheStoredIdsAndLeavesThePanelAlone(): void
    {
        $clientId = $this->fake->addClient('paigemurray');
        $domainId = $this->fake->addWebsite($clientId, 'umbrella.example');
        $this->model->serviceProperties->save([
            'ispconfig_client_id' => (string) $clientId,
            'ispconfig_client_username' => 'paigemurray',
            'ispconfig_domain_id' => (string) $domainId,
        ]);

        $mark = $this->wireMark();
        $result = ispconfigconnect_UnlinkAccount($this->params());

        self::assertSame('success', $result);
        // The WHMCS service-property API has no delete, so "cleared" is a
        // blank value - which Support\ServiceProperties reads back as "not
        // stored", exactly like a property that was never written.
        self::assertSame('', $this->model->serviceProperties->get('ispconfig_client_id'));
        self::assertSame('', $this->model->serviceProperties->get('ispconfig_domain_id'));
        // A mistake must be reversible WITHOUT touching the customer's
        // hosting: no session, no call, nothing. The absence of ANY wire
        // traffic is the proof that the panel account survives untouched.
        self::assertSame([], $this->methodsSince($mark));
    }

    public function testUnlinkIsLoggedWithWhatItCleared(): void
    {
        $this->model->serviceProperties->save([
            'ispconfig_client_id' => '4',
            'ispconfig_client_username' => 'paigemurray',
        ]);

        ispconfigconnect_UnlinkAccount($this->params());

        $logged = print_r(self::lastLog('UnlinkAccount')['response'], true);
        self::assertStringContainsString('4', $logged);
        self::assertStringContainsString('paigemurray', $logged);
    }

    // ---- the admin service tab reports the state -------------------------

    public function testTheAdminTabNamesTheAccountAServiceIsLinkedTo(): void
    {
        $clientId = $this->fake->addClient('globex');
        $this->fake->addWebsite($clientId, 'globex.example');
        $this->model->serviceProperties->save([
            'ispconfig_client_id' => (string) $clientId,
            'ispconfig_client_username' => 'globex',
        ]);

        $fields = ispconfigconnect_AdminServicesTabFields($this->params(['domain' => 'globex.example']));

        $link = $fields['ISPConfig Link'];
        self::assertStringContainsString('globex', $link);
        self::assertStringContainsString((string) $clientId, $link);
    }

    public function testTheAdminTabReportsAStaleLinkAndNeverRePointsIt(): void
    {
        // Rule 5. The panel client was deleted by hand; another client now
        // owns the service's domain and would match. The tab must say STALE
        // and leave the stored id exactly where it is.
        $this->model->serviceProperties->save([
            'ispconfig_client_id' => '9999',
            'ispconfig_client_username' => 'gone',
        ]);
        $survivor = $this->fake->addClient('globex');
        $this->fake->addWebsite($survivor, 'globex.example');

        $fields = ispconfigconnect_AdminServicesTabFields(
            $this->params(['domain' => 'globex.example']),
            $this->resolver([42 => self::matched($survivor, 'globex', 'domain globex.example -> #' . $survivor)])
        );

        self::assertMatchesRegularExpression('/stale/i', $fields['ISPConfig Link']);
        self::assertStringContainsString('9999', $fields['ISPConfig Link']);
        self::assertSame('9999', $this->model->serviceProperties->get('ispconfig_client_id'));
        self::assertSame([], $this->resolverCalls, 'a stale link must not be re-resolved');
    }

    public function testOpeningTheAdminTabAdoptsAnUnlinkedService(): void
    {
        // Reading a service's tab is non-destructive and already talks to the
        // panel about that service, so it is one of the moments adoption is
        // allowed to happen by itself.
        $clientId = $this->fake->addClient('acme');
        $this->fake->addWebsite($clientId, 'acme.example');

        $fields = ispconfigconnect_AdminServicesTabFields(
            $this->params(['domain' => 'acme.example', 'username' => 'oldpleskname']),
            $this->resolver([42 => self::matched(
                $clientId,
                'acme',
                'service domain acme.example matched ISPConfig client #' . $clientId . ' (acme)'
            )])
        );

        self::assertSame((string) $clientId, $this->model->serviceProperties->get('ispconfig_client_id'));
        self::assertStringContainsString('acme', $fields['ISPConfig Link']);

        $logged = print_r(self::lastLog('Adopt')['response'], true);
        self::assertStringContainsString('acme.example', $logged);
    }

    public function testTheAdminTabNamesTheCandidatesForAnAmbiguousService(): void
    {
        $fields = ispconfigconnect_AdminServicesTabFields(
            $this->params(['domain' => 'shared.example']),
            $this->resolver([42 => [
                'reason' => 'ambiguous',
                'evidence' => 'shared.example is owned by more than one ISPConfig client',
                'candidates' => ['#2 (acme)', '#3 (globex)'],
            ]])
        );

        self::assertNull($this->model->serviceProperties->get('ispconfig_client_id'));
        self::assertStringContainsString('acme', $fields['ISPConfig Link']);
        self::assertStringContainsString('globex', $fields['ISPConfig Link']);
    }

    public function testTheAdminTabExplainsAnUnmatchedServiceWithoutAnError(): void
    {
        $fields = ispconfigconnect_AdminServicesTabFields(
            $this->params(['domain' => 'nowhere.example']),
            $this->resolver([42 => [
                'reason' => 'no_match',
                'evidence' => 'no ISPConfig client owns the web domain nowhere.example',
            ]])
        );

        self::assertStringContainsString('nowhere.example', $fields['ISPConfig Link']);
        // Still renders everything else - the tab must never blank out.
        self::assertArrayHasKey('ISPConfig Panel', $fields);
    }

    public function testTheAdminTabStillRendersWhenThePanelIsUnreachable(): void
    {
        $this->fake->apiEnabled = false;

        $fields = ispconfigconnect_AdminServicesTabFields($this->params());

        self::assertArrayHasKey('ISPConfig Link', $fields);
        self::assertArrayHasKey('ISPConfig Panel', $fields);
    }

    /** @return array{action: string, request: mixed, response: mixed} */
    private static function lastLog(string $action): array
    {
        $entries = array_values(array_filter(
            ModuleCallLog::entries(),
            static fn (array $entry): bool => $entry['action'] === $action
        ));
        self::assertNotSame([], $entries, $action . ' must record a module-log entry');

        /** @var array{action: string, request: mixed, response: mixed} $last */
        $last = $entries[count($entries) - 1];

        return $last;
    }
}
