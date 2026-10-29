<?php

declare(strict_types=1);

namespace Baander\Tooling\Tests;

use Baander\Tooling\Development\Acceptance\MediaClient;
use Baander\Tooling\Development\Acceptance\MediaProbe;
use Baander\Tooling\Development\Acceptance\Support;
use Baander\Tooling\Process\ProcessRunner;
use Baander\Tooling\Process\ProcessSpec;
use PHPUnit\Framework\TestCase;

final class DevelopmentAcceptanceTest extends TestCase
{
    private \Baander\Tooling\Process\EngineExecutor $executor;
    private string $root;

    protected function setUp(): void
    {
        $this->executor = new \Baander\Tooling\Process\EngineExecutor(new \Baander\Tooling\Process\ExecutionContext(getenv()));
        $this->root = Support::temporary('baander-acceptance-test-');
    }
    protected function tearDown(): void
    {
        $this->executor->close();
        Support::removeTree($this->root);
    }

    private function decode(string $value): string
    {
        return (string) base64_decode(strtr($value, '-_', '+/'), true);
    }

    public function testDpopProofHasVerifiableEs256SignatureAndBoundClaims(): void
    {
        $client = new MediaClient('https://media.baander.app/', '/unused', $this->executor->context);
        $client->token = 'private-test-token';
        [$header, $claims, $signature] = explode('.', $client->proof('POST', 'https://media.baander.app/api/song?private=query#fragment'));
        $decodedHeader = json_decode($this->decode($header), true, flags: JSON_THROW_ON_ERROR);
        $decodedClaims = json_decode($this->decode($claims), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(['typ' => 'dpop+jwt', 'alg' => 'ES256', 'jwk' => $client->jwk], $decodedHeader);
        self::assertSame('POST', $decodedClaims['htm']);
        self::assertSame('https://media.baander.app/api/song', $decodedClaims['htu']);
        self::assertSame(
            rtrim(strtr(base64_encode(hash('sha256', 'private-test-token', true)), '+/', '-_'), '='),
            $decodedClaims['ath'],
        );
        self::assertLessThanOrEqual(1, abs(time() - $decodedClaims['iat']));
        $point = "\x04" . $this->decode($client->jwk['x']) . $this->decode($client->jwk['y']);
        $public = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $point;
        $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($public), 64, "\n") . "-----END PUBLIC KEY-----\n";
        $raw = $this->decode($signature);
        self::assertSame(64, strlen($raw));
        $integer = static function (string $value): string {
            $value = ltrim($value, "\0");
            if (ord($value[0]) >= 128) {
                $value = "\0" . $value;
            }
            return "\x02" . chr(strlen($value)) . $value;
        };
        $pair = $integer(substr($raw, 0, 32)) . $integer(substr($raw, 32));
        self::assertSame(
            1,
            openssl_verify($header . '.' . $claims, "\x30" . chr(strlen($pair)) . $pair, $pem, OPENSSL_ALGO_SHA256),
        );
    }

    public function testVerifiedHttpsNegotiatesNonceAndReadsAudioToEof(): void
    {
        $configuration = $this->root . '/openssl.cnf';
        file_put_contents($configuration, "[req]\ndistinguished_name=dn\n[dn]\n[v3]\nsubjectAltName=IP:127.0.0.1\n");
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        self::assertNotFalse($key);
        $request = openssl_csr_new(['commonName' => '127.0.0.1'], $key, ['config' => $configuration]);
        self::assertNotFalse($request);
        $certificate = openssl_csr_sign($request, null, $key, 1, ['config' => $configuration, 'x509_extensions' => 'v3']);
        self::assertNotFalse($certificate);
        openssl_x509_export_to_file($certificate, $this->root . '/certificate.pem');
        openssl_pkey_export_to_file($key, $this->root . '/key.pem');
        $runner = new ProcessRunner();
        $server = $runner->start(new ProcessSpec(
            [PHP_BINARY, __DIR__ . '/Fixtures/media-https.php', $this->root],
            environment: getenv(),
            timeout: 20,
            processGroup: true,
        ));
        try {
            $deadline = Support::now() + 5;
            while (!is_file($this->root . '/port') && Support::now() < $deadline) {
                $server->pump();
                usleep(10000);
            }
            self::assertFileExists($this->root . '/port');
            $origin = 'https://127.0.0.1:' . trim((string) file_get_contents($this->root . '/port'));
            $client = new MediaClient($origin, $this->root . '/certificate.pem', $this->executor->context);
            self::assertSame([200, ['authenticated' => true]], $client->request('/nonce', 'POST', []));
            $requestText = (string) file_get_contents($this->root . '/request-1');
            self::assertStringEndsWith('{}', $requestText);
            preg_match('/^DPoP: (.+)$/m', $requestText, $proof);
            $claims = json_decode($this->decode(explode('.', trim($proof[1]))[1]), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame('qualified-nonce', $claims['nonce']);
            $result = $client->request('/audio', destination: $this->root . '/audio.flac');
            self::assertSame(strlen("audio\0bytes"), $result['bytes']);
            self::assertSame(hash('sha256', "audio\0bytes"), $result['sha256']);
            self::assertTrue($result['readToEof']);
            try {
                $client->request('/truncated', destination: $this->root . '/truncated.flac');
                self::fail('Truncated Content-Length was accepted.');
            } catch (\RuntimeException $error) {
                self::assertSame('Audio response ended before Content-Length.', $error->getMessage());
            }
            self::assertSame(0, $runner->wait($server)->exitCode);
        } finally {
            $server->dispose();
        }
    }

    public function testAudioOracleRequiresTheCompleteNonSilentEightSecondTone(): void
    {
        $fixture = $this->root . '/tone.flac';
        $this->executor->run(new \Baander\Tooling\Process\ProcessSpec([
            'ffmpeg',
            '-v',
            'error',
            '-f',
            'lavfi',
            '-i',
            'sine=frequency=440:sample_rate=48000:duration=8',
            '-c:a',
            'flac',
            $fixture,
        ], processGroup: true), new \Baander\Tooling\Process\CompletionPolicy([0]));
        $result = new MediaProbe($this->executor, $this->executor->context)->decodedAudio($fixture);
        self::assertSame('flac', $result['codec']);
        self::assertSame(64000, $result['decodedSamples']);
        self::assertTrue($result['nonSilent']);
        $short = $this->root . '/short.flac';
        $this->executor->run(new \Baander\Tooling\Process\ProcessSpec([
            'ffmpeg',
            '-v',
            'error',
            '-f',
            'lavfi',
            '-i',
            'sine=frequency=440:sample_rate=48000:duration=1',
            '-c:a',
            'flac',
            $short,
        ], processGroup: true), new \Baander\Tooling\Process\CompletionPolicy([0]));
        $this->expectExceptionMessage('Audio did not decode the complete eight second tone:');
        new MediaProbe($this->executor, $this->executor->context)->decodedAudio($short);
    }

    public function testMediaProbeRejectsSymlinkedFixtureBeforeAnyWrite(): void
    {
        file_put_contents($this->root . '/disposable-qualification', 'owned');
        mkdir($this->root . '/media');
        file_put_contents($this->root . '/outside.flac', 'unchanged');
        symlink($this->root . '/outside.flac', $this->root . '/media/workflow-qualification-tone.flac');
        try {
            new MediaProbe($this->executor, $this->executor->context)->qualify(['workspace' => $this->root, 'state-root' => $this->root . '/state', 'instance' => 'persistent']);
            self::fail('Symlinked source fixture was accepted.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('symlink', strtolower($error->getMessage()));
            self::assertSame('unchanged', file_get_contents($this->root . '/outside.flac'));
        }
    }

    public function testExistingWorkspaceIsRejectedBeforeDockerOrStateMutation(): void
    {
        $state = $this->root . '/state';
        $result = $this->executor->run(new \Baander\Tooling\Process\ProcessSpec([
            PHP_BINARY,
            dirname(__DIR__, 2) . '/dev_environment_acceptance.php',
            '--workspace',
            $this->root,
            '--state-root',
            $state,
        ], processGroup: true), new \Baander\Tooling\Process\CompletionPolicy(null));
        self::assertSame(1, $result->exitCode);
        self::assertStringContainsString('Select a new workspace', $result->stderr);
        self::assertDirectoryDoesNotExist($state);
    }
}
