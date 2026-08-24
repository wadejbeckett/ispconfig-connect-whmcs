<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Ispconfigconnect\Api;

/**
 * The canonical least-privilege remote_functions grant list (spec 2.4).
 *
 * ISPConfig checks each call against the remote user's grant list via
 * checkPerm() (remoting.inc.php), and the checked grant name is NOT always
 * the called function's name.  Every entry below was verified against the
 * 3.3.1p1 source; the aliases are:
 *   - server_get_all                  -> checks 'server_get'    (remoting.inc.php)
 *   - client_template_additional_add  -> checks 'client_update' (remote.d/client.inc.php)
 *   - client_template_additional_delete -> checks 'client_update' (remote.d/client.inc.php)
 * and client_delete_everything checks its own grant at entry, then ALSO
 * checks 'client_delete' mid-function after cascading the client's objects
 * (remote.d/client.inc.php) - so both grants are required.
 *
 * login/logout are not grant-checked; get_function_list is.
 *
 * Pure data + lookup: no I/O.  docs/REMOTE-USER.md is the operator-facing
 * rendering of requiredGrants().
 */
final class Permissions
{
    /** Called functions whose checkPerm() name differs from their own name. */
    private const ALIASES = [
        'server_get_all' => 'server_get',
        'client_template_additional_add' => 'client_update',
        'client_template_additional_delete' => 'client_update',
    ];

    /**
     * Every remote function the module calls in Phases 1-2 (spec 1.5),
     * excluding login/logout which need no grant.
     */
    private const CALLED_FUNCTIONS = [
        'get_function_list',
        'monitor_jobqueue_count',
        'server_get_all',
        'client_get',
        'client_get_by_username',
        'client_get_sites_by_user',
        'client_add',
        'client_update',
        'client_change_password',
        'client_delete_everything',
        'client_templates_get_all',
        'client_template_additional_add',
        'client_template_additional_delete',
        'sites_web_domain_add',
        'sites_web_domain_get',
        'sites_web_domain_set_status',
        'sites_ftp_user_add',
        'sites_database_user_add',
        'sites_database_add',
        'mail_domain_add',
        'dns_templatezone_add',
        'dns_zone_get_id',
        'quota_get_by_user',
        'trafficquota_get_by_user',
    ];

    /**
     * Grants required beyond the per-call checks: secondary checkPerm() calls
     * inside function bodies.
     */
    private const EXTRA_GRANTS = [
        // client_delete_everything re-checks 'client_delete' after cascading
        // (remote.d/client.inc.php) - without it, termination fails half-way.
        'client_delete',
    ];

    /**
     * The checkPerm() grant name for a function the module calls.
     *
     * @throws \InvalidArgumentException for functions outside the module's
     *         call surface - keeps this list the single source of truth
     */
    public static function grantFor(string $function): string
    {
        if (!in_array($function, self::CALLED_FUNCTIONS, true)) {
            throw new \InvalidArgumentException(
                sprintf('%s is not a remote function this module calls', $function)
            );
        }

        return self::ALIASES[$function] ?? $function;
    }

    /**
     * The complete remote_functions grant list for the module's remote user:
     * deduplicated, sorted.
     *
     * @return list<string>
     */
    public static function requiredGrants(): array
    {
        $grants = array_map(
            static fn (string $function): string => self::ALIASES[$function] ?? $function,
            self::CALLED_FUNCTIONS
        );
        $grants = array_values(array_unique(array_merge($grants, self::EXTRA_GRANTS)));
        sort($grants);

        return $grants;
    }

    /**
     * The safe read-only calls TestConnection / Verify Permissions run.
     * Write grants cannot be probed without creating data, so only these
     * three are verified live; the rest are documentation-checked.
     *
     * @return array<string, array{grant: string, params: array<string, mixed>}>
     */
    public static function readProbes(): array
    {
        return [
            'server_get_all' => ['grant' => self::grantFor('server_get_all'), 'params' => []],
            'client_templates_get_all' => ['grant' => self::grantFor('client_templates_get_all'), 'params' => []],
            'monitor_jobqueue_count' => ['grant' => self::grantFor('monitor_jobqueue_count'), 'params' => ['server_id' => 0]],
        ];
    }
}
