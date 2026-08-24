<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Ispconfigconnect\Api;

use WHMCS\Module\Server\Ispconfigconnect\Api\Exception\TransportException;

/**
 * ext-curl transport to remote/json.php (spec 2.1).
 *
 * Not final on purpose: buildOptions() and execute() are the protected seams
 * the unit tests use to assert the exact curl option set and both failure
 * paths without any network I/O (spec 2.1 mandates the seam).
 *
 * Security invariants enforced here:
 *   - TLS peer + host verification ON unless ServerConfig::verifyTls is false;
 *   - a literal PEM CA bundle is written to a 0600 temp file and removed
 *     after every request (success or failure);
 *   - failure messages carry the curl error / HTTP status and the URL, but
 *     NEVER the request body - it contains credentials.
 */
class CurlTransport implements HttpTransportInterface
{
    private ?string $temporaryCaFile = null;

    public function __construct(private readonly ServerConfig $config)
    {
    }

    public function __destruct()
    {
        // Safety net for buildOptions() calls that never reached post()'s
        // finally (e.g. curl_init failure).
        $this->cleanupTemporaryCaFile();
    }

    public function post(string $method, string $jsonBody): string
    {
        $options = $this->buildOptions($method, $jsonBody);

        try {
            $result = $this->execute($options);
        } finally {
            $this->cleanupTemporaryCaFile();
        }

        $url = (string) $options[CURLOPT_URL];

        if ($result['errno'] !== 0 || $result['body'] === false) {
            throw new TransportException(sprintf(
                'curl error %d while calling %s: %s',
                $result['errno'],
                $url,
                $result['error'] !== '' ? $result['error'] : 'no response received'
            ));
        }

        if ($result['status'] >= 400) {
            throw new TransportException(sprintf('HTTP status %d from %s', $result['status'], $url));
        }

        return $result['body'];
    }

    /**
     * Builds the complete curl option set for one request - the unit-test
     * seam.  A literal-PEM caBundle is materialized as a temp file here;
     * post() removes it after the request runs.
     *
     * @return array<int, mixed>
     */
    protected function buildOptions(string $method, string $jsonBody): array
    {
        // json_handler.inc.php takes the method from the FIRST query-string
        // key, so the method must be the sole key with no '=' after it.
        $options = [
            CURLOPT_URL => $this->config->endpointUrl() . '?' . rawurlencode($method),
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $jsonBody,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->config->timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => min(10, $this->config->timeoutSeconds),
            CURLOPT_SSL_VERIFYPEER => $this->config->verifyTls,
            CURLOPT_SSL_VERIFYHOST => $this->config->verifyTls ? 2 : 0,
        ];

        $caBundle = $this->config->caBundle;
        if ($caBundle !== null && $caBundle !== '') {
            $options[CURLOPT_CAINFO] = str_contains($caBundle, '-----BEGIN')
                ? $this->writeTemporaryCaFile($caBundle)
                : $caBundle;
        }

        return $options;
    }

    /**
     * Runs the request - overridable so tests can substitute canned results.
     *
     * @param array<int, mixed> $options
     * @return array{body: string|false, errno: int, error: string, status: int}
     */
    protected function execute(array $options): array
    {
        $handle = curl_init();
        if ($handle === false) {
            throw new TransportException('Unable to initialize curl');
        }

        try {
            curl_setopt_array($handle, $options);
            $body = curl_exec($handle);

            return [
                'body' => is_string($body) ? $body : false,
                'errno' => curl_errno($handle),
                'error' => curl_error($handle),
                'status' => (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE),
            ];
        } finally {
            curl_close($handle);
        }
    }

    protected function cleanupTemporaryCaFile(): void
    {
        if ($this->temporaryCaFile !== null) {
            @unlink($this->temporaryCaFile); // best-effort: may already be gone
            $this->temporaryCaFile = null;
        }
    }

    private function writeTemporaryCaFile(string $pem): string
    {
        $path = tempnam(sys_get_temp_dir(), 'ispcca');
        if ($path === false || file_put_contents($path, $pem) === false) {
            throw new TransportException('Unable to write the CA bundle to a temporary file');
        }
        chmod($path, 0o600); // the system temp dir is shared
        $this->temporaryCaFile = $path;

        return $path;
    }
}
