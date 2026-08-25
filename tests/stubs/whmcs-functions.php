<?php

/**
 * Test doubles for WHMCS runtime functions that the module entry file calls.
 * Loaded from tests/bootstrap.php and from phpstan bootstrapFiles.
 * Never shipped; the real functions exist inside WHMCS.
 */

declare(strict_types=1);

require_once __DIR__ . '/ModuleCallLog.php';
require_once __DIR__ . '/HookRegistry.php';

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

if (!function_exists('add_hook')) {
    /**
     * WHMCS's hook registration (developers.whmcs.com/hooks/sample-hook):
     * add_hook($hookPoint, $priority, $function).
     *
     * @param callable|string $function the function name or an anonymous function
     */
    function add_hook(string $hookPoint, int $priority, $function): void
    {
        \Ispconfigconnect\Tests\Stubs\HookRegistry::register($hookPoint, $priority, $function);
    }
}
