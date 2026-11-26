<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Console\Commands\ExtensionLicenseIssueCommand;
use App\Modules\Extension\Application\ExtensionLicenseIssuer;
use App\Modules\Extension\Application\ExtensionLicenseService;
use PHPUnit\Framework\TestCase;

final class ExtensionLicenseIssuerTest extends TestCase
{
    private const ENV_KEYS = [
        'EXTENSION_LICENSE_PRIVATE_KEY',
        'EXTENSION_LICENSE_PRIVATE_KEY_FILE',
        'EXTENSION_LICENSE_PUBLIC_KEY',
        'EXTENSION_LICENSE_PUBLIC_KEY_FILE',
        'EXTENSION_LICENSE_FILE',
    ];

    /** @var array<string, string> */
    private array $originalEnv = [];

    /** @var array<string, string> */
    private array $originalPutenv = [];

    private string $tempDir;

    private string $privateKeyPem = '';

    private string $publicKeyPem = '';

    protected function setUp(): void
    {
        foreach (self::ENV_KEYS as $key) {
            if (array_key_exists($key, $_ENV)) {
                $env = $_ENV[$key];
                $this->originalEnv[$key] = is_scalar($env) ? (string) $env : '';
            }
            $putenv = getenv($key);
            if ($putenv !== false) {
                $this->originalPutenv[$key] = $putenv;
            }
            $this->setEnv($key, null);
        }

        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'siro-issuer-' . bin2hex(random_bytes(4));
        mkdir($this->tempDir, 0775, true);

        [$this->privateKeyPem, $this->publicKeyPem] = $this->generateKeyPair();
        $this->setEnv('EXTENSION_LICENSE_PRIVATE_KEY', $this->privateKeyPem);
        $this->setEnv('EXTENSION_LICENSE_PUBLIC_KEY', $this->publicKeyPem);
    }

    protected function tearDown(): void
    {
        foreach (self::ENV_KEYS as $key) {
            if (array_key_exists($key, $this->originalEnv)) {
                $this->setEnv($key, $this->originalEnv[$key]);
            } elseif (array_key_exists($key, $this->originalPutenv)) {
                $this->setEnv($key, $this->originalPutenv[$key]);
            } else {
                $this->setEnv($key, null);
            }
        }

        if (is_dir($this->tempDir)) {
            foreach (glob($this->tempDir . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->tempDir);
        }
    }

    public function testIssuesLicenseAcceptedByService(): void
    {
        $payload = $this->payload();
        $issuer = new ExtensionLicenseIssuer();
        $document = $issuer->issueDocument($payload);

        self::assertArrayHasKey('payload', $document);
        self::assertArrayHasKey('signature', $document);
        self::assertNotSame('', $document['signature']);
        self::assertNotFalse(base64_decode($document['signature'], true));
        self::assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/',
            (string) $document['payload']['issued_at']
        );

        $json = $issuer->issue($payload);
        self::assertStringNotContainsString('PRIVATE KEY', $json);
        self::assertStringNotContainsString('BEGIN', $json);

        $this->activate($json);
        $service = new ExtensionLicenseService();
        $status = $service->load($json);
        self::assertTrue($status['ok'], $status['reason']);
        self::assertSame('pro', $status['edition']);
        self::assertSame('lic_test', $status['license_id']);
        self::assertSame(['siro/alpha'], $status['extensions']);
        self::assertSame(['reports'], $status['features']);
        self::assertTrue($service->entitled('siro/alpha', 'reports'));
        self::assertFalse($service->entitled('siro/beta'));
    }

    public function testNormalizesUnixTimestampsAndWildcards(): void
    {
        $now = time();
        $payload = $this->payload([
            'issued_at' => $now - 60,
            'not_before' => (string) ($now - 60),
            'expires_at' => (float) ($now + 86400),
            'grace_days' => '7',
            'extensions' => '*',
            'features' => '*',
        ]);

        $issuer = new ExtensionLicenseIssuer();
        $document = $issuer->issueDocument($payload);

        self::assertSame(gmdate('Y-m-d\TH:i:s\Z', $now - 60), $document['payload']['issued_at']);
        self::assertSame(gmdate('Y-m-d\TH:i:s\Z', $now + 86400), $document['payload']['expires_at']);
        self::assertSame(7, $document['payload']['grace_days']);
        self::assertSame(['*'], $document['payload']['extensions']);
        self::assertSame(['*'], $document['payload']['features']);

        $json = $issuer->issue($payload);
        $this->activate($json);
        $service = new ExtensionLicenseService();
        self::assertTrue($service->load($json)['ok']);
        self::assertTrue($service->entitled('siro/anything', 'any-feature'));
    }

    public function testAcceptsPrivateKeyFromFile(): void
    {
        $keyFile = $this->tempDir . DIRECTORY_SEPARATOR . 'private.pem';
        self::assertNotFalse(file_put_contents($keyFile, $this->privateKeyPem));

        $this->setEnv('EXTENSION_LICENSE_PRIVATE_KEY', null);
        $this->setEnv('EXTENSION_LICENSE_PRIVATE_KEY_FILE', $keyFile);

        $issuer = new ExtensionLicenseIssuer();
        $json = $issuer->issue($this->payload());

        self::assertStringNotContainsString('PRIVATE KEY', $json);
        self::assertTrue((new ExtensionLicenseService())->load($json)['ok']);
    }

    public function testRejectsMissingPrivateKey(): void
    {
        $this->setEnv('EXTENSION_LICENSE_PRIVATE_KEY', null);
        $this->setEnv('EXTENSION_LICENSE_PRIVATE_KEY_FILE', null);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not configured');

        (new ExtensionLicenseIssuer())->issue($this->payload());
    }

    public function testRejectsInvalidPrivateKey(): void
    {
        $this->setEnv('EXTENSION_LICENSE_PRIVATE_KEY', 'not-a-private-key');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('private key is invalid');

        (new ExtensionLicenseIssuer())->issue($this->payload());
    }

    public function testRejectsMissingRequiredFields(): void
    {
        $payload = $this->payload();
        unset($payload['customer_id']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('customer_id');

        (new ExtensionLicenseIssuer())->issue($payload);
    }

    public function testRejectsUnknownEdition(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('edition');

        (new ExtensionLicenseIssuer())->issue($this->payload(['edition' => 'platinum']));
    }

    public function testRejectsEmptyExtensions(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('extensions');

        (new ExtensionLicenseIssuer())->issue($this->payload(['extensions' => []]));
    }

    public function testRejectsExpiredTimestamps(): void
    {
        $now = time();
        $payload = $this->payload([
            'issued_at' => $now - 7200,
            'not_before' => $now - 7200,
            'expires_at' => $now - 3600,
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('expires_at');

        (new ExtensionLicenseIssuer())->issue($payload);
    }

    public function testRejectsInvalidTimestampString(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('issued_at');

        (new ExtensionLicenseIssuer())->issue($this->payload(['issued_at' => 'not-a-timestamp']));
    }

    public function testWrongKeyProducesInvalidSignature(): void
    {
        $issuer = new ExtensionLicenseIssuer();
        $json = $issuer->issue($this->payload());

        [, $otherPublicKey] = $this->generateKeyPair();
        $this->setEnv('EXTENSION_LICENSE_PUBLIC_KEY', $otherPublicKey);

        $status = (new ExtensionLicenseService())->load($json);
        self::assertFalse($status['ok']);
        self::assertSame('invalid_signature', $status['reason']);
    }

    public function testCommandWritesLicenseFileAndReportsJson(): void
    {
        $payloadFile = $this->writePayload($this->payload());
        $out = $this->tempDir . DIRECTORY_SEPARATOR . 'license.json';

        $command = new ExtensionLicenseIssueCommand();
        ob_start();
        $code = $command->run(['--payload=' . $payloadFile, '--out=' . $out, '--json']);
        $output = ob_get_clean();

        self::assertSame(0, $code);
        self::assertFileExists($out);
        self::assertIsString($output);
        $decoded = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertTrue($decoded['ok']);
        self::assertSame($out, $decoded['out']);
        self::assertSame('pro', $decoded['license']['edition']);
        self::assertStringNotContainsString('PRIVATE KEY', $output);

        $status = (new ExtensionLicenseService())->load($out);
        self::assertTrue($status['ok'], $status['reason']);
        $this->setEnv('EXTENSION_LICENSE_FILE', $out);
        self::assertTrue((new ExtensionLicenseService())->entitled('siro/alpha', 'reports'));
    }

    public function testCommandRefusesToOverwriteWithoutForce(): void
    {
        $payloadFile = $this->writePayload($this->payload());
        $out = $this->tempDir . DIRECTORY_SEPARATOR . 'existing.json';
        self::assertNotFalse(file_put_contents($out, 'sentinel'));

        $command = new ExtensionLicenseIssueCommand();
        ob_start();
        $code = $command->run(['--payload=' . $payloadFile, '--out=' . $out]);
        ob_end_clean();
        self::assertSame(1, $code);
        self::assertSame('sentinel', (string) file_get_contents($out));

        ob_start();
        $code = $command->run(['--payload=' . $payloadFile, '--out=' . $out, '--force']);
        ob_end_clean();
        self::assertSame(0, $code);
        self::assertNotSame('sentinel', (string) file_get_contents($out));
        self::assertTrue((new ExtensionLicenseService())->load($out)['ok']);
    }

    public function testCommandRejectsInvalidPayloadFile(): void
    {
        $payloadFile = $this->tempDir . DIRECTORY_SEPARATOR . 'bad-payload.json';
        self::assertNotFalse(file_put_contents($payloadFile, (string) json_encode(['edition' => 'pro'])));
        $out = $this->tempDir . DIRECTORY_SEPARATOR . 'bad-license.json';

        $command = new ExtensionLicenseIssueCommand();
        ob_start();
        $code = $command->run(['--payload=' . $payloadFile, '--out=' . $out]);
        ob_end_clean();

        self::assertSame(1, $code);
        self::assertFileDoesNotExist($out);
    }

    public function testCommandPrintsLicenseToStdoutWithoutOut(): void
    {
        $payloadFile = $this->writePayload($this->payload());

        $command = new ExtensionLicenseIssueCommand();
        ob_start();
        $code = $command->run(['--payload=' . $payloadFile]);
        $output = ob_get_clean();

        self::assertSame(0, $code);
        self::assertIsString($output);
        $decoded = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertSame('lic_test', $decoded['payload']['license_id']);
        self::assertIsString($decoded['signature']);
        self::assertStringNotContainsString('PRIVATE KEY', $output);
    }

    public function testCommandRejectsMissingPayloadArgument(): void
    {
        $command = new ExtensionLicenseIssueCommand();
        ob_start();
        $code = $command->run([]);
        ob_end_clean();

        self::assertSame(1, $code);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        $now = time();
        $payload = [
            'license_id' => 'lic_test',
            'customer_id' => 'cust_1',
            'edition' => 'pro',
            'extensions' => ['siro/alpha'],
            'features' => ['reports'],
            'issued_at' => gmdate('Y-m-d\TH:i:s\Z', $now - 60),
            'not_before' => gmdate('Y-m-d\TH:i:s\Z', $now - 60),
            'expires_at' => gmdate('Y-m-d\TH:i:s\Z', $now + 3600),
            'grace_days' => 30,
            'limits' => ['extensions' => 5],
        ];

        foreach ($overrides as $key => $value) {
            $payload[$key] = $value;
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function writePayload(array $payload): string
    {
        $path = $this->tempDir . DIRECTORY_SEPARATOR . 'payload-' . bin2hex(random_bytes(4)) . '.json';
        self::assertNotFalse(file_put_contents($path, (string) json_encode($payload)));

        return $path;
    }

    private function activate(string $json): void
    {
        $path = $this->tempDir . DIRECTORY_SEPARATOR . 'active-' . bin2hex(random_bytes(4)) . '.json';
        self::assertNotFalse(file_put_contents($path, $json));
        $this->setEnv('EXTENSION_LICENSE_FILE', $path);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function generateKeyPair(): array
    {
        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        self::assertNotFalse($key, 'Unable to generate RSA key pair for issuer tests');

        $privatePem = '';
        self::assertTrue(openssl_pkey_export($key, $privatePem));

        $details = openssl_pkey_get_details($key);
        self::assertIsArray($details);
        self::assertIsString($details['key']);

        return [$privatePem, $details['key']];
    }

    private function setEnv(string $key, ?string $value): void
    {
        if ($value === null) {
            unset($_ENV[$key]);
            putenv($key);
            return;
        }
        $_ENV[$key] = $value;
        putenv($key . '=' . $value);
    }
}
