<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Ispconfigconnect\Api\Exception;

/**
 * "The login is not allowed from <ip>" - the remote user's IP allowlist does
 * not include this WHMCS server's address.  Configuration problem; the
 * message includes the address that must be allowlisted.
 */
class IpNotAllowedException extends LoginFailedException
{
}
