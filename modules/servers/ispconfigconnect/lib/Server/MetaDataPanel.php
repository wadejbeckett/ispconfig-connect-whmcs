<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Ispconfigconnect\Server;

/**
 * The "ISPConfig Version / Pending Jobs / ..." block shown under each
 * ISPConfig server on Configuration > Servers.
 *
 * WHMCS core renders its own styled `<div class="remote-meta-data">` under the
 * hostname for bundled modules (Plesk shows "Plesk Version / Load Averages /
 * License Max # of Accounts" there). That block did NOT appear for this module
 * even when GetRemoteMetaData returned Plesk's exact key vocabulary, and its
 * label strings exist in no plain file of the install - it is rendered from
 * ionCube-encoded core and appears gated to bundled modules. So this class
 * builds the equivalent ourselves, injected through the documented
 * AdminAreaFooterOutput hook ("Accepts HTML to be output before the closing
 * body tag of the admin area output").
 *
 * Output shape: one `<script type="application/json">` payload, one `<style>`,
 * and one small vanilla-JS block that places a node per server row.
 *
 * ## Why the data travels as JSON rather than as ready-made HTML
 *
 * Every value here originates on the ISPConfig side (a version string, a probe
 * error message) and is therefore untrusted. Encoding it with JSON_HEX_TAG and
 * friends leaves no `<`, `>`, `&`, `"` or `'` anywhere in the document, and the
 * script writes it into the DOM through textContent only - never innerHTML - so
 * there is no HTML parsing step for a hostile value to escape from. No inline
 * event handlers, no jQuery assumption, no network calls.
 *
 * Pure rendering: no WHMCS calls, no I/O, no API calls. Reading the cached
 * values out of tblservers_remote is the hook file's job - Configuration >
 * Servers renders one row per server, and probing the panel from there would be
 * slow and could trip its login lockout.
 *
 * ## Status: not wired to the live panel
 *
 * The only caller was modules/servers/ispconfigconnect/hooks.php, which was
 * deleted once WHMCS 9.0.6 proved it never loads a server module's hooks.php.
 * The panel an operator actually sees is rendered by the self-contained
 * hooks/ispconfigconnect.php, which installs into <whmcs>/includes/hooks and
 * cannot rely on this class - a file in that directory has no guarantee the
 * module's PSR-4 autoloader is registered.
 *
 * This class is kept because it is the pure, unit-testable statement of the
 * same contract, and RemoteMetaDataTest round-trips stored meta_data through it.
 * FIELDS below must therefore stay in step with
 * ISPCONFIGCONNECT_METAPANEL_FIELDS in the hook file; they have drifted once.
 */
final class MetaDataPanel
{
    public const PAYLOAD_ELEMENT_ID = 'ispconfigconnect-server-meta';
    public const BLOCK_CLASS = 'ispconfigconnect-meta';

    /**
     * meta_data key => label, in display order. `service_count` is deliberately
     * absent: WHMCS core already renders it as "Services: N" in the same row's
     * Remote Usage Stats cell (live-verified), and repeating it would read as
     * two different numbers.
     */
    private const FIELDS = [
        'ispconfig_version' => 'ISPConfig Version',
        'pending_jobs' => 'Pending Jobs',
        'client_count' => 'Clients',
        'server_count' => 'ISPConfig Nodes',
    ];

    private const NO_DATA_LABEL = 'ISPConfig';
    private const NO_DATA_TEXT = 'no data collected yet - use Test Connection, or wait for the next refresh';

    private function __construct()
    {
    }

    /**
     * @param list<ServerMetaData> $servers every server whose module is this one
     * @return string HTML for the footer hook; '' when there is nothing to show
     */
    public static function render(array $servers): string
    {
        $payload = [];
        foreach ($servers as $server) {
            $payload[] = [
                'id' => $server->serverId,
                'hostname' => $server->hostname,
                'lines' => self::linesFor($server),
            ];
        }

        if ($payload === []) {
            return '';
        }

        // JSON_HEX_* keeps every character that is special to the HTML parser
        // out of the document; INVALID_UTF8_SUBSTITUTE guarantees encoding
        // succeeds even if the panel answered with broken bytes, because this
        // runs while an admin page is being rendered.
        $json = json_encode(
            $payload,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
            | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        );
        if ($json === false) {
            return '';
        }

        return '<script type="application/json" id="' . self::PAYLOAD_ELEMENT_ID . '">' . $json . "</script>\n"
            . '<style>' . self::css() . "</style>\n"
            . '<script>' . self::script() . "</script>\n";
    }

    /**
     * @return list<array{label: string, value: string, warn?: bool}>
     */
    private static function linesFor(ServerMetaData $server): array
    {
        $lines = [];
        foreach (self::FIELDS as $key => $label) {
            $value = $server->values[$key] ?? null;
            if (is_scalar($value) && trim((string) $value) !== '') {
                $lines[] = ['label' => $label, 'value' => trim((string) $value)];
            }
        }

        // WHMCS's own tblservers_remote.updated_at is authoritative for "when
        // did WHMCS last refresh this"; checked_at is the module's own stamp
        // and only fills in when the column is empty.
        $checkedAt = $server->values['checked_at'] ?? null;
        $lastUpdated = $server->updatedAt ?? (is_scalar($checkedAt) ? trim((string) $checkedAt) : '');
        if ((string) $lastUpdated !== '') {
            $lines[] = ['label' => 'Last Updated', 'value' => (string) $lastUpdated];
        }

        // A failed probe must never be silent: RemoteMetaData drops the key it
        // could not read and says why here instead.
        $error = $server->values['ispconfig_error'] ?? null;
        if (is_scalar($error) && trim((string) $error) !== '') {
            $lines[] = ['label' => 'Probe Errors', 'value' => trim((string) $error), 'warn' => true];
        }

        if ($lines === []) {
            $lines[] = ['label' => self::NO_DATA_LABEL, 'value' => self::NO_DATA_TEXT];
        }

        return $lines;
    }

    private static function css(): string
    {
        return '.' . self::BLOCK_CLASS . '{margin-top:5px;font-size:11px;line-height:1.5;color:#6c757d}'
            . '.' . self::BLOCK_CLASS . ' .' . self::BLOCK_CLASS . '-label{font-weight:600}'
            . '.' . self::BLOCK_CLASS . ' .' . self::BLOCK_CLASS . '-warn{color:#b02a37}';
    }

    /**
     * Vanilla JS, defensively written: no library is assumed beyond the DOM
     * (the admin theme's own scripts are none of our business), every lookup is
     * null-checked, and placing the block is skipped rather than guessed when
     * the row cannot be identified.
     *
     * Rows are matched exactly as the hook file matches them - serverLinks()
     * and linkTarget() are the same code, and HooksTest holds the two to that:
     * the server's own edit link (configservers.php, action=manage, numeric
     * `id`) read from the RESOLVED URL, because WHMCS writes it relative; never
     * any other configservers.php link, since a server group's
     * action=managegroup shares the id namespace; a ?id=N link to some other
     * script only inside a table; and last a cell whose text is exactly the
     * hostname. Insertion is idempotent per cell, so a second run
     * (DOMContentLoaded) cannot duplicate the block.
     */
    private static function script(): string
    {
        $payloadId = self::PAYLOAD_ELEMENT_ID;
        $blockClass = self::BLOCK_CLASS;

        return <<<JS
            (function () {
                function serverLinks() {
                    var preferred = {}, fallback = {}, links = document.getElementsByTagName('a');
                    for (var i = 0; i < links.length; i++) {
                        var link = links[i], target = linkTarget(link);
                        if (!target) { continue; }
                        if (target.serversScript) {
                            // Only the per-server EDIT link (action=manage) is an
                            // unambiguous anchor for a server row, and it exists
                            // wherever the row is, so a redesigned (non-table)
                            // layout still resolves. configservers.php with
                            // action=managegroup (server groups) shares the id
                            // namespace, so any configservers link that is NOT
                            // action=manage is ignored outright - never taken as a
                            // row anchor and never used as a fallback either.
                            if (target.action === 'manage' && !preferred[target.id]) {
                                preferred[target.id] = link;
                            }
                        } else if (!fallback[target.id] && link.closest && link.closest('table')) {
                            // Some other ?id=N link, accepted only inside a table -
                            // sidebar and breadcrumb links must never be mistaken
                            // for a server row.
                            fallback[target.id] = link;
                        }
                    }
                    for (var key in fallback) {
                        if (!preferred[key]) { preferred[key] = fallback[key]; }
                    }
                    return preferred;
                }

                /**
                 * Where a link really goes, resolved exactly as the browser
                 * resolves it on a click. WHMCS writes the per-row edit link
                 * RELATIVE - href="?action=manage&id=N", with no script name at
                 * all - so the raw attribute cannot say which script it targets
                 * (and a substring test on it silently sent every relative link,
                 * group links included, down the unfiltered fallback path).
                 * Resolving it answers that for the relative, root-relative and
                 * absolute forms alike.
                 *
                 * A link counts as the servers script when it names
                 * configservers.php, or points back at this very page under
                 * whatever URL the page was served (the PHP side only emits this
                 * script on the servers list).
                 *
                 * null - never a guess - when the href is missing or unparsable,
                 * carries no purely numeric id, or the browser has no URL
                 * parser; the row then falls back to the hostname-text match,
                 * which places the block but never rewrites core's Accounts cell.
                 */
                function linkTarget(link) {
                    var href = link.getAttribute('href');
                    if (!href || typeof URL !== 'function') { return null; }
                    var url;
                    try {
                        url = new URL(href, document.baseURI || window.location.href);
                    } catch (err) {
                        return null;
                    }
                    if (!url.searchParams) { return null; }
                    var id = url.searchParams.get('id');
                    if (!id || !/^\d+\$/.test(id)) { return null; }
                    var path = url.pathname || '';
                    return {
                        id: id,
                        action: url.searchParams.get('action'),
                        serversScript: /(?:^|\/)configservers\.php\$/i.test(path)
                            || path === window.location.pathname
                    };
                }

                function cellWithExactText(text) {
                    if (!text) { return null; }
                    var cells = document.getElementsByTagName('td');
                    for (var c = 0; c < cells.length; c++) {
                        if ((cells[c].textContent || '').replace(/^\s+|\s+\$/g, '') === text) { return cells[c]; }
                    }
                    return null;
                }

                function buildBlock(lines) {
                    var box = document.createElement('div');
                    box.className = '{$blockClass}';
                    for (var i = 0; i < lines.length; i++) {
                        var line = lines[i];
                        if (!line) { continue; }
                        var row = document.createElement('div');
                        if (line.warn) { row.className = '{$blockClass}-warn'; }
                        var label = document.createElement('span');
                        label.className = '{$blockClass}-label';
                        label.textContent = String(line.label) + ': ';
                        row.appendChild(label);
                        row.appendChild(document.createTextNode(String(line.value)));
                        box.appendChild(row);
                    }
                    return box;
                }

                function run() {
                    var holder = document.getElementById('{$payloadId}');
                    if (!holder) { return; }
                    var servers;
                    try { servers = JSON.parse(holder.textContent || '[]'); } catch (err) { return; }
                    if (!servers || typeof servers.length !== 'number') { return; }

                    var links = serverLinks();
                    for (var s = 0; s < servers.length; s++) {
                        var server = servers[s];
                        if (!server || !server.lines || !server.lines.length) { continue; }
                        var link = links[String(server.id)];
                        var cell = link && link.closest ? link.closest('td') : null;
                        if (!cell && link) { cell = link.parentNode; }
                        if (!cell) { cell = cellWithExactText(server.hostname); }
                        if (!cell || cell.querySelector('.{$blockClass}')) { continue; }
                        cell.appendChild(buildBlock(server.lines));
                    }
                }

                if (document.readyState === 'loading') {
                    document.addEventListener('DOMContentLoaded', run);
                } else {
                    run();
                }
            })();
            JS;
    }
}
