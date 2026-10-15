<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Ispconfigconnect\Api\Exception;

/**
 * code=remote_fault envelope.  The JSON handler flattens every server-side
 * SoapFault to code "remote_fault" and forwards only the fault MESSAGE
 * (verified: json_handler.inc.php run() catch block, ISPConfig 3.3.1p1).
 * The original fault code (login_failed, session_does_not_exist, ...) is NOT
 * transmitted, so classification sentinel-matches the fixed English message
 * strings hard-coded in interface/lib/classes/remoting.inc.php.  Those strings
 * have been stable for 15+ years and are not localized.
 *
 * Anything that does not match a sentinel stays a plain RemoteFaultException -
 * notably data_processing_error validation text, which is localized and
 * HTML-joined and must NEVER be string-matched.
 */
class RemoteFaultException extends IspconfigException
{
    /**
     * Sentinels verified against ISPConfig 3.3.1p1 remoting.inc.php.
     * Order matters: first prefix match wins.
     *
     * @var array<class-string<RemoteFaultException>, list<string>>
     */
    private const SENTINELS = [
        LockoutException::class => [
            'The login failure limit has been reached.',
            'The Session failure limit has been reached.',
        ],
        SessionIpMismatchException::class => [
            'Session IP mismatch.',
        ],
        SessionExpiredException::class => [
            'The Session is expired or does not exist.',
        ],
        IpNotAllowedException::class => [
            'The login is not allowed from ',
        ],
        LoginFailedException::class => [
            'The login failed. Username or password wrong.',
            'The login failed. User is blocked.',
            'The login failed. Client may not use api.',
            'The login username is empty.',
            'The login password is empty.',
        ],
        PermissionDeniedException::class => [
            'You do not have the permissions to access this function.',
        ],
        MaintenanceModeException::class => [
            'This ISPConfig installation is currently under maintenance',
        ],
    ];

    public static function fromMessage(string $message): self
    {
        foreach (self::SENTINELS as $class => $prefixes) {
            foreach ($prefixes as $prefix) {
                if (str_starts_with($message, $prefix)) {
                    return new $class($message);
                }
            }
        }

        return new self($message);
    }
}
