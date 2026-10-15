<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Ispconfigconnect\Provision;

use WHMCS\Module\Server\Ispconfigconnect\Api\Exception\IspconfigException;

/**
 * A create() stage failed after client_add had already succeeded (spec 2.7).
 * Carries the stage name and the rollback outcome; the ORIGINAL failure is
 * kept both as the previous exception and verbatim inside the message, so
 * WHMCS's return-the-message-string error convention loses nothing.
 */
final class ProvisioningException extends IspconfigException
{
    public function __construct(
        public readonly string $stage,
        public readonly bool $rollbackSucceeded,
        int $clientId,
        \Throwable $original,
        ?\Throwable $rollbackFailure = null,
    ) {
        $rollbackNote = $rollbackSucceeded
            ? sprintf('rollback removed ISPConfig client #%d', $clientId)
            : sprintf(
                'ROLLBACK FAILED - ISPConfig client #%d needs manual cleanup%s',
                $clientId,
                $rollbackFailure !== null ? ': ' . $rollbackFailure->getMessage() : ''
            );

        parent::__construct(
            sprintf(
                'Provisioning failed at stage "%s": %s (%s)',
                $stage,
                $original->getMessage(),
                $rollbackNote
            ),
            0,
            $original
        );
    }
}
