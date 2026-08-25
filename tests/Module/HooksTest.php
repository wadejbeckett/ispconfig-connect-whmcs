<?php

declare(strict_types=1);

namespace Ispconfigconnect\Tests\Module;

use Ispconfigconnect\Tests\Stubs\HookRegistry;
use PHPUnit\Framework\TestCase;
use WHMCS\Module\Server\Ispconfigconnect\Server\MetaDataPanel;

// hooks.php is a WHMCS file guarded by `if (!defined('WHMCS')) die;`, exactly
// like the entry file - the constant must exist BEFORE the require.
if (!defined('WHMCS')) {
    define('WHMCS', true);
}

require_once __DIR__ . '/../../modules/servers/ispconfigconnect/hooks.php';

/**
 * Covers modules/servers/ispconfigconnect/hooks.php: the AdminAreaFooterOutput
 * hook that injects the cached ISPConfig stats into Configuration > Servers.
 *
 * The hook must be inert everywhere else in the admin area, must never throw
 * (a hook exception would damage an unrelated admin page), and must never
 * touch the ISPConfig API - the servers page renders one row per server and an
 * API call there would be slow and could trip the panel's login lockout.
 */
final class HooksTest extends TestCase
{
    /** @var list<array<string, mixed>> */
    private const ROWS = [
        [
            'id' => 3,
            'name' => 'ISPConfig 1',
            'hostname' => 'panel.example.net',
            'meta_data' => '{"ispconfig_version":"3.3.1p1","pending_jobs":2,"client_count":9,"checked_at":"2026-08-24 09:00:00 UTC"}',
            'updated_at' => '2026-08-24 09:00:12',
        ],
    ];

    public function testRegistersItselfOnTheDocumentedAdminFooterHook(): void
    {
        $registered = HookRegistry::for('AdminAreaFooterOutput');

        self::assertNotEmpty($registered, 'hooks.php must register on AdminAreaFooterOutput');
        self::assertContains(
            'ispconfigconnect_hook_adminServersMetaData',
            array_column($registered, 'function')
        );
    }

    public function testRendersTheStatsBlockOnTheServersPage(): void
    {
        $html = ispconfigconnect_hook_adminServersMetaData(
            $this->vars('configservers'),
            static fn (): array => self::ROWS
        );

        self::assertStringContainsString(MetaDataPanel::PAYLOAD_ELEMENT_ID, $html);
        self::assertStringContainsString('3.3.1p1', $html);
        self::assertStringContainsString('Pending Jobs', $html);
        self::assertStringContainsString('2026-08-24 09:00:12', $html);
    }

    public function testRendersNothingOnEveryOtherAdminPage(): void
    {
        foreach (['clientssummary', 'index', 'configproducts', 'systemmodulelog'] as $filename) {
            self::assertSame(
                '',
                ispconfigconnect_hook_adminServersMetaData(
                    $this->vars($filename),
                    static fn (): array => self::ROWS
                ),
                $filename . ' must not receive the servers-page block'
            );
        }
    }

    /**
     * `filename` is the documented AdminAreaFooterOutput variable, but the
     * request path is a cheap second opinion for builds that name it
     * differently - being wrong there would silently disable the panel.
     */
    public function testFallsBackToTheRequestPathWhenFilenameIsAbsent(): void
    {
        $previous = $_SERVER['REQUEST_URI'] ?? null;
        $_SERVER['REQUEST_URI'] = '/admin/configservers.php?action=manage&id=3';
        try {
            $html = ispconfigconnect_hook_adminServersMetaData([], static fn (): array => self::ROWS);
        } finally {
            if ($previous === null) {
                unset($_SERVER['REQUEST_URI']);
            } else {
                $_SERVER['REQUEST_URI'] = $previous;
            }
        }

        self::assertStringContainsString(MetaDataPanel::PAYLOAD_ELEMENT_ID, $html);
    }

    public function testRendersNothingWhenNoServerUsesThisModule(): void
    {
        self::assertSame(
            '',
            ispconfigconnect_hook_adminServersMetaData(
                $this->vars('configservers'),
                static fn (): array => []
            )
        );
    }

    /** A broken query must degrade to "no panel", never to a broken admin page. */
    public function testADatabaseFailureNeverBreaksTheAdminPage(): void
    {
        $html = ispconfigconnect_hook_adminServersMetaData(
            $this->vars('configservers'),
            static function (): array {
                throw new \RuntimeException('SQLSTATE[42S02]: Base table not found');
            }
        );

        self::assertSame('', $html);
    }

    public function testMalformedRowsAreSkippedInsteadOfFatal(): void
    {
        $html = ispconfigconnect_hook_adminServersMetaData(
            $this->vars('configservers'),
            static fn (): array => [
                ['id' => 1, 'name' => 'x', 'hostname' => 'h1', 'meta_data' => '{bad', 'updated_at' => null],
                ['id' => 2],
            ]
        );

        self::assertStringContainsString(MetaDataPanel::PAYLOAD_ELEMENT_ID, $html);
        self::assertStringContainsString('no data collected yet', $html);
    }

    /**
     * The production path: no injected provider, so the hook falls through to
     * the Capsule reader. It must return a list and never throw - outside
     * WHMCS there is no Capsule class at all, and referencing a missing class
     * would be a fatal Error on every admin page.
     */
    public function testTheDefaultRowReaderNeverThrows(): void
    {
        // No WHMCS database in scope: no servers, hence no block - and no
        // Error for the missing Capsule class.
        self::assertSame([], ispconfigconnect_hook_serverRows());
        self::assertSame('', ispconfigconnect_hook_adminServersMetaData($this->vars('configservers')));
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
