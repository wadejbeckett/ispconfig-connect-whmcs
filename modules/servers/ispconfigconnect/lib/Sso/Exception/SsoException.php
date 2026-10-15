<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Ispconfigconnect\Sso\Exception;

use WHMCS\Module\Server\Ispconfigconnect\Api\Exception\IspconfigException;

/**
 * Single sign-on is not AVAILABLE on this panel.
 *
 * Every subclass means the same thing to a caller building a UI: do not offer
 * an SSO button, because pressing it could not work. That is deliberately
 * narrower than "the SSO call failed" - a panel that refuses a specific client
 * (unknown client id, locked account) raises the API layer's own
 * RemoteFaultException instead, because the operator needs to see why.
 */
abstract class SsoException extends IspconfigException
{
}
