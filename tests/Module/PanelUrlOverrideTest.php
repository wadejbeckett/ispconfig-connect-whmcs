<?php

declare(strict_types=1);

namespace Ispconfigconnect\Tests\Module;

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The product option "Panel URL Override" (configoption14) as a SECURITY
 * boundary.
 *
 * The value is typed by a WHMCS admin into a plain text field and is then
 * consumed in three places that hand it to a browser:
 *
 *   - the client area, where it becomes the href of the "Open Control Panel"
 *     button every customer of the product sees;
 *   - the admin service tab, where it becomes a link an admin clicks;
 *   - single sign-on, where it is the redirect target WHMCS sends the browser
 *     to (AdminSingleSignOn directly, ServiceSingleSignOn as the base of the
 *     single sign-on login URL).
 *
 * HTML escaping does NOT make those safe: `javascript:alert(1)` survives
 * htmlspecialchars() intact and still executes when the link is clicked, which
 * makes a mistyped or hostile option value stored XSS against customers, and
 * an off-site absolute URL an open redirect. The only workable defence is a
 * scheme allowlist at every point of consumption, with the server-derived panel
 * URL as the fallback - which is what these tests pin down.
 *
 * The rule is deliberately identical to Sso\CompanionClient::normalizeBaseUrl(),
 * so anything the client-area link accepts is also a legal SSO base.
 */
final class PanelUrlOverrideTest extends ModuleTestCase
{
    /** What ModuleTestCase's server row derives: https://<hostname>:<port>/. */
    private const DERIVED = 'https://panel.example.net:8080/';

    /**
     * Values that must NEVER reach a browser. Each is either script-bearing
     * (stored XSS), off-scheme, or not absolute - and a non-absolute value is
     * resolved against whatever page the customer happens to be on.
     *
     * @return array<string, array{string}>
     */
    public static function rejectedOverrides(): array
    {
        return [
            // Stored XSS: escaping does nothing to a javascript: href.
            'javascript scheme' => ['javascript:alert(document.domain)'],
            'javascript scheme, mixed case' => ['JaVaScRiPt:alert(document.domain)'],
            'javascript scheme, leading whitespace' => ['  javascript:alert(1)'],
            'javascript scheme, split by a newline' => ["java\nscript:alert(1)"],
            'javascript scheme, split by a tab' => ["java\tscript:alert(1)"],
            'data url' => ['data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg=='],
            'vbscript scheme' => ['vbscript:msgbox(1)'],
            // Open redirect: absolute-but-not-http, or host-relative.
            'protocol-relative' => ['//evil.tld/ispconfig'],
            'protocol-relative with backslashes' => ['\\\\evil.tld'],
            'bare hostname' => ['evil.tld'],
            'absolute path only' => ['/ispconfig/'],
            'relative path' => ['ispconfig/login.php'],
            'file url' => ['file:///etc/passwd'],
            'ftp url' => ['ftp://panel.example.net/'],
            // Reads as the panel host, resolves to evil.tld.
            'credentials in the authority' => ['https://panel.example.net@evil.tld/'],
            // Same trick, hidden from parse_url(): it keeps the backslash in
            // the userinfo, while browsers read '\' inside an authority as '/'.
            'backslash in the authority' => ['https://panel.example.net\\@evil.tld/'],
            'no host at all' => ['https://'],
            // Rejected for agreement with CompanionClient::normalizeBaseUrl():
            // a base URL carrying a query or fragment cannot have the SSO
            // token path appended to it meaningfully. The bare '?' and '#'
            // forms are here because parse_url() reports an EMPTY query or
            // fragment for them, which an isset() check alone would miss.
            // The ASCII screen is byte-based, so UNICODE whitespace and the
            // zero-width/BiDi format characters walked straight through it -
            // while the rule this function documents is "no whitespace,
            // control characters or backslashes ANYWHERE". None of these is a
            // scheme bypass (the scheme is still https, and which HOST an
            // operator names is deliberately not policed), but an invisible
            // character in the authority is a homograph: the link reads as the
            // panel's hostname and resolves somewhere else, and it survives a
            // copy-paste out of a document without ever being visible in the
            // product config field.
            'non-breaking space in the authority' => ["https://\u{00A0}evil.tld/"],
            'zero-width space in the authority' => ["https://\u{200B}evil.tld/"],
            'ideographic space in the authority' => ["https://\u{3000}evil.tld/"],
            'byte-order mark in the authority' => ["https://\u{FEFF}evil.tld/"],
            'right-to-left override in the authority' => ["https://\u{202E}evil.tld/"],
            'line separator in the authority' => ["https://\u{2028}evil.tld/"],
            // Not valid UTF-8 at all: the Unicode screen cannot answer, so the
            // value must be refused rather than waved through.
            'invalid utf-8' => ["https://ev\xC3il.tld/"],
            'query string' => ['https://cp.example.net/?next=x'],
            'bare question mark' => ['https://cp.example.net/?'],
            'fragment' => ['https://cp.example.net/#panel'],
            'bare hash' => ['https://cp.example.net/#'],
        ];
    }

    /**
     * Values an operator legitimately types. Each must be used EXACTLY as
     * written - a vanity hostname, a reverse proxy path and a non-default port
     * are all normal ISPConfig deployments.
     *
     * @return array<string, array{string}>
     */
    public static function acceptedOverrides(): array
    {
        return [
            'plain https host' => ['https://cp.example.net'],
            'trailing slash is preserved' => ['https://panel.example.net/custom/'],
            'explicit port' => ['https://cp.example.net:8443/'],
            'path prefix behind a proxy' => ['https://www.example.net/ispconfig/'],
            'uppercase scheme' => ['HTTPS://cp.example.net/'],
            'internationalised host' => ['https://xn--bcher-kva.example/'],
            // The screen above rejects invisible characters, NOT non-ASCII in
            // general: an operator may legitimately type an IDN as raw UTF-8
            // rather than punycode, and both spellings name the same panel.
            'internationalised host, raw utf-8' => ["https://b\u{00FC}cher.example/"],
            'internationalised host, non-latin' => ['https://пример.example/'],
            // Plain http is a downgrade, but it is the operator's decision and
            // a genuine deployment on a private network.
            'http host' => ['http://192.0.2.10:8080/'],
        ];
    }

    // ---- client area ------------------------------------------------------

    #[DataProvider('rejectedOverrides')]
    public function testTheCustomerLinkFallsBackWhenTheOverrideIsNotAnHttpUrl(string $override): void
    {
        $output = ispconfigconnect_ClientArea($this->params(['configoption14' => $override]));

        self::assertSame(self::DERIVED, $output['templateVariables']['panelUrl']);
    }

    #[DataProvider('acceptedOverrides')]
    public function testTheCustomerLinkUsesAValidOverrideVerbatim(string $override): void
    {
        $output = ispconfigconnect_ClientArea($this->params(['configoption14' => $override]));

        self::assertSame($override, $output['templateVariables']['panelUrl']);
    }

    // ---- admin service tab ------------------------------------------------

    #[DataProvider('rejectedOverrides')]
    public function testTheAdminPanelLinkFallsBackWhenTheOverrideIsNotAnHttpUrl(string $override): void
    {
        $fields = ispconfigconnect_AdminServicesTabFields($this->params(['configoption14' => $override]));

        // Asserted whole rather than by substring: the anchor is emitted by the
        // module itself, so there is exactly one right answer, and a partial
        // match could pass on markup that also carried the rejected value.
        self::assertSame(
            '<a href="' . self::DERIVED . '" target="_blank">' . self::DERIVED . '</a>',
            $fields['ISPConfig Panel']
        );
    }

    // ---- single sign-on ---------------------------------------------------

    #[DataProvider('rejectedOverrides')]
    public function testAdminSingleSignOnNeverRedirectsToARejectedOverride(string $override): void
    {
        $serverOnly = $this->params(['configoption14' => $override]);
        unset($serverOnly['model'], $serverOnly['serviceid']);

        $result = ispconfigconnect_AdminSingleSignOn($serverOnly);

        self::assertTrue($result['success']);
        self::assertSame(self::DERIVED, $result['redirectTo']);
    }

    #[DataProvider('acceptedOverrides')]
    public function testAdminSingleSignOnHonoursAValidOverride(string $override): void
    {
        $serverOnly = $this->params(['configoption14' => $override]);
        unset($serverOnly['model'], $serverOnly['serviceid']);

        $result = ispconfigconnect_AdminSingleSignOn($serverOnly);

        self::assertTrue($result['success']);
        self::assertSame($override, $result['redirectTo']);
    }

    /**
     * The panel mints the token; the BASE the token path is appended to is
     * this option. A rejected value must degrade to the server-derived base -
     * the customer still gets a working login, just not the attacker's one.
     */
    #[DataProvider('rejectedOverrides')]
    public function testServiceSingleSignOnBuildsTheLoginUrlOnTheDerivedBase(string $override): void
    {
        $this->fake->installCompanion();
        self::assertSame('success', ispconfigconnect_CreateAccount($this->params()));

        $result = ispconfigconnect_ServiceSingleSignOn($this->params(['configoption14' => $override]));

        self::assertTrue($result['success'], (string) ($result['errorMsg'] ?? ''));
        self::assertStringStartsWith(self::DERIVED . 'connectsso/?token=', $result['redirectTo']);
    }

    /**
     * The server-level SSO refusal names the panel to sign in at, so the same
     * validation has to hold on the failure path too.
     */
    public function testTheSsoRefusalMessageNeverQuotesARejectedOverride(): void
    {
        $serverOnly = $this->params(['configoption14' => 'javascript:alert(document.domain)']);
        unset($serverOnly['model'], $serverOnly['serviceid']);
        // No client id is stored, so AdminSingleSignOn falls through to the
        // explanation branch rather than minting a ticket.
        $result = ispconfigconnect_singleSignOn($serverOnly, 'AdminSingleSignOn');

        self::assertFalse($result['success']);
        self::assertStringContainsString(self::DERIVED, $result['errorMsg']);
        self::assertStringNotContainsString('javascript:', $result['errorMsg']);
    }
    /**
     * The server-level SSO refusal only QUOTES the panel address; a server row
     * whose Hostname/IP ServerConfig rejects must not turn it into a throw.
     */
    public function testTheSsoRefusalStillAnswersWhenTheServerRecordIsUnusable(): void
    {
        $serverOnly = $this->params(['serverhostname' => 'https://panel.example.net', 'serverip' => '']);
        unset($serverOnly['model'], $serverOnly['serviceid']);

        $result = ispconfigconnect_singleSignOn($serverOnly, 'AdminSingleSignOn');

        self::assertFalse($result['success']);
        self::assertStringContainsString('no administrator single sign-on', $result['errorMsg']);
        self::assertStringContainsString("the panel's own login page", $result['errorMsg']);
    }
}
