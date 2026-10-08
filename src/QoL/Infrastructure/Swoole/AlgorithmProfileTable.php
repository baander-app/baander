<?php

declare(strict_types=1);

namespace App\QoL\Infrastructure\Swoole;

use App\QoL\Domain\Port\AlgorithmProfileStoreInterface;
use App\QoL\Domain\ValueObject\AlgorithmProfile;
use JsonException;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Swoole\Table;
use SwooleBundle\SwooleBundle\Server\Runtime\Bootable;

/**
 * The governor's algorithm profile, shared by every HTTP worker.
 *
 * Implements Bootable: boot() creates the Swoole\Table before the server forks
 * its workers and fills it from the saved profile file, so every worker, and every
 * worker started by a reload, reads the same profile. A change is saved to its own
 * file before it reaches the table; learning persistence never writes the profile,
 * so a worker that missed a change cannot overwrite it.
 *
 * Outside the server (console, tests) the table is never created and the profile
 * is held in this process only.
 *
 * set() runs inside server control operations: it must not touch pooled services
 * such as the logger.
 */
final class AlgorithmProfileTable implements AlgorithmProfileStoreInterface, Bootable
{
    private const string KEY = 'profile';
    private const string FILE = 'algorithm_profile.json';

    private ?Table $table = null;
    private AlgorithmProfile $local = AlgorithmProfile::Balanced;

    public function __construct(
        private readonly string $stateDir,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function boot(array $runtimeConfiguration = []): void
    {
        if ($this->table !== null) {
            return;
        }

        $table = new Table(2);
        $table->column(self::KEY, Table::TYPE_STRING, 16);
        $table->create();
        $table->set(self::KEY, [self::KEY => $this->load()->value]);
        $this->table = $table;
    }

    public function get(): AlgorithmProfile
    {
        if ($this->table === null) {
            return $this->local;
        }

        $value = $this->table->get(self::KEY, self::KEY);

        return is_string($value) ? (AlgorithmProfile::tryFrom($value) ?? AlgorithmProfile::Balanced) : AlgorithmProfile::Balanced;
    }

    /** @throws RuntimeException when the profile cannot be saved; the table then keeps the previous profile */
    public function set(AlgorithmProfile $profile): void
    {
        $this->save($profile);
        $this->local = $profile;
        $this->table?->set(self::KEY, [self::KEY => $profile->value]);
    }

    /** The saved profile, or Balanced when none is saved or the file cannot be read. */
    private function load(): AlgorithmProfile
    {
        $path = $this->path();
        if (!is_file($path)) {
            return AlgorithmProfile::Balanced;
        }

        try {
            $saved = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
            $profile = is_array($saved) && is_string($saved['profile'] ?? null) ? AlgorithmProfile::tryFrom($saved['profile']) : null;
        } catch (JsonException) {
            $profile = null;
        }
        if ($profile === null) {
            $this->logger->warning('QoL algorithm profile file is unreadable; using the balanced profile', ['path' => $path]);

            return AlgorithmProfile::Balanced;
        }

        return $profile;
    }

    /** Writes a temporary file and renames it, so concurrent writers never leave a torn file. */
    private function save(AlgorithmProfile $profile): void
    {
        if (!is_dir($this->stateDir) && !mkdir($this->stateDir, 0755, true) && !is_dir($this->stateDir)) {
            throw new RuntimeException(sprintf('Cannot create the QoL state directory %s.', $this->stateDir));
        }

        $path = $this->path();
        $temporary = sprintf('%s.%s.tmp', $path, bin2hex(random_bytes(6)));
        $json = json_encode(['profile' => $profile->value], JSON_THROW_ON_ERROR);
        if (file_put_contents($temporary, $json) === false || !rename($temporary, $path)) {
            if (is_file($temporary)) {
                unlink($temporary);
            }

            throw new RuntimeException(sprintf('Cannot save the QoL algorithm profile to %s.', $path));
        }
    }

    private function path(): string
    {
        return sprintf('%s/%s', $this->stateDir, self::FILE);
    }
}
