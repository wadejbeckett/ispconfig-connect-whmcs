<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Ispconfigconnect\Api\Exception;

/**
 * The session exists but was created from a different source IP (sessions are
 * IP-bound; verified: remoting.inc.php getSession()).  A fresh login from the
 * current IP resolves it, but a NAT pool rotating addresses mid-batch will
 * hit this repeatedly - the docs call that constraint out.
 */
class SessionIpMismatchException extends SessionExpiredException
{
}
