<?php

declare(strict_types=1);

namespace Ispconfigconnect\Tests\Module;

/**
 * ChangePassword scope: rotates every credential the module provisioned with
 * the service password - the panel login always, plus the FTP user and the
 * database user when their ids are stored as service properties. Both
 * sites_*_user_update functions are plain updateQuery partial updates
 * (remote.d/sites.inc.php:310/:228) - safe, unlike client_update.
 */
final class ChangePasswordTest extends ModuleTestCase
{
    private const NEW_PASSWORD = 'Rot@ted!789';

    public function testRotatesPanelFtpAndDatabasePasswords(): void
    {
        self::assertSame('success', ispconfigconnect_CreateAccount($this->params([
            'configoption11' => 'on', // Create Database
        ])));
        $clientId = (int) array_key_first($this->fake->clients());
        $ftpUserId = (int) array_key_first($this->fake->ftpUsers());
        $databaseUserId = (int) array_key_first($this->fake->databaseUsers());

        $mark = $this->wireMark();
        self::assertSame(
            'success',
            ispconfigconnect_ChangePassword($this->params(['password' => self::NEW_PASSWORD]))
        );

        self::assertSame(
            'crypt:' . self::NEW_PASSWORD,
            $this->fake->clients()[$clientId]['password']
        );
        self::assertSame(
            'crypt:' . self::NEW_PASSWORD,
            $this->fake->ftpUsers()[$ftpUserId]['password']
        );
        self::assertSame(
            'crypt:' . self::NEW_PASSWORD,
            $this->fake->databaseUsers()[$databaseUserId]['database_password']
        );
        $methods = $this->methodsSince($mark);
        self::assertContains('client_change_password', $methods);
        self::assertContains('sites_ftp_user_update', $methods);
        self::assertContains('sites_database_user_update', $methods);
    }

    public function testWithoutStoredFtpAndDatabaseIdsOnlyThePanelRotates(): void
    {
        self::assertSame('success', ispconfigconnect_CreateAccount($this->params([
            'configoption3' => '', // Create Website off
            'configoption4' => '', // Create FTP User off
        ])));
        $clientId = (int) array_key_first($this->fake->clients());

        $mark = $this->wireMark();
        self::assertSame(
            'success',
            ispconfigconnect_ChangePassword($this->params(['password' => self::NEW_PASSWORD]))
        );

        self::assertSame(
            'crypt:' . self::NEW_PASSWORD,
            $this->fake->clients()[$clientId]['password']
        );
        $methods = $this->methodsSince($mark);
        self::assertNotContains('sites_ftp_user_update', $methods);
        self::assertNotContains('sites_database_user_update', $methods);
    }

    public function testEmptyPasswordIsRejectedBeforeAnyApiCall(): void
    {
        $this->model->serviceProperties->save(['ispconfig_client_id' => '7']);

        $result = ispconfigconnect_ChangePassword($this->params(['password' => '']));

        self::assertNotSame('success', $result);
        self::assertStringContainsStringIgnoringCase('password', $result);
        // The guard fires before any wire traffic: an empty rotation would
        // otherwise no-op the FTP/DB updates (updateQueryPrepare drops empty
        // PASSWORD fields) while leaving the old credentials live.
        self::assertSame([], $this->fake->requestLog);
    }
}
