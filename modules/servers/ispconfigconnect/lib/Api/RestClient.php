<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Ispconfigconnect\Api;

use WHMCS\Module\Server\Ispconfigconnect\Api\Exception\ApiDisabledException;
use WHMCS\Module\Server\Ispconfigconnect\Api\Exception\ApiProtocolException;
use WHMCS\Module\Server\Ispconfigconnect\Api\Exception\MalformedResponseException;
use WHMCS\Module\Server\Ispconfigconnect\Api\Exception\RemoteFaultException;

/**
 * Envelope-level client for ISPConfig's remote/json.php (spec 2.2).
 *
 * Encodes request params, decodes the {"code","message","response"} envelope
 * (verified: interface/lib/classes/json_handler.inc.php _return_json(),
 * ISPConfig 3.3.1p1), and maps every non-ok outcome to a typed exception.
 * Session handling, retries, and lockout circuits live in SessionManager -
 * this class is strictly one request, one response.
 */
final class RestClient
{
    /**
     * WHY THERE ARE TWO MECHANISMS, not one longer list.
     *
     * Everything ISPConfig reads comes back as `SELECT *` (remoting_lib.inc.php
     * getDataRecord(), quota_lib.inc.php), so a response carries whatever the
     * COLUMN LIST happens to hold - not what the module asked for - and every
     * call is written to tblmodulelog, which every WHMCS admin can read.
     * Two different problems live in that payload and they need two different
     * answers:
     *
     *  - CREDENTIALS AND KEY MATERIAL are long, unique, high-entropy strings.
     *    They are handed to the logger as `replaceVars`, WHMCS's own
     *    convention: logModuleCall blanks every occurrence out of the stored
     *    request and response. str_replace on a value like that is exact, and
     *    leaving the KEY visible-but-masked is what tells an operator that the
     *    panel returned key material at all.
     *
     *  - PERSONAL AND FINANCIAL COLUMNS cannot be handled that way. A bank
     *    code, a VAT id, a country, a 2FA mail address or a 10-character
     *    activation code is short and low-entropy: as a str_replace needle it
     *    would blank unrelated text elsewhere in the same entry (`bank_code`
     *    = "1" would blank every "1"), and masking still hands the raw value
     *    to logModuleCall, so the data is only as safe as a WHMCS-side
     *    implementation detail. Those columns are therefore REMOVED from the
     *    copy handed to the logger, before it leaves this class. The module
     *    never reads them, so nothing is lost - see RECORD_PROJECTIONS.
     *
     * Matching is exact on the NORMALIZED key name (lowercased, separators
     * stripped) rather than by substring. Substring matching is what let the
     * original leak through: the defensive rule looked for "password" and
     * sys_user's column is the German `passwort` (install/sql/ispconfig3.sql:1861).
     * It also fails in the other direction - "password" matches
     * `lost_password_function`, a 1/0 flag whose value would be a catastrophic
     * masking needle. Both spellings are listed explicitly below, and a
     * conservative needle set (HEURISTIC_NEEDLES) redacts - never masks -
     * anything else that looks like a password column.
     *
     * @var list<string>
     */
    private const MASKED_KEYS = [
        'password',
        // sys_user.passwort - the PANEL LOGIN HASH (crypt SHA-512), returned
        // by client_get_by_username, which is a bare SELECT * FROM sys_user
        // (remote.d/client.inc.php:478; column at install/sql/ispconfig3.sql:1861).
        'passwort',
        'new_password',
        'remote_password',
        'database_password',
        'session_id',
        // The one-time panel-login token minted by the optional ispconfig-sso
        // project (Sso\CompanionClient).
        // It arrives in the RESPONSE, is a bearer credential for the seconds
        // it lives, and tblmodulelog is readable by every WHMCS admin - so it
        // is masked exactly like a password.
        'token',
        // sys_user.otp_recovery (:1878) - the bcrypt hash of the 2FA recovery
        // codes. Same shape as a password hash, same treatment.
        'otp_recovery',
        // Key-material COLUMNS that record-shaped responses echo back, because
        // ISPConfig's reads are SELECT * (remoting_lib getDataRecord,
        // quota_lib): web_domain.ssl_key is the site's TLS private key and
        // ssl_request its CSR (install/sql/ispconfig3.sql:2036/:2033 - both in
        // every quota_get_by_user row), client.id_rsa is the client's SSH
        // private key (:260 - in every client_get row, and mirrored on
        // sys_user.id_rsa :1871), and mail_domain.dkim_private / relay_pass
        // (:893/:897) ride along on mail domain reads.
        'ssl_key',
        'ssl_request',
        'id_rsa',
        'dkim_private',
        'relay_pass',
    ];

    /**
     * Columns REMOVED from the logged copy wherever they appear, in request or
     * response. All verified in install/sql/ispconfig3.sql (ISPConfig 3.3.1p1):
     * the `client` table's money and tax columns at :152 and :165-171, its
     * self-registration `activation_code` at :269 and `tmp_data` blob at :259,
     * and sys_user's `otp_data` (:1877, the address 2FA mails go to) and
     * `lost_password_hash` (:1874, a live password-reset token hash).
     *
     * The module reads none of them; a WHMCS module log is not a place to keep
     * another company's customers' bank details.
     *
     * @var list<string>
     */
    private const PRIVATE_KEYS = [
        'bank_account_owner',
        'bank_account_number',
        'bank_code',
        'bank_name',
        'bank_account_iban',
        'bank_account_swift',
        'paypal_email',
        'vat_id',
        'otp_data',
        'activation_code',
        'lost_password_hash',
        'tmp_data',
    ];

    /**
     * Last-resort needles for a password-ish column nobody enumerated (a
     * future ISPConfig column, an add-on function). A match here REDACTS -
     * it never adds a masking needle - because an unknown column's value could
     * be a flag ("1") or an enum ("y"), and blanking those out of the log by
     * str_replace does more damage than the column was worth.
     *
     * @var list<string>
     */
    private const HEURISTIC_NEEDLES = ['passwor', 'passwd', 'passphrase'];

    /**
     * Better than scrubbing a blocklist after the fact: for the remote reads
     * that answer with a CUSTOMER RECORD, the logged copy keeps an ALLOWLIST
     * and drops the rest, so a column nobody has heard of yet is private by
     * default. A blocklist rots the moment ISPConfig adds a column; this does
     * not.
     *
     * Both reads below are unfiltered SELECTs on customer tables and both run
     * on every Server Sync - `client_get` with client_id -1 returns EVERY
     * client on the panel in one call (remoting_lib.inc.php getDataRecord():
     * "$primary_id == -1"), including clients that are not WHMCS customers at
     * all.
     *
     * @var array<string, list<string>>
     */
    private const RECORD_PROJECTIONS = [
        'client_get' => self::CLIENT_ROW_LOGGED,
        'client_get_by_customer_no' => self::CLIENT_ROW_LOGGED,
        // Resolves the group to a client_id and then RETURNS client_get's own
        // answer - `return $this->client_get($session_id, $client_id)`,
        // remote.d/client.inc.php:690-703 - so the payload is the same
        // `SELECT * FROM client` record reached under a second method name.
        // Keyed by method, an allowlist is only as complete as this list.
        'client_get_by_groupid' => self::CLIENT_ROW_LOGGED,
        // `SELECT company_name,contact_name,gender,email,language FROM client`
        // (:118-134). ISPConfig narrows this one server-side, but the name and
        // gender it does return are still another company's customer's personal
        // data, and the module never reads them.
        'client_get_emailcontact' => self::CLIENT_ROW_LOGGED,
        'client_get_by_username' => self::SYS_USER_ROW_LOGGED,
    ];

    /**
     * What survives from a `client` row: the columns the module actually reads
     * (client_id/username/email/template_master/created_at/locked in
     * Sync\AccountLister, web_php_options/ssh_chroot/locked in
     * Provision\Recipe) plus the structural columns that make a log entry
     * worth reading - ownership, lock state, node bindings. Everything else -
     * the postal address, the bank block, the tax id, the notes field - is
     * dropped. MASKED_KEYS survive too, masked by value.
     *
     * @var list<string>
     */
    private const CLIENT_ROW_LOGGED = [
        'client_id',
        'sys_userid',
        'sys_groupid',
        'username',
        'email',
        'language',
        'usertheme',
        'locked',
        'canceled',
        'can_use_api',
        'parent_client_id',
        'template_master',
        'template_additional',
        'created_at',
        'added_date',
        'web_php_options',
        'ssh_chroot',
        'ssh_rsa',
        'force_suexec',
        'validation_status',
        'default_webserver',
        'default_mailserver',
        'default_dnsserver',
        'default_slave_dnsserver',
        'default_dbserver',
        'default_xmppserver',
        'web_servers',
        'mail_servers',
        'dns_servers',
        'db_servers',
        'xmpp_servers',
    ];

    /**
     * What survives from a `sys_user` row: the identity and group columns
     * Sync\AccountLister and Usage\Collector read (userid, groups,
     * default_group), plus module/theme/state columns that carry no personal
     * data. `passwort`, `otp_recovery` and `id_rsa` survive as MASKED_KEYS;
     * `otp_data`, `lost_password_hash`, `lost_password_reqtime`,
     * `lost_password_function` and `last_password_change` do not survive at all.
     *
     * @var list<string>
     */
    private const SYS_USER_ROW_LOGGED = [
        'userid',
        'sys_userid',
        'sys_groupid',
        'username',
        'client_id',
        'groups',
        'default_group',
        'active',
        'typ',
        'language',
        'modules',
        'startmodule',
        'app_theme',
        'otp_type',
        'otp_attempts',
        'ssh_rsa',
    ];

    /**
     * Normalized key prefixes an allowlist always accepts: `limit_*` (the ~60
     * per-client quota columns) and `sys_perm_*` are integers and enums that
     * explain a provisioning failure and cannot carry personal data.
     *
     * @var list<string>
     */
    private const ALLOWED_PREFIXES = ['limit', 'sysperm'];

    /** Value substituted for a scrubbed key that stays visible in the log. */
    public const REDACTED_PLACEHOLDER = '[redacted by ispconfigconnect]';

    /** Key under which a projection reports how many columns it dropped. */
    public const SCRUB_NOTICE_KEY = 'ispconfigconnect_scrubbed';

    /**
     * A hard, deliberately small cap on how many bytes of a non-envelope body
     * ever reach an exception message (and thus tblmodulelog / the admin UI).
     * The body is a diagnostic hint, never a payload: enough to recognise an
     * HTML error page or a display_errors notice, not enough to be a place
     * another company's customer record could hide.
     */
    private const BODY_EXCERPT_LIMIT = 120;

    /**
     * Normalized-name lookups, built once from the constants above.
     *
     * @var array<string, array<string, true>>
     */
    private static array $lookups = [];

    /** @var (\Closure(string, array<string, mixed>, mixed, list<string>): void)|null */
    private readonly ?\Closure $logger;

    /**
     * @param null|callable(string $action, array<string, mixed> $request, mixed $response, list<string> $sensitiveValues): void $logger
     *        invoked for EVERY API call, success or failure (failure: response
     *        = exception message)
     */
    public function __construct(
        private readonly HttpTransportInterface $transport,
        ?callable $logger = null,
    ) {
        $this->logger = $logger === null ? null : $logger(...);
    }

    /**
     * @param array<string, mixed> $params keyed by the remote function's PHP
     *        parameter NAMES - reflection-matched server-side, order-free (§1.1)
     * @return mixed the envelope's "response" member
     */
    public function call(string $method, array $params): mixed
    {
        $sensitiveValues = self::maskableValues($params);

        try {
            $body = json_encode($params, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $result = $this->decode($this->transport->post($method, $body));
        } catch (\Throwable $failure) {
            $this->log($method, $params, $failure->getMessage(), $sensitiveValues);

            throw $failure;
        }

        if ($method === 'login' && is_string($result) && $result !== '') {
            // The freshly issued session id only exists in the response; it is
            // as sensitive as the password that produced it.
            $sensitiveValues[] = $result;
        }

        if (is_array($result)) {
            // Record-shaped responses echo stored credential columns
            // (client_get returns client.password, client_get_by_username
            // returns sys_user.passwort) - collect the response side too so
            // the log wiring can mask what scrubResponse() deliberately keeps.
            $sensitiveValues = array_values(array_unique(array_merge(
                $sensitiveValues,
                self::maskableValues($result)
            )));
        }

        $this->log($method, $params, $result, $sensitiveValues);

        return $result;
    }

    public function login(string $username, #[\SensitiveParameter] string $password): string
    {
        // login($username, $password, $client_login = false) - remoting.inc.php.
        $response = $this->call('login', [
            'username' => $username,
            'password' => $password,
            'client_login' => false,
        ]);

        if (!is_string($response) || $response === '') {
            throw new MalformedResponseException('login did not return a session id string');
        }

        return $response;
    }

    public function logout(string $sessionId): bool
    {
        return (bool) $this->call('logout', ['session_id' => $sessionId]);
    }

    private function decode(string $raw): mixed
    {
        // A UTF-8 BOM (e.g. a panel include saved by a Windows editor) prefixes
        // the WHOLE body; strip it before matching sentinels or decoding. The
        // original $raw is kept only for the diagnostic excerpt's byte count.
        $body = str_starts_with($raw, "\xEF\xBB\xBF") ? substr($raw, 3) : $raw;

        // remote/json.php die()s with this plain-text body BEFORE the JSON
        // handler runs when remote_api_allowed != 'yes' (§1.2) - prefix match.
        if (str_starts_with($body, 'Remote API is disabled')) {
            throw new ApiDisabledException(ApiDisabledException::SENTINEL);
        }

        $decoded = self::decodeEnvelope($body);
        if ($decoded === null) {
            throw new MalformedResponseException(
                'Response is not the ISPConfig JSON envelope: ' . $this->excerpt($raw)
            );
        }

        $code = (string) $decoded['code'];
        $message = (string) ($decoded['message'] ?? '');

        if ($code === 'ok') {
            return $decoded['response'] ?? null;
        }
        if ($code === 'remote_fault') {
            throw RemoteFaultException::fromMessage($message);
        }

        throw new ApiProtocolException($code, $message !== '' ? $message : 'API returned code "' . $code . '"');
    }

    /**
     * Decodes the ISPConfig envelope, recovering from stray output that a
     * strict decode rejects. json_handler.inc.php prints exactly one envelope
     * and exit()s, so a PHP notice/deprecation emitted with display_errors=On,
     * or any bytes echoed around the envelope, wraps a body that is still
     * RECOVERABLE - and a server-side write (client_add commits the row before
     * printing "ok") must not read back as a client-side failure.
     *
     * @param string $body the response body, already stripped of a leading BOM
     * @return array<array-key, mixed>|null the {"code",...} envelope, or null
     *         when the body is not a recoverable envelope
     */
    private static function decodeEnvelope(string $body): ?array
    {
        $decoded = json_decode($body, true);
        if (is_array($decoded) && array_key_exists('code', $decoded)) {
            return $decoded;
        }

        return self::recoverEnvelope($body);
    }

    /**
     * Conservative recovery: the slice from the first `{"code"` to the last
     * `}` is accepted ONLY if that slice itself decodes to a {"code",...}
     * envelope. Anything less certain keeps the MalformedResponseException.
     *
     * @return array<array-key, mixed>|null
     */
    private static function recoverEnvelope(string $body): ?array
    {
        $start = strpos($body, '{"code"');
        if ($start === false) {
            return null;
        }
        $end = strrpos($body, '}');
        if ($end === false || $end <= $start) {
            return null;
        }

        $decoded = json_decode(substr($body, $start, $end - $start + 1), true);

        return is_array($decoded) && array_key_exists('code', $decoded) ? $decoded : null;
    }

    /**
     * A SAFE, hard-capped hint about a body that is not the JSON envelope -
     * for the module log and the admin UI. It NEVER echoes envelope bytes (a
     * wrapper-mangled success can carry a live session id or a stored password
     * hash inside a {"code"...} it failed to strict-decode), it runs whatever
     * it does show through the same secret redaction the array scrubber uses,
     * and it cuts on a UTF-8 character boundary through mb_scrub so json_encode
     * of the resulting message can never fail on a split multibyte sequence.
     */
    private function excerpt(string $raw): string
    {
        $bytes = strlen($raw);
        if ($bytes === 0) {
            return 'empty body (0 bytes) - the panel returned nothing, e.g. an HTTP '
                . 'redirect/error page or json_encode() failing on non-UTF-8 record data';
        }

        $display = str_starts_with($raw, "\xEF\xBB\xBF") ? substr($raw, 3) : $raw;

        // Show only what comes BEFORE the envelope; the envelope itself (where
        // any secret lives) is described by offset, never printed.
        $envelopeAt = strpos($display, '{"code"');
        $shown = $envelopeAt === false ? $display : substr($display, 0, $envelopeAt);

        // Belt and braces for the no-envelope case, where the whole body is
        // shown: redact any value the array scrubber would mask or drop.
        $shown = self::redactSecretsInText($shown);

        // Valid UTF-8 first, then a boundary-safe cut: the message must survive
        // json_encode (WHMCS transports it) and a utf8mb4 strict-mode insert.
        $scrubbed = mb_scrub($shown, 'UTF-8');
        $safe = mb_strcut($scrubbed, 0, self::BODY_EXCERPT_LIMIT, 'UTF-8');

        $hint = sprintf('non-JSON body, %d bytes, starts with: %s%s', $bytes, $safe, $safe === $scrubbed ? '' : '...');
        if ($envelopeAt !== false) {
            $hint .= sprintf(' (a {"code"...} envelope at byte %d is present but not shown)', $envelopeAt);
        }

        return $hint;
    }

    /**
     * Every value under a MASKED_KEYS key, at any nesting depth
     * (client_add/client_update carry the client password inside the `params`
     * member; a record response carries key-material columns). The caller
     * hands these to the logger as WHMCS's replaceVars.
     *
     * Public because Support\Factory runs the same sweep as a second line of
     * defence - from THIS vocabulary, so the two can never drift apart again.
     *
     * @param array<array-key, mixed> $payload
     * @return list<string>
     */
    public static function maskableValues(array $payload): array
    {
        $values = [];
        $walk = static function (array $node) use (&$walk, &$values): void {
            foreach ($node as $key => $value) {
                if (is_array($value)) {
                    $walk($value);
                } elseif (is_string($value) && $value !== '' && self::isMaskedKey($key)) {
                    $values[] = $value;
                }
            }
        };
        $walk($payload);

        return array_values(array_unique($values));
    }

    /**
     * The request as the logger should see it: intact except for keys that
     * carry data this module never authors (PRIVATE_KEYS) or that look like an
     * unenumerated password column.
     *
     * Requests get lighter treatment than responses, on purpose. A request is
     * written by this module out of WHMCS's own record of its own customer, and
     * reading back what was sent is the entire diagnostic value of the module
     * log - so it stays readable, and its secrets are covered by
     * maskableValues(). A response is the PANEL's copy of every customer it
     * hosts, which is a different question and gets scrubResponse().
     *
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public static function scrubRequest(array $request): array
    {
        /** @var array<string, mixed> $scrubbed */
        $scrubbed = self::scrub($request);

        return $scrubbed;
    }

    /**
     * The response as the logger should see it. For the customer-record reads
     * in RECORD_PROJECTIONS it keeps an allowlist and drops the rest; for every
     * other method it is the PRIVATE_KEYS/heuristic scrub.
     *
     * The value RETURNED TO THE MODULE is never touched - callers get the
     * panel's answer in full; only the copy that goes to tblmodulelog is
     * reduced.
     */
    public static function scrubResponse(string $method, mixed $response): mixed
    {
        // A STRING response reaches the logger too - notably the exception
        // MESSAGE on the failure path, which can carry a diagnostic hint about
        // the panel's body. Run it through the same secret redaction the array
        // path uses so nothing under a credential/private key survives here
        // either, not only when the response happens to be an array.
        if (is_string($response)) {
            return self::redactSecretsInText($response);
        }

        if (!is_array($response)) {
            return $response;
        }

        $projection = self::RECORD_PROJECTIONS[$method] ?? null;
        if ($projection !== null) {
            $response = self::project($response, $projection);
        }

        return self::scrub($response);
    }

    /**
     * Redacts, out of a free-text string, every value the array scrubber would
     * mask (credentials, key material) or drop (bank/tax/PII columns), using
     * the SAME vocabulary so the two can never disagree. Used for a string
     * response and for the diagnostic body excerpt: if the text is (or wraps) a
     * decodable object, its secret values are blanked; otherwise it is left
     * untouched (a session id or a monitor count carries nothing to redact).
     */
    private static function redactSecretsInText(string $text): string
    {
        if ($text === '' || !str_contains($text, '{')) {
            return $text;
        }

        foreach (self::secretValuesInText($text) as $secret) {
            $text = str_replace($secret, self::REDACTED_PLACEHOLDER, $text);
        }

        return $text;
    }

    /**
     * Best-effort extraction of the secret values carried by any JSON object in
     * a free-text string: the whole body, a wrapped {"code",...} envelope, or a
     * single object embedded in surrounding output. Conservative - if none of
     * those decode, nothing is reported and the text is left as-is.
     *
     * @return list<string>
     */
    private static function secretValuesInText(string $text): array
    {
        $decoded = json_decode($text, true);
        if (is_array($decoded)) {
            return self::secretValues($decoded);
        }

        $envelope = self::recoverEnvelope($text);
        if (is_array($envelope)) {
            return self::secretValues($envelope);
        }

        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $embedded = json_decode(substr($text, $start, $end - $start + 1), true);
            if (is_array($embedded)) {
                return self::secretValues($embedded);
            }
        }

        return [];
    }

    /**
     * Every string value under a MASKED_KEYS or PRIVATE_KEYS key, at any depth
     * - the values that must never appear verbatim in a log or the admin UI.
     *
     * @param array<array-key, mixed> $payload
     * @return list<string>
     */
    private static function secretValues(array $payload): array
    {
        $values = [];
        $walk = static function (array $node) use (&$walk, &$values): void {
            foreach ($node as $key => $value) {
                if (is_array($value)) {
                    $walk($value);
                } elseif (
                    is_string($value) && $value !== '' && is_string($key)
                    && (self::isMaskedKey($key) || self::isPrivateKey($key))
                ) {
                    $values[] = $value;
                }
            }
        };
        $walk($payload);

        return array_values(array_unique($values));
    }

    /**
     * @param array<array-key, mixed> $payload
     * @return array<array-key, mixed>
     */
    private static function scrub(array $payload): array
    {
        $out = [];
        foreach ($payload as $key => $value) {
            if (is_string($key) && self::isPrivateKey($key)) {
                $out[$key] = $value === null || $value === '' ? $value : self::REDACTED_PLACEHOLDER;
                continue;
            }
            $out[$key] = is_array($value) ? self::scrub($value) : $value;
        }

        return $out;
    }

    /**
     * Applies an allowlist to a record, or to every record of a multi-record
     * answer (client_get(-1) returns a LIST of client rows).
     *
     * @param array<array-key, mixed> $payload
     * @param list<string> $allowed
     * @return array<array-key, mixed>
     */
    private static function project(array $payload, array $allowed): array
    {
        $hasStringKey = false;
        foreach (array_keys($payload) as $key) {
            if (is_string($key)) {
                $hasStringKey = true;
                break;
            }
        }

        if (!$hasStringKey) {
            return array_map(
                static fn (mixed $row): mixed => is_array($row) ? self::project($row, $allowed) : $row,
                $payload
            );
        }

        $kept = [];
        $dropped = 0;
        foreach ($payload as $key => $value) {
            if (
                !is_string($key)
                // This module's own annotation from an earlier pass, not a
                // panel column. It has to survive re-projection: the scrub runs
                // twice on every call (RestClient::log(), then Factory's
                // defensive pass), and dropping the notice here would replace a
                // true count with a fresh count of the notice itself - every
                // record entry then reports "1 further column(s)", whatever the
                // real number. Keeping it makes project() idempotent.
                || $key === self::SCRUB_NOTICE_KEY
                || self::isAllowedKey($key, $allowed)
                || self::isMaskedKey($key)
            ) {
                $kept[$key] = $value;
                continue;
            }
            $dropped++;
        }
        if ($dropped > 0) {
            $kept[self::SCRUB_NOTICE_KEY] = $dropped
                . ' further column(s) of this ISPConfig record were dropped before logging';
        }

        return $kept;
    }

    private static function isMaskedKey(int|string $key): bool
    {
        return is_string($key) && isset(self::lookup('masked')[self::normalizeKey($key)]);
    }

    /** Explicit private column, or an unenumerated password-ish column. */
    private static function isPrivateKey(string $key): bool
    {
        $normalized = self::normalizeKey($key);
        if (isset(self::lookup('private')[$normalized])) {
            return true;
        }
        if (isset(self::lookup('masked')[$normalized])) {
            return false;
        }

        foreach (self::HEURISTIC_NEEDLES as $needle) {
            if (str_contains($normalized, $needle)) {
                return true;
            }
        }

        return false;
    }

    /** @param list<string> $allowed */
    private static function isAllowedKey(string $key, array $allowed): bool
    {
        $normalized = self::normalizeKey($key);
        foreach ($allowed as $candidate) {
            if ($normalized === self::normalizeKey($candidate)) {
                return true;
            }
        }
        foreach (self::ALLOWED_PREFIXES as $prefix) {
            if (str_starts_with($normalized, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Case- and separator-insensitive, so `bank_account_iban`,
     * `bankAccountIban` and `Bank-Account-IBAN` are one name - and so the
     * comparison is EXACT rather than a substring test.
     */
    private static function normalizeKey(string $key): string
    {
        return (string) preg_replace('/[^a-z0-9]/', '', strtolower($key));
    }

    /** @return array<string, true> */
    private static function lookup(string $which): array
    {
        if (self::$lookups === []) {
            self::$lookups = [
                'masked' => self::normalizedSet(self::MASKED_KEYS),
                'private' => self::normalizedSet(self::PRIVATE_KEYS),
            ];
        }

        return self::$lookups[$which];
    }

    /**
     * @param list<string> $keys
     * @return array<string, true>
     */
    private static function normalizedSet(array $keys): array
    {
        $set = [];
        foreach ($keys as $key) {
            $set[self::normalizeKey($key)] = true;
        }

        return $set;
    }

    /**
     * @param array<string, mixed> $request
     * @param list<string> $sensitiveValues
     */
    private function log(string $action, array $request, mixed $response, array $sensitiveValues): void
    {
        if ($this->logger === null) {
            return;
        }

        try {
            ($this->logger)(
                $action,
                self::scrubRequest($request),
                self::scrubResponse($action, $response),
                $sensitiveValues
            );
        } catch (\Throwable) {
            // A logging failure (e.g. WHMCS unable to write tblmodulelog) must
            // never break the API call it describes: on the success path it
            // would turn an on-the-wire success into a thrown failure (create
            // without rollback), and on the failure path it would replace the
            // real error with the logger's own.
        }
    }
}
