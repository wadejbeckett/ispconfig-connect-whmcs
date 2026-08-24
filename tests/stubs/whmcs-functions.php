<?php

/**
 * Test doubles for WHMCS runtime functions that the module entry file calls.
 * Loaded from tests/bootstrap.php and from phpstan bootstrapFiles.
 * Never shipped; the real functions exist inside WHMCS.
 */

declare(strict_types=1);

require_once __DIR__ . '/ModuleCallLog.php';

if (!function_exists('logModuleCall')) {
    /**
     * @param mixed $request
     * @param mixed $response
     * @param mixed $processedData
     * @param array<int, string> $replaceVars values to mask in stored request/response
     */
    function logModuleCall(
        string $module,
        string $action,
        $request,
        $response,
        $processedData = '',
        array $replaceVars = []
    ): void {
        \Ispconfigconnect\Tests\Stubs\ModuleCallLog::record(
            $module,
            $action,
            $request,
            $response,
            $processedData,
            $replaceVars
        );
    }
}
