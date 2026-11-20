<?php

declare(strict_types=1);

namespace Baander\DevQualification\Tests\Support;

use Baander\Containers\Docker\Client;
use Baander\Containers\Docker\Target;
use Baander\DevQualification\Fixtures\Qualification\Application;
use Baander\DevQualification\Fixtures\Qualification\Dataset;
use Baander\Process\Execution\CommandExecutor;
use Baander\Process\Execution\CompletionPolicy;
use Baander\Process\Execution\ProcessResult;
use Baander\Process\Execution\ProcessSpec;
use Baander\Process\Execution\RunningProcess;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class QualificationApplicationRunner implements CommandExecutor
{
    /** @var list<ProcessSpec> */
    public array $calls = [];
    public bool $wrongVolume = false;
    public bool $missingCatalog = false;
    public bool $fail = false;
    public bool $changedLibrary = false;
    public bool $customClient = false;
    public bool $missingQueues = false;
    private int $user = 0;
    /** @var list<array<string,string>> */
    private array $libraries = [];

    public function run(ProcessSpec $command, CompletionPolicy $policy = new CompletionPolicy()): ProcessResult
    {
        $this->calls[] = $command;
        $args = $command->argv;
        $output = '{}';
        if (in_array('ps', $args, true)) {
            $output = str_repeat(in_array('web', $args, true) ? 'a' : 'b', 64);
        } elseif (in_array('inspect', $args, true)) {
            $web = in_array(str_repeat('a', 64), $args, true);
            $output = json_encode([['Id' => str_repeat($web ? 'a' : 'b', 64), 'Image' => 'sha256:' . str_repeat($web ? 'a' : 'b', 64), 'State' => ['Running' => true], 'Config' => ['Labels' => ['org.baander.preset.owner' => 'fixture-owner']], 'Mounts' => [['Type' => 'volume', 'Name' => $this->wrongVolume ? 'wrong-volume' : 'fixture-media', 'Destination' => '/storage/media', 'RW' => false]]]], JSON_THROW_ON_ERROR);
        } elseif (in_array('app:user:create', $args, true)) {
            ++$this->user;
            $output = json_encode(['publicId' => str_repeat('U', 20) . $this->user, 'email' => $args[array_search('app:user:create', $args, true) + 1]], JSON_THROW_ON_ERROR);
        } elseif (in_array('app:oauth:client:create', $args, true)) {
            $output = json_encode(['clientId' => str_repeat('C', 21), 'type' => 'public', 'clientSecret' => null], JSON_THROW_ON_ERROR);
        } elseif (in_array('app:library:create', $args, true)) {
            $start = array_search('app:library:create', $args, true);
            $index = count($this->libraries);
            $library = ['id' => sprintf('00000000-0000-4000-8000-%012d', $index), 'publicId' => str_repeat('L', 20) . $index, 'path' => $args[$start + 2], 'type' => $args[$start + 3]];
            $this->libraries[] = $library;
            $output = json_encode($library, JSON_THROW_ON_ERROR);
        } elseif (in_array('app:library:member:grant', $args, true)) {
            $output = '{"granted":true}';
        } elseif (in_array('app:metadata:sync', $args, true)) {
            $output = '{"jobsDispatched":3,"jobId":"metadata-job"}';
        } elseif (in_array('app:library:scan', $args, true)) {
            $output = '{"scanStatus":"idle"}';
        } elseif (in_array('psql', $args, true)) {
            if (str_contains($command->stdin ?? '', 'FROM oauth_clients')) {
                $output = '[{"clientId":"baander_dev_spa_00001","firstParty":true,"passwordClient":true,"revoked":false}]';
            } elseif (str_contains($command->stdin ?? '', 'playback_catalog')) {
                $rows = [];
                if (!$this->missingCatalog) {
                    foreach (array_merge(array_slice(Dataset::plan('fixture-run')['files'], 0, 2), array_slice(Dataset::plan('fixture-run')['files'], 2000, 8)) as $file) {
                        $rows[] = ['path' => '/storage/media/' . $file['path'], 'title' => $file['title'], 'songPublicId' => $file['kind'] === 'song' ? str_repeat('S', 21) : null, 'albumPublicId' => $file['kind'] === 'song' ? str_repeat('A', 21) : null, 'moviePublicId' => $file['kind'] !== 'song' ? str_repeat('M', 21) : null, 'videoPublicId' => $file['kind'] !== 'song' ? str_repeat('V', 21) : null];
                    }
                }
                $output = json_encode($rows, JSON_THROW_ON_ERROR);
            } else {
                $libraries = $this->libraries;
                if ($this->changedLibrary) {
                    $libraries[0]['path'] = '/storage/media/another-run/music';
                }
                $output = json_encode($libraries, JSON_THROW_ON_ERROR);
            }
        }
        if (str_contains($command->stdin ?? '', 'AUTH_SPA_CLIENT_ID')) {
            $output = json_encode(['clientId' => $this->customClient ? 'different-client' : 'baander_dev_spa_00001'], JSON_THROW_ON_ERROR);
        }
        if (str_contains($command->stdin ?? '', 'RedisQueueSampler')) {
            $output = $this->missingQueues ? '{}' : '{"async":{"state":"known","sampledAt":1.5,"unreadCount":0,"pendingCount":0,"dueCount":0,"futureCount":0},"scheduler":{"state":"uninitialized","sampledAt":1.5,"unreadCount":null,"pendingCount":null,"dueCount":0,"futureCount":0}}';
        }
        return new ProcessResult($this->fail ? 1 : 0, null, false, false, $output, $this->fail ? 'private-secret' : '', .01);
    }

    public function start(ProcessSpec $command): RunningProcess
    {
        throw new \LogicException('Unexpected start');
    }
    public function wait(RunningProcess $process, CompletionPolicy $policy = new CompletionPolicy()): ProcessResult
    {
        throw new \LogicException('Unexpected wait');
    }
    public function stop(RunningProcess $process, int $signal = 15): ProcessResult
    {
        throw new \LogicException('Unexpected stop');
    }
}
