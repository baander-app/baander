<?php

declare(strict_types=1);

namespace App\Tests\Support\Outbound;

/** A disposable HTTP/TLS peer; clients must pin its named hosts to loopback. */
final class LocalTlsServer
{
    public readonly string $certificate;
    public readonly int $tlsPort;
    public readonly int $httpPort;
    private readonly string $directory;
    /** @var resource */
    private $process;
    /** @var array<int, resource> */
    private array $pipes = [];

    public function __construct()
    {
        $this->directory = sys_get_temp_dir() . '/baander-outbound-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        $this->certificate = $this->directory . '/certificate.pem';
        $key = openssl_pkey_new(['private_key_bits' => 2048]);
        $csr = openssl_csr_new(['commonName' => 'outbound.baander.app'], $key);
        $certificate = openssl_csr_sign($csr, null, $key, 1);
        openssl_x509_export_to_file($certificate, $this->certificate);
        openssl_pkey_export_to_file($key, $this->directory . '/key.pem');
        $this->process = proc_open([
            PHP_BINARY,
            dirname(__DIR__, 2) . '/Fixtures/Outbound/server.php',
            $this->directory,
        ], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $this->pipes);
        stream_set_timeout($this->pipes[1], 5);
        $line = fgets($this->pipes[1]);
        if ($line === false) {
            $this->close();
            throw new \RuntimeException('The disposable outbound server did not start.');
        }
        $ports = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        $this->tlsPort = $ports['tls'];
        $this->httpPort = $ports['http'];
    }

    /** @return list<string> */
    public function requests(): array
    {
        $path = $this->directory . '/requests';
        return is_file($path) ? file($path, FILE_IGNORE_NEW_LINES) : [];
    }

    public function close(): void
    {
        proc_terminate($this->process);
        foreach ($this->pipes as $pipe) {
            fclose($pipe);
        }
        proc_close($this->process);
        foreach (glob($this->directory . '/*') as $path) {
            unlink($path);
        }
        rmdir($this->directory);
    }
}
