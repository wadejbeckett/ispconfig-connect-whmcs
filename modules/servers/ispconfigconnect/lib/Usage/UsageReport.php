<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Ispconfigconnect\Usage;

/**
 * The outcome of one UsageUpdate sweep: the services whose usage was read, and
 * the ones that could not be read with the reason why.
 *
 * A per-service failure is not fatal - a nightly sweep must import the 499
 * services it can read rather than abort on the one it cannot - so failures
 * are DATA here, for the entry file to log.
 */
final readonly class UsageReport
{
    /**
     * @param list<ServiceUsage>   $usage
     * @param array<int, string>   $failures WHMCS service id => reason
     */
    public function __construct(
        public array $usage = [],
        public array $failures = [],
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->usage === [];
    }
}
