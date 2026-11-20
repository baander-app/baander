<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\LogViewer\Agent;

/** Local spool grants retirement only the bounded final-collection operation. */
final readonly class CheckpointExchange
{
    private string $directory;

    public function __construct(string $databasePath)
    {
        $this->directory = $databasePath.'.checkpoints';
        if (!is_dir($this->directory) && !mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new \RuntimeException('Cannot create checkpoint spool.');
        }
    }

    public function request(string $containerId, float $timeout): string
    {
        if (!preg_match('/^[a-f0-9]{12,64}$/D', $containerId) || $timeout < 0.1 || $timeout > 10) {
            throw new \InvalidArgumentException('Invalid checkpoint request.');
        }
        $id = bin2hex(random_bytes(16));
        $path = $this->directory.'/'.$id.'.request';
        $temporary = $path.'.tmp';
        file_put_contents($temporary, json_encode(['containerId' => $containerId, 'expiresAt' => microtime(true) + $timeout], JSON_THROW_ON_ERROR), LOCK_EX);
        rename($temporary, $path);

        return $id;
    }

    /** @return array<string, mixed>|null */
    public function result(string $id): ?array
    {
        $path = $this->directory.'/'.$id.'.response';
        if (!is_file($path)) {
            return null;
        }
        $result = json_decode((string) file_get_contents($path), true, 16, JSON_THROW_ON_ERROR);
        unlink($path);

        return $result;
    }

    public function process(Collector $collector): void
    {
        foreach (glob($this->directory.'/*.request') ?: [] as $path) {
            $request = json_decode((string) file_get_contents($path), true, 16, JSON_THROW_ON_ERROR);
            unlink($path);
            try {
                if (($request['expiresAt'] ?? 0) < microtime(true)) {
                    throw new \RuntimeException('Final collection deadline expired.');
                }
                $collector->tick($request['containerId'], $request['expiresAt']);
                $result = ['complete' => microtime(true) <= $request['expiresAt'], 'containerId' => $request['containerId']];
            } catch (\Throwable) {
                $result = ['complete' => false, 'containerId' => $request['containerId'], 'error' => 'Final collection failed; a collection gap remains.'];
            }
            if (!$result['complete']) {
                $collector->finalGap($request['containerId']);
            }
            if (microtime(true) <= $request['expiresAt']) {
                file_put_contents(str_replace('.request', '.response', $path), json_encode($result, JSON_THROW_ON_ERROR), LOCK_EX);
            }
        }
        foreach (glob($this->directory.'/*.response') ?: [] as $path) {
            if (filemtime($path) < time() - 60) {
                unlink($path);
            }
        }
    }
}
