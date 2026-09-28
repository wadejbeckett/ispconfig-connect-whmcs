<?php

declare(strict_types=1);

namespace Ispconfigconnect\Tests\Unit\Api;

use Ispconfigconnect\Tests\Support\FakeIspconfigServer;
use WHMCS\Module\Server\Ispconfigconnect\Api\Exception\TransportException;
use WHMCS\Module\Server\Ispconfigconnect\Api\HttpTransportInterface;

/**
 * The behavioral fake behind a switch: while $down is true every request fails
 * the way CurlTransport reports a proxy 502 or a timeout, and EVERY attempt -
 * failed or not - is recorded. That is what lets a test tell a call that went
 * to the wire from one the SessionManager's latched circuit answered alone,
 * which the fake's own request log cannot show (a failed request never reaches
 * it).
 */
final class FlakyTransport implements HttpTransportInterface
{
    public bool $down = false;

    /** @var list<string> remote method of every attempt, in order */
    public array $attempted = [];

    public function __construct(private readonly FakeIspconfigServer $fake)
    {
    }

    public function post(string $method, string $jsonBody): string
    {
        $this->attempted[] = $method;
        if ($this->down) {
            throw new TransportException('HTTP status 502 while calling ' . $method);
        }

        return $this->fake->post($method, $jsonBody);
    }

    public function attempts(): int
    {
        return count($this->attempted);
    }
}
