<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Ispconfigconnect\Usage;

use WHMCS\Module\Server\Ispconfigconnect\Api\Exception\IspconfigException;

/**
 * A usage read completed but its result cannot be attributed to the client it
 * was requested for. Distinct from the Api\Exception family: nothing went
 * wrong on the wire - the ANSWER is unusable.
 */
final class UsageException extends IspconfigException
{
}
