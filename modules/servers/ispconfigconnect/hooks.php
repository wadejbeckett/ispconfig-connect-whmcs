<?php

/**
 * ISPConfig Connect for WHMCS - admin-area hooks.
 *
 * WHMCS detects and loads `hooks.php` from a module's root directory on every
 * page load (developers.whmcs.com/hooks/module-hooks). Note the caveat there:
 * hook files are discovered when the module is activated, so an existing
 * installation must re-save a product's Module Settings tab once for this file
 * to be picked up.
 *
 * Unlike lib/, this file is allowed to talk to WHMCS - add_hook() and
 * WHMCS\Database\Capsule - and it is the ONLY place in the module that does so
 * outside the entry file. Everything it renders comes from
 * Server\MetaDataPanel, which stays pure and unit-tested.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

use WHMCS\Module\Server\Ispconfigconnect\Server\MetaDataPanel;
use WHMCS\Module\Server\Ispconfigconnect\Server\ServerMetaData;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/** tblservers.type of the servers this module manages. */
const ISPCONFIGCONNECT_HOOK_MODULE = 'ispconfigconnect';

/** The admin script that renders Configuration > System Settings > Servers. */
const ISPCONFIGCONNECT_HOOK_SERVERS_PAGE = 'configservers';

add_hook('AdminAreaFooterOutput', 1, 'ispconfigconnect_hook_adminServersMetaData');

/**
 * Injects the cached ISPConfig stats block into Configuration > Servers.
 *
 * AdminAreaFooterOutput "runs on every admin area page load" and "accepts HTML
 * to be output before the closing body tag" (developers.whmcs.com hooks
 * reference), which is exactly what this needs: the servers table is already
 * in the DOM by then, and every other admin page gets an empty string back.
 *
 * NO ISPConfig API call happens here. The values were collected by
 * `ispconfigconnect_GetRemoteMetaData()` and cached by WHMCS in
 * tblservers_remote; probing the panel while rendering a page that lists every
 * server would be slow and could trip its 10-failures-per-5-minutes login
 * lockout.
 *
 * @param array<string, mixed> $vars the documented AdminAreaFooterOutput vars
 * @param null|callable(): list<array<string, mixed>> $rowProvider test seam;
 *        WHMCS always calls this hook with $vars alone
 * @return string HTML, or '' when there is nothing to add
 */
function ispconfigconnect_hook_adminServersMetaData(array $vars, ?callable $rowProvider = null): string
{
    // A hook runs inside somebody else's page render: a Throwable escaping
    // here (a schema change, a missing autoloaded class) would break an admin
    // page that has nothing to do with this module. Degrade to silence.
    try {
        if (!ispconfigconnect_hook_isServersPage($vars)) {
            return '';
        }

        $rows = $rowProvider === null ? ispconfigconnect_hook_serverRows() : $rowProvider();

        $servers = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $servers[] = ServerMetaData::fromRow($row);
            }
        }

        return MetaDataPanel::render($servers);
    } catch (\Throwable) {
        return '';
    }
}

/**
 * True only on the servers page.
 *
 * `filename` is the documented variable (the admin script name without its
 * extension); the request path is a second opinion, because being wrong about
 * the variable would disable the panel silently rather than loudly.
 *
 * @param array<string, mixed> $vars
 */
function ispconfigconnect_hook_isServersPage(array $vars): bool
{
    $filename = is_scalar($vars['filename'] ?? null) ? strtolower(trim((string) $vars['filename'])) : '';
    if ($filename === ISPCONFIGCONNECT_HOOK_SERVERS_PAGE) {
        return true;
    }
    if ($filename !== '') {
        // A named page that is not the servers page: trust it and stop.
        return false;
    }

    $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);

    return is_string($path)
        && strtolower(basename($path)) === ISPCONFIGCONNECT_HOOK_SERVERS_PAGE . '.php';
}

/**
 * Every WHMCS server running this module, with its cached remote metadata.
 *
 * One query per admin servers-page render, no matter how many servers exist.
 * `tblservers_remote` is LEFT JOINed because a server WHMCS has never
 * refreshed has no row there yet - the panel then says so instead of
 * disappearing.
 *
 * @return list<array<string, mixed>>
 */
function ispconfigconnect_hook_serverRows(): array
{
    if (!class_exists('\\WHMCS\\Database\\Capsule')) {
        return [];
    }

    try {
        $rows = \WHMCS\Database\Capsule::table('tblservers')
            ->leftJoin('tblservers_remote', 'tblservers_remote.server_id', '=', 'tblservers.id')
            ->where('tblservers.type', ISPCONFIGCONNECT_HOOK_MODULE)
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
