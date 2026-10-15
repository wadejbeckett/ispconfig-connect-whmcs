<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Ispconfigconnect\Api\Exception;

/**
 * The session id was rejected: expired (30-minute hard TTL, never extended
 * on use - verified: remoting.inc.php, tstamp is set once at login) or
 * unknown.  One fresh login + single retry is safe; repeated retries with a
 * dead session feed the IP lockout.
 */
class SessionExpiredException extends RemoteFaultException
{
}
