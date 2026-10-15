<?php

declare(strict_types=1);

namespace Ispconfigconnect\Tests\Unit\Server;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WHMCS\Module\Server\Ispconfigconnect\Server\MetaDataPanel;
use WHMCS\Module\Server\Ispconfigconnect\Server\RemoteMetaData;
use WHMCS\Module\Server\Ispconfigconnect\Server\ServerMetaData;

#[CoversClass(MetaDataPanel::class)]
final class MetaDataPanelTest extends TestCase
{
    public function testRendersNothingWhenNoIspconfigServerIsConfigured(): void
    {
        self::assertSame('', MetaDataPanel::render([]));
    }

    public function testCarriesTheStatsForEachServerInAJsonPayload(): void
    {
        $html = MetaDataPanel::render([
            $this->server(3, [
                'ispconfig_version' => '3.3.1p1',
                'pending_jobs' => 4,
                'client_count' => 12,
                'server_count' => 2,
                'ispconfig_status' => RemoteMetaData::STATUS_OK,
            ], '2026-08-24 09:15:00'),
        ]);

        $payload = $this->payload($html);

        self::assertCount(1, $payload);
        self::assertSame(3, $payload[0]['id']);
        self::assertSame('panel3.example.net', $payload[0]['hostname']);

        $lines = $this->lines($payload[0]);
        self::assertSame('3.3.1p1', $lines['ISPConfig Version']);
        self::assertSame('4', $lines['Pending Jobs']);
        self::assertSame('12', $lines['Clients']);
        self::assertSame('2', $lines['ISPConfig Nodes']);
        self::assertSame('2026-08-24 09:15:00', $lines['Last Updated']);
    }

    public function testFallsBackToTheProbeTimestampWhenWhmcsHasNoUpdatedAt(): void
    {
        $html = MetaDataPanel::render([
            $this->server(1, ['checked_at' => '2026-08-24 09:00:00 UTC'], null),
        ]);

        self::assertSame('2026-08-24 09:00:00 UTC', $this->lines($this->payload($html)[0])['Last Updated']);
    }

    public function testAServerWhmcsHasNeverRefreshedSaysSo(): void
    {
        $html = MetaDataPanel::render([$this->server(5, [], null)]);

        $lines = $this->lines($this->payload($html)[0]);
        self::assertArrayHasKey('ISPConfig', $lines);
        self::assertStringContainsString('no data collected yet', $lines['ISPConfig']);
    }

    public function testSurfacesTheProbeDiagnosticSoAFailureIsNotSilent(): void
    {
        $html = MetaDataPanel::render([
            $this->server(2, [
                'ispconfig_version' => '3.3.1p1',
                'ispconfig_status' => RemoteMetaData::STATUS_DEGRADED,
                'ispconfig_error' => 'clients: You do not have the permissions to access this function.',
            ], null),
        ]);

        $payload = $this->payload($html);
        $lines = $this->lines($payload[0]);
        self::assertStringContainsString('permissions', $lines['Probe Errors']);

        $warned = array_values(array_filter(
            $payload[0]['lines'],
            static fn (array $line): bool => ($line['warn'] ?? false) === true
        ));
        self::assertCount(1, $warned);
        self::assertSame('Probe Errors', $warned[0]['label']);
    }

    // ---- injection safety --------------------------------------------------

    public function testHostileValuesCannotBreakOutOfTheJsonScriptBlock(): void
    {
        $html = MetaDataPanel::render([
            $this->server(1, [
                'ispconfig_version' => '</script><script>alert(1)</script>',
                'ispconfig_error' => '<img src=x onerror=alert(2)>',
            ], '"><b>oops</b>'),
        ]);

        self::assertStringNotContainsString('<script>alert(1)', $html);
        self::assertStringNotContainsString('<img src=x', $html);
        self::assertStringNotContainsString('<b>oops', $html);
        // JSON_HEX_TAG keeps every angle bracket out of the payload entirely.
        self::assertStringNotContainsString('</script><script>', $html);

        // ...and the value still arrives intact on the JavaScript side.
        $lines = $this->lines($this->payload($html)[0]);
        self::assertSame('</script><script>alert(1)</script>', $lines['ISPConfig Version']);
    }

    public function testEmitsNoInlineEventHandlers(): void
    {
        $html = MetaDataPanel::render([$this->server(1, ['pending_jobs' => 1], null)]);

        self::assertDoesNotMatchRegularExpression('/\son[a-z]+\s*=/i', $html);
        self::assertStringNotContainsString('javascript:', $html);
    }

    public function testTheInjectedScriptBuildsNodesWithoutInnerHtml(): void
    {
        $html = MetaDataPanel::render([$this->server(1, ['pending_jobs' => 1], null)]);

        // textContent-only DOM building is what makes the payload safe; an
        // innerHTML assignment would reintroduce the escaping burden.
        self::assertStringNotContainsString('innerHTML', $html);
        self::assertStringNotContainsString('document.write', $html);
        self::assertStringContainsString('textContent', $html);
    }

    public function testTheScriptDoesNotAssumeJqueryIsLoaded(): void
    {
        $html = MetaDataPanel::render([$this->server(1, ['pending_jobs' => 1], null)]);

        self::assertStringNotContainsString('jQuery', $html);
        self::assertDoesNotMatchRegularExpression('/(^|[^\w$])\$\(/', $html);
    }

    /**
     * WHMCS writes a server row's edit link relative ("?action=manage&id=N"),
     * and a server group's link (action=managegroup) shares the id namespace.
     * The row matcher must therefore judge the RESOLVED URL and accept only
     * action=manage on configservers.php. The old substring test on the raw
     * attribute took any configservers link with an id - a group link
     * included - and sent every relative one down the fallback path.
     *
     * The same code runs in the live hook file; HooksTest executes it and
     * holds this copy to it byte for byte. Here it is checked in the rendered
     * output, which also proves the heredoc emits each `$` anchor literally
     * rather than eating it.
     */
    public function testTheRowMatcherAnchorsOnlyTheResolvedEditLink(): void
    {
        $html = MetaDataPanel::render([$this->server(1, ['pending_jobs' => 1], null)]);

        self::assertStringContainsString('new URL(href, document.baseURI || window.location.href)', $html);
        self::assertStringContainsString("target.action === 'manage'", $html);
        self::assertStringContainsString('/(?:^|\/)configservers\.php$/i.test(path)', $html);
        self::assertStringContainsString('/^\d+$/.test(id)', $html);
        self::assertStringNotContainsString("href.indexOf('configservers')", $html);
    }

    public function testEveryConfiguredServerGetsItsOwnPayloadEntry(): void
    {
        $html = MetaDataPanel::render([
            $this->server(1, ['pending_jobs' => 1], null),
            $this->server(2, ['pending_jobs' => 9], null),
        ]);

        $payload = $this->payload($html);
        self::assertSame([1, 2], array_column($payload, 'id'));
    }

    // ---- helpers -----------------------------------------------------------

    /** @param array<string, mixed> $values */
    private function server(int $id, array $values, ?string $updatedAt): ServerMetaData
    {
        return new ServerMetaData($id, 'ISPConfig ' . $id, 'panel' . $id . '.example.net', $values, $updatedAt);
    }

    /**
     * @return list<array{id: int, hostname: string, lines: list<array{label: string, value: string, warn?: bool}>}>
     */
    private function payload(string $html): array
    {
        self::assertMatchesRegularExpression(
            '~<script type="application/json" id="ispconfigconnect-server-meta">(.*?)</script>~s',
            $html
        );
        preg_match(
            '~<script type="application/json" id="ispconfigconnect-server-meta">(.*?)</script>~s',
            $html,
            $matches
        );

        /** @var list<array{id: int, hostname: string, lines: list<array{label: string, value: string, warn?: bool}>}> $decoded */
        $decoded = json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }

    /**
     * @param array{lines: list<array{label: string, value: string, warn?: bool}>} $entry
     * @return array<string, string>
     */
    private function lines(array $entry): array
    {
        $lines = [];
        foreach ($entry['lines'] as $line) {
            $lines[$line['label']] = $line['value'];
        }

        return $lines;
    }
}
