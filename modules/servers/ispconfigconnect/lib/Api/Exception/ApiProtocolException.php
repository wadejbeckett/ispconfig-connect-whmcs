<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Ispconfigconnect\Api\Exception;

/**
 * The envelope carried a non-ok, non-remote_fault code.  These come from the
 * JSON handler itself (verified: interface/lib/classes/json_handler.inc.php):
 *   - invalid_method : no method in query string / unknown method name
 *   - invalid_data   : request body was not valid JSON
 *   - invalid_class  : server-side registration problem
 * They indicate a programming or version-skew problem, never a data problem.
 */
class ApiProtocolException extends IspconfigException
{
    public function __construct(
        public readonly string $apiCode,
        string $message,
    ) {
        parent::__construct($message);
    }
}
