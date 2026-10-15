<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Ispconfigconnect\Api\Exception;

/**
 * Credentials rejected, user blocked, or client not allowed to use the API.
 * Retrying with the same credentials is pointless and feeds the panel's
 * 10-failures-per-5-minutes IP lockout - never auto-retry.
 */
class LoginFailedException extends RemoteFaultException
{
}
