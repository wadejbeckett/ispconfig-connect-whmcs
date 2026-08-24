<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Ispconfigconnect\Api\Exception;

/**
 * The remote user's remote_functions grant list does not include the called
 * function.  The fix is configuration, not retry: grant the function names
 * documented by Api\Permissions to the remote user.
 */
class PermissionDeniedException extends RemoteFaultException
{
}
