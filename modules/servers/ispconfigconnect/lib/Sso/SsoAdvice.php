<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Ispconfigconnect\Sso;

/**
 * What every surface says about single sign-on, in one place.
 *
 * ## Why the wording is a class and not five string literals
 *
 * Four admin surfaces report the same fact - Test Connection, Verify
 * Permissions, the admin service tab and the module log - and each of them used
 * to word it slightly differently, or not at all. The states are not
 * interchangeable: each one sends an operator somewhere DIFFERENT, and the two
 * that look alike are exactly the two that must never be confused.
 *
 *   installed      nothing to do; the button is offered to customers on this
 *                  server. Presence is NOT permission (see below).
 *   absent         install the separate ispconfig-sso project on the PANEL.
 *   probe denied   tick get_function_list ("Server functions"); the panel could
 *                  not be asked at all, which is not the same as "no add-on".
 *   not granted    the add-on IS there; tick connect_sso_token_create. Nothing
 *                  needs installing - this is the misconfiguration an operator
 *                  reaches by doing step 1 and forgetting step 2.
 *   unreachable    fix the connection; single sign-on was never probed.
 *
 * PRESENCE IS NOT PERMISSION, and no probe can close that gap: the panel's
 * get_function_list returns the handler's whole method map rather than the
 * caller's grants (ISPConfig remoting.inc.php:685-692), and ISPConfig exposes no
 * way at all to read a remote user's grant list back. The ispconfig-sso
 * project's own integration guide states the consequence for consumers - "it may
 * still not be granted to you; you find that out by calling it" - so the
 * "installed" line names the grant instead of pretending detection covered it,
 * and STATE_NOT_GRANTED exists for what a real login attempt then discovers.
 *
 * AND NO, THE GRANT MUST NOT BE PROBED BY MINTING. Calling the function with a
 * deliberately invalid client id would separate "permission denied" from
 * "invalid client id" - and it would cost a line in the panel's sys_log AND its
 * auth.log every time, because the add-on audits every refusal by design ("a
 * refused attempt to mint a password substitute must not be the one event that
 * is recorded nowhere"). Manufacturing "refused to mint a token" entries in a
 * production audit trail from a routine Test Connection is not a trade this
 * module makes, and probing with a REAL client id is worse still: the add-on
 * keeps one live token per client, so it would invalidate the login the
 * customer is holding.
 *
 * ## Why there is exactly one customer sentence
 *
 * The admin lines above are full of hostnames, grant names and panel menus,
 * which is right for the person who can act on them and wrong for everybody
 * else. A customer can act on precisely one thing - sign in the ordinary way -
 * so they get one sentence carrying no diagnosis at all, and the diagnosis goes
 * to the module log. Which of the five states produced the failure is an
 * operator's business.
 *
 * Pure data: no I/O, no WHMCS, no API calls.
 */
final class SsoAdvice
{
    /**
     * The panel reports the ispconfig-sso remote function. Its grant may still
     * be withheld - see the class docblock.
     */
    public const STATE_INSTALLED = CompanionClient::STATUS_INSTALLED;

    /** The panel answered, and the add-on is not there. */
    public const STATE_ABSENT = CompanionClient::STATUS_ABSENT;

    /** The presence probe itself was refused: get_function_list is not granted. */
    public const STATE_PROBE_DENIED = CompanionClient::STATUS_UNKNOWN;

    /**
     * The add-on is installed but the remote user may not call its function.
     * Only a real mint attempt can discover this, so it is reported where one
     * happens (the SSO entry points) rather than by a probe.
     */
    public const STATE_NOT_GRANTED = 'not_granted';

    /** The panel could not be reached, so nothing about SSO was established. */
    public const STATE_UNREACHABLE = 'unreachable';

    private function __construct()
    {
    }

    /**
     * The operator-facing line for one state: what it means on THIS server, and
     * what to do next.
     *
     * @throws \InvalidArgumentException on an unrecognised state - a caller
     *         inventing a sixth answer is a bug, not a display problem
     */
    public static function admin(string $state): string
    {
        return match ($state) {
            self::STATE_INSTALLED =>
                'Available - this panel has the ispconfig-sso add-on, so services on this server'
                . ' show the "Log in to ISPConfig" button. The panel never reports grants, so if a'
                . ' login is refused anyway, tick connect_sso_token_create for this server\'s'
                . ' remote user under System > Remote Users > Functions.',
            self::STATE_ABSENT =>
                'Not installed - this panel does not have the ispconfig-sso add-on, so no login'
                . ' button is offered for services on this server and customers sign in with the'
                . ' panel address and their login name (nothing is broken by this). To enable it,'
                . ' install the separate ispconfig-sso project on the ISPConfig server, tick'
                . ' connect_sso_token_create for this server\'s remote user, then open'
                . ' Configuration > System Settings > Servers once so WHMCS re-probes.',
            self::STATE_PROBE_DENIED =>
                'Cannot tell - this server\'s remote user lacks the get_function_list grant, so the'
                . ' panel cannot be asked whether the ispconfig-sso add-on is there. That is NOT the'
                . ' same as the add-on being absent: tick "Server functions" (the checkbox carrying'
                . ' get_function_list) under System > Remote Users > Functions and check again.',
            self::STATE_NOT_GRANTED =>
                'Installed but not granted - this panel has the ispconfig-sso add-on, but this'
                . ' server\'s remote user may not call connect_sso_token_create, so every login'
                . ' attempt is refused. Nothing needs deploying: tick that one function under'
                . ' System > Remote Users > Functions.',
            self::STATE_UNREACHABLE =>
                'Not checked - the panel could not be reached, so single sign-on could not be'
                . ' probed. Fix the connection first (Test Connection on this server names the'
                . ' reason), then check again.',
            default => throw new \InvalidArgumentException(
                'Unknown single sign-on state: ' . $state
            ),
        };
    }

    /**
     * The one sentence a customer may see when single sign-on does not work,
     * whatever the reason. No hostname, no grant, no panel vocabulary, no
     * diagnosis - only the action they can take themselves.
     */
    public static function customer(): string
    {
        return 'Single sign-on is not available for this service right now. Open the control panel'
            . ' with the button on this page and sign in with your control panel login name;'
            . ' if that does not work, please contact support.';
    }
}
