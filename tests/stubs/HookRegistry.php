<?php

declare(strict_types=1);

namespace Ispconfigconnect\Tests\Stubs;

/**
 * Records what the module's hooks.php registers through WHMCS's global
 * add_hook(). WHMCS's real implementation stores the callable and invokes it
 * when the hook point fires; the tests only need to see WHAT was registered
 * and to be able to call it, so registration is all this keeps.
 */
final class HookRegistry
{
    /** @var array<string, list<array{priority: int, function: callable|string}>> */
    private static array $hooks = [];

    /** @param callable|string $function */
    public static function register(string $hookPoint, int $priority, $function): void
    {
        self::$hooks[$hookPoint][] = ['priority' => $priority, 'function' => $function];
    }

    /** @return array<string, list<array{priority: int, function: callable|string}>> */
    public static function all(): array
    {
        return self::$hooks;
    }

    /** @return list<array{priority: int, function: callable|string}> */
    public static function for(string $hookPoint): array
    {
        return self::$hooks[$hookPoint] ?? [];
    }

    public static function reset(): void
    {
        self::$hooks = [];
    }
}
