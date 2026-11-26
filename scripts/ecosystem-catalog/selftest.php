<?php

declare(strict_types=1);

/**
 * Offline self-test for scripts/ecosystem-catalog/build.php.
 *
 * Creates fixtures under the OS temp directory and removes them afterwards.
 * No fixture is written into the repository.
 *
 * Usage:
 *   php scripts/ecosystem-catalog/selftest.php
 */

const EXIT_TEST_FAILURE = 1;

$repoRoot = dirname(__DIR__, 2);
$builder = __DIR__ . DIRECTORY_SEPARATOR . 'build.php';
$tempRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'siro-ecosystem-catalog-selftest-' . bin2hex(random_bytes(6));

/**
 * @return array{code: int, stdout: string, stderr: string}
 */
function runCommand(array $command, string $cwd): array
{
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = proc_open($command, $descriptors, $pipes, $cwd);
    if (!is_resource($process)) {
        throw new RuntimeException('could not start command: ' . implode(' ', $command));
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($process);

    return [
        'code' => $code,
        'stdout' => $stdout === false ? '' : $stdout,
        'stderr' => $stderr === false ? '' : $stderr,
    ];
}

function writeJson(string $path, array $data): void
{
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    if (file_put_contents($path, $json, LOCK_EX) === false) {
        throw new RuntimeException('could not write fixture: ' . $path);
    }
}

function assertThat(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function removeTree(string $path): void
{
    if (!file_exists($path)) {
        return;
    }

    if (is_dir($path)) {
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            removeTree($path . DIRECTORY_SEPARATOR . $entry);
        }

        @rmdir($path);

        return;
    }

    @unlink($path);
}

function catalogItem(string $id, array $overrides = []): array
{
    return array_replace([
        'id' => $id,
        'type' => 'plugin',
        'name' => 'Fixture Item',
        'description' => 'Fixture description.',
        'vendor' => 'Siro',
        'edition' => 'free',
        'status' => 'published',
        'latest_version' => '1.0.0',
        'min_core' => '^1.0',
        'min_commerce' => '^0.1',
        'license_required' => false,
        'license_sku' => null,
        'preview_url' => null,
        'changelog_url' => null,
        'artifact_url' => null,
        'sha256' => null,
        'signature' => null,
        'signature_url' => null,
        'published_at' => '2026-11-24',
        'tags' => ['fixture'],
    ], $overrides);
}

try {
    if (!mkdir($tempRoot, 0777, true) && !is_dir($tempRoot)) {
        throw new RuntimeException('could not create temp directory: ' . $tempRoot);
    }

    $lint = runCommand([PHP_BINARY, '-l', $builder], $repoRoot);
    assertThat($lint['code'] === 0, 'php -l failed: ' . $lint['stderr'] . $lint['stdout']);
    echo "PASS php -l build.php\n";

    $validPath = $tempRoot . DIRECTORY_SEPARATOR . 'valid-catalog.json';
    $valid = [
        'schema' => 'siro.ecosystem-catalog/1',
        'generated_at' => '2026-11-24T00:00:00Z',
        'items' => [
            catalogItem('siro/product-qa', [
                'name' => 'Product Q&A',
                'preview_url' => '/preview/product-qa',
                'changelog_url' => 'https://cdn.example.com/changelog/product-qa.md',
                'changelog' => "1.0.0\n- Initial release.",
            ]),
            catalogItem('acme/fashion-theme', [
                'type' => 'theme',
                'name' => 'Fashion Theme',
                'status' => 'planned',
                'preview_url' => 'https://cdn.example.com/themes/fashion.png',
            ]),
            catalogItem('siro/affiliate', [
                'name' => 'Affiliate',
                'edition' => 'pro',
                'status' => 'published',
                'license_required' => true,
                'license_sku' => 'siro-affiliate',
                'artifact_url' => 'https://cdn.example.com/artifacts/siro-affiliate-1.0.0.siro.zip',
                'sha256' => str_repeat('A', 64),
                'signature' => str_repeat('S', 64),
            ]),
        ],
    ];
    writeJson($validPath, $valid);

    $check = runCommand([PHP_BINARY, $builder, '--in=' . $validPath, '--check'], $repoRoot);
    assertThat($check['code'] === 0, 'valid --check failed: ' . $check['stderr']);
    assertThat(str_contains($check['stdout'], '3 items'), 'valid --check did not report 3 items');
    echo "PASS build.php --check accepts a valid fixture\n";

    $feedOne = $tempRoot . DIRECTORY_SEPARATOR . 'feed-one.json';
    $feedTwo = $tempRoot . DIRECTORY_SEPARATOR . 'feed-two.json';

    $buildOne = runCommand([PHP_BINARY, $builder, '--in=' . $validPath, '--out=' . $feedOne], $repoRoot);
    assertThat($buildOne['code'] === 0, 'first --out failed: ' . $buildOne['stderr']);
    $buildTwo = runCommand([PHP_BINARY, $builder, '--in=' . $validPath, '--out=' . $feedTwo], $repoRoot);
    assertThat($buildTwo['code'] === 0, 'second --out failed: ' . $buildTwo['stderr']);

    $bytesOne = (string) file_get_contents($feedOne);
    $bytesTwo = (string) file_get_contents($feedTwo);
    assertThat($bytesOne === $bytesTwo, 'two builds from the same source are not byte-identical');
    assertThat(str_ends_with($bytesOne, "\n"), 'feed is missing the trailing newline');
    assertThat(str_contains($bytesOne, "\n    \"schema\""), 'feed is not JSON_PRETTY_PRINT formatted');

    $decoded = json_decode($bytesOne, true, 512, JSON_THROW_ON_ERROR);
    assertThat($decoded['schema'] === 'siro.ecosystem-catalog/1', 'feed schema is wrong');
    assertThat(
        array_column($decoded['items'], 'id') === ['acme/fashion-theme', 'siro/affiliate', 'siro/product-qa'],
        'feed items are not sorted by id'
    );
    assertThat(
        $decoded['items'][2]['changelog'] === "1.0.0\n- Initial release.",
        'changelog was not forwarded verbatim'
    );
    assertThat(
        $decoded['items'][0]['changelog'] === null,
        'missing changelog should be forwarded as null'
    );

    $publicForbidden = [
        'license_required',
        'license_sku',
        'artifact_url',
        'sha256',
        'signature',
        'signature_url',
        'installed_state',
        'license_status',
    ];
    foreach ($decoded['items'] as $publicItem) {
        foreach ($publicForbidden as $forbidden) {
            assertThat(
                !array_key_exists($forbidden, $publicItem),
                sprintf('public feed leaked non-public field: %s', $forbidden)
            );
        }
    }
    echo "PASS build.php --out is deterministic, sorted and public-safe\n";

    $publicCheck = runCommand([PHP_BINARY, $builder, '--in=' . $validPath, '--check-public=' . $feedOne], $repoRoot);
    assertThat($publicCheck['code'] === 0, '--check-public should pass on a fresh feed: ' . $publicCheck['stderr']);
    echo "PASS build.php --check-public accepts the committed feed\n";

    file_put_contents($feedOne, $bytesOne . "\n", LOCK_EX);
    $publicDrift = runCommand([PHP_BINARY, $builder, '--in=' . $validPath, '--check-public=' . $feedOne], $repoRoot);
    assertThat($publicDrift['code'] === 4, '--check-public drift should exit 4, got ' . $publicDrift['code']);
    assertThat(str_contains($publicDrift['stderr'], 'drift'), '--check-public drift message is unclear');
    echo "PASS build.php --check-public rejects drift with exit 4\n";

    $missingPublic = $tempRoot . DIRECTORY_SEPARATOR . 'missing-public-feed.json';
    $publicMissing = runCommand([PHP_BINARY, $builder, '--in=' . $validPath, '--check-public=' . $missingPublic], $repoRoot);
    assertThat($publicMissing['code'] === 4, '--check-public missing should exit 4, got ' . $publicMissing['code']);
    assertThat(str_contains($publicMissing['stderr'], 'missing'), '--check-public missing message is unclear');
    echo "PASS build.php --check-public reports a missing feed with exit 4\n";

    $invalidPath = $tempRoot . DIRECTORY_SEPARATOR . 'invalid-catalog.json';
    $invalid = [
        'schema' => 'siro.ecosystem-catalog/1',
        'generated_at' => '2026-11-24T00:00:00Z',
        'items' => [
            catalogItem('Bad/Id'),
            catalogItem('siro/duplicate'),
            catalogItem('siro/duplicate'),
            catalogItem('siro/bad-edition', [
                'type' => 'module',
                'edition' => 'enterprise',
                'status' => 'live',
                'license_required' => 'no',
            ]),
            catalogItem('siro/pro-lie', [
                'edition' => 'pro',
                'status' => 'published',
                'license_required' => false,
                'artifact_url' => 'javascript:alert(1)',
                'sha256' => 'abc',
                'preview_url' => 'http://insecure.example.com/preview',
            ]),
        ],
    ];
    writeJson($invalidPath, $invalid);

    $invalidCheck = runCommand([PHP_BINARY, $builder, '--in=' . $invalidPath, '--check'], $repoRoot);
    assertThat($invalidCheck['code'] === 4, 'invalid catalog should exit 4, got ' . $invalidCheck['code']);
    assertThat(str_contains($invalidCheck['stderr'], 'invalid ecosystem catalog'), 'invalid catalog error is unclear');
    assertThat(str_contains($invalidCheck['stderr'], 'duplicates'), 'duplicate id was not reported');
    assertThat(str_contains($invalidCheck['stderr'], 'javascript:'), 'unsafe URL was not reported');
    assertThat(str_contains($invalidCheck['stderr'], 'license_required'), 'Pro license rule was not reported');
    echo "PASS build.php rejects invalid fixtures with exit 4\n";

    $plannedProPath = $tempRoot . DIRECTORY_SEPARATOR . 'planned-pro-catalog.json';
    writeJson($plannedProPath, [
        'schema' => 'siro.ecosystem-catalog/1',
        'generated_at' => '2026-11-24T00:00:00Z',
        'items' => [
            catalogItem('siro/planned-pro', [
                'edition' => 'pro',
                'status' => 'planned',
                'license_required' => true,
                'license_sku' => 'siro-planned-pro',
                'artifact_url' => null,
                'sha256' => null,
                'signature' => null,
            ]),
        ],
    ]);
    $plannedProCheck = runCommand([PHP_BINARY, $builder, '--in=' . $plannedProPath, '--check'], $repoRoot);
    assertThat($plannedProCheck['code'] === 0, 'planned Pro without artifact/sha/signature should be valid: ' . $plannedProCheck['stderr']);
    echo "PASS build.php accepts a planned Pro item without artifact, sha256 or signature\n";

    $publishedProFields = [
        'artifact_url' => 'https://cdn.example.com/artifacts/siro-pro-1.0.0.siro.zip',
        'sha256' => str_repeat('a', 64),
        'signature' => str_repeat('S', 64),
    ];
    foreach (['artifact_url', 'sha256', 'signature'] as $missingField) {
        $missingPath = $tempRoot . DIRECTORY_SEPARATOR . 'published-pro-missing-' . $missingField . '.json';
        $overrides = [
            'edition' => 'pro',
            'status' => 'published',
            'license_required' => true,
            'license_sku' => 'siro-pro',
        ] + $publishedProFields;
        $overrides[$missingField] = null;

        writeJson($missingPath, [
            'schema' => 'siro.ecosystem-catalog/1',
            'generated_at' => '2026-11-24T00:00:00Z',
            'items' => [catalogItem('siro/published-pro', $overrides)],
        ]);

        $missingCheck = runCommand([PHP_BINARY, $builder, '--in=' . $missingPath, '--check'], $repoRoot);
        assertThat(
            $missingCheck['code'] === 4,
            sprintf('published Pro missing %s should exit 4, got %d', $missingField, $missingCheck['code'])
        );
        assertThat(
            str_contains($missingCheck['stderr'], $missingField),
            sprintf('published Pro missing %s was not reported', $missingField)
        );
    }
    echo "PASS build.php rejects published Pro without artifact_url, sha256 or signature with exit 4\n";

    $changelogPath = $tempRoot . DIRECTORY_SEPARATOR . 'changelog-catalog.json';
    writeJson($changelogPath, [
        'schema' => 'siro.ecosystem-catalog/1',
        'generated_at' => '2026-11-24T00:00:00Z',
        'items' => [
            catalogItem('siro/changelog-control', [
                'changelog' => "bad\u{0007}bell",
            ]),
            catalogItem('siro/changelog-too-long', [
                'changelog' => str_repeat('a', 1001),
            ]),
            catalogItem('siro/changelog-wrong-type', [
                'changelog' => 42,
            ]),
        ],
    ]);
    $changelogCheck = runCommand([PHP_BINARY, $builder, '--in=' . $changelogPath, '--check'], $repoRoot);
    assertThat($changelogCheck['code'] === 4, 'invalid changelog should exit 4, got ' . $changelogCheck['code']);
    assertThat(str_contains($changelogCheck['stderr'], 'control characters'), 'changelog control characters were not reported');
    assertThat(str_contains($changelogCheck['stderr'], '1000 characters or fewer'), 'changelog max length was not reported');
    assertThat(str_contains($changelogCheck['stderr'], 'plain-text string'), 'changelog type was not reported');
    echo "PASS build.php validates changelog text with exit 4\n";

    $secretPath = $tempRoot . DIRECTORY_SEPARATOR . 'secret-catalog.json';
    writeJson($secretPath, [
        'schema' => 'siro.ecosystem-catalog/1',
        'generated_at' => '2026-11-24T00:00:00Z',
        'items' => [
            catalogItem('siro/secret-item', [
                'private_key' => 'not-a-real-key',
            ]),
        ],
    ]);
    $secretCheck = runCommand([PHP_BINARY, $builder, '--in=' . $secretPath, '--check'], $repoRoot);
    assertThat($secretCheck['code'] === 4, 'secret-like field should exit 4, got ' . $secretCheck['code']);
    assertThat(str_contains($secretCheck['stderr'], 'secret-like field'), 'secret-like field was not reported');
    echo "PASS build.php rejects secret-like fields with exit 4\n";

    $missingPath = $tempRoot . DIRECTORY_SEPARATOR . 'does-not-exist.json';
    $missingCheck = runCommand([PHP_BINARY, $builder, '--in=' . $missingPath, '--check'], $repoRoot);
    assertThat($missingCheck['code'] === 3, 'missing source should exit 3, got ' . $missingCheck['code']);
    assertThat(str_contains($missingCheck['stderr'], 'catalog source not found'), 'missing source error is unclear');
    echo "PASS build.php reports a missing source with exit 3\n";

    $usageCheck = runCommand([PHP_BINARY, $builder, '--bogus'], $repoRoot);
    assertThat($usageCheck['code'] === 2, 'unknown option should exit 2, got ' . $usageCheck['code']);
    echo "PASS build.php reports usage errors with exit 2\n";

    $publisher = __DIR__ . DIRECTORY_SEPARATOR . 'publish-pro.php';
    $publishLint = runCommand([PHP_BINARY, '-l', $publisher], $repoRoot);
    assertThat($publishLint['code'] === 0, 'php -l publish-pro.php failed: ' . $publishLint['stderr'] . $publishLint['stdout']);
    echo "PASS php -l publish-pro.php\n";

    $publishFixture = [
        'schema' => 'siro.ecosystem-catalog/1',
        'generated_at' => '2026-11-24T00:00:00Z',
        'items' => [
            catalogItem('siro/affiliate', [
                'name' => 'Affiliate',
                'edition' => 'pro',
                'status' => 'planned',
                'license_required' => true,
                'license_sku' => 'siro-affiliate',
            ]),
            catalogItem('siro/free-fixture', ['status' => 'published']),
        ],
    ];
    $publishFixturePath = static function (string $name) use ($tempRoot, $publishFixture): string {
        $path = $tempRoot . DIRECTORY_SEPARATOR . $name;
        writeJson($path, $publishFixture);

        return $path;
    };

    $artifactBytes = 'selftest-artifact-payload';
    $artifactPath = $tempRoot . DIRECTORY_SEPARATOR . 'siro-affiliate-1.0.0.siro.zip';
    file_put_contents($artifactPath, $artifactBytes, LOCK_EX);
    $artifactSha = hash('sha256', $artifactBytes);
    $artifactSignature = str_repeat('S', 64);
    $artifactUrl = 'https://cdn.example.com/artifacts/siro-affiliate-1.0.0.siro.zip';

    $publishPath = $publishFixturePath('publish-catalog.json');
    $publishSuccess = runCommand([
        PHP_BINARY,
        $publisher,
        '--catalog=' . $publishPath,
        '--id=siro/affiliate',
        '--version=1.0.0',
        '--artifact=' . $artifactPath,
        '--artifact-url=' . $artifactUrl,
        '--sha256=' . $artifactSha,
        '--signature=' . $artifactSignature,
    ], $repoRoot);
    assertThat($publishSuccess['code'] === 0, 'publish-pro success failed: ' . $publishSuccess['stderr']);
    assertThat(str_contains($publishSuccess['stdout'], 'published siro/affiliate 1.0.0'), 'publish-pro success output is unclear');

    $publishedData = json_decode((string) file_get_contents($publishPath), true, 512, JSON_THROW_ON_ERROR);
    $publishedItem = null;
    foreach ($publishedData['items'] as $candidate) {
        if (($candidate['id'] ?? null) === 'siro/affiliate') {
            $publishedItem = $candidate;
        }
    }
    assertThat($publishedItem !== null, 'publish-pro did not keep the catalog item');
    assertThat($publishedItem['status'] === 'published', 'publish-pro did not set status=published');
    assertThat($publishedItem['latest_version'] === '1.0.0', 'publish-pro did not set latest_version');
    assertThat($publishedItem['artifact_url'] === $artifactUrl, 'publish-pro did not set artifact_url');
    assertThat($publishedItem['sha256'] === $artifactSha, 'publish-pro did not set sha256');
    assertThat($publishedItem['signature'] === $artifactSignature, 'publish-pro did not set signature');
    assertThat($publishedItem['signature_url'] === null, 'publish-pro should clear an absent signature_url');

    $publishedBuild = runCommand([PHP_BINARY, $builder, '--in=' . $publishPath, '--check'], $repoRoot);
    assertThat($publishedBuild['code'] === 0, 'published catalog failed build validation: ' . $publishedBuild['stderr']);
    echo "PASS publish-pro.php publishes a Pro item with a verified local artifact\n";

    $detOne = $publishFixturePath('publish-deterministic-one.json');
    $detTwo = $publishFixturePath('publish-deterministic-two.json');
    $publishArguments = [
        PHP_BINARY,
        $publisher,
        '--id=siro/affiliate',
        '--version=1.0.0',
        '--artifact=' . $artifactPath,
        '--artifact-url=' . $artifactUrl,
        '--sha256=' . $artifactSha,
        '--signature=' . $artifactSignature,
    ];
    $detRunOne = runCommand(array_merge($publishArguments, ['--catalog=' . $detOne]), $repoRoot);
    $detRunTwo = runCommand(array_merge($publishArguments, ['--catalog=' . $detTwo]), $repoRoot);
    assertThat($detRunOne['code'] === 0, 'deterministic publish one failed: ' . $detRunOne['stderr']);
    assertThat($detRunTwo['code'] === 0, 'deterministic publish two failed: ' . $detRunTwo['stderr']);
    assertThat(
        (string) file_get_contents($detOne) === (string) file_get_contents($detTwo),
        'two publishes from the same input are not byte-identical'
    );
    echo "PASS publish-pro.php is deterministic\n";

    $beforeMissingSignature = $publishFixturePath('publish-missing-signature.json');
    $missingSignatureBytes = (string) file_get_contents($beforeMissingSignature);
    $missingSignature = runCommand([
        PHP_BINARY,
        $publisher,
        '--catalog=' . $beforeMissingSignature,
        '--id=siro/affiliate',
        '--version=1.0.0',
        '--artifact=' . $artifactPath,
        '--artifact-url=' . $artifactUrl,
        '--sha256=' . $artifactSha,
    ], $repoRoot);
    assertThat($missingSignature['code'] === 2, 'publish-pro without signature should exit 2, got ' . $missingSignature['code']);
    assertThat(
        (string) file_get_contents($beforeMissingSignature) === $missingSignatureBytes,
        'publish-pro changed the catalog when signature was missing'
    );

    $mismatchPath = $publishFixturePath('publish-checksum-mismatch.json');
    $mismatchBytes = (string) file_get_contents($mismatchPath);
    $mismatch = runCommand([
        PHP_BINARY,
        $publisher,
        '--catalog=' . $mismatchPath,
        '--id=siro/affiliate',
        '--version=1.0.0',
        '--artifact=' . $artifactPath,
        '--artifact-url=' . $artifactUrl,
        '--sha256=' . str_repeat('b', 64),
        '--signature=' . $artifactSignature,
    ], $repoRoot);
    assertThat($mismatch['code'] === 4, 'publish-pro checksum mismatch should exit 4, got ' . $mismatch['code']);
    assertThat(str_contains($mismatch['stderr'], 'mismatch'), 'publish-pro checksum mismatch message is unclear');
    assertThat(
        (string) file_get_contents($mismatchPath) === $mismatchBytes,
        'publish-pro changed the catalog after a checksum mismatch'
    );

    $unknownPath = $publishFixturePath('publish-unknown-id.json');
    $unknown = runCommand([
        PHP_BINARY,
        $publisher,
        '--catalog=' . $unknownPath,
        '--id=siro/not-real',
        '--version=1.0.0',
        '--artifact=' . $artifactPath,
        '--artifact-url=' . $artifactUrl,
        '--sha256=' . $artifactSha,
        '--signature=' . $artifactSignature,
    ], $repoRoot);
    assertThat($unknown['code'] === 4, 'publish-pro unknown id should exit 4, got ' . $unknown['code']);

    $freePath = $publishFixturePath('publish-free-item.json');
    $free = runCommand([
        PHP_BINARY,
        $publisher,
        '--catalog=' . $freePath,
        '--id=siro/free-fixture',
        '--version=1.0.0',
        '--artifact=' . $artifactPath,
        '--artifact-url=' . $artifactUrl,
        '--sha256=' . $artifactSha,
        '--signature=' . $artifactSignature,
    ], $repoRoot);
    assertThat($free['code'] === 4, 'publish-pro free item should exit 4, got ' . $free['code']);

    $dryRunPath = $publishFixturePath('publish-dry-run.json');
    $dryRunBytes = (string) file_get_contents($dryRunPath);
    $dryRun = runCommand(array_merge($publishArguments, ['--catalog=' . $dryRunPath, '--dry-run']), $repoRoot);
    assertThat($dryRun['code'] === 0, 'publish-pro dry-run should exit 0, got ' . $dryRun['code']);
    assertThat(str_contains($dryRun['stdout'], 'DRY RUN'), 'publish-pro dry-run output is unclear');
    assertThat(
        (string) file_get_contents($dryRunPath) === $dryRunBytes,
        'publish-pro dry-run changed the catalog'
    );

    $urlPath = $publishFixturePath('publish-url-artifact.json');
    $urlPublish = runCommand([
        PHP_BINARY,
        $publisher,
        '--catalog=' . $urlPath,
        '--id=siro/affiliate',
        '--version=1.0.0',
        '--artifact=' . $artifactUrl,
        '--sha256=' . $artifactSha,
        '--signature=' . $artifactSignature,
    ], $repoRoot);
    assertThat($urlPublish['code'] === 0, 'publish-pro URL artifact failed: ' . $urlPublish['stderr']);
    echo "PASS publish-pro.php refuses missing signature, checksum mismatch and non-Pro items\n";

    echo "All ecosystem catalog self-tests passed.\n";
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, 'FAIL: ' . $exception->getMessage() . PHP_EOL);
    fwrite(STDERR, 'Temp directory (removed next): ' . $tempRoot . PHP_EOL);
    exit(EXIT_TEST_FAILURE);
} finally {
    removeTree($tempRoot);
}
