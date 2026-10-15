<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Ispconfigconnect\Api\Exception;

/**
 * The panel's 10-failures-per-5-minutes IP lockout has engaged
 * (login_failure_limit / session_failure_limit).  Invalid or expired session
 * ids count as failures too (verified: remoting.inc.php getSession()).
 * Callers MUST stop calling for the remainder of the request - every further
 * attempt extends the lockout window.
 */
class LockoutException extends RemoteFaultException
{
}
