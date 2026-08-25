<?php

declare(strict_types=1);

namespace Ispconfigconnect\Tests\Module;

use Ispconfigconnect\Tests\Stubs\HookRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

// The hook file is a WHMCS file guarded by `if (!defined('WHMCS')) die;`,
// exactly like the entry file - the constant must exist BEFORE the require.
if (!defined('WHMCS')) {
    define('WHMCS', true);
}

require_once __DIR__ . '/../../modules/servers/ispconfigconnect/hooks/ispconfigconnect.php';

/**
 * Covers modules/servers/ispconfigconnect/hooks/ispconfigconnect.php - the file
 * an operator installs as <whmcs>/includes/hooks/ispconfigconnect.php.
 *
 * ## Why this file is not modules/servers/ispconfigconnect/hooks.php any more
 *
 * LIVE-PROVEN on WHMCS 9.0.6: WHMCS does NOT load a server module's
 * `hooks.php`. A file-level marker written into the deployed copy never fired
 * across many admin page loads, the Servers page included. `includes/hooks/*.php`
 * is the loader that does run, on every admin and client page, so the panel now
 * ships as a file for that directory instead.
 *
 * That location sets the whole test contract below. The file is loaded on EVERY
 * page, so it must be inert everywhere except Configuration > Servers, must
 * spend at most one query when it does run, must never touch the ISPConfig API,
 * and must never let a Throwable escape into somebody else's page render.
 */
final class HooksTest extends TestCase
{
    /** A row as the servers page sees it once GetRemoteMetaData has run once. */
    private const META = '{"ispconfig_version":"3.3.1p1","pending_jobs":2,"client_count":9,'
        . '"website_count":14,"service_count":14,"server_count":1,"sso_available":true,'
        . '"ispconfig_status":"ok","checked_at":"2026-08-24 09:00:00 UTC"}';

    private const UPDATED_AT = '2026-08-24 09:00:12';

    public function testRegistersItselfOnTheDocumentedAdminFooterHook(): void
    {
        $registered = HookRegistry::for('AdminAreaFooterOutput');

        self::assertNotEmpty($registered, 'the hook file must register on AdminAreaFooterOutput');
        self::assertContains(
            'ispconfigconnect_metaPanelFooterOutput',
            array_column($registered, 'function')
        );
    }

    // ---- page gating -----------------------------------------------------

    public function testRendersTheStatsBlockOnTheServersPage(): void
    {
        $html = $this->render($this->vars('configservers'), [$this->row()]);

        self::assertStringContainsString(ISPCONFIGCONNECT_METAPANEL_ID, $html);
        foreach (
            ['ISPConfig Version', '3.3.1p1', 'Pending Jobs', 'Clients', 'ISPConfig Nodes',
                'Single Sign-On', 'Last Updated'] as $expected
        ) {
            self::assertStringContainsString($expected, $html, $expected . ' must be in the block');
        }
    }

    /**
     * Core already renders `service_count` as "Services: N" in the same row's
     * Remote Usage Stats cell (live-verified), and `website_count` is the same
     * number. Rendering either of them here would put one number on the page
     * twice under two names, which reads as two different facts.
     *
     * This is the field list docs/INSTALL.md documents and that
     * Server\MetaDataPanel renders; the two must not drift apart.
     */
    public function testTheBlockDoesNotRepeatTheWebsiteCountCoreAlreadyShows(): void
    {
        $lines = $this->linesFor($this->row());

        self::assertArrayNotHasKey('Websites', $lines);
        self::assertArrayNotHasKey('Services', $lines);
        self::assertSame(
            ['ISPConfig Version', 'Pending Jobs', 'Clients', 'ISPConfig Nodes', 'Single Sign-On', 'Last Updated'],
            array_keys($lines)
        );
    }

    public function testRendersNothingOnEveryOtherAdminPage(): void
    {
        foreach (['clientssummary', 'index', 'configproducts', 'systemmodulelog', 'configservers2'] as $filename) {
            $this->withRequestUri('/admin/' . $filename . '.php', function () use ($filename): void {
                self::assertSame(
                    '',
                    $this->render($this->vars($filename), [$this->row()]),
                    $filename . ' must not receive the servers-page block'
                );
            });
        }
    }

    /**
     * `filename` is an assumption about WHMCS internals that has never been
     * observed live - this module already lost a release to one of those. An
     * unexpected value must fall through to the URL, not veto it.
     */
    public function testAnUnexpectedFilenameDoesNotVetoTheRequestPath(): void
    {
        $this->withRequestUri('/admin/configservers.php', function (): void {
            foreach (['admin/configservers', 'configservers.php', 'Servers', ''] as $filename) {
                self::assertStringContainsString(
                    ISPCONFIGCONNECT_METAPANEL_ID,
                    $this->render($this->vars($filename), [$this->row()]),
                    'filename "' . $filename . '" must not suppress the block on configservers.php'
                );
            }
        });
    }

    /**
     * Gating must be settled before any query: the file runs on every admin
     * page load, and a query per page would be a tax on the whole install.
     */
    public function testAPageThatIsNotTheServersPageNeverReachesTheDatabase(): void
    {
        $touched = false;
        $this->withRequestUri('/admin/clientssummary.php?userid=1', function () use (&$touched): void {
            ispconfigconnect_metaPanelFooterOutput(
                $this->vars('clientssummary'),
                static function () use (&$touched): array {
                    $touched = true;

                    return [];
                }
            );
        });

        self::assertFalse($touched, 'the row provider must not be called off the servers page');
    }

    /**
     * `filename` is the documented AdminAreaFooterOutput variable, but the
     * request path is a cheap second opinion for builds that name it
     * differently - being wrong there would silently disable the panel.
     */
    public function testFallsBackToTheRequestPathWhenFilenameIsAbsent(): void
    {
        // A renamed admin directory is supported, so only the basename counts.
        $this->withRequestUri('/secret-admin/configservers.php?action=manage&id=3', function (): void {
            self::assertStringContainsString(
                ISPCONFIGCONNECT_METAPANEL_ID,
                $this->render([], [$this->row()])
            );
        });
    }

    // ---- content ---------------------------------------------------------

    public function testShowsTheCollectedPanelFactsWithTheirValues(): void
    {
        $lines = $this->linesFor($this->row());

        self::assertSame('3.3.1p1', $lines['ISPConfig Version'] ?? null);
        self::assertSame('2', $lines['Pending Jobs'] ?? null);
        self::assertSame('9', $lines['Clients'] ?? null);
        self::assertSame('1', $lines['ISPConfig Nodes'] ?? null);
    }

    /** @return array<string, array{mixed, string}> */
    public static function ssoFlags(): array
    {
        return [
            'boolean true' => [true, 'Available'],
            'boolean false' => [false, 'Not installed'],
            'json 1' => [1, 'Available'],
            'json 0' => [0, 'Not installed'],
            'string 1' => ['1', 'Available'],
            'string empty' => ['', 'Not installed'],
        ];
    }

    /**
     * sso_available survives a JSON round-trip through tblservers_remote, and
     * the driver may hand any of these back.
     */
    #[DataProvider('ssoFlags')]
    public function testSingleSignOnAvailabilityRendersBothStates(mixed $flag, string $expected): void
    {
        $lines = $this->linesFor($this->row(['sso_available' => $flag]));

        self::assertSame($expected, $lines['Single Sign-On'] ?? null);
    }

    /**
     * GetRemoteMetaData deliberately omits the key when the companion probe
     * itself failed. "Unknown" must not be rendered as "Not installed".
     */
    public function testSingleSignOnIsOmittedWhenTheAnswerIsUnknown(): void
    {
        self::assertArrayNotHasKey('Single Sign-On', $this->linesFor($this->row([], ['sso_available'])));
        self::assertArrayNotHasKey('Single Sign-On', $this->linesFor($this->row(['sso_available' => null])));

        // A non-scalar is not an answer either. Reporting "Not installed" for it
        // would state as fact something the stored value does not say.
        self::assertArrayNotHasKey('Single Sign-On', $this->linesFor($this->row(['sso_available' => ['x']])));
        self::assertArrayNotHasKey(
            'Single Sign-On',
            $this->linesFor($this->row(['sso_available' => ['a' => 'b']]))
        );
    }

    public function testLastUpdatedIsRelativeToNow(): void
    {
        $stamp = strtotime(self::UPDATED_AT);
        self::assertIsInt($stamp);

        $cases = [
            30 => 'just now',
            240 => '4 minutes ago',
            3600 => '1 hour ago',
            7200 => '2 hours ago',
            86400 => '1 day ago',
            259200 => '3 days ago',
        ];

        foreach ($cases as $elapsed => $expected) {
            $lines = $this->linesFor($this->row(), $stamp + $elapsed);
            self::assertSame($expected, $lines['Last Updated'] ?? null, $elapsed . 's elapsed');
        }
    }

    /**
     * tblservers_remote.updated_at carries no timezone, so a WHMCS whose MySQL
     * session zone differs from PHP's can hand back a stamp in the future.
     * Guessing "just now" there would be a lie; the absolute value is not.
     */
    public function testAStampInTheFutureFallsBackToTheAbsoluteValue(): void
    {
        $stamp = strtotime(self::UPDATED_AT);
        self::assertIsInt($stamp);

        $lines = $this->linesFor($this->row(), $stamp - 7200);

        self::assertSame(self::UPDATED_AT, $lines['Last Updated'] ?? null);
    }

    public function testAnUnparsableStampFallsBackToTheAbsoluteValue(): void
    {
        $lines = $this->linesFor($this->row([], [], 'not a date at all'));

        self::assertSame('not a date at all', $lines['Last Updated'] ?? null);
    }

    /**
     * A server WHMCS has never refreshed has no tblservers_remote row at all,
     * so updated_at is NULL - the module's own checked_at stamp is then the
     * only thing that can date the numbers.
     */
    public function testFallsBackToTheModulesOwnCheckedAtWhenTheColumnIsNull(): void
    {
        $stamp = strtotime('2026-08-24 09:00:00 UTC');
        self::assertIsInt($stamp);

        $lines = $this->linesFor($this->row([], [], null), $stamp + 120);

        self::assertSame('2 minutes ago', $lines['Last Updated'] ?? null);
    }

    /** A failed probe must never be silent - it replaces the numbers it could not read. */
    public function testAProbeErrorIsShownEvenWhenNoNumbersWereCollected(): void
    {
        $html = $this->render($this->vars('configservers'), [[
            'id' => 3,
            'name' => 'ISPConfig 1',
            'hostname' => 'panel.example.net',
            'meta_data' => '{"ispconfig_status":"unavailable","ispconfig_error":"panel version: connect timed out"}',
            'updated_at' => self::UPDATED_AT,
        ]]);

        self::assertStringContainsString('Probe Errors', $html);
        self::assertStringContainsString('connect timed out', $html);
    }

    // ---- nothing to show -------------------------------------------------

    public function testRendersNothingWhenNoServerUsesThisModule(): void
    {
        self::assertSame('', $this->render($this->vars('configservers'), []));
    }

    /**
     * @return array<string, array{mixed}> every shape tblservers_remote.meta_data
     *         can arrive in when there is nothing usable behind it
     */
    public static function unusableMetaData(): array
    {
        return [
            'never refreshed (NULL join)' => [null],
            'empty string' => [''],
            'whitespace' => ["  \n"],
            'truncated json' => ['{"ispconfig_version":"3.3.1'],
            'json scalar' => ['"3.3.1p1"'],
            'json list' => ['[1,2,3]'],
            'json null' => ['null'],
            'not json at all' => ['<html>gateway timeout</html>'],
            'only keys we do not render' => ['{"service_count":14,"ispconfig_status":"ok"}'],
        ];
    }

    /**
     * A row with no usable metadata must add NOTHING to the page - not a block,
     * not a placeholder. Core already lists the server; an empty box under it
     * would only look broken.
     */
    #[DataProvider('unusableMetaData')]
    public function testARowWithoutUsableMetaDataRendersNothing(mixed $metaData): void
    {
        $html = $this->render($this->vars('configservers'), [[
            'id' => 3,
            'name' => 'ISPConfig 1',
            'hostname' => 'panel.example.net',
            'meta_data' => $metaData,
            'updated_at' => self::UPDATED_AT,
        ]]);

        self::assertSame('', $html);
    }

    public function testAMissingRowShapeIsSkippedInsteadOfFatal(): void
    {
        $html = $this->render($this->vars('configservers'), [
            ['id' => 2],
            [],
            ['meta_data' => '{bad'],
            $this->row(),
        ]);

        // The one good row still renders; the broken ones cost nothing.
        self::assertStringContainsString('3.3.1p1', $html);
        self::assertSame(1, substr_count($html, '"lines"'));
    }

    /** A broken query must degrade to "no panel", never to a broken admin page. */
    public function testADatabaseFailureNeverBreaksTheAdminPage(): void
    {
        $html = ispconfigconnect_metaPanelFooterOutput(
            $this->vars('configservers'),
            static function (): array {
                throw new \RuntimeException('SQLSTATE[42S02]: Base table not found');
            }
        );

        self::assertSame('', $html);
    }

    // ---- escaping --------------------------------------------------------

    /**
     * Every value here came off a remote panel over the network. It reaches the
     * page as JSON with the HTML-significant characters hex-escaped, and the
     * script writes it through textContent, so there is no HTML parsing step
     * for a hostile value to break out of.
     */
    public function testValuesFromThePanelCannotInjectMarkup(): void
    {
        $hostile = '</script><svg onload=alert(1)>"\'&';

        $html = $this->render($this->vars('configservers'), [[
            'id' => 3,
            'name' => 'ISPConfig 1',
            'hostname' => $hostile,
            'meta_data' => json_encode(['ispconfig_version' => $hostile, 'ispconfig_error' => $hostile]),
            'updated_at' => self::UPDATED_AT,
        ]]);

        $payload = $this->payloadElementOf($html);

        // Nothing in the payload can end the <script> element or start a tag,
        // an entity or an attribute: `"` survives only as JSON's own structural
        // delimiter, which is why it is not in this list.
        foreach (['<', '>', '&', "'"] as $char) {
            self::assertStringNotContainsString(
                $char,
                $payload,
                $char . ' must be hex-escaped before it reaches the document'
            );
        }
        self::assertStringContainsString(
            trim((string) json_encode('<', JSON_HEX_TAG), '"'),
            $payload,
            'JSON_HEX_TAG must be in force'
        );

        // The payload element's own closing tag is the ONLY </script> before
        // the behaviour script - i.e. two in the whole output. A third would
        // mean the value broke out of the element.
        self::assertSame(2, substr_count($html, '</script>'));

        // Escaped, not mangled: the block still shows what the panel said.
        $decoded = json_decode($payload, true);
        self::assertSame($hostile, $decoded[0]['lines'][0]['value'] ?? null);
        self::assertSame($hostile, $decoded[0]['hostname'] ?? null);

        // The reader writes those values through textContent; innerHTML would
        // re-open the HTML parsing step this whole design avoids.
        self::assertStringNotContainsString('innerHTML', $html);
    }

    /**
     * Runs $body with $_SERVER['REQUEST_URI'] set, restoring whatever the
     * runtime had (CLI has none at all).
     *
     * @param callable(): void $body
     */
    private function withRequestUri(string $uri, callable $body): void
    {
        $previous = $_SERVER['REQUEST_URI'] ?? null;
        $_SERVER['REQUEST_URI'] = $uri;
        try {
            $body();
        } finally {
            if ($previous === null) {
                unset($_SERVER['REQUEST_URI']);
            } else {
                $_SERVER['REQUEST_URI'] = $previous;
            }
        }
    }

    /** The JSON between the payload element's own tags. */
    private function payloadElementOf(string $html): string
    {
        $open = strpos($html, '<script type="application/json"');
        self::assertIsInt($open);
        $start = strpos($html, '>', $open);
        self::assertIsInt($start);
        $end = strpos($html, '</script>', $start);
        self::assertIsInt($end);

        return substr($html, $start + 1, $end - $start - 1);
    }

    // ---- the query -------------------------------------------------------

    /**
     * One query, and it asks only for servers running THIS module - the servers
     * page lists every server an install has, and decorating a cPanel or Plesk
     * row with ISPConfig numbers would be worse than showing nothing.
     */
    public function testTheQueryAsksOnlyForRowsRunningThisModuleAndRunsOnce(): void
    {
        $builder = $this->queryBuilderDouble([
            (object) [
                'id' => 3,
                'name' => 'ISPConfig 1',
                'hostname' => 'panel.example.net',
                'meta_data' => self::META,
                'updated_at' => self::UPDATED_AT,
            ],
        ]);

        $rows = ispconfigconnect_metaPanelRows(static fn (): object => $builder);

        $methods = array_column($builder->calls, 'method');
        self::assertSame(['leftJoin', 'where', 'get'], $methods, 'exactly one query, in this shape');
        self::assertSame(
            ['tblservers.type', ISPCONFIGCONNECT_METAPANEL_MODULE],
            $builder->calls[1]['args'],
            'the module filter is what keeps other vendors\' servers untouched'
        );
        self::assertSame('tblservers_remote', $builder->calls[0]['args'][0] ?? null);

        self::assertCount(1, $rows);
        self::assertSame('panel.example.net', $rows[0]['hostname'] ?? null);
        self::assertSame(self::META, $rows[0]['meta_data'] ?? null);
    }

    /** Drivers hand rows back as objects or as arrays depending on fetch mode. */
    public function testRowsArriveAsObjectsOrArrays(): void
    {
        $builder = $this->queryBuilderDouble([
            ['id' => 3, 'name' => 'a', 'hostname' => 'h1', 'meta_data' => null, 'updated_at' => null],
            (object) ['id' => 4, 'name' => 'b', 'hostname' => 'h2', 'meta_data' => null, 'updated_at' => null],
        ]);

        $rows = ispconfigconnect_metaPanelRows(static fn (): object => $builder);

        self::assertSame(['h1', 'h2'], array_column($rows, 'hostname'));
    }

    /** A schema change across WHMCS versions must cost the panel, not the page. */
    public function testAQueryThatThrowsYieldsNoRows(): void
    {
        self::assertSame([], ispconfigconnect_metaPanelRows(static function (): object {
            throw new \RuntimeException('SQLSTATE[42S22]: Column not found');
        }));
    }

    /**
     * The production path: no injected builder, so the reader falls through to
     * Capsule. Outside WHMCS there is no Capsule class at all, and referencing
     * a missing class would be a fatal Error on every admin page.
     */
    public function testTheDefaultRowReaderNeverThrows(): void
    {
        self::assertSame([], ispconfigconnect_metaPanelRows());
        self::assertSame('', ispconfigconnect_metaPanelFooterOutput($this->vars('configservers')));
    }

    // ---- delivery --------------------------------------------------------

    /**
     * WHMCS includes EVERY includes/hooks/*.php. An operator who leaves a
     * second copy in that directory under any other .php name - the usual
     * `ispconfigconnect-old.php` of an upgrade - would have this file loaded
     * twice, and an unguarded redeclaration is a FATAL on every admin AND
     * client page of a live billing system.
     *
     * Run in a subprocess: the failure mode being guarded against cannot be
     * caught, so in-process it would take the suite down with it.
     */
    public function testLoadingTheFileTwiceIsHarmlessRatherThanAFatal(): void
    {
        $hook = realpath(__DIR__ . '/../../modules/servers/ispconfigconnect/hooks/ispconfigconnect.php');
        self::assertIsString($hook);

        $script = '<?php define("WHMCS", true); function add_hook($a, $b, $c) {} '
            . '$f = ' . var_export($hook, true) . '; '
            . 'include $f; include $f; echo "SURVIVED";';

        // No .php suffix: tempnam() has already created this exact path, and
        // appending one would write to a different file and orphan the original.
        $file = tempnam(sys_get_temp_dir(), 'ispcc-dbl-');
        self::assertIsString($file);
        file_put_contents($file, $script);

        try {
            $output = shell_exec(
                escapeshellarg(PHP_BINARY) . ' -d error_reporting=E_ALL -d display_errors=1 '
                . escapeshellarg($file) . ' 2>&1'
            );
        } finally {
            @unlink($file);
        }

        self::assertIsString($output);
        self::assertStringNotContainsString('Fatal error', (string) $output, (string) $output);
        self::assertStringNotContainsString('Warning', (string) $output, (string) $output);
        self::assertStringContainsString('SURVIVED', (string) $output);
    }

    /**
     * The second load must not register the hook a second time either - that
     * would render the whole block twice into one page.
     */
    public function testASecondLoadDoesNotRegisterTheHookAgain(): void
    {
        $before = count(HookRegistry::for('AdminAreaFooterOutput'));

        include __DIR__ . '/../../modules/servers/ispconfigconnect/hooks/ispconfigconnect.php';

        self::assertCount($before, HookRegistry::for('AdminAreaFooterOutput'));
    }

    /**
     * The installer decides "did I write this file?" by grepping for the
     * marker. If the two ever disagree, install-hook.sh would refuse to update
     * its own file, so they are checked against each other here.
     */
    public function testTheInstallerAndTheHookFileAgreeOnTheOwnershipMarker(): void
    {
        $dir = __DIR__ . '/../../modules/servers/ispconfigconnect/hooks/';

        $hook = file_get_contents($dir . 'ispconfigconnect.php');
        $installer = file_get_contents($dir . 'install-hook.sh');
        self::assertIsString($hook);
        self::assertIsString($installer);

        self::assertStringContainsString(ISPCONFIGCONNECT_METAPANEL_MARKER, $hook);
        self::assertStringContainsString(ISPCONFIGCONNECT_METAPANEL_MARKER, $installer);
    }

    // ---- helpers ---------------------------------------------------------

    /**
     * A fluent stand-in for the Capsule query builder that records what it was
     * asked for. Anonymous rather than a shared double: nothing else in the
     * suite queries through this seam.
     *
     * @param list<array<string, mixed>|object> $rows
     * @return object{calls: list<array{method: string, args: array<int, mixed>}>}
     */
    private function queryBuilderDouble(array $rows): object
    {
        return new class ($rows) {
            /** @var list<array{method: string, args: array<int, mixed>}> */
            public array $calls = [];

            /** @param list<array<string, mixed>|object> $rows */
            public function __construct(private readonly array $rows)
            {
            }

            /** @param array<int, mixed> $arguments */
            public function __call(string $method, array $arguments): mixed
            {
                $this->calls[] = ['method' => $method, 'args' => $arguments];

                return $method === 'get' ? $this->rows : $this;
            }
        };
    }

    /**
     * Core prints "Accounts: N" from tblservers_remote.num_accounts, a column
     * no module can write - it is reset to 0 on every metadata refresh - so the
     * payload carries the live client count for the script to put in that cell.
     */
    public function testPayloadCarriesTheLiveClientCountForTheAccountsCell(): void
    {
        $payload = ispconfigconnect_metaPanelPayload([$this->row()], (int) strtotime(self::UPDATED_AT));

        self::assertSame(9, $payload[0]['accounts']);
    }

    public function testAccountsCountIsOmittedWhenTheClientProbeDidNotAnswer(): void
    {
        $row = $this->row();
        $row['meta_data'] = '{"ispconfig_version":"3.3.1p1","ispconfig_status":"degraded"}';

        $payload = ispconfigconnect_metaPanelPayload([$row], (int) strtotime(self::UPDATED_AT));

        self::assertArrayNotHasKey('accounts', $payload[0] ?? []);
    }

    /**
     * @param array<string, mixed> $vars
     * @param list<array<string, mixed>> $rows
     */
    private function render(array $vars, array $rows, ?int $now = null): string
    {
        return ispconfigconnect_metaPanelFooterOutput($vars, static fn (): array => $rows, $now);
    }

    /**
     * The block a single row produces, flattened to label => value so the
     * assertions read as what an operator sees.
     *
     * @param array<string, mixed> $row
     * @return array<string, string>
     */
    private function linesFor(array $row, ?int $now = null): array
    {
        $payload = ispconfigconnect_metaPanelPayload([$row], $now ?? (int) strtotime(self::UPDATED_AT));

        $lines = [];
        foreach ($payload[0]['lines'] ?? [] as $line) {
            $lines[$line['label']] = $line['value'];
        }

        return $lines;
    }

    /**
     * One servers-page row. $overrides are merged into the decoded meta_data,
     * $drop removes keys from it, and $updatedAt replaces the column.
     *
     * @param array<string, mixed> $overrides
     * @param list<string> $drop
     * @return array<string, mixed>
     */
    private function row(array $overrides = [], array $drop = [], ?string $updatedAt = self::UPDATED_AT): array
    {
        /** @var array<string, mixed> $meta */
        $meta = json_decode(self::META, true);
        foreach ($drop as $key) {
            unset($meta[$key]);
        }

        return [
            'id' => 3,
            'name' => 'ISPConfig 1',
            'hostname' => 'panel.example.net',
            'meta_data' => json_encode(array_merge($meta, $overrides)),
            'updated_at' => $updatedAt,
        ];
    }

    /** @return array<string, mixed> the documented AdminAreaFooterOutput $vars */
    private function vars(string $filename): array
    {
        return [
            'charset' => 'utf-8',
            'template' => 'blend',
            'filename' => $filename,
            'adminid' => 1,
            'pagetitle' => 'Servers',
        ];
    }
}
