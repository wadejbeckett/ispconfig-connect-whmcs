<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Ispconfigconnect\Sso\Exception;

/**
 * The panel answered, but not with a payload this module is willing to
 * turn into a redirect.
 *
 * This is a SECURITY boundary, not just a version-skew guard: the token and
 * the endpoint path both end up inside a URL a browser is sent to, so a
 * malformed or hostile payload (a protocol-relative path, an absolute URL, a
 * traversal segment, a token carrying its own query separator) must never
 * reach the address bar. Rejecting is always safe - the caller falls back to
 * showing the panel URL.
 */
final class CompanionResponseException extends SsoException
{
}
