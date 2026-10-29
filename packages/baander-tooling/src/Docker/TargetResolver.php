<?php

declare(strict_types=1);

namespace Baander\Tooling\Docker;

use Baander\Tooling\Configuration\DockerSettings;
use Baander\Tooling\Filesystem\PrivateDirectory;
use Baander\Tooling\Process\CommandExecutor;
use Baander\Tooling\Process\ProcessSpec;

final readonly class TargetResolver
{
    /** @param array<string,string> $environment */
    public function __construct(private CommandExecutor $executor, private array $environment) {}

    public function resolve(DockerSettings $settings, string $snapshotDirectory): Target
    {
        $executable = $this->executable($settings->executable);
        $context = $settings->context;
        $endpoint = $settings->host;
        if ($context === null && $endpoint === null) {
            $context = $this->nonempty('DOCKER_CONTEXT');
            if ($context === null) {
                $endpoint = $this->nonempty('DOCKER_HOST');
            }
        }
        if ($context === null && $endpoint === null) {
            $context = trim($this->administrative($executable, ['context', 'show']));
            if ($context === '') {
                throw new \RuntimeException('Docker did not report its selected context.');
            }
        }
        $tlsDirectory = null;
        $requiredFiles = [];
        $verify = false;
        $tls = false;
        if ($context !== null) {
            $inspection = json_decode($this->administrative($executable, ['context', 'inspect', $context]), true, flags: JSON_THROW_ON_ERROR);
            $docker = $inspection[0]['Endpoints']['docker'] ?? null;
            if (!is_array($docker) || !is_string($docker['Host'] ?? null)) {
                throw new \RuntimeException('Docker context has no supported Docker endpoint.');
            }
            $endpoint = $docker['Host'];
            $base = $inspection[0]['Storage']['TLSPath'] ?? null;
            $requiredFiles = $inspection[0]['TLSMaterial']['docker'] ?? [];
            if ($requiredFiles !== [] && (!is_string($base) || !is_dir($base . '/docker'))) {
                throw new \RuntimeException('Docker context TLS connection material is missing.');
            }
            if (is_string($base) && is_dir($base . '/docker')) {
                $tlsDirectory = $base . '/docker';
                $tls = true;
                $verify = !($docker['SkipTLSVerify'] ?? false);
            }
        }
        // The built-in context represents the CLI's ambient host/TLS configuration.
        if ($context === null || $context === 'default') {
            $verify = $this->nonempty('DOCKER_TLS_VERIFY') !== null;
            $tls = $verify || $this->nonempty('DOCKER_TLS') !== null;
            if ($tls) {
                $tlsDirectory = $this->nonempty('DOCKER_CERT_PATH') ?? (($this->nonempty('DOCKER_CONFIG') ?? (($this->environment['HOME'] ?? '') . '/.docker')));
            }
        }
        new DockerSettings($executable, host: $endpoint);
        PrivateDirectory::prepare($snapshotDirectory);
        $snapshotDirectory = realpath($snapshotDirectory) ?: throw new \RuntimeException('Cannot resolve Docker transport snapshot directory.');
        $flags = [];
        $files = [];
        if ($tls) {
            $flags[] = $verify ? '--tlsverify' : '--tls';
            foreach (['ca.pem' => '--tlscacert', 'cert.pem' => '--tlscert', 'key.pem' => '--tlskey'] as $name => $flag) {
                $source = $tlsDirectory . '/' . $name;
                if (!file_exists($source)) {
                    if (in_array($name, $requiredFiles, true)) {
                        throw new \RuntimeException('Docker context TLS connection material is missing: ' . $name);
                    }
                    array_push($flags, $flag, '');
                    continue;
                }
                if (!is_file($source) || !is_readable($source)) {
                    throw new \RuntimeException('Docker TLS connection material is unreadable: ' . $name);
                }
                $destination = $snapshotDirectory . '/' . $name;
                $files[$destination] = self::snapshotFile($source, $destination);
                array_push($flags, $flag, $destination);
            }
        }
        $result = $this->executor->run(new ProcessSpec([$executable, '--host', $endpoint, ...$flags, 'info', '--format', '{{json .}}'], environment: Client::transportEnvironment($this->environment), processGroup: true, replaceEnvironment: true));
        $information = json_decode($result->stdout, true, flags: JSON_THROW_ON_ERROR);
        $id = $information['ID'] ?? null;
        if (!is_string($id) || $id === '') {
            throw new \RuntimeException('Cannot establish Docker daemon identity.');
        }
        return new Target($executable, $endpoint, $id, $context, $snapshotDirectory, $flags, $files);
    }

    private static function snapshotFile(string $source, string $destination): string
    {
        $output = @fopen($destination, 'x');
        if ($output === false) {
            throw new \RuntimeException('Cannot create private Docker TLS snapshot.');
        }
        try {
            if (!chmod($destination, 0600)) {
                throw new \RuntimeException('Cannot protect Docker TLS snapshot.');
            }
            $input = fopen($source, 'rb');
            if ($input === false) {
                throw new \RuntimeException('Cannot read Docker TLS connection material.');
            }
            try {
                if (stream_copy_to_stream($input, $output) === false) {
                    throw new \RuntimeException('Cannot copy Docker TLS connection material.');
                }
            } finally {
                fclose($input);
            }
        } finally {
            fclose($output);
        }
        return hash_file('sha256', $destination) ?: throw new \RuntimeException('Cannot fingerprint Docker transport snapshot.');
    }

    /** @param list<string> $arguments */
    private function administrative(string $executable, array $arguments): string
    {
        return $this->executor->run(new ProcessSpec([$executable, ...$arguments], environment: $this->environment, processGroup: true, replaceEnvironment: true))->stdout;
    }

    private function nonempty(string $name): ?string
    {
        $value = $this->environment[$name] ?? '';
        return $value === '' ? null : $value;
    }

    private function executable(string $name): string
    {
        $candidates = str_contains($name, '/') ? [$name] : array_map(static fn(string $path): string => $path . '/' . $name, explode(PATH_SEPARATOR, $this->environment['PATH'] ?? ''));
        foreach ($candidates as $candidate) {
            if (is_file($candidate) && is_executable($candidate)) {
                return realpath($candidate) ?: $candidate;
            }
        }
        throw new \RuntimeException('Docker executable is unavailable: ' . $name);
    }
}
