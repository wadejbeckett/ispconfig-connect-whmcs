<?php

declare(strict_types=1);

namespace Ispconfigconnect\Tests\Support;

/**
 * Stands in for the SoapFault thrown by remoting.inc.php handlers. The JSON
 * handler flattens every fault to code "remote_fault", keeping only the
 * message (json_handler.inc.php run()) - so the message is all we carry.
 */
final class FakeSoapFault extends \RuntimeException
{
}
