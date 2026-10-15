<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Ispconfigconnect\Support;

/**
 * The one place lib code touches a WHMCS API: wires the RestClient logger
 * callback (spec 2.2) to WHMCS's logModuleCall().
 *
 * Division of responsibility (spec 2.2/2.8): the callback receives request and
 * response with credentials INTACT plus the list of sensitive values the
 * RestClient knows about (password, session_id, remote_password, ...). It
 * forwards them as logModuleCall's replaceVars, because masking-by-value is
 * WHMCS's own convention — the logger masks every replaceVars occurrence in
 * the stored request/response.
 */
final class Logging
{
    private function __construct()
    {
    }

    /**
     * @return \Closure(string, array<string, mixed>, mixed, list<string>): void
     *     logger callable for the RestClient constructor
     */
    public static function wire(string $module): \Closure
    {
        return static function (
            string $action,
            array $request,
            mixed $response,
            array $sensitiveValues = []
        ) use ($module): void {
            // '' as a replaceVar would corrupt WHMCS's str_replace-based
            // masking; duplicates are dropped for log readability only.
            $replaceVars = array_values(array_unique(array_filter(
                $sensitiveValues,
                static fn (mixed $value): bool => is_string($value) && $value !== ''
            )));

            \logModuleCall($module, $action, $request, $response, '', $replaceVars);
        };
    }
}
