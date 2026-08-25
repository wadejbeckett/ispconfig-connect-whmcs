<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Ispconfigconnect\Sso\Exception;

/**
 * The panel does not provide the SSO remote function: it is not registered, so
 * the JSON handler answered with its invalid_method envelope (verified:
 * interface/lib/classes/json_handler.inc.php run()).
 *
 * This is the NORMAL state of a stock ISPConfig, not a defect. Core has NO
 * one-time-login API of its own; the separate ispconfig-sso project (an
 * optional GPLv3 panel add-on this module neither ships nor requires) supplies
 * one through the documented remote.d auto-load point. Without it the module
 * still works - it shows the panel URL and username instead of a login button.
 */
final class CompanionNotInstalledException extends SsoException
{
}
