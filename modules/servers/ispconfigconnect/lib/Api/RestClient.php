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
     * Request keys whose values RestClient knows are secrets, at any nesting
     * depth (client_add/client_update carry the client password inside the
     * `params` member).  They are handed to the logger callback separately so
     * the wiring (Support\Logging) can feed WHMCS's replaceVars masking; the
     * request itself reaches the logger INTACT by design (spec 2.2).
     */
    private const SENSITIVE_KEYS = ['password', 'new_password', 'remote_password', 'database_password', 'session_id'];

    private const BODY_EXCERPT_LIMIT = 300;

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
        $sensitiveValues = $this->sensitiveValuesIn($params);

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
            // (client_get returns the password column) - collect the response
            // side too so the log wiring can mask it.
            $sensitiveValues = array_values(array_unique(array_merge(
                $sensitiveValues,
                $this->sensitiveValuesIn($result)
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
        // remote/json.php die()s with this plain-text body BEFORE the JSON
        // handler runs when remote_api_allowed != 'yes' (§1.2) - prefix match.
        if (str_starts_with($raw, 'Remote API is disabled')) {
            throw new ApiDisabledException(ApiDisabledException::SENTINEL);
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !array_key_exists('code', $decoded)) {
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

    private function excerpt(string $body): string
    {
        return strlen($body) > self::BODY_EXCERPT_LIMIT
            ? substr($body, 0, self::BODY_EXCERPT_LIMIT) . '...'
            : $body;
    }

    /**
     * @param array<array-key, mixed> $params
     * @return list<string>
     */
    private function sensitiveValuesIn(array $params): array
    {
        $values = [];
        $walk = static function (array $node) use (&$walk, &$values): void {
            foreach ($node as $key => $value) {
                if (is_array($value)) {
                    $walk($value);
                } elseif (is_string($value) && $value !== '' && in_array($key, self::SENSITIVE_KEYS, true)) {
                    $values[] = $value;
                }
            }
        };
        $walk($params);

        return array_values(array_unique($values));
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
            ($this->logger)($action, $request, $response, $sensitiveValues);
        } catch (\Throwable) {
            // A logging failure (e.g. WHMCS unable to write tblmodulelog) must
            // never break the API call it describes: on the success path it
            // would turn an on-the-wire success into a thrown failure (create
            // without rollback), and on the failure path it would replace the
            // real error with the logger's own.
        }
    }
}
