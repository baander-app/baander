<?php

declare(strict_types=1);

namespace SwooleBundle\SwooleBundle\Server\Runtime\HMR;

use Assert\Assertion;
use Assert\AssertionFailedException;
use Swoole\Server;
use Swoole\Timer;
use SwooleBundle\SwooleBundle\Server\Runtime\Bootable;

final class InotifyHMR implements HotModuleReloader, Bootable
{
    private const DEBOUNCE_MS = 500;

    /**
     * @var array<string, true> file path => true map
     */
    private array $nonReloadableFiles;

    /**
     * @var array<string, false|int> file path => int|false map
     */
    private array $watchedFiles = [];

    /**
     * @var resource returned by \inotify_init
     */
    private $inotify;

    /**
     * @var int IN_CLOSE_WRITE
     */
    private readonly int $watchMask;

    private bool $reloadPending = false;

    /**
     * @param array<string> $nonReloadableFiles
     * @throws AssertionFailedException
     */
    public function __construct(array $nonReloadableFiles = [])
    {
        Assertion::extensionLoaded(
            'inotify',
            'Swoole HMR requires "inotify" PHP Extension present and loaded in the system.'
        );
        $this->watchMask = defined('IN_CLOSE_WRITE') ? (int) constant('IN_CLOSE_WRITE') : 8;

        $this->setNonReloadableFiles($nonReloadableFiles);
    }

    public function __destruct()
    {
        if ($this->inotify === null) {
            return;
        }

        fclose($this->inotify);
    }

    public function tick(Server $server): void
    {
        // Only worker 0 handles HMR to prevent reload races across workers.
        // Each worker is a separate process with its own inotify handle and tick timer.
        // Without this guard, all 4 workers independently trigger $server->reload()
        // for the same file change — one reload becomes four, causing restart storms.
        if ($server->worker_id !== 0) {
            return;
        }

        // Drain the inotify queue to prevent buildup, even when debouncing
        $hasEvents = inotify_read($this->inotify) !== false;

        if ($hasEvents && !$this->reloadPending) {
            $this->reloadPending = true;
            Timer::after(self::DEBOUNCE_MS, function () use ($server): void {
                $this->reloadPending = false;
                $server->reload();
            });
        }

        $this->watchFiles(get_included_files());
    }

    /**
     * {@inheritDoc}
     *
     * @throws AssertionFailedException
     */
    public function boot(array $runtimeConfiguration = []): void
    {
        if (!empty($runtimeConfiguration['nonReloadableFiles'])) {
            $this->setNonReloadableFiles($runtimeConfiguration['nonReloadableFiles']);
        }

        // Files included before server start cannot be reloaded due to PHP limitations
        $this->setNonReloadableFiles(get_included_files());
        $this->initializeInotify();
    }

    /**
     * @return array<string>
     */
    public function getNonReloadableFiles(): array
    {
        return array_keys($this->nonReloadableFiles);
    }

    /**
     * @param array<string> $nonReloadableFiles files
     * @throws AssertionFailedException
     */
    private function setNonReloadableFiles(array $nonReloadableFiles): void
    {
        foreach ($nonReloadableFiles as $nonReloadableFile) {
            Assertion::file($nonReloadableFile);
            $this->nonReloadableFiles[$nonReloadableFile] = true;
        }
    }

    /**
     * @param array<string> $files
     */
    private function watchFiles(array $files): void
    {
        foreach ($files as $file) {
            if (isset($this->nonReloadableFiles[$file]) || isset($this->watchedFiles[$file])) {
                continue;
            }

            $this->watchedFiles[$file] = inotify_add_watch($this->inotify, $file, $this->watchMask);
        }
    }

    private function initializeInotify(): void
    {
        $this->inotify = inotify_init();
        stream_set_blocking($this->inotify, false);
    }
}
