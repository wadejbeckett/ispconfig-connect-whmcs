<?php

declare(strict_types=1);

namespace Ispconfigconnect\Tests\Unit\Sso;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WHMCS\Module\Server\Ispconfigconnect\Sso\CompanionClient;
use WHMCS\Module\Server\Ispconfigconnect\Sso\SsoAdvice;

/**
 * The single vocabulary every SSO surface speaks.
 *
 * Single sign-on has FIVE honestly distinguishable states, and each one sends
 * an operator somewhere different: install a project, tick one checkbox, tick a
 * different checkbox, fix the connection, or do nothing at all. Folding any two
 * of them into one sentence - "not installed" for a panel that has the add-on
 * but withheld the grant, most of all - is what turns a five-minute fix into a
 * support ticket, so this class is the one place the wording lives and every
 * admin surface renders it.
 *
 * The customer sentence is deliberately the opposite: ONE message, no state, no
 * hostnames, no grant names, no internal vocabulary. A customer can act on
 * exactly one thing (sign in the ordinary way), and everything else is noise
 * they cannot use and should not see.
 */
#[CoversClass(SsoAdvice::class)]
final class SsoAdviceTest extends TestCase
{
    /** @return list<string> */
    private function allStates(): array
    {
        return [
            SsoAdvice::STATE_INSTALLED,
            SsoAdvice::STATE_ABSENT,
            SsoAdvice::STATE_PROBE_DENIED,
            SsoAdvice::STATE_NOT_GRANTED,
            SsoAdvice::STATE_UNREACHABLE,
        ];
    }

    /**
     * The three states the presence probe can report keep CompanionClient's own
     * values, so a status() answer can be handed to this class unmapped and
     * nothing has to translate between two spellings of the same fact.
     */
    public function testTheProbeStatesShareTheCompanionClientVocabulary(): void
    {
        self::assertSame(CompanionClient::STATUS_INSTALLED, SsoAdvice::STATE_INSTALLED);
        self::assertSame(CompanionClient::STATUS_ABSENT, SsoAdvice::STATE_ABSENT);
        self::assertSame(CompanionClient::STATUS_UNKNOWN, SsoAdvice::STATE_PROBE_DENIED);
    }

    public function testEveryStateHasItsOwnDistinctInstruction(): void
    {
        $lines = array_map(SsoAdvice::admin(...), $this->allStates());

        self::assertCount(count($this->allStates()), array_unique($lines));
        foreach ($lines as $line) {
            self::assertNotSame('', trim($line));
        }
    }

    public function testAnInstalledPanelStillNamesTheGrantThatDetectionCannotSee(): void
    {
        // get_function_list reports the panel's whole method map, never the
        // caller's grants (ispconfig-sso README §4.2: "It may still not be
        // granted to you - you find that out by calling it"), so "installed"
        // must not be read as "single sign-on will work".
        $line = SsoAdvice::admin(SsoAdvice::STATE_INSTALLED);

        self::assertStringContainsString('Available', $line);
        self::assertStringContainsString('connect_sso_token_create', $line);
        self::assertStringContainsString('Remote Users', $line);
    }

    public function testAnAbsentAddOnSaysWhatToInstallAndThatNothingIsBroken(): void
    {
        $line = SsoAdvice::admin(SsoAdvice::STATE_ABSENT);

        self::assertStringContainsString('not installed', strtolower($line));
        self::assertStringContainsString('ispconfig-sso', $line);
        // The separate project, never a path inside this repository.
        self::assertStringNotContainsString('companion/', $line);
        // The cache is refreshed by the servers page, so an operator who has
        // just installed the project needs to be told to open it.
        self::assertStringContainsString('Servers', $line);
    }

    public function testARefusedProbeIsReportedAsUndeterminableRatherThanAbsent(): void
    {
        $line = SsoAdvice::admin(SsoAdvice::STATE_PROBE_DENIED);

        self::assertStringContainsString('get_function_list', $line);
        self::assertStringContainsString('Server functions', $line);
        // "We were not allowed to look" must never be reported as "it is not
        // there" - that sends an operator to install software they may have.
        self::assertStringNotContainsString('is not installed', $line);
    }

    /**
     * The common misconfiguration: the operator installed the panel add-on and
     * forgot to tick its checkbox. It is its own state, and its instruction
     * must not tell anyone to install anything.
     */
    public function testAWithheldGrantIsItsOwnStateAndNotAnInstallInstruction(): void
    {
        $line = SsoAdvice::admin(SsoAdvice::STATE_NOT_GRANTED);

        self::assertStringContainsString('connect_sso_token_create', $line);
        self::assertStringContainsString('Remote Users', $line);
        self::assertStringNotContainsString('install the separate', $line);
        self::assertNotSame(SsoAdvice::admin(SsoAdvice::STATE_ABSENT), $line);
    }

    public function testAnUnreachablePanelIsNotReportedAsAnSsoAnswerAtAll(): void
    {
        $line = SsoAdvice::admin(SsoAdvice::STATE_UNREACHABLE);

        self::assertStringContainsStringIgnoringCase('could not be reached', $line);
        self::assertStringContainsString('Test Connection', $line);
        self::assertStringNotContainsString('ispconfig-sso', $line);
    }

    public function testAnUnknownStateIsRefusedRatherThanGuessedAt(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        SsoAdvice::admin('something-else');
    }

    /**
     * DESIGN §3.6.3, applied to prose: everything a customer can see is
     * operator-free. No hostname, no grant name, no panel vocabulary, no
     * diagnosis - just the one action they can actually take.
     */
    public function testTheCustomerSentenceCarriesNoOperatorDetailAtAll(): void
    {
        $message = SsoAdvice::customer();

        self::assertNotSame('', trim($message));
        foreach (
            [
                'ispconfig-sso',
                'connect_sso_token_create',
                'get_function_list',
                'remote user',
                'grant',
                'System >',
                'http',
                'API',
            ] as $forbidden
        ) {
            self::assertStringNotContainsStringIgnoringCase(
                $forbidden,
                $message,
                sprintf('a customer must never be shown "%s"', $forbidden)
            );
        }
    }

    public function testTheCustomerSentenceIsTheSameWhateverWentWrong(): void
    {
        // One message, deliberately: which of the five states produced it is
        // an operator's business (the module log has it), never the customer's.
        self::assertSame(SsoAdvice::customer(), SsoAdvice::customer());
    }
}
