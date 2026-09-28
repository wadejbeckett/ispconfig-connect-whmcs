<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Ispconfigconnect\Provision;

/**
 * Recipe::changePackage() refused because the ISPConfig client is LOCKED
 * (client.locked = 'y', i.e. suspended through the whole-client lock).
 *
 * WHY A PACKAGE CHANGE MUST NOT RUN ON A LOCKED CLIENT. client_update merges
 * the stored record into its params and then re-runs func_client_lock() on the
 * merged 'locked' value (remote.d/client.inc.php client_update: `if
 * ($params['locked'])`). On a locked client that is func_client_lock('y')
 * again, which OVERWRITES client.tmp_data - the list of objects that were
 * already inactive before the suspension - with "everything is inactive"
 * (functions.inc.php func_client_lock). A later unsuspend then has nothing to
 * restore: the customer's websites, mail and databases stay off.
 *
 * Flipping the lock off and back on around the template change avoids that,
 * but it disables and re-enables every object of the client in two separate
 * calls, and a failure between them strands the client in the wrong state. So
 * the recipe refuses instead, and makes NO client_update at all; the entry file
 * turns this into an operator refusal ("unsuspend first").
 *
 * A dedicated type, not a message match, because the entry file must tell this
 * refusal apart from every other failure without parsing text.
 */
final class ClientLockedException extends \RuntimeException
{
    public function __construct(public readonly int $clientId)
    {
        parent::__construct(sprintf(
            'ISPConfig client #%d is locked (suspended); its package cannot be changed until it is unlocked',
            $clientId
        ));
    }
}
