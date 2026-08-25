<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Ispconfigconnect\Sso\Exception;

/**
 * The panel has no SSO companion: the remote function is not registered, so
 * the JSON handler answered with its invalid_method envelope (verified:
 * interface/lib/classes/json_handler.inc.php run()).
 *
 * ISPConfig core has NO one-time-login API of its own - the companion in
 * companion/ispconfig-sso supplies it through the documented remote.d
 * auto-load point. Without it the module still works; it just shows the panel
 * URL and username instead of a login button.
 */
final class CompanionNotInstalledException extends SsoException
{
}
