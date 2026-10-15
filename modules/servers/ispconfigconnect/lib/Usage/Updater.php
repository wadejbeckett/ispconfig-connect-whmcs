<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Ispconfigconnect\Usage;

use WHMCS\Module\Server\Ispconfigconnect\Api\Exception\IspconfigException;
use WHMCS\Module\Server\Ispconfigconnect\Api\Exception\LockoutException;
use WHMCS\Module\Server\Ispconfigconnect\Api\SessionManager;

/**
 * The data half of ispconfigconnect_UsageUpdate: turns the module's
 * provisioned services into the disk and bandwidth figures WHMCS stores in
 * tblhosting (developers.whmcs.com/provisioning-modules/usage-update/).
 *
 * This class does no database work and calls nothing in WHMCS - the entry file
 * owns the tblhosting query and the Capsule update, so that the lib stays
 * extractable and testable without a WHMCS install.
 *
 * Error policy: one unreadable service must not cost the other 499 their
 * nightly import, so per-service failures are collected into the report. The
 * one exception is LockoutException - the panel has locked this IP out for
 * five minutes, SessionManager's circuit is already open, and every further
 * service would only manufacture an identical failure while extending the
 * lockout window (remoting.inc.php counts dead-session lookups as failures).
 */
final class Updater
{
    private readonly Collector $collector;

    public function __construct(SessionManager $session, ?Collector $collector = null)
    {
        $this->collector = $collector ?? new Collector($session);
    }

    /**
     * @param list<ServiceRef> $services
     * @throws LockoutException when the panel locks this IP out mid-sweep
     */
    public function collect(array $services): UsageReport
    {
        $usage = [];
        $failures = [];

        foreach ($services as $service) {
            if ($service->clientId <= 0) {
                // No ispconfig_client_id property: the service was never
                // provisioned by this module (imported, or created before it
                // was installed). Not an error worth a network call.
                $failures[$service->serviceId] = 'no ISPConfig client id stored for this service';

                continue;
            }

            try {
                $usage[] = ServiceUsage::fromClientUsage(
                    $service->serviceId,
                    $this->collector->usageFor($service->clientId)
                );
            } catch (LockoutException $lockout) {
                throw $lockout;
            } catch (IspconfigException $failure) {
                $failures[$service->serviceId] = $failure->getMessage();
            }
        }

        return new UsageReport($usage, $failures);
    }
}
