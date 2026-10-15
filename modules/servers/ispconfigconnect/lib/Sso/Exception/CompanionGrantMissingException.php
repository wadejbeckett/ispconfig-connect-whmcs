<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Ispconfigconnect\Sso\Exception;

/**
 * The SSO project is installed but the remote user may not call it: ISPConfig's
 * checkPerm() rejected the call because `connect_sso_token_create` is missing
 * from the remote user's remote_functions list.
 *
 * Configuration, not retry - the message names the grant to tick.
 */
final class CompanionGrantMissingException extends SsoException
{
}
