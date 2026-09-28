<?php

declare(strict_types=1);

namespace Ispconfigconnect\Tests\Module;

/**
 * WHERE THE SERVICE STATUS COMES FROM.
 *
 * The client-area login button and the customer single sign-on path are both
 * gated on the service being Active. WHMCS hands a module the status as
 * $params['status'] - core's buildServiceParams() sets `$params["status"] =
 * $serviceData->domainStatus` and `$params["model"] = $serviceData` (decoded
 * WHMCS 8.12.1 WHMCS\Module\Server) - and never as 'domainstatus', which is the
 * tblhosting COLUMN name. A gate keyed on 'domainstatus' therefore never fired
 * in production: every suspended service kept its button.
 *
 * These tests drive both gates with the key WHMCS really sends, and with the
 * service model as the only carrier of the status, the way a caller that
 * builds $params without it would.
 */
final class ServiceStatusSourceTest extends ModuleTestCase
{
    /** @return \Closure(int): ?array<string, mixed> server 7 has the SSO add-on */
    private static function fleet(): \Closure
    {
        return static fn (int $serverId): ?array => $serverId === 7 ? ['sso_state' => 'installed'] : null;
    }

    /**
     * An Eloquent-shaped service model: attributes are not properties, they
     * come back through getAttribute() - which is what the real model's magic
     * __get/__isset resolve to.
     */
    private function eloquentModel(?string $status): object
    {
        return new class ($this->model->serviceProperties, $status) {
            public function __construct(
                public ServicePropertiesStoreDouble $serviceProperties,
                private readonly ?string $status,
            ) {
            }

            public function getAttribute(string $key): mixed
            {
                return $key === 'domainstatus' ? $this->status : null;
            }
        };
    }

    // ---- the key WHMCS actually sends --------------------------------------

    public function testTheStatusParameterWhmcsSendsGatesTheLoginButton(): void
    {
        self::assertSame('success', ispconfigconnect_CreateAccount($this->params()));

        $active = ispconfigconnect_ClientArea($this->params(['serverid' => 7, 'status' => 'Active']), self::fleet());
        $suspended = ispconfigconnect_ClientArea($this->params(['serverid' => 7, 'status' => 'Suspended']), self::fleet());

        self::assertTrue($active['templateVariables']['ssoAvailable']);
        self::assertFalse($suspended['templateVariables']['ssoAvailable']);
    }

    public function testTheStatusParameterWhmcsSendsGatesServiceSingleSignOn(): void
    {
        $this->fake->installCompanion();
        self::assertSame('success', ispconfigconnect_CreateAccount($this->params()));
        $mark = $this->wireMark();

        $result = ispconfigconnect_ServiceSingleSignOn($this->params(['status' => 'Suspended']));

        self::assertFalse($result['success']);
        self::assertArrayHasKey('errorMsg', $result);
        // Refused before the panel: no login, and above all no token minted.
        self::assertSame([], $this->methodsSince($mark));
    }

    // ---- the service model as the last resort ------------------------------

    public function testTheServiceModelIsReadWhenNoParameterCarriesTheStatus(): void
    {
        $this->fake->installCompanion();
        self::assertSame('success', ispconfigconnect_CreateAccount($this->params()));

        $suspended = $this->params(['serverid' => 7, 'model' => $this->eloquentModel('Suspended')]);
        self::assertFalse(ispconfigconnect_ClientArea($suspended, self::fleet())['templateVariables']['ssoAvailable']);
        self::assertFalse(ispconfigconnect_ServiceSingleSignOn($suspended)['success']);

        $active = $this->params(['serverid' => 7, 'model' => $this->eloquentModel('Active')]);
        self::assertTrue(ispconfigconnect_ClientArea($active, self::fleet())['templateVariables']['ssoAvailable']);
        self::assertTrue(ispconfigconnect_ServiceSingleSignOn($active)['success']);
    }

    public function testAModelExposingTheStatusAsAMagicPropertyIsReadToo(): void
    {
        self::assertSame('success', ispconfigconnect_CreateAccount($this->params()));
        $model = new class ($this->model->serviceProperties) {
            public function __construct(public ServicePropertiesStoreDouble $serviceProperties)
            {
            }

            public function __isset(string $name): bool
            {
                return $name === 'domainstatus';
            }

            public function __get(string $name): mixed
            {
                return $name === 'domainstatus' ? 'Suspended' : null;
            }
        };

        $output = ispconfigconnect_ClientArea($this->params(['serverid' => 7, 'model' => $model]), self::fleet());

        self::assertFalse($output['templateVariables']['ssoAvailable']);
    }

    public function testAModelWhoseAttributeReadThrowsCostsTheGateNotThePage(): void
    {
        self::assertSame('success', ispconfigconnect_CreateAccount($this->params()));
        // Eloquent with strict attribute access throws on a missing attribute.
        $model = new class ($this->model->serviceProperties) {
            public function __construct(public ServicePropertiesStoreDouble $serviceProperties)
            {
            }

            public function getAttribute(string $key): mixed
            {
                throw new \LogicException('The attribute [' . $key . '] does not exist');
            }
        };

        $output = ispconfigconnect_ClientArea($this->params(['serverid' => 7, 'model' => $model]), self::fleet());

        // Nothing says what the status is, so - exactly as before - the gate
        // is not applied; the point is that the page still renders.
        self::assertSame('templates/overview.tpl', $output['tabOverviewReplacementTemplate']);
        self::assertTrue($output['templateVariables']['ssoAvailable']);
    }

    // ---- precedence --------------------------------------------------------

    public function testTheStatusIsReadFromStatusThenDomainstatusThenTheModel(): void
    {
        $model = $this->eloquentModel('Fraud');

        self::assertSame('Active', ispconfigconnect_serviceStatusOf([
            'status' => ' Active ',
            'domainstatus' => 'Suspended',
            'model' => $model,
        ]));
        self::assertSame('Suspended', ispconfigconnect_serviceStatusOf([
            'status' => '',
            'domainstatus' => 'Suspended',
            'model' => $model,
        ]));
        self::assertSame('Fraud', ispconfigconnect_serviceStatusOf(['model' => $model]));
        // A model without the attribute, or no model at all: nothing is known.
        self::assertSame('', ispconfigconnect_serviceStatusOf(['model' => $this->model]));
        self::assertSame('', ispconfigconnect_serviceStatusOf([]));
    }

    public function testWithNoStatusAnywhereTheButtonIsStillOffered(): void
    {
        // Unchanged behaviour: an unknown status neither gates nor breaks.
        self::assertSame('success', ispconfigconnect_CreateAccount($this->params()));

        $output = ispconfigconnect_ClientArea($this->params(['serverid' => 7]), self::fleet());

        self::assertTrue($output['templateVariables']['ssoAvailable']);
    }
}
