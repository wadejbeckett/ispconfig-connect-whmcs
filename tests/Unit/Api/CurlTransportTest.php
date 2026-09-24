<?php

declare(strict_types=1);

namespace Ispconfigconnect\Tests\Unit\Api;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WHMCS\Module\Server\Ispconfigconnect\Api\CurlTransport;
use WHMCS\Module\Server\Ispconfigconnect\Api\Exception\TransportException;
use WHMCS\Module\Server\Ispconfigconnect\Api\ServerConfig;

#[CoversClass(CurlTransport::class)]
final class CurlTransportTest extends TestCase
{
    private const PEM = "-----BEGIN CERTIFICATE-----\nMIIBfakefakefakefake\n-----END CERTIFICATE-----\n";

    private static function config(
        bool $verifyTls = true,
        ?string $caBundle = null,
        int $timeoutSeconds = 30,
    ): ServerConfig {
        return new ServerConfig(
            host: 'panel.example.test',
            username: 'remote',
            password: 'panel-pw',
            port: 8080,
            secure: true,
            verifyTls: $verifyTls,
            caBundle: $caBundle,
            timeoutSeconds: $timeoutSeconds,
        );
    }

    // ---- TLS verification -------------------------------------------------

    public function testTlsVerificationIsOnByDefault(): void
    {
        $options = (new ExposedCurlTransport(self::config()))->exposedBuildOptions();

        self::assertTrue($options[CURLOPT_SSL_VERIFYPEER]);
        self::assertSame(2, $options[CURLOPT_SSL_VERIFYHOST]);
    }

    public function testTlsVerificationOptOutComesOnlyFromConfig(): void
    {
        $options = (new ExposedCurlTransport(self::config(verifyTls: false)))->exposedBuildOptions();

        self::assertFalse($options[CURLOPT_SSL_VERIFYPEER]);
        self::assertSame(0, $options[CURLOPT_SSL_VERIFYHOST]);
    }

    // ---- CA bundle handling -----------------------------------------------

    public function testCaBundlePathIsPassedAsCaInfo(): void
    {
        $transport = new ExposedCurlTransport(self::config(caBundle: '/etc/ssl/certs/panel-ca.pem'));
        $options = $transport->exposedBuildOptions();

        self::assertSame('/etc/ssl/certs/panel-ca.pem', $options[CURLOPT_CAINFO]);
    }

    public function testNoCaBundleMeansNoCaInfoOption(): void
    {
        $options = (new ExposedCurlTransport(self::config()))->exposedBuildOptions();

        self::assertArrayNotHasKey(CURLOPT_CAINFO, $options);
    }

    public function testLiteralPemIsWrittenToAPrivateTempFileAndCleanedUp(): void
    {
        $transport = new ExposedCurlTransport(self::config(caBundle: self::PEM));
        $options = $transport->exposedBuildOptions();

        $path = $options[CURLOPT_CAINFO];
        self::assertIsString($path);
        self::assertNotSame(self::PEM, $path);
        self::assertFileExists($path);
        self::assertSame(self::PEM, file_get_contents($path));
        // The bundle may sit next to other users' files in the temp dir.
        self::assertSame('600', decoct(fileperms($path) & 0o777));

        $transport->exposedCleanup();
        self::assertFileDoesNotExist($path);
    }

    // ---- URL / method encoding --------------------------------------------

    public function testMethodNameIsTheSoleQueryKeyWithNoEquals(): void
    {
        $transport = new ExposedCurlTransport(self::config());

        $options = $transport->exposedBuildOptions('server_get_all');
        self::assertSame(
            'https://panel.example.test:8080/remote/json.php?server_get_all',
            $options[CURLOPT_URL]
        );

        // Reserved characters must be encoded so json_handler still sees a
        // single query key - and never an '=' that would split key/value.
        $options = $transport->exposedBuildOptions('weird method&');
        self::assertSame(
            'https://panel.example.test:8080/remote/json.php?weird%20method%26',
            $options[CURLOPT_URL]
        );
        $query = explode('?', (string) $options[CURLOPT_URL], 2)[1];
        self::assertStringNotContainsString('=', $query);
    }

    // ---- timeouts -----------------------------------------------------------

    public function testTimeoutsComeFromConfigWithConnectCappedAtTen(): void
    {
        $options = (new ExposedCurlTransport(self::config(timeoutSeconds: 30)))->exposedBuildOptions();
        self::assertSame(30, $options[CURLOPT_TIMEOUT]);
        self::assertSame(10, $options[CURLOPT_CONNECTTIMEOUT]);

        $options = (new ExposedCurlTransport(self::config(timeoutSeconds: 4)))->exposedBuildOptions();
        self::assertSame(4, $options[CURLOPT_TIMEOUT]);
        self::assertSame(4, $options[CURLOPT_CONNECTTIMEOUT]);
    }

    // ---- request shape ------------------------------------------------------

    public function testPostCarriesTheBodyVerbatimWithJsonContentType(): void
    {
        $body = '{"username":"remote","password":"panel-pw","client_login":false}';
        $options = (new ExposedCurlTransport(self::config()))->exposedBuildOptions('login', $body);

        self::assertTrue($options[CURLOPT_POST]);
        self::assertSame($body, $options[CURLOPT_POSTFIELDS]);
        self::assertTrue($options[CURLOPT_RETURNTRANSFER]);
        self::assertContains('Content-Type: application/json', $options[CURLOPT_HTTPHEADER]);
    }

    // ---- failure handling ---------------------------------------------------

    public function testCurlFailureThrowsTransportExceptionWithoutTheRequestBody(): void
    {
        $secretBody = '{"username":"remote","password":"Sup3r-Secret!"}';
        $transport = new StubbedExecCurlTransport(self::config(), [
            'body' => false,
            'errno' => 7,
            'error' => 'Failed to connect to panel.example.test port 8080: Connection refused',
            'status' => 0,
        ]);

        try {
            $transport->post('login', $secretBody);
            self::fail('Expected TransportException');
        } catch (TransportException $exception) {
            self::assertStringContainsString('7', $exception->getMessage());
            self::assertStringContainsString('Connection refused', $exception->getMessage());
            // Credentials live in the body - it must never leak into messages.
            self::assertStringNotContainsString('Sup3r-Secret!', $exception->getMessage());
            self::assertStringNotContainsString($secretBody, $exception->getMessage());
        }
    }

    public function testHttpErrorStatusThrowsTransportExceptionWithoutTheRequestBody(): void
    {
        $secretBody = '{"username":"remote","password":"Sup3r-Secret!"}';
        $transport = new StubbedExecCurlTransport(self::config(), [
            'body' => '<html>Internal Server Error</html>',
            'errno' => 0,
            'error' => '',
            'status' => 500,
        ]);

        try {
            $transport->post('login', $secretBody);
            self::fail('Expected TransportException');
        } catch (TransportException $exception) {
            self::assertStringContainsString('500', $exception->getMessage());
            self::assertStringNotContainsString('Sup3r-Secret!', $exception->getMessage());
        }
    }

    public function testRedirectStatusIsRejectedAsNonSuccessWithoutTheRequestBody(): void
    {
        // A 3xx is non-2xx: the interface promises an exception. Redirects are
        // deliberately not followed, so it must not slip through as "success"
        // and reach the envelope decoder as an unrecognisable body.
        $secretBody = '{"username":"remote","password":"Sup3r-Secret!"}';
        $transport = new StubbedExecCurlTransport(self::config(), [
            'body' => '',
            'errno' => 0,
            'error' => '',
            'status' => 301,
        ]);

        try {
            $transport->post('login', $secretBody);
            self::fail('Expected TransportException');
        } catch (TransportException $exception) {
            self::assertStringContainsString('301', $exception->getMessage());
            self::assertStringNotContainsString('Sup3r-Secret!', $exception->getMessage());
        }
    }

    public function testAnEmptyNonSuccessBodyIsNamedAsTheLikelyCause(): void
    {
        // A 5xx (or any non-2xx) that also returns nothing is the common shape
        // of a proxy/error page; the empty body is called out so the operator
        // is not left with a bare status.
        $transport = new StubbedExecCurlTransport(self::config(), [
            'body' => '',
            'errno' => 0,
            'error' => '',
            'status' => 502,
        ]);

        try {
            $transport->post('logout', '{"session_id":"x"}');
            self::fail('Expected TransportException');
        } catch (TransportException $exception) {
            self::assertStringContainsString('502', $exception->getMessage());
            self::assertStringContainsString('empty response body', $exception->getMessage());
        }
    }

    public function testA2xxWithAnEmptyBodyIsPassedThroughForTheDecoderToDiagnose(): void
    {
        // 204 (and an empty 200) ARE 2xx: the interface promises an exception
        // only for a non-2xx status, so the transport accepts them and returns
        // the empty body. Naming an empty body is then the envelope decoder's
        // job (RestClient), not the transport's.
        $transport = new StubbedExecCurlTransport(self::config(), [
            'body' => '',
            'errno' => 0,
            'error' => '',
            'status' => 204,
        ]);

        self::assertSame('', $transport->post('logout', '{"session_id":"x"}'));
    }

    public function testOneHundredContinueStatusIsAlsoRejected(): void
    {
        // Anything below 200 is non-2xx too.
        $transport = new StubbedExecCurlTransport(self::config(), [
            'body' => '',
            'errno' => 0,
            'error' => '',
            'status' => 100,
        ]);

        $this->expectException(TransportException::class);
        $transport->post('logout', '{"session_id":"x"}');
    }

    public function testRedirectsAreNotFollowed(): void
    {
        // Enabling CURLOPT_FOLLOWLOCATION would re-POST the credential body to a
        // Location host on 307/308; it must be absent or explicitly false.
        $options = (new ExposedCurlTransport(self::config()))->exposedBuildOptions();

        self::assertNotSame(true, $options[CURLOPT_FOLLOWLOCATION] ?? false);
    }

    public function testSuccessfulPostReturnsTheRawBodyAndCleansUpTheTempCaFile(): void
    {
        $envelope = '{"code":"ok","message":"","response":true}';
        $transport = new StubbedExecCurlTransport(self::config(caBundle: self::PEM), [
            'body' => $envelope,
            'errno' => 0,
            'error' => '',
            'status' => 200,
        ]);

        self::assertSame($envelope, $transport->post('logout', '{"session_id":"x"}'));

        $path = $transport->seenOptions[CURLOPT_CAINFO];
        self::assertIsString($path);
        self::assertFileDoesNotExist($path);
    }

    public function testTempCaFileIsCleanedUpEvenWhenThePostFails(): void
    {
        $transport = new StubbedExecCurlTransport(self::config(caBundle: self::PEM), [
            'body' => false,
            'errno' => 28,
            'error' => 'Operation timed out',
            'status' => 0,
        ]);

        try {
            $transport->post('login', '{"username":"remote","password":"pw"}');
            self::fail('Expected TransportException');
        } catch (TransportException) {
        }

        $path = $transport->seenOptions[CURLOPT_CAINFO];
        self::assertIsString($path);
        self::assertFileDoesNotExist($path);
    }
}

/**
 * Exposes the protected option-building seam (spec 2.1) for assertions.
 */
final class ExposedCurlTransport extends CurlTransport
{
    /** @return array<int, mixed> */
    public function exposedBuildOptions(string $method = 'login', string $jsonBody = '{}'): array
    {
        return $this->buildOptions($method, $jsonBody);
    }

    public function exposedCleanup(): void
    {
        $this->cleanupTemporaryCaFile();
    }
}

/**
 * Replaces the curl execution with a canned result - no network in tests.
 */
final class StubbedExecCurlTransport extends CurlTransport
{
    /** @var array<int, mixed> */
    public array $seenOptions = [];

    /** @param array{body: string|false, errno: int, error: string, status: int} $result */
    public function __construct(ServerConfig $config, private readonly array $result)
    {
        parent::__construct($config);
    }

    protected function execute(array $options): array
    {
        $this->seenOptions = $options;

        return $this->result;
    }
}
