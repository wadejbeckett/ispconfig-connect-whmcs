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
     * Logging::wire() plus a defensive pass that adds any *password-keyed
     * value (at any depth, in request AND response) to the sensitive list
     * before it reaches logModuleCall's replaceVars. RestClient only flags
     * the request key names it knows (password, new_password,
     * remote_password, session_id - spec 2.2); ISPConfig traffic also
     * carries secrets elsewhere - sites_database_user_add's
     * database_password param, and client_get responses echo the stored
     * password column - and an unmasked module-log entry is a credential
     * leak.
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
            $walk = static function (array $node) use (&$walk, &$sensitiveValues): void {
                foreach ($node as $key => $value) {
                    if (is_array($value)) {
                        $walk($value);
                    } elseif (
                        is_string($value)
                        && $value !== ''
                        && is_string($key)
                        && str_contains(strtolower($key), 'password')
                    ) {
                        $sensitiveValues[] = $value;
                    }
                }
            };
            $walk($request);
            if (is_array($response)) {
                $walk($response);
            }

            $inner($action, $request, $response, array_values(array_unique($sensitiveValues)));
        };
    }
}
