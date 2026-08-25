<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Ispconfigconnect\Support;

use WHMCS\Module\Server\Ispconfigconnect\Api\CurlTransport;
use WHMCS\Module\Server\Ispconfigconnect\Api\HttpTransportInterface;
use WHMCS\Module\Server\Ispconfigconnect\Api\RestClient;
use WHMCS\Module\Server\Ispconfigconnect\Api\SessionManager;

/**
 * Builds the wired API stack the entry file uses for one WHMCS operation:
 * CurlTransport -> RestClient (logging via Support\Logging) -> SessionManager,
 * all derived from the raw WHMCS $params via Params::serverConfig().
 */
final class Factory
{
    public const MODULE_NAME = 'ispconfigconnect';

    /**
     * TEST-ONLY override of the transport layer. When set, it is invoked as
     * fn (ServerConfig $config): HttpTransportInterface and its return value
     * replaces the CurlTransport - the module tests use it to hand every
     * build the shared in-process FakeIspconfigServer. Production code MUST
     * never assign it; null (the default) means real curl.
     */
    public static ?\Closure $transportFactory = null;

    private function __construct()
    {
    }

    /**
     * @param array<string, mixed> $params raw WHMCS provisioning $params
     */
    public static function sessionManager(array $params): SessionManager
    {
        $config = Params::serverConfig($params);

        $transport = self::$transportFactory !== null
            ? (self::$transportFactory)($config)
            : new CurlTransport($config);
        if (!$transport instanceof HttpTransportInterface) {
            throw new \LogicException('Factory::$transportFactory must return an HttpTransportInterface');
        }

        return new SessionManager(
            new RestClient($transport, self::logger()),
            $config->username,
            $config->password
        );
    }

    /**
     * Logging::wire() plus a defensive second pass, immediately in front of
     * logModuleCall: re-run RestClient's scrub over request and response, and
     * re-collect the values that have to reach WHMCS's replaceVars.
     *
     * It runs over payloads RestClient has normally already cleaned, so in the
     * happy path it changes nothing - the point is that this is the LAST code
     * the payload passes through, and it catches anything that reaches the
     * logger by another route.
     *
     * It delegates to RestClient rather than re-deciding what a secret is.
     * The pass used to carry its own rule - "any key containing 'password'" -
     * and that divergence is exactly how the panel login hash leaked: ISPConfig
     * spells the sys_user column `passwort` (install/sql/ispconfig3.sql:1861),
     * which the substring never matched, while `lost_password_function` - a
     * 1/0 flag - is one it DID match, feeding "1" to logModuleCall as a
     * str_replace masking needle. One vocabulary, in one place, or the two
     * drift apart again.
     *
     * @return \Closure(string, array<string, mixed>, mixed, list<string>): void
     */
    private static function logger(): \Closure
    {
        $inner = Logging::wire(self::MODULE_NAME);

        return static function (
            string $action,
            array $request,
            mixed $response,
            array $sensitiveValues = []
        ) use ($inner): void {
            $sensitiveValues = array_merge($sensitiveValues, RestClient::maskableValues($request));
            if (is_array($response)) {
                $sensitiveValues = array_merge($sensitiveValues, RestClient::maskableValues($response));
            }

            $inner(
                $action,
                RestClient::scrubRequest($request),
                RestClient::scrubResponse($action, $response),
                array_values(array_unique($sensitiveValues))
            );
        };
    }
}
