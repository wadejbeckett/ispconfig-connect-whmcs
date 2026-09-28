<?php

declare(strict_types=1);

namespace Ispconfigconnect\Tests\Module;

use Ispconfigconnect\Tests\Stubs\HookRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WHMCS\Module\Server\Ispconfigconnect\Server\MetaDataPanel;
use WHMCS\Module\Server\Ispconfigconnect\Server\ServerMetaData;

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
    /**
     * A row as the servers page sees it once GetRemoteMetaData has run once.
     *
     * `service_count` mirrors `client_count` because that is what the module
     * actually stores: one WHMCS service is one ISPConfig client, and the
     * website count that used to answer this key was removed outright
     * (Server\RemoteMetaData, "Why there is no website count"), so no
     * `website_count` key exists in the payload any more.
     */
    private const META = '{"ispconfig_version":"3.3.1p1","pending_jobs":2,"client_count":9,'
        . '"service_count":9,"server_count":1,"sso_available":true,'
        . '"ispconfig_status":"ok","checked_at":"2026-08-24 09:00:00 UTC"}';

    private const UPDATED_AT = '2026-08-24 09:00:12';

    /** Where the hook file and its installer ship. */
    private const HOOKS_DIR = __DIR__ . '/../../modules/servers/ispconfigconnect/hooks';

    /**
     * Every external command install-hook.sh runs - what a PATH needs to hold
     * when a test hides one of the privilege-drop tools from it.
     */
    private const INSTALLER_TOOLS = [
        'basename', 'dirname', 'cat', 'sed', 'grep', 'ls', 'awk', 'id', 'cmp',
        'mktemp', 'chmod', 'mv', 'rm', 'php', 'runuser', 'setpriv', 'su',
    ];

    /** @var list<string> scratch directories an installer test created */
    private array $scratch = [];

    protected function tearDown(): void
    {
        foreach ($this->scratch as $dir) {
            $this->removeTree($dir);
        }
        $this->scratch = [];

        parent::tearDown();
    }

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
     * Remote Usage Stats cell (live-verified), and that key now carries the
     * ISPConfig client count - the number this block prints as "Clients".
     * Rendering it here as well would put one number on the page twice under
     * two names, which reads as two different facts.
     *
     * "Websites" is asserted absent too, and stays asserted: the payload no
     * longer has a `website_count` key at all (the bulk read that produced it
     * shipped every site's TLS private key), so a line under that label could
     * only ever be an invented number.
     *
     * This is the field list docs/INSTALL.md documents and that
     * Server\MetaDataPanel renders; the two must not drift apart.
     */
    public function testTheBlockDoesNotRepeatTheServiceCountCoreAlreadyShows(): void
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
        // The list has no action, which is what makes it the page we render on.
        $this->withRequestUri('/secret-admin/configservers.php', function (): void {
            self::assertStringContainsString(
                ISPCONFIGCONNECT_METAPANEL_ID,
                $this->render([], [$this->row()])
            );
        });
    }

    /**
     * configservers.php serves the server list AND the edit, add and group
     * forms and their POST handlers. Only the list carries the table this block
     * decorates, so every other action must render nothing - no query, no
     * payload, and no risk of the block being attached to a non-table container
     * on a form page.
     *
     * @return array<string, array{array<string, string>}>
     */
    public static function nonListActions(): array
    {
        return [
            'edit a server' => [['action' => 'manage', 'id' => '3']],
            'add a server' => [['action' => 'add']],
            'manage a group' => [['action' => 'managegroup', 'id' => '3']],
            'save handler' => [['action' => 'save', 'id' => '3']],
            'group save handler' => [['action' => 'managegroupsave']],
        ];
    }

    /** @param array<string, string> $get */
    #[DataProvider('nonListActions')]
    public function testRendersNothingOnConfigserversSubPages(array $get): void
    {
        $this->withGet($get, function (): void {
            self::assertSame(
                '',
                $this->render($this->vars('configservers'), [$this->row()]),
                'a configservers action other than the list must not render'
            );
        });
    }

    /** The list is the one action that renders: no action, or action=list. */
    public function testRendersOnTheServersListAction(): void
    {
        foreach ([[], ['action' => 'list']] as $get) {
            $this->withGet($get, function (): void {
                self::assertStringContainsString(
                    ISPCONFIGCONNECT_METAPANEL_ID,
                    $this->render($this->vars('configservers'), [$this->row()]),
                    'the servers list must render'
                );
            });
        }
    }

    /**
     * A configservers action carried only in the request URI (not $_GET) must
     * gate the block too - the URI is the fallback the page check already uses.
     */
    public function testASubPageActionInTheRequestUriAlsoSuppressesTheBlock(): void
    {
        $this->withRequestUri('/admin/configservers.php?action=manage&id=3', function (): void {
            self::assertSame('', $this->render($this->vars('configservers'), [$this->row()]));
        });
    }

    /**
     * A sub-page must be gated BEFORE the query runs, exactly like any other
     * non-servers page - the whole point is to keep configservers's edit/add/
     * group actions from paying for a query on every request.
     */
    public function testASubPageNeverReachesTheDatabase(): void
    {
        $touched = false;
        $this->withGet(['action' => 'manage', 'id' => '3'], function () use (&$touched): void {
            ispconfigconnect_metaPanelFooterOutput(
                $this->vars('configservers'),
                static function () use (&$touched): array {
                    $touched = true;

                    return [];
                }
            );
        });

        self::assertFalse($touched, 'the row provider must not be called on a configservers sub-page');
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
     * GetRemoteMetaData deliberately omits the key when the SSO probe
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

    // ---- row matching (L1) -----------------------------------------------

    /**
     * The injected script must anchor a row on the server's OWN edit link
     * (configservers.php?action=manage&id=N) and nothing else: a
     * configservers.php?action=managegroup&id=N (server groups) link shares the
     * id namespace and would otherwise decorate a group row for the same id.
     *
     * WHMCS writes that edit link RELATIVE - href="?action=manage&id=N", no
     * script name at all (decoded 8.12.1 configservers.php) - so the filter
     * must judge the URL the browser would resolve, not the raw attribute: a
     * substring test for "configservers" on the attribute let every relative
     * link, group links included, through the unfiltered fallback. These
     * source-level guards run everywhere; the executed behaviour is
     * testTheScriptAnchorsTheEditLinkRowForEveryHrefForm below.
     */
    public function testTheScriptAnchorsRowsOnlyOnTheManageActionLink(): void
    {
        $script = $this->behaviourScriptOf(
            $this->render($this->vars('configservers'), [$this->row()])
        );

        self::assertStringContainsString(
            'new URL(href, document.baseURI || window.location.href)',
            $script,
            'the href must be resolved against the page before it is judged'
        );
        self::assertStringContainsString(
            "target.action === 'manage'",
            $script,
            'the preferred-link filter must require action=manage'
        );
        self::assertStringContainsString(
            '/(?:^|\/)configservers\.php$/i.test(path)',
            $script,
            'the servers script is recognised by the resolved path'
        );
        self::assertStringContainsString('/^\d+$/.test(id)', $script, 'only a purely numeric id anchors');
        self::assertStringNotContainsString(
            "href.indexOf('configservers')",
            $script,
            'a substring test on the raw attribute misses WHMCS\'s relative links'
        );
    }

    /**
     * The script, executed. Each form WHMCS (or a theme) may write the edit
     * link in - relative, script-relative, root-relative, absolute, and a
     * servers page served under another URL - must put the block on the edit
     * link's row and correct THAT row's Accounts figure, with a server-group
     * table for the same id placed FIRST in the document, where a leaky filter
     * picks it up. A page whose only link for the id is a group link must fall
     * back to the hostname cell and leave core's Accounts figure alone.
     *
     * Needs node (any version with a global URL); the DOM is the minimal
     * stand-in in DOM_HARNESS, so no npm package is involved. Skipped, not
     * failed, where node is absent - the source guards above still run.
     */
    public function testTheScriptAnchorsTheEditLinkRowForEveryHrefForm(): void
    {
        $node = $this->which('node');
        if ($node === null) {
            self::markTestSkipped('node is not installed, so the servers-page script cannot be executed here');
        }

        $html = $this->render($this->vars('configservers'), [$this->row()]);
        $results = $this->runNode($node, self::DOM_HARNESS, (string) json_encode([
            'script' => $this->behaviourScriptOf($html),
            'payload' => $this->payloadElementOf($html),
            'payloadId' => ISPCONFIGCONNECT_METAPANEL_ID,
            'blockClass' => ISPCONFIGCONNECT_METAPANEL_CLASS,
        ]));

        foreach (['relative', 'scriptRelative', 'rootRelative', 'absolute', 'servedUnderAnotherUrl'] as $form) {
            $result = $results[$form] ?? null;
            self::assertIsArray($result, $form);
            self::assertSame(['server'], $result['blockIn'] ?? null, $form . ': the block belongs on the edit link\'s row');
            self::assertSame(
                'Accounts: 9Services: 9',
                $result['accounts'] ?? null,
                $form . ': the linked row\'s Accounts figure is corrected'
            );
            self::assertSame(
                'Accounts: 0Services: 9',
                $result['groupAccounts'] ?? null,
                $form . ': the server-group row is never touched'
            );
        }

        // With core's real row shape the block still lands under the server
        // name, not in the edit-icon column the link lives in.
        $whmcs = $results['whmcsMarkup'] ?? null;
        self::assertIsArray($whmcs);
        self::assertSame(['server'], $whmcs['blockIn'] ?? null, 'the block goes in the row\'s first (name) cell');
        self::assertSame('Accounts: 9Services: 9', $whmcs['accounts'] ?? null);
        self::assertSame('Accounts: 0Services: 9', $whmcs['groupAccounts'] ?? null);

        $groupOnly = $results['groupLinkOnly'] ?? null;
        self::assertIsArray($groupOnly);
        self::assertSame(['host'], $groupOnly['blockIn'] ?? null, 'a group link never anchors a row');
        self::assertSame(
            'Accounts: 0Services: 9',
            $groupOnly['accounts'] ?? null,
            'a hostname-text match must not rewrite core\'s Accounts figure'
        );
        self::assertSame('Accounts: 0Services: 9', $groupOnly['groupAccounts'] ?? null);
    }

    /**
     * Server\MetaDataPanel carries its own copy of the script (it is the
     * library statement of this panel, kept for its unit tests). Its row
     * matcher is held byte-for-byte to the hook file's, so the executed test
     * above speaks for both and the two cannot drift apart again.
     */
    public function testTheLibraryPanelMatchesRowsWithTheHookFilesCode(): void
    {
        $hook = $this->behaviourScriptOf($this->render($this->vars('configservers'), [$this->row()]));
        $library = $this->behaviourScriptOf(MetaDataPanel::render([
            new ServerMetaData(3, 'ISPConfig 1', 'panel.example.net', ['pending_jobs' => 1], null),
        ]));

        self::assertSame($this->rowMatcherOf($hook), $this->rowMatcherOf($library));
    }

    /**
     * The Accounts rewrite mutates a cell WHMCS core owns, so it must run only
     * when the row was found by an id link - never on the hostname-text
     * fallback, which can match another vendor's row for the same host.
     */
    public function testTheAccountsRewriteRunsOnlyForALinkMatchedRow(): void
    {
        $script = $this->behaviourScriptOf(
            $this->render($this->vars('configservers'), [$this->row()])
        );

        self::assertStringContainsString('var matchedByLink = false;', $script);
        self::assertMatchesRegularExpression(
            '/if\s*\(\s*matchedByLink\s*\)\s*\{\s*correctAccountCount\(/',
            $script,
            'correctAccountCount must be gated behind a link match'
        );
    }

    /** The behaviour <script> (the last one), not the JSON payload element. */
    private function behaviourScriptOf(string $html): string
    {
        $open = strrpos($html, '<script>');
        self::assertIsInt($open);
        $start = $open + strlen('<script>');
        $end = strpos($html, '</script>', $start);
        self::assertIsInt($end);

        return substr($html, $start, $end - $start);
    }

    /** serverLinks() and linkTarget(): everything up to cellWithExactText(). */
    private function rowMatcherOf(string $script): string
    {
        $start = strpos($script, 'function serverLinks()');
        self::assertIsInt($start);
        $end = strpos($script, 'function cellWithExactText(', $start);
        self::assertIsInt($end);

        return substr($script, $start, $end - $start);
    }

    /**
     * Runs $program under node with $stdin on its standard input and returns
     * the JSON it prints.
     *
     * @return array<string, mixed>
     */
    private function runNode(string $node, string $program, string $stdin): array
    {
        $process = proc_open(
            [$node, '-e', $program],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        self::assertIsResource($process);

        fwrite($pipes[0], $stdin);
        fclose($pipes[0]);
        $output = (string) stream_get_contents($pipes[1]);
        $errors = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        self::assertSame(0, proc_close($process), $errors);
        $decoded = json_decode($output, true);
        self::assertIsArray($decoded, $output . $errors);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /** The first executable called $tool on PATH, or null. */
    private function which(string $tool): ?string
    {
        foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $dir) {
            $candidate = $dir . '/' . $tool;
            if ($dir !== '' && is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * The smallest DOM the servers-page script can run in - exactly the
     * surface it touches, nothing more - so executing it needs node alone.
     * Reads {script, payload, payloadId, blockClass} on stdin, renders each
     * page below, and prints where the block landed and what the Accounts
     * cells read afterwards.
     *
     * Every page puts a server-GROUP table for the same id FIRST: a filter
     * that lets a group link through anchors that row, and correctAccountCount
     * then rewrites the group row's cell instead of the server's.
     */
    private const DOM_HARNESS = <<<'JS'
        'use strict';
        const input = JSON.parse(require('fs').readFileSync(0, 'utf8'));

        class Text {
            constructor(value) { this.nodeType = 3; this.nodeValue = value; this.parentNode = null; }
            get textContent() { return this.nodeValue; }
        }

        class Element {
            constructor(tag) {
                this.nodeType = 1;
                this.tagName = tag.toUpperCase();
                this.attributes = {};
                this.childNodes = [];
                this.parentNode = null;
            }
            getAttribute(name) {
                return Object.prototype.hasOwnProperty.call(this.attributes, name) ? this.attributes[name] : null;
            }
            setAttribute(name, value) { this.attributes[name] = String(value); }
            get className() { return this.getAttribute('class') || ''; }
            set className(value) { this.setAttribute('class', value); }
            get id() { return this.getAttribute('id') || ''; }
            appendChild(node) { node.parentNode = this; this.childNodes.push(node); return node; }
            get textContent() { return this.childNodes.map((node) => node.textContent).join(''); }
            set textContent(value) { this.childNodes = []; this.appendChild(new Text(String(value))); }
            closest(tag) {
                for (let node = this; node && node.nodeType === 1; node = node.parentNode) {
                    if (node.tagName === tag.toUpperCase()) { return node; }
                }
                return null;
            }
            descendants() {
                const found = [];
                const walk = (element) => element.childNodes.forEach((node) => {
                    if (node.nodeType === 1) { found.push(node); walk(node); }
                });
                walk(this);
                return found;
            }
            getElementsByTagName(tag) {
                return this.descendants().filter((element) => element.tagName === tag.toUpperCase());
            }
            getElementsByClassName(name) {
                return this.descendants().filter((element) => (' ' + element.className + ' ').includes(' ' + name + ' '));
            }
            querySelector(selector) {
                if (selector[0] !== '.') { throw new Error('unsupported selector ' + selector); }
                return this.getElementsByClassName(selector.slice(1))[0] || null;
            }
        }

        function h(tag, attributes, ...children) {
            const element = new Element(tag);
            Object.keys(attributes).forEach((name) => element.setAttribute(name, attributes[name]));
            children.forEach((child) => element.appendChild(typeof child === 'string' ? new Text(child) : child));
            return element;
        }

        function render(pageUrl, ...content) {
            const body = h('body', {}, ...content, h('script', { id: input.payloadId }, input.payload));
            const location = new URL(pageUrl);
            global.window = { location: { href: location.href, pathname: location.pathname } };
            global.document = {
                readyState: 'complete',
                baseURI: location.href,
                getElementById: (id) => body.descendants().find((element) => element.id === id) || null,
                getElementsByTagName: (tag) => body.getElementsByTagName(tag),
                createElement: (tag) => new Element(tag),
                createTextNode: (text) => new Text(text),
                addEventListener: () => {},
            };
            new Function(input.script)();
            const text = (id) => { const element = document.getElementById(id); return element ? element.textContent : null; };
            return {
                blockIn: body.getElementsByClassName(input.blockClass).map((block) => block.parentNode.id),
                accounts: text('usage'),
                groupAccounts: text('groupusage'),
            };
        }

        const PAGE = 'https://billing.example.com/admin/configservers.php';
        const usage = (id) => h('td', { id: id, class: 'server-usage-count' }, 'Accounts: 0', h('br', {}), 'Services: 9');
        const groupRow = (href) => h('table', {}, h('tr', {},
            h('td', { id: 'group' }, h('a', { href: href }, 'Web group')), usage('groupusage')));
        const serverRow = (href) => h('table', {}, h('tr', {},
            h('td', { id: 'server' }, h('a', { href: href }, 'ISPConfig 1'), h('br', {}), 'panel.example.net'),
            usage('usage')));
        const hostnameRow = () => h('table', {}, h('tr', {}, h('td', { id: 'host' }, 'panel.example.net'), usage('usage')));
        // Core's real row (configservers.php, WHMCS 7.10 to 8.12): the name
        // cell carries the makedefault link, and the edit link sits alone in
        // its own icon column near the end.
        const whmcsServerRow = () => h('table', {}, h('tr', {},
            h('td', { id: 'server' }, h('a', { href: '?action=makedefault&id=3&token=abc' }, 'ISPConfig 1')),
            h('td', {}, '203.0.113.10'),
            h('td', {}, '9/200'),
            usage('usage'),
            h('td', {}, ''),
            h('td', {}, ''),
            h('td', {}, h('a', { href: '?action=toggle&id=3' }, 'disable')),
            h('td', { id: 'edit' }, h('a', { href: '?action=manage&id=3' }, h('img', { src: 'images/edit.gif' }))),
            h('td', {}, h('a', { href: '#' }, 'delete'))));

        process.stdout.write(JSON.stringify({
            relative: render(PAGE,
                groupRow('?action=managegroup&id=3'), serverRow('?action=manage&id=3')),
            scriptRelative: render(PAGE,
                groupRow('configservers.php?action=managegroup&id=3'), serverRow('configservers.php?action=manage&id=3')),
            rootRelative: render(PAGE,
                groupRow('/admin/configservers.php?action=managegroup&id=3'),
                serverRow('/admin/configservers.php?action=manage&id=3')),
            absolute: render(PAGE,
                groupRow(PAGE + '?action=managegroup&id=3'), serverRow(PAGE + '?action=manage&id=3')),
            servedUnderAnotherUrl: render('https://billing.example.com/admin/servers',
                groupRow('?action=managegroup&id=3'), serverRow('?action=manage&id=3')),
            groupLinkOnly: render(PAGE, groupRow('?action=managegroup&id=3'), hostnameRow()),
            whmcsMarkup: render(PAGE, whmcsServerRow(), groupRow('?action=managegroup&id=3')),
        }));
        JS;

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

    /**
     * Runs $body with $_GET replaced by $get - WHMCS's authoritative source for
     * the configservers action - and restores it afterwards.
     *
     * @param array<string, string> $get
     * @param callable(): void $body
     */
    private function withGet(array $get, callable $body): void
    {
        $previous = $_GET;
        $_GET = $get;
        try {
            $body();
        } finally {
            $_GET = $previous;
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

    // ---- the installer (install-hook.sh) ---------------------------------

    /**
     * The whole lifecycle as whoever runs the suite: install, an idempotent
     * re-run, --status, --uninstall. As root the sandbox is root-owned, so this
     * is also the "root into a root-owned includes/hooks" path.
     */
    public function testTheInstallerInstallsReRunsReportsAndUninstalls(): void
    {
        $box = $this->fakeWhmcs();
        $installer = self::HOOKS_DIR . '/install-hook.sh';
        $target = $box['hooks'] . '/ispconfigconnect.php';

        [$code, $output] = $this->runInstaller($installer, [$box['root']]);
        self::assertSame(0, $code, $output);
        self::assertStringContainsString('installed ' . $target, $output);
        self::assertFileEquals(self::HOOKS_DIR . '/ispconfigconnect.php', $target);
        self::assertSame(0644, fileperms($target) & 0777, 'the web server must be able to read it');

        [$code, $output] = $this->runInstaller($installer, [$box['root']]);
        self::assertSame(0, $code, $output);
        self::assertStringContainsString('already up to date', $output);

        [$code, $output] = $this->runInstaller($installer, ['--status', $box['root']]);
        self::assertSame(0, $code, $output);
        self::assertStringContainsString('installed and up to date', $output);

        [$code, $output] = $this->runInstaller($installer, ['--uninstall', $box['root']]);
        self::assertSame(0, $code, $output);
        self::assertFileDoesNotExist($target);

        [$code, $output] = $this->runInstaller($installer, ['--status', $box['root']]);
        self::assertSame(0, $code, $output);
        self::assertStringContainsString('not installed', $output);
        self::assertSame([], $this->leftoverTemps($box['hooks']));
    }

    /**
     * A symlink planted at the well-known target name must be refused, never
     * written through - here aimed at a file that does not exist yet, which
     * is exactly what an attacker would point it at.
     */
    public function testTheInstallerRefusesASymlinkPlantedAtTheTarget(): void
    {
        $box = $this->fakeWhmcs();
        $target = $box['hooks'] . '/ispconfigconnect.php';
        self::assertTrue(mkdir($box['base'] . '/elsewhere', 0755));
        $aim = $box['base'] . '/elsewhere/written-through-the-link';
        self::assertTrue(symlink($aim, $target));

        [$code, $output] = $this->runInstaller(self::HOOKS_DIR . '/install-hook.sh', [$box['root']]);

        self::assertSame(1, $code, $output);
        self::assertStringContainsString('is a symlink', $output);
        self::assertFileDoesNotExist($aim);
        self::assertTrue(is_link($target), 'the link itself is left for the admin to inspect');
        self::assertSame([], $this->leftoverTemps($box['hooks']), 'a refused write removes its temp file');
    }

    /** No marker, no touching - neither overwrite nor delete. */
    public function testTheInstallerNeverOverwritesOrRemovesAForeignFile(): void
    {
        $box = $this->fakeWhmcs();
        $installer = self::HOOKS_DIR . '/install-hook.sh';
        $target = $box['hooks'] . '/ispconfigconnect.php';
        $foreign = "<?php // somebody else's hook\n";
        file_put_contents($target, $foreign);

        [$code, $output] = $this->runInstaller($installer, [$box['root']]);
        self::assertSame(1, $code, $output);
        self::assertStringContainsString('refusing to overwrite', $output);

        [$code, $output] = $this->runInstaller($installer, ['--uninstall', $box['root']]);
        self::assertSame(1, $code, $output);
        self::assertStringContainsString('refusing to remove', $output);

        [$code, $output] = $this->runInstaller($installer, ['--status', $box['root']]);
        self::assertSame(1, $code, $output);
        self::assertStringContainsString('FOREIGN FILE', $output);

        self::assertSame($foreign, file_get_contents($target));
    }

    /**
     * THE regression. INSTALL §2 runs the installer from the checkout or zip
     * the module arrived in, and as root that is often private (/root/src/...).
     * When includes/hooks belongs to the web user, the installer used to
     * re-execute ITSELF as that user, who could not even open the script:
     * "/bin/sh: 0: cannot open ...: Permission denied", exit 2, old hook left
     * in place. Root must keep reading the source and hand only the write to
     * the directory's owner - so the file still ends up owned by that user.
     *
     * Needs root and a "nobody" account; skipped elsewhere.
     */
    public function testARootInstallFromACheckoutTheHookOwnerCannotReadSucceeds(): void
    {
        $nobody = $this->requireRootAndNobody();
        if ($this->which('runuser') === null && $this->which('setpriv') === null && $this->which('su') === null) {
            self::markTestSkipped('no privilege-drop tool (runuser/setpriv/su) on this host');
        }

        $box = $this->fakeWhmcs();
        $this->requireWorldReachable($box['hooks']);
        $checkout = $this->checkout($box['base'] . '/private/hooks');
        self::assertTrue(chmod($box['base'] . '/private', 0700), 'the checkout must be out of nobody\'s reach');
        self::assertTrue(chown($box['hooks'], $nobody['uid']));
        self::assertTrue(chgrp($box['hooks'], $nobody['gid']));

        [$code, $output] = $this->runInstaller($checkout . '/install-hook.sh', [$box['root']]);

        self::assertSame(0, $code, $output);
        $installed = $box['hooks'] . '/ispconfigconnect.php';
        self::assertFileEquals(self::HOOKS_DIR . '/ispconfigconnect.php', $installed);
        self::assertSame($nobody['uid'], fileowner($installed), 'written as the directory\'s owner, not as root');
        self::assertSame(0644, fileperms($installed) & 0777);
        self::assertSame([], $this->leftoverTemps($box['hooks']));

        // The removal is the owner's too, and needs nothing from the checkout.
        [$code, $output] = $this->runInstaller($checkout . '/install-hook.sh', ['--uninstall', $box['root']]);
        self::assertSame(0, $code, $output);
        self::assertFileDoesNotExist($installed);
    }

    /**
     * Root often reaches WHMCS through a convenience symlink in a root-only
     * directory (/root/whmcs -> /var/www/whmcs). The dropped writer cannot
     * traverse that path, so the installer must hand it the PHYSICAL one.
     *
     * Needs root and a "nobody" account; skipped elsewhere.
     */
    public function testARootInstallThroughASymlinkInARootOnlyDirectoryWritesThroughThePhysicalPath(): void
    {
        $nobody = $this->requireRootAndNobody();
        if ($this->which('runuser') === null && $this->which('setpriv') === null && $this->which('su') === null) {
            self::markTestSkipped('no privilege-drop tool (runuser/setpriv/su) on this host');
        }

        $box = $this->fakeWhmcs();
        $this->requireWorldReachable($box['hooks']);
        $checkout = $this->checkout($box['base'] . '/checkout');
        self::assertTrue(chown($box['hooks'], $nobody['uid']));
        self::assertTrue(chgrp($box['hooks'], $nobody['gid']));
        self::assertTrue(mkdir($box['base'] . '/rootonly', 0700));
        self::assertTrue(symlink($box['root'], $box['base'] . '/rootonly/whmcs'));

        [$code, $output] = $this->runInstaller($checkout . '/install-hook.sh', [$box['base'] . '/rootonly/whmcs']);

        self::assertSame(0, $code, $output);
        $installed = $box['hooks'] . '/ispconfigconnect.php';
        self::assertFileEquals(self::HOOKS_DIR . '/ispconfigconnect.php', $installed);
        self::assertSame($nobody['uid'], fileowner($installed));

        [$code, $output] = $this->runInstaller($checkout . '/install-hook.sh', ['--uninstall', $box['base'] . '/rootonly/whmcs']);
        self::assertSame(0, $code, $output);
        self::assertFileDoesNotExist($installed);
    }

    /**
     * Where runuser is missing, setpriv does the drop - and must be given the
     * DIRECTORY's group. It used to be handed the owner's uid as --regid,
     * which stamps the file with whatever group happens to share that number.
     * The directory's gid here is deliberately neither nobody's uid nor its
     * primary group, so only the right source can produce it.
     *
     * Needs root, a "nobody" account and setpriv; skipped elsewhere.
     */
    public function testTheSetprivFallbackGivesTheFileTheHookDirectorysGroup(): void
    {
        $nobody = $this->requireRootAndNobody();
        if ($this->which('setpriv') === null) {
            self::markTestSkipped('setpriv is not installed');
        }
        $gid = 12345;
        self::assertNotContains($gid, [$nobody['uid'], $nobody['gid']]);

        $box = $this->fakeWhmcs();
        $this->requireWorldReachable($box['hooks']);
        $checkout = $this->checkout($box['base'] . '/checkout');
        self::assertTrue(chown($box['hooks'], $nobody['uid']));
        self::assertTrue(chgrp($box['hooks'], $gid));

        [$code, $output] = $this->runInstaller(
            $checkout . '/install-hook.sh',
            [$box['root']],
            $this->pathWithout($box['base'] . '/bin', ['runuser'])
        );

        self::assertSame(0, $code, $output);
        $installed = $box['hooks'] . '/ispconfigconnect.php';
        self::assertSame($nobody['uid'], fileowner($installed));
        self::assertSame($gid, filegroup($installed), 'setpriv must be given the directory\'s gid, not the owner\'s uid');
    }

    /**
     * The same two guarantees at source level, so they hold on hosts where the
     * root-only tests above are skipped: the content reaches the writer on
     * stdin, opened by the invoking user, rather than the installer
     * re-executing itself under another account; and setpriv is handed the
     * directory's gid.
     */
    public function testTheInstallerReadsTheSourceItselfAndDelegatesOnlyTheWrite(): void
    {
        $installer = file_get_contents(self::HOOKS_DIR . '/install-hook.sh');
        self::assertIsString($installer);

        self::assertMatchesRegularExpression(
            '/as_writer \/bin\/sh -c "\$WRITE_SCRIPT" [^\n]*< "\$SOURCE"/',
            $installer,
            'the writer must be fed the source on stdin'
        );
        self::assertStringNotContainsString(
            '$SOURCE_DIR/$SELF',
            $installer,
            'the installer must not re-run itself as another user - that user may not be able to read it'
        );
        self::assertStringContainsString('--regid="$writer_gid"', $installer);
    }

    // ---- helpers ---------------------------------------------------------

    /**
     * A throwaway WHMCS root - configuration.php and includes/hooks - under
     * the system temp dir, every level 0755 so a dropped-to user can reach it.
     * Removed again in tearDown().
     *
     * @return array{base: string, root: string, hooks: string}
     */
    private function fakeWhmcs(): array
    {
        $base = sys_get_temp_dir() . '/ispcc-installer-' . bin2hex(random_bytes(6));
        $root = $base . '/whmcs';
        $hooks = $root . '/includes/hooks';
        self::assertTrue(mkdir($hooks, 0755, true));
        $this->scratch[] = $base;
        foreach ([$base, $root, $root . '/includes', $hooks] as $dir) {
            chmod($dir, 0755);
        }
        file_put_contents($root . '/configuration.php', "<?php\n");

        return ['base' => $base, 'root' => $root, 'hooks' => $hooks];
    }

    /** A copy of the shipped hooks/ directory at $dir - an operator's checkout. */
    private function checkout(string $dir): string
    {
        self::assertTrue(mkdir($dir, 0755, true));
        foreach (['install-hook.sh', 'ispconfigconnect.php'] as $file) {
            self::assertTrue(copy(self::HOOKS_DIR . '/' . $file, $dir . '/' . $file));
        }

        return $dir;
    }

    /**
     * Runs the installer through /bin/sh, as its shebang would.
     *
     * @param list<string> $args
     * @param null|string $path a replacement PATH, to hide tools from it
     * @return array{int, string} exit code, and stdout followed by stderr
     */
    private function runInstaller(string $installer, array $args, ?string $path = null): array
    {
        $process = proc_open(
            array_merge(['/bin/sh', $installer], $args),
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            $path === null ? null : ['PATH' => $path]
        );
        self::assertIsResource($process);
        // The installer prints a few lines at most, far below a pipe buffer, so
        // draining one stream before the other cannot deadlock.
        $output = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $output];
    }

    /**
     * The installer's hidden temp files still in $hooks.
     *
     * @return list<string>
     */
    private function leftoverTemps(string $hooks): array
    {
        return glob($hooks . '/.ispconfigconnect.php.*') ?: [];
    }

    /**
     * A PATH directory holding every tool the installer runs except $hidden.
     *
     * @param list<string> $hidden
     */
    private function pathWithout(string $dir, array $hidden): string
    {
        self::assertTrue(mkdir($dir, 0755, true));
        foreach (self::INSTALLER_TOOLS as $tool) {
            $real = in_array($tool, $hidden, true) ? null : $this->which($tool);
            if ($real !== null) {
                self::assertTrue(symlink($real, $dir . '/' . $tool));
            }
        }

        return $dir;
    }

    /**
     * Root, plus an unprivileged account to hand includes/hooks to: the setup
     * the privilege drop exists for. Skips - never fails - anywhere else.
     *
     * @return array{uid: int, gid: int}
     */
    private function requireRootAndNobody(): array
    {
        if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            self::markTestSkipped('needs root: only a root install drops privileges');
        }
        $nobody = function_exists('posix_getpwnam') ? posix_getpwnam('nobody') : false;
        if (!is_array($nobody)) {
            self::markTestSkipped('no "nobody" account to drop to');
        }

        return ['uid' => (int) $nobody['uid'], 'gid' => (int) $nobody['gid']];
    }

    /**
     * Skips unless every directory above $dir is world-searchable - otherwise
     * no dropped-to user could reach the sandbox at all (a TMPDIR inside a
     * 0700 home, say), and the test would fail for a reason of its own.
     */
    private function requireWorldReachable(string $dir): void
    {
        for ($path = dirname($dir); ; $path = dirname($path)) {
            $perms = fileperms($path);
            if ($perms === false || ($perms & 0001) === 0) {
                self::markTestSkipped($path . ' is not world-searchable, so no dropped-to user could reach the sandbox');
            }
            if ($path === dirname($path)) {
                return;
            }
        }
    }

    /** rm -rf that never follows a symlink out of the tree. */
    private function removeTree(string $path): void
    {
        if (is_link($path) || !is_dir($path)) {
            @unlink($path);

            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->removeTree($path . '/' . $entry);
            }
        }
        @rmdir($path);
    }

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
