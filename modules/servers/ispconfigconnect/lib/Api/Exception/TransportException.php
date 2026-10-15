<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Ispconfigconnect\Api\Exception;

/**
 * Network-level failure: DNS, connect timeout, TLS handshake/verification,
 * or a non-success HTTP status from the panel.  The request may or may not
 * have reached ISPConfig - callers must treat the outcome as unknown.
 */
class TransportException extends IspconfigException
{
}
