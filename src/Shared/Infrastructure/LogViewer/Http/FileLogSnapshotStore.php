<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\LogViewer\Http;

use App\Shared\Application\Exception\InvalidInputException;
use App\Shared\Application\Exception\ServiceUnavailableException;
use App\Shared\Application\LogViewer\Service\LogSnapshotStoreInterface;

final readonly class FileLogSnapshotStore implements LogSnapshotStoreInterface
{
    public function __construct(private string $directory)
    {
    }

    public function save(array $snapshot): string
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new ServiceUnavailableException('Cannot create log snapshot storage.');
        }
        $lock = fopen($this->directory.'/.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            throw new ServiceUnavailableException('Cannot lock log snapshot storage.');
        }
        try {
            $bytes = 0;
            foreach (glob($this->directory.'/*.json') ?: [] as $path) {
                if (filemtime($path) < time() - 900) {
                    unlink($path);
                } else {
                    $bytes += filesize($path);
                }
            }
            $json = json_encode($snapshot, JSON_THROW_ON_ERROR);
            if ($bytes + strlen($json) > 268435456) {
                throw new ServiceUnavailableException('Log snapshot storage is full; retry after existing snapshots expire.');
            }
            $id = bin2hex(random_bytes(24));
            $path = $this->directory.'/'.$id.'.json';
            if (file_put_contents($path, $json, LOCK_EX) !== strlen($json)) {
                @unlink($path);
                throw new ServiceUnavailableException('Cannot retain log snapshot.');
            }
            chmod($path, 0600);

            return $id;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function load(string $id): array
    {
        if (preg_match('/^[a-f0-9]{48}$/D', $id) !== 1) {
            throw new InvalidInputException('Invalid log snapshot.');
        }
        $path = $this->directory.'/'.$id.'.json';
        if (!is_file($path) || filemtime($path) < time() - 900) {
            throw new InvalidInputException('Log snapshot expired; refresh the query.');
        }
        $data = json_decode(file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new ServiceUnavailableException('Log snapshot is unavailable.');
        }

        if (strtotime($data['snapshot']['expiresAt'] ?? '') < time()) {
            throw new InvalidInputException('Log snapshot expired; refresh the query.');
        }

        return $data;
    }
}
