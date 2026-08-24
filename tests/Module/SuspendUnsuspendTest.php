<?php

declare(strict_types=1);

namespace Ispconfigconnect\Tests\Module;

/**
 * Suspend/Unsuspend semantics of configoption13 'Suspend Entire Client'
 * (frozen position 13):
 *
 *  - checked: ONE protective client_update with locked='y'/'n' - ISPConfig's
 *    func_client_lock (functions.inc.php) cascades over every client object,
 *    so no separate website set_status call is made;
 *  - unchecked: website-only - sites_web_domain_set_status only, never a
 *    client_update;
 *  - guards: an already-locked client is never re-locked (re-locking
 *    overwrites client.tmp_data with everything-inactive, so a later unlock
 *    would restore nothing), and a not-locked client is never "unlocked"
 *    (client.inc.php runs func_client_lock for ANY truthy 'locked' - 'n'
 *    included - which would re-enable admin-disabled objects).
 */
final class SuspendUnsuspendTest extends ModuleTestCase
{
    public function testEntireClientSuspendLocksViaClientUpdateOnly(): void
    {
        self::assertSame('success', ispconfigconnect_CreateAccount($this->params()));
        $clientId = (int) array_key_first($this->fake->clients());
        $siteId = (int) array_key_first($this->fake->sites());

        $mark = $this->wireMark();
        self::assertSame('success', ispconfigconnect_SuspendAccount($this->params()));

        self::assertSame('y', $this->fake->clients()[$clientId]['locked']);
        // The lock cascade disabled the website - without any set_status call.
        self::assertSame('n', $this->fake->sites()[$siteId]['active']);
        $methods = $this->methodsSince($mark);
        self::assertContains('client_update', $methods);
        self::assertNotContains('sites_web_domain_set_status', $methods);
        // The state guard read the client record first.
        self::assertContains('client_get', $methods);
    }

    public function testWebsiteOnlySuspendMakesNoClientUpdateAtAll(): void
    {
        self::assertSame('success', ispconfigconnect_CreateAccount($this->params()));
        $clientId = (int) array_key_first($this->fake->clients());
        $siteId = (int) array_key_first($this->fake->sites());

        $websiteOnly = ['configoption13' => ''];

        $mark = $this->wireMark();
        self::assertSame('success', ispconfigconnect_SuspendAccount($this->params($websiteOnly)));

        self::assertSame('n', $this->fake->sites()[$siteId]['active']);
        // The client stays unlocked: mail/FTP/DB keep working.
        self::assertSame('n', $this->fake->clients()[$clientId]['locked']);
        self::assertNotContains('client_update', $this->methodsSince($mark));

        $mark = $this->wireMark();
        self::assertSame('success', ispconfigconnect_UnsuspendAccount($this->params($websiteOnly)));

        self::assertSame('y', $this->fake->sites()[$siteId]['active']);
        self::assertNotContains('client_update', $this->methodsSince($mark));
    }

    public function testSuspendUnsuspendPreservesPanelPasswordAndAddonAssignments(): void
    {
        // Create WITH an addon template assignment - the regression target of
        // the client_update pre-merge (client.inc.php): a partial update
        // without the protective fields would double-crypt the password hash
        // AND wipe client_template_assigned via template_additional=''.
        self::assertSame('success', ispconfigconnect_CreateAccount($this->params([
            'configoptions' => ['ISPConfig Extra Mailboxes' => 1],
        ])));
        $clientId = (int) array_key_first($this->fake->clients());
        self::assertCount(1, $this->fake->templateAssignments());

        self::assertSame('success', ispconfigconnect_SuspendAccount($this->params()));
        self::assertSame('success', ispconfigconnect_UnsuspendAccount($this->params()));

        // Single crypt marker: the stored hash passed through verbatim
        // (_ispconfig_pw_crypted=1), not re-crypted to 'crypt:crypt:...'.
        self::assertSame(
            'crypt:' . self::SERVICE_PASSWORD,
            $this->fake->clients()[$clientId]['password']
        );
        // The addon assignment survived both protective updates (bare-id
        // count-diff no-op, client_templates.inc.php update_client_templates).
        $assignments = array_values($this->fake->templateAssignments());
        self::assertCount(1, $assignments);
        self::assertSame($clientId, $assignments[0]['client_id']);
        self::assertSame($this->addonTemplateId, $assignments[0]['client_template_id']);
    }

    public function testDoubleSuspendSkipsRelockAndUnlockStillRestores(): void
    {
        self::assertSame('success', ispconfigconnect_CreateAccount($this->params()));
        $clientId = (int) array_key_first($this->fake->clients());
        $siteId = (int) array_key_first($this->fake->sites());

        self::assertSame('success', ispconfigconnect_SuspendAccount($this->params()));

        // Second suspend (WHMCS retries happen): the client_get guard must
        // skip the lock - re-locking would overwrite client.tmp_data with
        // everything-inactive and poison the later unlock
        // (functions.inc.php func_client_lock).
        $mark = $this->wireMark();
        self::assertSame('success', ispconfigconnect_SuspendAccount($this->params()));
        self::assertSame(['login', 'client_get', 'logout'], $this->methodsSince($mark));

        // The unlock after a double suspend still restores the website - the
        // proof tmp_data was not poisoned.
        self::assertSame('success', ispconfigconnect_UnsuspendAccount($this->params()));
        self::assertSame('n', $this->fake->clients()[$clientId]['locked']);
        self::assertSame('y', $this->fake->sites()[$siteId]['active']);
    }

    public function testUnsuspendOfNotLockedClientMakesNoClientUpdate(): void
    {
        self::assertSame('success', ispconfigconnect_CreateAccount($this->params()));

        // ISPConfig treats ANY truthy 'locked' as a func_client_lock trigger
        // ('n' included, client.inc.php client_update) - unlocking a
        // not-locked client would re-enable objects an admin disabled on
        // purpose, so the module must skip the update entirely.
        $mark = $this->wireMark();
        self::assertSame('success', ispconfigconnect_UnsuspendAccount($this->params()));
        self::assertSame(['login', 'client_get', 'logout'], $this->methodsSince($mark));
    }
}
