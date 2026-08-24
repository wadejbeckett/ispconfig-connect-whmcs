<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Ispconfigconnect\Api\Exception;

/**
 * The panel answered with the plain-text (non-JSON) body
 * "Remote API is disabled in security settings." - remote/json.php dies with
 * that exact string before the JSON handler runs when
 * remote_api_allowed != 'yes'  (verified: interface/web/remote/json.php,
 * ISPConfig 3.3.1p1).
 */
class ApiDisabledException extends IspconfigException
{
    public const SENTINEL = 'Remote API is disabled in security settings.';
}
