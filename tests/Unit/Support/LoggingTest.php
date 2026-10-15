<?php

declare(strict_types=1);

namespace Ispconfigconnect\Tests\Unit\Support;

use Ispconfigconnect\Tests\Stubs\ModuleCallLog;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WHMCS\Module\Server\Ispconfigconnect\Support\Logging;

#[CoversClass(Logging::class)]
final class LoggingTest extends TestCase
{
    private const PASSWORD = 'S3cret!panel-pw';
    private const SESSION_ID = 'a0123456789abcdef0123456789abcdef01234567';

    protected function setUp(): void
    {
        ModuleCallLog::reset();
    }

    protected function tearDown(): void
    {
        ModuleCallLog::reset();
    }

    public function testWireReturnsCallableThatRecordsEveryCallUnderTheModuleName(): void
    {
        $logger = Logging::wire('ispconfigconnect');

        $logger(
            'login',
            ['username' => 'whmcs-remote', 'password' => self::PASSWORD, 'client_login' => false],
            self::SESSION_ID,
            [self::PASSWORD, self::SESSION_ID]
        );

        $entries = ModuleCallLog::entries();
        self::assertCount(1, $entries);
        self::assertSame('ispconfigconnect', $entries[0]['module']);
        self::assertSame('login', $entries[0]['action']);
        self::assertSame('', $entries[0]['processed']);
        // The request reaches logModuleCall INTACT — masking is WHMCS's job,
        // driven by the replaceVars list (spec 2.2/2.8).
        self::assertSame(self::PASSWORD, $entries[0]['request']['password']);
        self::assertSame(self::SESSION_ID, $entries[0]['response']);
    }

    public function testPasswordThroughThePipelineIsAlwaysCoveredByReplaceVars(): void
    {
        $logger = Logging::wire('ispconfigconnect');

        // Simulate the RestClient call sequence of one WHMCS operation, exactly
        // as spec 2.2 mandates: the logger fires for every call, success or
        // failure, with password/session values intact plus the sensitive list.
        $logger(
            'login',
            ['username' => 'whmcs-remote', 'password' => self::PASSWORD, 'client_login' => false],
            self::SESSION_ID,
            [self::PASSWORD, self::SESSION_ID]
        );
        $logger(
            'client_add',
            ['session_id' => self::SESSION_ID, 'reseller_id' => 0, 'params' => ['username' => 'c1']],
            17,
            [self::SESSION_ID]
        );
        $logger(
            'client_change_password',
            ['session_id' => self::SESSION_ID, 'client_id' => 17, 'new_password' => 'NewPw!123'],
            'The Session is expired or does not exist.', // failure path: response = exception message
            [self::SESSION_ID, 'NewPw!123']
        );
        $logger(
            'logout',
            ['session_id' => self::SESSION_ID],
            true,
            [self::SESSION_ID]
        );

        self::assertCount(4, ModuleCallLog::entries());
        self::assertSame([], ModuleCallLog::entriesLeaking(self::PASSWORD));
        self::assertSame([], ModuleCallLog::entriesLeaking(self::SESSION_ID));
        self::assertSame([], ModuleCallLog::entriesLeaking('NewPw!123'));
    }

    public function testEntriesLeakingSentinelActuallyDetectsAnUncoveredSecret(): void
    {
        // Negative control: prove the assertion above is not vacuously green.
        // A logger call that omits the password from sensitiveValues MUST be
        // flagged by the stub.
        $logger = Logging::wire('ispconfigconnect');

        $logger(
            'login',
            ['username' => 'whmcs-remote', 'password' => self::PASSWORD],
            self::SESSION_ID,
            [self::SESSION_ID] // password deliberately missing
        );

        self::assertSame([0], ModuleCallLog::entriesLeaking(self::PASSWORD));
    }

    public function testEmptyAndDuplicateSensitiveValuesAreDroppedFromReplaceVars(): void
    {
        $logger = Logging::wire('ispconfigconnect');

        // An empty replaceVar would corrupt WHMCS's masking (str_replace with
        // '' needle); duplicates are just noise. Both are filtered.
        $logger(
            'login',
            ['username' => 'whmcs-remote', 'password' => self::PASSWORD],
            self::SESSION_ID,
            ['', self::PASSWORD, self::PASSWORD, '', self::SESSION_ID]
        );

        $entries = ModuleCallLog::entries();
        self::assertSame([self::PASSWORD, self::SESSION_ID], $entries[0]['replaceVars']);
    }

    public function testDistinctModulesWireDistinctLoggers(): void
    {
        $loggerA = Logging::wire('ispconfigconnect');
        $loggerB = Logging::wire('othermodule');

        $loggerA('logout', ['session_id' => self::SESSION_ID], true, [self::SESSION_ID]);
        $loggerB('logout', ['session_id' => self::SESSION_ID], true, [self::SESSION_ID]);

        $entries = ModuleCallLog::entries();
        self::assertSame('ispconfigconnect', $entries[0]['module']);
        self::assertSame('othermodule', $entries[1]['module']);
    }
}
