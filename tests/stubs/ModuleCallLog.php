<?php

declare(strict_types=1);

namespace Ispconfigconnect\Tests\Stubs;

/**
 * Captures logModuleCall() invocations so tests can assert both that logging
 * happens and that credential scrubbing (replaceVars) is applied the way the
 * real WHMCS logger would apply it: every replaceVar value is masked out of
 * the stored request and response.
 */
final class ModuleCallLog
{
    /** @var list<array{module: string, action: string, request: mixed, response: mixed, processed: mixed, replaceVars: array<int, string>}> */
    private static array $entries = [];

    /**
     * @param mixed $request
     * @param mixed $response
     * @param mixed $processed
     * @param array<int, string> $replaceVars
     */
    public static function record(
        string $module,
        string $action,
        $request,
        $response,
        $processed,
        array $replaceVars
    ): void {
        self::$entries[] = [
            'module' => $module,
            'action' => $action,
            'request' => $request,
            'response' => $response,
            'processed' => $processed,
            'replaceVars' => $replaceVars,
        ];
    }

    /** @return list<array{module: string, action: string, request: mixed, response: mixed, processed: mixed, replaceVars: array<int, string>}> */
    public static function entries(): array
    {
        return self::$entries;
    }

    public static function reset(): void
    {
        self::$entries = [];
    }

    /**
     * Mimics WHMCS masking: serialize the payload, then assert no replaceVar
     * value survives outside the replaceVars list itself.  Returns the list of
     * log entries in which $secret appears in request or response WITHOUT
     * being covered by that entry's replaceVars.
     *
     * @return list<int> offending entry indexes
     */
    public static function entriesLeaking(string $secret): array
    {
        $offenders = [];
        foreach (self::$entries as $i => $entry) {
            if (in_array($secret, $entry['replaceVars'], true)) {
                continue;
            }
            $haystack = print_r($entry['request'], true) . ' ' . print_r($entry['response'], true);
            if ($secret !== '' && str_contains($haystack, $secret)) {
                $offenders[] = $i;
            }
        }
        return $offenders;
    }
}
