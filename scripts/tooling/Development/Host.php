<?php

declare(strict_types=1);

namespace Baander\Tooling\Development;

use Baander\Tooling\Filesystem\AtomicFile;
use Baander\Tooling\Filesystem\PrivateDirectory;

final class Host
{
    public function __construct(private readonly StateStore $store, private readonly Runner $runner,
        private readonly HostSystem $system = new HostSystem()) {}

    /** @return array<string,mixed> */
    public function inspect(): array
    {
        if ($this->system->executable('nmcli') === null) {
            throw new WorkflowError('Host setup requires NetworkManager managing DNS; install/configure it explicitly.');
        }
        $manager = $this->system->executable('NetworkManager');
        if ($manager === null && is_file('/usr/sbin/NetworkManager')) {
            $manager = '/usr/sbin/NetworkManager';
        }
        if ($manager === null) {
            throw new WorkflowError('The NetworkManager daemon executable is unavailable.');
        }
        $configuration = $this->runner->run([$manager, '--print-config'])->stdout;
        if (preg_match('/^\s*(dns\s*=\s*none|rc-manager\s*=\s*unmanaged)\s*$/m', $configuration) === 1) {
            throw new WorkflowError('NetworkManager DNS is disabled; resolve host DNS ownership before setup.');
        }
        if (!$this->system->resolverOwned()) {
            throw new WorkflowError('NetworkManager does not own resolv.conf; configure DNS ownership explicitly.');
        }
        if ($this->system->executable('mkcert') === null) {
            throw new WorkflowError('Install mkcert, then invoke host certificate setup explicitly.');
        }
        $this->system->assertPortAvailable();
        return ['resolver' => 'NetworkManager', 'port' => 443, 'hostname' => 'baander.test'];
    }

    /** @param list<string> $paths */
    public function sharePaths(array $paths): void
    {
        $ca = Environment::resolvedPath($this->store->root . '/host-setup/ca');
        $sources = [];
        foreach ($paths as $path) {
            $source = realpath(Environment::absolutePath($path));
            if ($source === false || !is_dir($source)) {
                throw new WorkflowError('Select existing directories for container access.');
            }
            if (self::contains($source, $ca) || self::contains($ca, $source)) {
                throw new WorkflowError('A shared path must not expose the private certificate authority.');
            }
            $sources[] = $source;
        }
        if (!$this->system->selinuxEnabled()) {
            return;
        }
        $executable = $this->system->executable('chcon');
        if ($executable === null) {
            throw new WorkflowError('Install chcon before preparing SELinux container access.');
        }
        PrivateDirectory::prepare($ca);
        PrivateDirectory::prepare($this->store->root);
        $this->runner->run([$executable, '-t', 'container_file_t', '-l', 's0', $this->store->root]);
        $gateway = $this->store->root . '/gateway';
        PrivateDirectory::prepare($gateway);
        $instances = [];
        foreach (scandir($this->store->root) ?: [] as $name) {
            if (preg_match('/\A(?:persistent|preview-[a-f0-9]{16})\z/', $name) === 1) {
                $instances[] = $this->store->directory($name);
            }
        }
        foreach ([$gateway, ...$instances, ...$sources] as $source) {
            if (is_link($source)) {
                throw new WorkflowError('Refusing to relabel a symlink instance directory.');
            }
            $this->runner->run([$executable, '-R', '--no-dereference', '-t', 'container_file_t', '-l', 's0', $source], timeout: null);
        }
    }

    private static function contains(string $parent, string $child): bool
    {
        return $parent === $child || str_starts_with($child, rtrim($parent, '/') . '/');
    }

    public function instructions(): string
    {
        $directory = $this->store->root . '/host-setup';
        PrivateDirectory::prepare($directory);
        $source = dirname(__DIR__, 3) . '/docker/dev/host-setup';
        foreach (['10-baander-dns.conf', 'baander.conf', 'README.md'] as $name) {
            $content = file_get_contents($source . '/' . $name);
            if ($content === false) {
                throw new WorkflowError('Cannot read host setup instructions.');
            }
            AtomicFile::write($directory . '/' . $name, $content);
        }
        return $directory;
    }

    public function certificates(bool $installTrust = false): string
    {
        $directory = $this->store->root . '/gateway/tls';
        $ca = $this->store->root . '/host-setup/ca';
        PrivateDirectory::prepare($directory);
        PrivateDirectory::prepare($ca);
        $environment = ['CAROOT' => $ca];
        if ($installTrust) {
            $this->runner->run(['mkcert', '-install'], env: $environment, interactive: true);
        }
        foreach (['cert.pem', 'key.pem'] as $name) {
            PrivateDirectory::assertNoSymlinks($directory . '/' . $name);
        }
        $this->runner->run(['mkcert', '-cert-file', $directory . '/cert.pem', '-key-file', $directory . '/key.pem',
            'baander.test', '*.baander.test'], env: $environment);
        if (!chmod($directory . '/key.pem', 0600)) {
            throw new WorkflowError('Cannot make the gateway private key private.');
        }
        return $directory;
    }
}
