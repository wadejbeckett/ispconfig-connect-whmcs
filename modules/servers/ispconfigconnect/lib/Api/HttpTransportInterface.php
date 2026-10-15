<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Ispconfigconnect\Api;

use WHMCS\Module\Server\Ispconfigconnect\Api\Exception\TransportException;

/**
 * Wire-level transport to ISPConfig's remote/json.php.
 *
 * The verified wire contract (ISPConfig 3.3.1p1, interface/web/remote/json.php
 * + interface/lib/classes/json_handler.inc.php):
 *   - POST to  <base>/remote/json.php?<method>
 *   - the method name MUST be the FIRST key of the query string
 *   - the request body is a JSON object whose keys are the PHP parameter
 *     NAMES of the remote function (reflection-matched, order-independent;
 *     missing params arrive as null)
 *   - the response body is the JSON envelope, or a plain-text error when the
 *     API is disabled
 *
 * Implementations do transport only - no envelope parsing, no retries.
 */
interface HttpTransportInterface
{
    /**
     * @param string $method   remote API method name (becomes the sole query-string key)
     * @param string $jsonBody already-encoded JSON request body
     * @return string raw response body
     * @throws TransportException on network/TLS failure or non-2xx HTTP status
     */
    public function post(string $method, string $jsonBody): string;
}
