<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Ispconfigconnect\Sso;

/**
 * One minted single-use panel-login token and the URL that redeems it.
 *
 * The token is a bearer credential with a lifetime measured in seconds:
 * anything holding this object holds a login. Hand `url` straight to WHMCS's
 * SSO `redirectTo` and drop the object - never store it, never log it, never
 * put it in a template variable that outlives the redirect.
 */
final class SsoTicket
{
    public function __construct(
        #[\SensitiveParameter]
        public readonly string $token,
        public readonly string $url,
        /** Seconds until the token expires; 0 when the panel did not say. */
        public readonly int $expiresInSeconds = 0,
        /** The panel username the token logs in as, when the panel reports it. */
        public readonly ?string $username = null,
    ) {
    }

    /**
     * Keeps the token out of var_dump()/debug_zval_dump() output - the URL
     * embeds it, so both are masked.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'token' => '***',
            'url' => '***',
            'expiresInSeconds' => $this->expiresInSeconds,
            'username' => $this->username,
        ];
    }
}
