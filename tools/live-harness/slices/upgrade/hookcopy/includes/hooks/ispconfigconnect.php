<?php

/**
 * ISPConfig Connect for WHMCS - the servers-page metadata panel.
 *
 * INSTALL LOCATION: <whmcs>/includes/hooks/ispconfigconnect.php
 * (`hooks/install-hook.sh /path/to/whmcs`, or copy it there by hand).
 * It ships inside the module only so the zip carries it; WHMCS never loads it
 * from where it ships.
 *
 * ## Why it lives in includes/hooks and not in the module
 *
 * LIVE-PROVEN on WHMCS 9.0.6: WHMCS does NOT load
 * `modules/servers/<module>/hooks.php`. A file-level marker written into the
 * deployed copy never fired across many admin page loads, the Servers page
 * included. `includes/hooks/*.php` is the documented, universal loader that
 * does run, so the panel ships as a file for that directory instead.
 *
 * ## Why it renders the block itself
 *
 * Core's own styled `<div class="remote-meta-data">` under a server's hostname
 * (Plesk fills it with "Plesk Version / Load Averages / License Max # of
 * Accounts") is rendered by ionCube-encoded core and is gated to BUNDLED
 * modules. Proven by elimination: with tblservers_remote.meta_data hand-filled
 * with Plesk's exact key vocabulary and a fresh updated_at, our row still
 * rendered no block while Plesk's rendered normally on the same page. So this
 * file builds the equivalent and injects it through AdminAreaFooterOutput,
 * which "accepts HTML to be output before the closing body tag".
 *
 * ## What it may cost
 *
 * This file is loaded on EVERY page. It therefore returns before doing anything
 * at all unless the current page is Configuration > Servers; spends at most ONE
 * database query when it does run; makes NO ISPConfig API call (the values were
 * collected by ispconfigconnect_GetRemoteMetaData() and cached by WHMCS in
 * tblservers_remote - probing the panel while rendering a page that lists every
 * server would be slow and could trip its 10-failures-per-5-minutes login
 * lockout); and never lets a Throwable escape into somebody else's page render.
 *
 * ## Why it duplicates nothing from the module's lib/
 *
 * A file in includes/hooks cannot assume the module's PSR-4 autoloader is
 * registered - that is a module-invocation concern, and this runs outside any
 * module call. Self-contained is the only shape that is certain to work, so
 * every helper it needs is right here.
 *
 * Function names are deliberately unlike the ones the retired
 * modules/servers/ispconfigconnect/hooks.php used: an upgraded install that
 * still has that stale file on disk can never collide with this one.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

// @ispconfigconnect-hook-file@ - install-hook.sh treats this marker as proof
// that it wrote the file, and refuses to touch a file that lacks it.

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

// LOAD-ONCE GUARD, part 1 of 2 - the runtime half.
//
// WHMCS includes EVERY includes/hooks/*.php, so a second copy of this file left
// in that directory under any other .php name - the `ispconfigconnect-old.php`
// an operator keeps across an upgrade - gets loaded twice. This file is loaded
// on every admin AND client page, so a redeclaration here would take the whole
// billing system down rather than just the panel.
//
// `return` at file scope ends only this include, and it is enough for the
// constants and the hook registration below because those are runtime
// statements. It is NOT enough for the functions - see part 2.
if (defined('ISPCONFIGCONNECT_METAPANEL_MARKER')) {
    return;
}

/** The installer's ownership marker; see the comment above. */
const ISPCONFIGCONNECT_METAPANEL_MARKER = '@ispconfigconnect-hook-file@';

/** tblservers.type of the servers this module manages. */
const ISPCONFIGCONNECT_METAPANEL_MODULE = 'ispconfigconnect';

/** The admin script that renders Configuration > System Settings > Servers. */
const ISPCONFIGCONNECT_METAPANEL_PAGE = 'configservers';

/** id of the JSON payload element, and the class the block is styled by. */
const ISPCONFIGCONNECT_METAPANEL_ID = 'ispconfigconnect-server-meta';
const ISPCONFIGCONNECT_METAPANEL_CLASS = 'ispconfigconnect-meta';

/**
 * meta_data key => label, in display order.
 *
 * `service_count` is deliberately absent: WHMCS core already renders it itself
 * as "Services: N" in the same row's Remote Usage Stats cell (live-verified),
 * and it now carries the ISPConfig CLIENT count - the same number this block
 * prints as "Clients" - so rendering it here too would show one fact twice
 * under two names and read as two different facts.
 *
 * `website_count` is absent because it no longer exists AT ALL: counting vhosts
 * cost a `sites_web_domain_get(-1)` bulk read that dragged every site's TLS
 * private key across the wire, so the key was removed from the payload rather
 * than approximated (Server\RemoteMetaData, "Why there is no website count").
 * Nothing in tblservers_remote carries it any more.
 *
 * This list is the contract docs/INSTALL.md documents to operators, and the one
 * Server\MetaDataPanel renders; keep the three in step.
 */
const ISPCONFIGCONNECT_METAPANEL_FIELDS = [
    'ispconfig_version' => 'ISPConfig Version',
    'pending_jobs' => 'Pending Jobs',
    'client_count' => 'Clients',
    'server_count' => 'ISPConfig Nodes',
];

if (function_exists('add_hook')) {
    add_hook('AdminAreaFooterOutput', 1, 'ispconfigconnect_metaPanelFooterOutput');
}

// LOAD-ONCE GUARD, part 2 of 2 - the compile-time half.
//
// PHP early-binds functions declared unconditionally at the top level of a
// file: they are declared when the file is COMPILED, before the `return` above
// ever executes, so the runtime guard cannot protect them. Declaring them
// inside a conditional block defers binding to when the block runs, which the
// runtime guard has already prevented on a second load. Verified, not assumed -
// HooksTest::testLoadingTheFileTwiceIsHarmlessRatherThanAFatal loads this file
// twice in a subprocess and fails on a fatal or a warning.
if (!function_exists('ispconfigconnect_metaPanelFooterOutput')) {
    /**
     * Injects the cached ISPConfig stats block into Configuration > Servers.
     *
     * @param array<string, mixed> $vars the documented AdminAreaFooterOutput vars
     * @param null|callable(): list<array<string, mixed>> $rowProvider test seam;
     *        WHMCS always calls this hook with $vars alone
     * @param null|int $now unix time, injectable so "4 minutes ago" is testable
     * @return string HTML, or '' when there is nothing to add
     */
    function ispconfigconnect_metaPanelFooterOutput(
        array $vars,
        ?callable $rowProvider = null,
        ?int $now = null
    ): string {
        // A hook runs inside somebody else's page render: a Throwable escaping here
        // (a schema change, a PHP version quirk) would break an admin page that has
        // nothing to do with this module. Degrade to silence.
        try {
            // Cheapest possible check first, and before any I/O - every admin page
            // in the install pays for whatever happens above this line.
            if (!ispconfigconnect_metaPanelIsServersPage($vars)) {
                return '';
            }

            $rows = $rowProvider === null ? ispconfigconnect_metaPanelRows() : $rowProvider();

            return ispconfigconnect_metaPanelRender(
                ispconfigconnect_metaPanelPayload($rows, $now ?? time())
            );
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * True only on the servers page.
     *
     * `filename` is the documented AdminAreaFooterOutput variable (the admin script
     * name without its extension). The request path is checked too, and NEITHER
     * signal may veto the other: this whole module already lost a release to an
     * assumption about WHMCS internals that turned out to be wrong, and an
     * unexpected `filename` value must degrade to "check the URL", not to "never
     * render". Only the basename is compared, so an install with a renamed admin
     * directory still matches.
     *
     * Both checks are string work on values already in memory - no I/O - which is
     * what makes them affordable on every admin page load.
     *
     * @param array<string, mixed> $vars
     */
    function ispconfigconnect_metaPanelIsServersPage(array $vars): bool
    {
        $filename = $vars['filename'] ?? null;
        $filename = is_scalar($filename) ? strtolower(trim((string) $filename)) : '';

        if ($filename === ISPCONFIGCONNECT_METAPANEL_PAGE) {
            return true;
        }

        $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);

        return is_string($path)
            && strtolower(basename($path)) === ISPCONFIGCONNECT_METAPANEL_PAGE . '.php';
    }

    /**
     * Every WHMCS server running this module, with its cached remote metadata.
     *
     * ONE query per servers-page render, no matter how many servers exist, and the
     * `tblservers.type` filter is what keeps other vendors' rows untouched.
     * `tblservers_remote` is LEFT JOINed because a server WHMCS has never refreshed
     * has no row there yet.
     *
     * @param null|callable(): object $tableFactory test seam returning a query
     *        builder; production passes nothing and gets Capsule's
     * @return list<array<string, mixed>>
     */
    function ispconfigconnect_metaPanelRows(?callable $tableFactory = null): array
    {
        try {
            if ($tableFactory === null) {
                // Referencing a class WHMCS may have moved would be a fatal Error
                // on an admin page, so ask before touching it.
                if (!class_exists('\\WHMCS\\Database\\Capsule')) {
                    return [];
                }
                $query = \WHMCS\Database\Capsule::table('tblservers');
            } else {
                $query = $tableFactory();
            }

            $rows = $query
                ->leftJoin('tblservers_remote', 'tblservers_remote.server_id', '=', 'tblservers.id')
                ->where('tblservers.type', ISPCONFIGCONNECT_METAPANEL_MODULE)
                ->get([
                    'tblservers.id as id',
                    'tblservers.name as name',
                    'tblservers.hostname as hostname',
                    'tblservers_remote.meta_data as meta_data',
                    'tblservers_remote.updated_at as updated_at',
                ]);

            if (!is_iterable($rows)) {
                return [];
            }

            $result = [];
            foreach ($rows as $row) {
                if (!is_array($row) && !is_object($row)) {
                    continue;
                }
                $columns = (array) $row;
                $result[] = [
                    'id' => $columns['id'] ?? null,
                    'name' => $columns['name'] ?? null,
                    'hostname' => $columns['hostname'] ?? null,
                    'meta_data' => $columns['meta_data'] ?? null,
                    'updated_at' => $columns['updated_at'] ?? null,
                ];
            }

            return $result;
        } catch (\Throwable) {
            // Column/table changes across WHMCS versions must cost the panel, not
            // the page.
            return [];
        }
    }

    /**
     * Turns database rows into the per-server blocks the page will show.
     *
     * A row with nothing usable behind it is dropped rather than rendered as an
     * empty box: core already lists the server, and a placeholder under it would
     * only look broken.
     *
     * @param list<array<string, mixed>> $rows
     * @param int $now unix time
     * @return list<array{
     *     id: int,
     *     hostname: string,
     *     lines: list<array{label: string, value: string, warn?: bool, hint?: string}>,
     *     accounts?: int
     * }>
     */
    function ispconfigconnect_metaPanelPayload(array $rows, int $now): array
    {
        $payload = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $values = ispconfigconnect_metaPanelValues($row['meta_data'] ?? null);
            $lines = [];

            foreach (ISPCONFIGCONNECT_METAPANEL_FIELDS as $key => $label) {
                $value = $values[$key] ?? null;
                if (is_scalar($value) && trim((string) $value) !== '') {
                    $lines[] = ['label' => $label, 'value' => trim((string) $value)];
                }
            }

            // Absent, null, or any non-scalar means "the SSO probe itself
            // failed", which is not the same as "not installed" - say nothing
            // rather than the wrong thing. is_scalar() rather than `!== null` so
            // a malformed meta_data (an array where a flag belongs) is treated
            // as the non-answer it is instead of being read as a definite "no".
            // Three states, because "not installed" and "we were not allowed
            // to look" need different actions from the admin. GetRemoteMetaData
            // caches sso_state for exactly this; sso_available is the older
            // boolean and is absent when the probe was refused, so it is only
            // the fallback for a row cached before sso_state existed.
            $ssoState = $values['sso_state'] ?? null;
            $sso = $values['sso_available'] ?? null;
            if (is_scalar($ssoState) && (string) $ssoState !== '') {
                $label = match ((string) $ssoState) {
                    'installed' => 'Available',
                    'absent' => 'Not installed',
                    'unknown' => 'Cannot tell - grant get_function_list to this server\'s remote user',
                    default => '',
                };
                if ($label !== '') {
                    $lines[] = ['label' => 'Single Sign-On', 'value' => $label];
                }
            } elseif (is_scalar($sso)) {
                $lines[] = [
                    'label' => 'Single Sign-On',
                    'value' => ispconfigconnect_metaPanelIsTruthy($sso) ? 'Available' : 'Not installed',
                ];
            }

            $error = $values['ispconfig_error'] ?? null;
            $error = is_scalar($error) ? trim((string) $error) : '';

            if ($lines === [] && $error === '') {
                continue;
            }

            // WHMCS's own tblservers_remote.updated_at is authoritative for "when
            // did WHMCS last refresh this"; checked_at is the module's own stamp
            // and only fills in when the column is NULL (a server WHMCS has never
            // refreshed has no tblservers_remote row at all).
            $stamp = $row['updated_at'] ?? null;
            $stamp = is_scalar($stamp) ? trim((string) $stamp) : '';
            if ($stamp === '') {
                $checkedAt = $values['checked_at'] ?? null;
                $stamp = is_scalar($checkedAt) ? trim((string) $checkedAt) : '';
            }
            if ($stamp !== '') {
                $relative = ispconfigconnect_metaPanelAgo($stamp, $now);
                $lines[] = $relative === ''
                    ? ['label' => 'Last Updated', 'value' => $stamp]
                    : ['label' => 'Last Updated', 'value' => $relative, 'hint' => $stamp];
            }

            if ($error !== '') {
                $lines[] = ['label' => 'Probe Errors', 'value' => $error, 'warn' => true];
            }

            $id = $row['id'] ?? null;
            $hostname = $row['hostname'] ?? null;
            $entry = [
                'id' => (int) (is_scalar($id) ? $id : 0),
                'hostname' => is_scalar($hostname) ? trim((string) $hostname) : '',
                'lines' => $lines,
            ];

            // The live client count, for the "Accounts: N" cell core would
            // otherwise print as 0 forever (see correctAccountCount() in the
            // script). Only sent when the probe actually answered.
            $clients = $values['client_count'] ?? null;
            if (is_int($clients) || (is_string($clients) && ctype_digit($clients))) {
                $entry['accounts'] = (int) $clients;
            }

            $payload[] = $entry;
        }

        return $payload;
    }

    /**
     * Decodes tblservers_remote.meta_data, tolerating every shape it can arrive in:
     * a NULL join, a JSON string, an already-decoded array (driver-dependent), and
     * corrupt or truncated JSON. Never throws.
     *
     * @return array<string, mixed>
     */
    function ispconfigconnect_metaPanelValues(mixed $metaData): array
    {
        if (is_string($metaData)) {
            $metaData = trim($metaData) === '' ? null : json_decode($metaData, true);
        }

        // A scalar or a JSON list is not the flat map GetRemoteMetaData returns.
        if (!is_array($metaData) || array_is_list($metaData)) {
            return [];
        }

        $values = [];
        foreach ($metaData as $key => $value) {
            $values[(string) $key] = $value;
        }

        return $values;
    }

    /** JSON and the database each have their own idea of "true". */
    function ispconfigconnect_metaPanelIsTruthy(mixed $value): bool
    {
        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
        }

        return $value === true || (is_int($value) && $value !== 0) || (is_float($value) && $value !== 0.0);
    }

    /**
     * "4 minutes ago" for a database timestamp, or '' when no honest relative
     * answer exists and the caller should print the absolute value instead.
     *
     * tblservers_remote.updated_at carries no timezone, so a WHMCS whose MySQL
     * session zone differs from PHP's default can hand back a stamp in the future.
     * A minute of skew is noise; more than that would make "just now" a lie.
     */
    function ispconfigconnect_metaPanelAgo(string $stamp, int $now): string
    {
        $stamp = trim($stamp);
        if ($stamp === '' || $stamp === '0000-00-00 00:00:00') {
            return '';
        }

        $timestamp = strtotime($stamp);
        if ($timestamp === false) {
            return '';
        }

        $elapsed = $now - $timestamp;
        if ($elapsed < -60) {
            return '';
        }
        if ($elapsed < 60) {
            return 'just now';
        }

        foreach ([[86400, 'day'], [3600, 'hour'], [60, 'minute']] as [$seconds, $unit]) {
            if ($elapsed >= $seconds) {
                $count = intdiv($elapsed, $seconds);

                return $count . ' ' . $unit . ($count === 1 ? '' : 's') . ' ago';
            }
        }

        return '';
    }

    /**
     * The HTML AdminAreaFooterOutput will print before </body>.
     *
     * ## Why the data travels as JSON rather than as ready-made HTML
     *
     * Every value here originates on the ISPConfig side (a version string, a probe
     * error message) and is therefore untrusted. Encoding it with JSON_HEX_TAG and
     * friends leaves no `<`, `>`, `&`, `"` or `'` anywhere in the document, and the
     * script writes it into the DOM through textContent and setAttribute only -
     * never innerHTML - so there is no HTML parsing step for a hostile value to
     * escape from. No inline event handlers, no jQuery assumption, no network call.
     *
     * @param list<array{
     *     id: int,
     *     hostname: string,
     *     lines: list<array{label: string, value: string, warn?: bool, hint?: string}>
     * }> $payload
     */
    function ispconfigconnect_metaPanelRender(array $payload): string
    {
        if ($payload === []) {
            return '';
        }

        // INVALID_UTF8_SUBSTITUTE guarantees encoding succeeds even if the panel
        // answered with broken bytes, because this runs mid page render.
        $json = json_encode(
            $payload,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
            | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        );
        if ($json === false) {
            return '';
        }

        return '<script type="application/json" id="' . ISPCONFIGCONNECT_METAPANEL_ID . '">' . $json . "</script>\n"
            . '<style>' . ispconfigconnect_metaPanelCss() . "</style>\n"
            . '<script>' . ispconfigconnect_metaPanelScript() . "</script>\n";
    }

    /**
     * Deliberately close to core's own block: the same small grey type under the
     * hostname that the bundled modules get. `remote-meta-data` is core's class, so
     * where core styles it we inherit that look; our own class carries the
     * fallback for where it does not.
     */
    function ispconfigconnect_metaPanelCss(): string
    {
        $class = ISPCONFIGCONNECT_METAPANEL_CLASS;

        return '.' . $class . '{margin-top:5px;font-size:11px;line-height:1.5;color:#6c757d}'
            . '.' . $class . ' .' . $class . '-label{font-weight:600}'
            . '.' . $class . ' .' . $class . '-warn{color:#b02a37}';
    }

    /**
     * Vanilla JS, defensively written: no library is assumed beyond the DOM (the
     * admin theme's own scripts are none of our business), every lookup is
     * null-checked, and placing the block is skipped rather than guessed when the
     * row cannot be identified.
     *
     * Rows are matched on the numeric `id` query parameter of a link inside a table
     * - that is how the servers list links each row to its edit page - and fall
     * back to a cell whose text is exactly the hostname. Insertion is idempotent
     * per cell, so a second run cannot duplicate the block.
     */
    function ispconfigconnect_metaPanelScript(): string
    {
        $js = <<<'JS'
            (function () {
                function serverLinks() {
                    var preferred = {}, fallback = {}, links = document.getElementsByTagName('a');
                    for (var i = 0; i < links.length; i++) {
                        var link = links[i], href = link.getAttribute('href');
                        if (!href) { continue; }
                        var match = /[?&]id=(\d+)(?:&|#|$)/.exec(href);
                        if (!match) { continue; }
                        if (href.indexOf('configservers') !== -1) {
                            // A link to this server's own edit page: unambiguous
                            // wherever it sits, so a redesigned (non-table) layout
                            // still resolves.
                            if (!preferred[match[1]]) { preferred[match[1]] = link; }
                        } else if (!fallback[match[1]] && link.closest && link.closest('table')) {
                            // Some other ?id=N link, accepted only inside a table -
                            // sidebar and breadcrumb links must never be mistaken
                            // for a server row.
                            fallback[match[1]] = link;
                        }
                    }
                    for (var key in fallback) {
                        if (!preferred[key]) { preferred[key] = fallback[key]; }
                    }
                    return preferred;
                }

                function cellWithExactText(text) {
                    if (!text) { return null; }
                    var cells = document.getElementsByTagName('td');
                    for (var c = 0; c < cells.length; c++) {
                        if ((cells[c].textContent || '').replace(/^\s+|\s+$/g, '') === text) { return cells[c]; }
                    }
                    return null;
                }

                function buildBlock(lines) {
                    var box = document.createElement('div');
                    box.className = 'remote-meta-data __CLASS__';
                    for (var i = 0; i < lines.length; i++) {
                        var line = lines[i];
                        if (!line) { continue; }
                        var row = document.createElement('div');
                        if (line.warn) { row.className = '__CLASS__-warn'; }
                        if (line.hint) { row.setAttribute('title', String(line.hint)); }
                        var label = document.createElement('span');
                        label.className = '__CLASS__-label';
                        label.textContent = String(line.label) + ': ';
                        row.appendChild(label);
                        row.appendChild(document.createTextNode(String(line.value)));
                        box.appendChild(row);
                    }
                    return box;
                }

                function run() {
                    var holder = document.getElementById('__ID__');
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
                        if (!cell || cell.querySelector('.__CLASS__')) { continue; }
                        cell.appendChild(buildBlock(server.lines));
                        correctAccountCount(cell, server);
                    }
                }

                /**
                 * Core prints "Accounts: N" from tblservers_remote.num_accounts,
                 * a column no module can write: it is reset to 0 on every
                 * metadata refresh and only core's own sync process() step ever
                 * fills it, so for this module it reads 0 forever (see
                 * docs/IMPLEMENTATION-NOTES.md, "WHMCS servers page"). We know
                 * the true figure - it was just probed live - so put it in the
                 * cell rather than leave a permanently wrong zero on the page.
                 *
                 * Strictly presentational, strictly our own rows: the Services
                 * figure beside it is core's and is left untouched, and if the
                 * cell is not in the shape core renders, nothing happens.
                 */
                function correctAccountCount(hostnameCell, server) {
                    if (typeof server.accounts !== 'number') { return; }
                    var row = hostnameCell.parentNode;
                    if (!row || !row.getElementsByClassName) { return; }
                    var counts = row.getElementsByClassName('server-usage-count');
                    if (!counts || !counts.length) { return; }
                    var cell = counts[0];
                    var nodes = cell.childNodes;
                    for (var n = 0; n < nodes.length; n++) {
                        var node = nodes[n];
                        if (node.nodeType !== 3) { continue; }
                        var text = node.nodeValue || '';
                        if (!/^\s*Accounts:\s*\d+\s*$/.test(text)) { continue; }
                        node.nodeValue = text.replace(/(\d+)/, String(server.accounts));
                        cell.setAttribute('title', 'Live count from the ISPConfig panel');
                        return;
                    }
                }

                if (document.readyState === 'loading') {
                    document.addEventListener('DOMContentLoaded', run);
                } else {
                    run();
                }
            })();
            JS;

        return strtr($js, [
            '__ID__' => ISPCONFIGCONNECT_METAPANEL_ID,
            '__CLASS__' => ISPCONFIGCONNECT_METAPANEL_CLASS,
        ]);
    }
}
