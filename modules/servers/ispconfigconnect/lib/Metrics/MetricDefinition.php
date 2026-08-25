<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Ispconfigconnect\Metrics;

/**
 * One usage-billing metric, described in plain data.
 *
 * WHMCS wants \WHMCS\UsageBilling\Metrics\Metric instances, but lib code must
 * not reference WHMCS classes (they are ionCube-encoded and absent from the
 * test runtime), so the entry file does the final construction:
 *
 *   new Metric($d->systemName, $d->displayName, <TYPE const>, new <Unit>($d->unitLabel))
 *
 * $type is one of Provider::TYPE_*, $unit one of Provider::UNIT_*.
 */
final readonly class MetricDefinition
{
    public function __construct(
        public string $systemName,
        public string $displayName,
        public string $type,
        public string $unit,
        public string $unitLabel,
    ) {
    }
}
