<?php

/**
 * Stand-ins for the WHMCS core classes the entry file references.
 *
 * WHMCS core is ionCube-encoded and absent from the test runtime, so these
 * declare the same public shapes the developer documentation shows - nothing
 * more. They are the class-level counterpart of tests/stubs/whmcs-functions.php
 * and are never shipped.
 *
 * Fidelity notes (all from developers.whmcs.com, which is the only readable
 * source for these types):
 * - ProviderInterface's three methods are declared WITHOUT parameter or return
 *   types, exactly as the documented sample implements them
 *   (provisioning-modules/usage-metrics/). Adding a parameter type here would
 *   let the module's adapter declare one too, and it would then be
 *   incompatible with the real, encoded interface.
 * - Metric::withUsage() returns a NEW instance in the sample
 *   (`$metric = $metric->withUsage(...)`), so it is modelled as immutable.
 * - WHMCS\Service\Status::ACTIVE / ::SUSPENDED carry the tblhosting
 *   domainstatus vocabulary ('Active' / 'Suspended'), which is what the
 *   Server Sync sample returns.
 */

declare(strict_types=1);

namespace WHMCS\UsageBilling\Contracts\Metrics {
    interface MetricInterface
    {
        public const TYPE_SNAPSHOT = 'snapshot';
        public const TYPE_PERIOD_DAY = 'period_day';
        public const TYPE_PERIOD_MONTH = 'period_month';
    }

    interface ProviderInterface
    {
        /** @return list<\WHMCS\UsageBilling\Metrics\Metric> */
        public function metrics();

        /** @return array<string, list<\WHMCS\UsageBilling\Metrics\Metric>> */
        public function usage();

        /**
         * @param mixed $tenant
         * @return list<\WHMCS\UsageBilling\Metrics\Metric>
         */
        public function tenantUsage($tenant);
    }
}

namespace WHMCS\UsageBilling\Metrics {
    class Usage
    {
        public function __construct(private readonly int|float $value)
        {
        }

        public function value(): int|float
        {
            return $this->value;
        }
    }

    class Metric
    {
        private ?Usage $usage = null;

        public function __construct(
            private readonly string $systemName,
            private readonly string $displayName,
            private readonly string $type,
            private readonly Units\Unit $units,
        ) {
        }

        public function systemName(): string
        {
            return $this->systemName;
        }

        public function displayName(): string
        {
            return $this->displayName;
        }

        public function type(): string
        {
            return $this->type;
        }

        public function units(): Units\Unit
        {
            return $this->units;
        }

        public function usage(): ?Usage
        {
            return $this->usage;
        }

        public function withUsage(Usage $usage): self
        {
            $copy = clone $this;
            $copy->usage = $usage;

            return $copy;
        }
    }
}

namespace WHMCS\UsageBilling\Metrics\Units {
    abstract class Unit
    {
        public function __construct(private readonly string $label = '')
        {
        }

        public function label(): string
        {
            return $this->label;
        }
    }

    class Bytes extends Unit
    {
    }

    class MegaBytes extends Unit
    {
    }

    class GigaBytes extends Unit
    {
    }

    class Accounts extends Unit
    {
    }

    class Domains extends Unit
    {
    }
}

namespace WHMCS\Service {
    class Status
    {
        public const ACTIVE = 'Active';
        public const SUSPENDED = 'Suspended';
    }
}
