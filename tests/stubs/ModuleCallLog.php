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
     * The stricter sibling of entriesLeaking(): instead of accepting an entry
     * because the secret is listed verbatim in replaceVars, it APPLIES the
     * masking the way logModuleCall does - every replaceVars value is blanked
     * out of the stored text - and then looks for the secret in what is left.
     *
     * This catches two things entriesLeaking() cannot:
     *   - a secret that only survives as a SUBSTRING of a larger stored value
     *     (the panel login hash 'crypt:<clear>' embeds the clear password:
     *     masking the hash removes it, listing the clear alone would not);
     *   - a secret that was scrubbed out of the payload entirely rather than
     *     masked by value - the right answer for personal and financial
     *     columns, which are too short and too low-entropy to be safe
     *     str_replace needles.
     *
     * @return list<int> offending entry indexes
     */
    public static function entriesLeakingAfterMasking(string $secret): array
    {
        $offenders = [];
        foreach (self::$entries as $i => $entry) {
            $masks = array_values(array_filter(
                $entry['replaceVars'],
                static fn (string $value): bool => $value !== ''
            ));
            // Longest first, so a value containing another is masked whole.
            usort($masks, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

            $haystack = str_replace(
                $masks,
                '***',
                print_r($entry['request'], true) . ' ' . print_r($entry['response'], true)
            );
            if ($secret !== '' && str_contains($haystack, $secret)) {
                $offenders[] = $i;
            }
        }

        return $offenders;
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
