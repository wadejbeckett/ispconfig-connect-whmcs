<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Ispconfigconnect\Api\Exception;

/**
 * The response body was not the expected JSON envelope
 * {"code": ..., "message": ..., "response": ...}.
 */
class MalformedResponseException extends IspconfigException
{
}
