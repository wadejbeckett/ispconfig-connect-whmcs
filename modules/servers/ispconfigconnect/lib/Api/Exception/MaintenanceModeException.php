<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Ispconfigconnect\Api\Exception;

/**
 * The panel is in maintenance mode; retry later.  Raised at login time
 * (verified: remoting.inc.php login()).
 */
class MaintenanceModeException extends RemoteFaultException
{
}
