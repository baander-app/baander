<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\LogViewer\Agent;

use App\Shared\Infrastructure\LogViewer\ArchiveReader;
use App\Shared\Infrastructure\LogViewer\FileRecordReader;
use App\Shared\Infrastructure\LogViewer\LogIndex;
use App\Shared\Infrastructure\LogViewer\LogNormalizer;
use App\Shared\Infrastructure\LogViewer\LogStorageLimitException;

final class Collector
{
    private ?DockerClient $docker;
    /** @var array<string,DockerLogSession> */
    private array $dockerSessions = [];
    /** @var array<string,array<string,mixed>> */
    private array $dockerProgress = [];
    /** @var array<string,array{signature:string,generation:string,members:list<string>,checkedAt:float}> */
    private array $archiveObservations = [];
    private ?string $finalService = null;
    private readonly \Closure $archiveHasher;
    /** @var array<string,array<string,mixed>> */
    private array $sourceStates = [];
    private int $eventsSince;
    /** @var array<string,array{signature:string,since:float}> */
    private array $fileObservations = [];
    private bool $final = false;
    private float $finalDeadline = 0;
    private bool $fileFailure = false;
    /** @var array<string,bool> */
    private array $connectedSources = [];

    public function __construct(private readonly AgentConfiguration $config, private readonly LogIndex $index, ?\Closure $archiveHasher = null)
    {
        $this->archiveHasher = $archiveHasher ?? static fn (string $path): string => hash_file('sha256', $path);
        $this->docker = $config->get('dockerSocket') ? new DockerClient($config->get('dockerSocket')) : null;
        $this->eventsSince = time() - 60;
        foreach ($index->sources()['items'] as $source) {
            if ($source['id'] === LogIndex::sourceId($config->get('nodeId').':docker')) {
                $this->eventsSince = $source['eventCursor'] ?? $this->eventsSince;
            }
        }
    }

    public function tick(?string $finalContainer = null, ?float $deadline = null): void
    {
        $this->sourceStates = array_column($this->index->sources()['items'], null, 'id');
        $this->restoreSourceOrigins();
        $this->refreshFileAvailability();
        $this->final = null !== $finalContainer;
        $this->finalDeadline = $deadline ?? microtime(true) + 5;
        $this->fileFailure = false;
        $this->finalService = null;
        if (null !== $this->docker) {
            $this->containers($finalContainer);
        }
        foreach ($this->config->get('fileRoots', []) as $root) {
            if ($this->final && null !== $this->finalService && $root['service'] !== $this->finalService) {
                continue;
            }
            $this->files($root);
        }
        $this->index->prune(gmdate('c', time() - $this->config->get('retentionDays', 7) * 86400));
        $this->index->pruneToSize($this->config->get('maxStorageBytes', 1073741824));
        if ($this->final && $this->fileFailure) {
            throw new \RuntimeException('Final file collection incomplete.');
        }
    }

    private function restoreSourceOrigins(): void
    {
        $prefix = $this->config->get('nodeId').':file:';
        foreach ($this->sourceStates as $id => $source) {
            $previous = $source;
            if ('file' === ($source['kind'] ?? null) && !isset($source['originPath']) && str_starts_with($source['name'], $prefix)) {
                $path = substr($source['name'], strlen($prefix));
                foreach ($this->config->get('fileRoots', []) as $root) {
                    if (str_starts_with($path, rtrim($root['path'], '/').'/')) {
                        $source['originPath'] = $path;
                        $source['originRoot'] = $root['path'];
                        break;
                    }
                }
            }
            if (!array_key_exists('available', $source) || 'collector' === ($source['kind'] ?? null) && str_contains($source['name'], ':checkpoint:')) {
                $source['available'] = false;
            }
            if ($source !== $previous) {
                $this->index->upsertSource($source);
                $this->sourceStates[$id] = $source;
            }
        }
    }

    private function refreshFileAvailability(): void
    {
        $membersByPath = [];
        $restoredOrigins = [];
        $unknownArchives = array_filter($this->sourceStates, static fn (array $source): bool => 'archive' === ($source['kind'] ?? null) && !isset($source['originPath']));
        if ([] !== $unknownArchives) {
            foreach ($this->config->get('fileRoots', []) as $root) {
                if (realpath($root['path']) !== $root['path'] || !is_dir($root['path'])) {
                    continue;
                }
                try {
                    $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root['path'], \FilesystemIterator::SKIP_DOTS));
                    $scanned = 0;
                    foreach ($files as $file) {
                        if (++$scanned > 10000) {
                            break;
                        }
                        $path = $file->getPathname();
                        if (!preg_match('/\.(gz|zip)$/iD', $path) || !$this->readableOrigin($path, $root['path'])) {
                            continue;
                        }
                        try {
                            $membersByPath[$path] = (new ArchiveReader())->members($path);
                        } catch (\Throwable) {
                            continue;
                        }
                        foreach ($membersByPath[$path] as $member) {
                            $id = LogIndex::sourceId($this->config->get('nodeId').':file:'.$path.':member:'.$member);
                            if (isset($this->sourceStates[$id])) {
                                $this->sourceStates[$id]['originPath'] = $path;
                                $this->sourceStates[$id]['originRoot'] = $root['path'];
                                $restoredOrigins[$id] = true;
                            }
                        }
                    }
                } catch (\UnexpectedValueException) {
                    continue;
                }
            }
        }
        foreach ($this->sourceStates as $id => $source) {
            if (!isset($source['originPath'], $source['originRoot'])) {
                continue;
            }
            $available = $this->readableOrigin($source['originPath'], $source['originRoot']);
            if ($available && 'archive' === ($source['kind'] ?? null)) {
                try {
                    $membersByPath[$source['originPath']] ??= (new ArchiveReader())->members($source['originPath']);
                    $available = in_array($source['member'] ?? '', $membersByPath[$source['originPath']], true);
                } catch (\Throwable) {
                    $available = false;
                }
            }
            if ('archive' === ($source['originError'] ?? null)) {
                $available = false;
            }
            if (($source['available'] ?? null) !== $available || isset($restoredOrigins[$id])) {
                $source['available'] = $available;
                $this->index->upsertSource($source);
                $this->sourceStates[$id] = $source;
            }
        }
    }

    private function readableOrigin(string $path, string $root): bool
    {
        if (!in_array($root, array_column($this->config->get('fileRoots', []), 'path'), true) || !str_starts_with($path, rtrim($root, '/').'/') || realpath($root) !== $root || realpath($path) !== $path || is_link($path) || !is_file($path)) {
            return false;
        }
        $stream = @fopen($path, 'rb');
        if (false === $stream) {
            return false;
        }
        try {
            return false !== fread($stream, 1);
        } finally {
            fclose($stream);
        }
    }

    private function containers(?string $finalContainer): void
    {
        $node = $this->config->get('nodeId');
        $daemonSource = LogIndex::sourceId($node.':docker');
        try {
            $info = $this->docker->json('/info');
            if (($info['ID'] ?? '') !== $this->config->get('expectedDaemonId')) {
                throw new \RuntimeException('Docker daemon identity mismatch.');
            }
            $containers = $this->docker->json('/containers/json?all=1');
            $until = time();
            $events = $this->docker->read('/events?'.http_build_query(['since' => $this->eventsSince, 'until' => $until, 'filters' => json_encode(['type' => ['container']])]), 2097152);
            $this->eventsSince = $until;
            $ids = array_fill_keys(array_column($containers, 'Id'), true);
            $admittedEvents = [];
            foreach (explode("\n", trim($events)) as $line) {
                $event = json_decode($line, true);
                if (is_array($event) && isset($event['Actor']['ID'])) {
                    if ($this->config->admits(['Id' => $event['Actor']['ID'], 'Labels' => $event['Actor']['Attributes'] ?? []])) {
                        $ids[$event['Actor']['ID']] = true;
                        $admittedEvents[$event['Actor']['ID']] = $event['Actor']['Attributes'];
                    }
                }
            }
            if (null !== $finalContainer) {
                $ids = [$finalContainer => true];
            } else {
                foreach ($this->sourceStates as $known) {
                    if (isset($known['container']) && !isset($ids[$known['container']])) {
                        $known['available'] = false;
                        if (!($known['finalCheckpoint'] ?? false)) {
                            $known['status'] = 'gap';
                            $known['issues'] = ['Container disappeared before a final collection could be verified.'];
                        }
                        $this->index->upsertSource($known);
                        $this->sourceStates[$known['id']] = $known;
                    }
                }
            }
            foreach (array_keys($ids) as $id) {
                if (!preg_match('/^[a-f0-9]{12,64}$/D', $id)) {
                    continue;
                }
                $container = null;
                try {
                    $container = $this->docker->json('/containers/'.$id.'/json');
                    if ($this->config->admits($container)) {
                        $this->container($container);
                    } elseif ($id === $finalContainer) {
                        throw new \RuntimeException('Container is not admitted.');
                    }
                } catch (\Throwable $error) {
                    if ($id === $finalContainer) {
                        throw $error;
                    }
                    if (null !== $container && $this->config->admits($container)) {
                        continue;
                    }
                    if (isset($admittedEvents[$id])) {
                        $eventGroup = $this->config->group(['Labels' => $admittedEvents[$id]]);
                        $this->source($node.':container:'.$id, ['service' => $admittedEvents[$id][$eventGroup['serviceLabel'] ?? 'com.docker.compose.service'] ?? 'container', 'container' => $id, 'kind' => 'container', 'available' => false, 'status' => 'gap', 'issues' => ['Admitted container exited before logs could be collected.']]);
                    }
                    foreach ($this->index->sources()['items'] as $known) {
                        if (($known['container'] ?? null) === $id) {
                            $known['available'] = false;
                            $known['status'] = 'gap';
                            $known['issues'] = ['Admitted container unavailable during discovery.'];
                            $this->index->upsertSource($known);
                        }
                    }
                }
            }
            $this->source($node.':docker', ['id' => $daemonSource, 'service' => 'docker', 'kind' => 'collector', 'status' => 'ready', 'eventCursor' => $this->eventsSince]);
        } catch (\Throwable $error) {
            $this->source($node.':docker', ['id' => $daemonSource, 'service' => 'docker', 'kind' => 'collector', 'available' => false, 'status' => 'gap', 'issues' => [$error->getMessage()]]);
            foreach ($this->index->sources()['items'] as $known) {
                if (($known['kind'] ?? '') === 'container') {
                    $known['available'] = false;
                    $known['status'] = 'gap';
                    $known['issues'] = ['Docker host collection unavailable.'];
                    $this->index->upsertSource($known);
                }
            }
            if (null !== $finalContainer) {
                throw $error;
            }
        }
    }

    /** @param array<string, mixed> $container */
    private function container(array $container): void
    {
        $id = $container['Id'];
        $group = $this->config->group($container);
        $labels = $container['Config']['Labels'] ?? [];
        $service = $labels[$group['serviceLabel'] ?? 'com.docker.compose.service'] ?? $container['Name'] ?? 'container';
        if ($this->final) {
            $this->finalService = $service;
        }
        $identity = $this->config->get('nodeId').':container:'.$id;
        $sourceId = LogIndex::sourceId($identity);
        $generation = $container['State']['StartedAt'] ?? $id;
        if (isset($this->dockerProgress[$sourceId]) && $this->dockerProgress[$sourceId]['generation'] !== $generation) {
            unset($this->dockerSessions[$sourceId], $this->dockerProgress[$sourceId], $this->connectedSources[$sourceId]);
        }
        $continuedSession = isset($this->dockerSessions[$sourceId]);
        if (!$continuedSession) {
            $checkpoint = $this->index->checkpoint($sourceId) ?? [];
            $uncertain = isset($checkpoint['timestamp']) && !isset($this->connectedSources[$sourceId]);
            $issues = $uncertain ? ['Docker reconnect retention cannot prove uninterrupted history.'] : [];
            $driver = $container['HostConfig']['LogConfig']['Type'] ?? '';
            if (!in_array($driver, ['json-file', 'local', 'journald'], true)) {
                $issues[] = 'Logging driver has no verified replay coverage: '.$driver;
            }
            $this->dockerProgress[$sourceId] = ['finalSnapshot' => $this->final, 'generation' => $generation, 'checkpoint' => $checkpoint, 'sequence' => $checkpoint['sequence'] ?? 0, 'uncertain' => $uncertain, 'issues' => $issues, 'limitations' => [], 'seen' => [], 'observedBoundary' => [], 'lastTimestamp' => $checkpoint['timestamp'] ?? null, 'boundary' => $uncertain ? [] : ($checkpoint['boundary'] ?? []), 'retainedFrom' => $this->sourceStates[$sourceId]['retainedFrom'] ?? null];
            $path = '/containers/'.$id.'/logs?'.http_build_query(['stdout' => 1, 'stderr' => 1, 'timestamps' => 1, 'since' => isset($checkpoint['timestamp']) ? DockerClient::timestampCursor($checkpoint['timestamp']) : 0]);
            $this->dockerSessions[$sourceId] = new DockerLogSession($this->docker, $path, $container['Config']['Tty'] ?? false, $checkpoint['timestamp'] ?? null);
        }
        $progress = &$this->dockerProgress[$sourceId];
        $this->source($identity, ['service' => $service, 'kind' => 'container', 'container' => $id, 'generation' => $generation, 'status' => 'catching_up', 'issues' => $progress['issues']]);
        try {
            $complete = $this->dockerSessions[$sourceId]->collect(function (array $item) use (&$progress, $group, $sourceId, $generation, $service): void {
                $timestamp = $item['timestamp'];
                if (null === $progress['lastTimestamp'] || $timestamp > $progress['lastTimestamp']) {
                    $progress['boundary'] = [];
                    $progress['seen'] = [];
                    $progress['lastTimestamp'] = $timestamp;
                }
                $fingerprint = hash('sha256', $item['stream']."\0".$timestamp."\0".$item['raw']);
                if ($timestamp === ($progress['checkpoint']['timestamp'] ?? null)) {
                    $occurrence = $progress['observedBoundary'][$fingerprint] = ($progress['observedBoundary'][$fingerprint] ?? 0) + 1;
                } else {
                    $occurrence = $progress['seen'][$fingerprint] = ($progress['seen'][$fingerprint] ?? 0) + 1;
                }
                if ($timestamp === $progress['lastTimestamp']) {
                    $progress['boundary'][$fingerprint] = max($progress['boundary'][$fingerprint] ?? 0, $occurrence);
                }
                if (!$progress['uncertain'] && $timestamp === ($progress['checkpoint']['timestamp'] ?? null) && $occurrence <= ($progress['checkpoint']['boundary'][$fingerprint] ?? 0)) {
                    return;
                }
                if (isset($group['requestHost'])) {
                    $json = json_decode($item['raw'], true);
                    $host = $json['host'] ?? $json['request']['host'] ?? null;
                    if ($host !== $group['requestHost']) {
                        if (!is_string($host) || '' === $host) {
                            $progress['issues'][] = 'Gateway records without an attributable request host are excluded.';
                        } else {
                            $progress['limitations'][] = 'Gateway collection is restricted to the configured request host.';
                        }
                        $this->index->saveCheckpoint($sourceId, $this->dockerCheckpoint($progress, $item['checkpointTimestamp']));
                        return;
                    }
                }
                $record = (new LogNormalizer())->normalize($item['raw'], ['timestamp' => $timestamp, 'node' => $this->config->get('nodeId'), 'service' => $service, 'stream' => $item['stream']]);
                $progress['retainedFrom'] ??= $record['timestamp'];
                if ($progress['uncertain'] && $timestamp <= ($progress['checkpoint']['resumeThrough'] ?? $progress['checkpoint']['timestamp'] ?? $timestamp)) {
                    $record['extra']['collectionReplay'] = ['uncertain' => true, 'reason' => 'Docker timestamp boundary has no durable byte position.'];
                }
                ++$progress['sequence'];
                $this->index->append($sourceId, $generation, $progress['sequence'], $record, $this->dockerCheckpoint($progress, $item['checkpointTimestamp']));
            }, seconds: $this->final ? max(0.01, min(1, $this->finalDeadline - microtime(true))) : 1);
            $safeTimestamp = $this->dockerSessions[$sourceId]->checkpointTimestamp();
            if (null !== $safeTimestamp) {
                $this->index->saveCheckpoint($sourceId, $this->dockerCheckpoint($progress, $safeTimestamp));
            }
            if ($complete) {
                foreach ($progress['checkpoint']['boundary'] ?? [] as $fingerprint => $count) {
                    if (($progress['observedBoundary'][$fingerprint] ?? 0) < $count) {
                        $progress['issues'][] = 'Docker replay boundary is missing; retained history may have rotated.';
                        break;
                    }
                }
                $this->connectedSources[$sourceId] = true;
                unset($this->dockerSessions[$sourceId]);
            }
            $issues = array_values(array_unique($progress['issues']));
            $this->source($identity, ['service' => $service, 'kind' => 'container', 'container' => $id, 'generation' => $generation, 'retainedFrom' => $progress['retainedFrom'], 'finalCheckpoint' => $this->final && $complete && $progress['finalSnapshot'], 'status' => [] !== $issues ? 'gap' : ($complete ? 'following' : 'catching_up'), 'issues' => $issues, 'limitations' => array_values(array_unique($progress['limitations']))]);
            if ($this->final && microtime(true) < $this->finalDeadline && (!$complete || !$progress['finalSnapshot'])) {
                $this->container($container);
                return;
            }
            if ($this->final && (!$complete || !$progress['finalSnapshot'])) {
                throw new \RuntimeException('Final Docker collection has more history to collect.');
            }
        } catch (\Throwable $error) {
            unset($this->dockerSessions[$sourceId], $this->dockerProgress[$sourceId], $this->connectedSources[$sourceId]);
            $this->source($identity, ['service' => $service, 'kind' => 'container', 'finalCheckpoint' => false, 'available' => $error instanceof LogStorageLimitException, 'status' => 'gap', 'issues' => [mb_strcut($error->getMessage(), 0, 1024, 'UTF-8')]]);
            throw $error;
        }
    }

    /** @param array<string,mixed> $progress
     * @return array<string,mixed>
     */
    private function dockerCheckpoint(array $progress, string $safeTimestamp): array
    {
        $boundary = $safeTimestamp === $progress['lastTimestamp'] ? $progress['boundary'] : ($safeTimestamp === ($progress['checkpoint']['timestamp'] ?? null) ? $progress['observedBoundary'] : []);
        return ['timestamp' => $safeTimestamp, 'boundary' => $boundary, 'sequence' => $progress['sequence'], 'resumeThrough' => $progress['lastTimestamp']];
    }

    /** @param array<string, mixed> $root */
    private function files(array $root): void
    {
        $rootPath = $root['path'];
        if (is_link($rootPath) || !is_dir($rootPath) || realpath($rootPath) !== rtrim($rootPath, '/')) {
            $optionalMissing = ($root['optional'] ?? false) && !file_exists($rootPath) && !is_link($rootPath);
            $this->fileFailure = !$optionalMissing || $this->fileFailure;
            $this->reconcileFiles($rootPath, []);
            $this->source('root:'.$rootPath, ['service' => $root['service'], 'kind' => 'file', 'available' => false, 'status' => $optionalMissing ? 'ready' : 'gap', 'issues' => $optionalMissing ? [] : ['Configured file root unavailable or not canonical.']], false);
            return;
        }
        if (isset($this->sourceStates[LogIndex::sourceId('root:'.$rootPath)])) {
            $this->source('root:'.$rootPath, ['service' => $root['service'], 'kind' => 'file', 'available' => true, 'status' => 'ready', 'issues' => []], false);
        }
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($rootPath, \FilesystemIterator::SKIP_DOTS));
        $count = 0;
        $readablePaths = [];
        $archiveMembers = [];
        $completeScan = true;
        foreach ($iterator as $file) {
            if (++$count > 10000) {
                $completeScan = false;
                $this->source('root:'.$rootPath, ['service' => $root['service'], 'kind' => 'file', 'status' => 'gap', 'issues' => ['File inventory exceeds scan bound.']]);
                break;
            }
            $path = $file->getPathname();
            if ($file->isLink() || !$file->isFile() || !str_starts_with((string) realpath($path), $rootPath.'/')) {
                continue;
            }
            $identity = $this->config->get('nodeId').':file:'.$path;
            $observation = null;
            try {
                if (!is_readable($path)) {
                    throw new \RuntimeException('Admitted log file is unreadable.');
                }
                if (preg_match('/\.(bz2|xz|zst|tar|tgz|7z|rar)$/iD', $path)) {
                    throw new \RuntimeException('Unsupported log archive format.');
                }
                if (preg_match('/\.(gz|zip)$/iD', $path)) {
                    clearstatcache(true, $path);
                    $signature = fileinode($path).':'.filesize($path).':'.filemtime($path).':'.filectime($path);
                    $observation = $this->archiveObservations[$path] ?? null;
                    if (null === $observation || $observation['signature'] !== $signature || microtime(true) - $observation['checkedAt'] >= 60) {
                        if (filesize($path) > 268435456) {
                            throw new \RuntimeException('Archive exceeds compressed byte bound.');
                        }
                        $archive = new ArchiveReader();
                        $observation = ['signature' => $signature, 'generation' => ($this->archiveHasher)($path), 'members' => $archive->members($path), 'checkedAt' => microtime(true)];
                        $archiveMembers[$path] = $observation['members'];
                        foreach ($observation['members'] as $member) {
                            $this->archive($identity, $path, $member, $root, $archive, $observation['generation']);
                        }
                        $this->archiveObservations[$path] = $observation;
                    }
                    $archiveMembers[$path] = $observation['members'];
                } else {
                    $this->file($identity, $path, $root);
                }
                $readablePaths[$path] = true;
            } catch (\Throwable $error) {
                $this->fileFailure = true;
                $storageLimited = $error instanceof LogStorageLimitException;
                if ($storageLimited) {
                    $readablePaths[$path] = true;
                    if (null !== $observation) {
                        $archiveMembers[$path] = $observation['members'];
                    }
                }
                $originError = $storageLimited ? null : (preg_match('/\.(gz|zip)$/iD', $path) && is_readable($path) ? 'archive' : 'read');
                foreach ($this->sourceStates as $knownId => $known) {
                    if (($known['originPath'] ?? null) === $path) {
                        $known['available'] = $storageLimited;
                        $known['originError'] = $originError;
                        $known['status'] = $storageLimited ? 'gap' : 'error';
                        $known['issues'] = array_values(array_unique([...$known['issues'], mb_strcut($error->getMessage(), 0, 1024, 'UTF-8')]));
                        $this->index->upsertSource($known);
                        $this->sourceStates[$knownId] = $known;
                    }
                }
                $this->source($identity, ['service' => $root['service'], 'kind' => 'file', 'originPath' => $path, 'originRoot' => $rootPath, 'originError' => $originError, 'available' => $storageLimited, 'status' => $storageLimited ? 'gap' : 'error', 'issues' => [$error->getMessage()]]);
            }
        }
        if ($completeScan) {
            $this->reconcileFiles($rootPath, $readablePaths, $archiveMembers);
        }
    }

    /** @param array<string,bool> $readablePaths
     * @param array<string,list<string>> $archiveMembers
     */
    private function reconcileFiles(string $root, array $readablePaths, array $archiveMembers = []): void
    {
        foreach ($this->sourceStates as $id => $source) {
            if (($source['originRoot'] ?? null) !== $root) {
                continue;
            }
            $available = isset($readablePaths[$source['originPath']]);
            if ($available && 'archive' === ($source['kind'] ?? null)) {
                $available = in_array($source['member'] ?? '', $archiveMembers[$source['originPath']] ?? $this->archiveObservations[$source['originPath']]['members'] ?? [], true);
            }
            if (($source['available'] ?? null) !== $available) {
                $source['available'] = $available;
                $this->index->upsertSource($source);
                $this->sourceStates[$id] = $source;
            }
        }
    }

    /** @param array<string, mixed> $root */
    private function file(string $identity, string $path, array $root): void
    {
        $sourceId = LogIndex::sourceId($identity);
        $this->source($identity, ['service' => $root['service'], 'kind' => 'file', 'originPath' => $path, 'originRoot' => $root['path'], 'originError' => null]);
        clearstatcache(true, $path);
        $signature = fileinode($path).':'.filesize($path).':'.filemtime($path);
        $observation = $this->fileObservations[$path] ?? ['signature' => '', 'since' => microtime(true)];
        if ($signature !== $observation['signature']) {
            $observation = ['signature' => $signature, 'since' => microtime(true)];
        }
        $this->fileObservations[$path] = $observation;
        $previousCheckpoint = $this->index->checkpoint($sourceId) ?? [];
        $graceFlush = !$this->final && microtime(true) - $observation['since'] > 1;
        $result = (new FileRecordReader())->read($path, $previousCheckpoint, $this->final || $graceFlush);
        foreach ($result['records'] as $position => $item) {
            if ('collection-gaps.jsonl' === basename($path)) {
                $gap = json_decode($item['raw'], true);
                if ('gap' === ($gap['coverage'] ?? null)) {
                    $result['issues'][] = 'Worker retirement reported an incomplete final collection.';
                }
            }
            $record = (new LogNormalizer())->normalize($item['raw'], ['node' => $this->config->get('nodeId'), 'service' => $root['service'], 'stream' => 'file']);
            if (0 === $position && ($previousCheckpoint['graceFlushed'] ?? false) && (($previousCheckpoint['graceFlushedPartialLine'] ?? false) || !FileRecordReader::startsRecord(strtok($item['raw'], "\n") ?: ''))) {
                $record['extra']['collectionFragment'] = ['previousGeneration' => $previousCheckpoint['generation'], 'previousSequence' => $previousCheckpoint['graceFlushedSequence'], 'reason' => 'Continuation arrived after the stable-file grace flush.'];
            }
            $checkpoint = array_replace($result['checkpoint'], ['offset' => $result['records'][$position + 1]['offset'] ?? $result['checkpoint']['offset'], 'graceFlushed' => $graceFlush && $position === array_key_last($result['records']), 'graceFlushedSequence' => $item['offset'], 'graceFlushedPartialLine' => !str_ends_with($item['raw'], "\n")]);
            $this->index->append($sourceId, (string) $result['checkpoint']['generation'], $item['offset'], $record, $checkpoint);
        }
        $this->source($identity, ['service' => $root['service'], 'kind' => 'file', 'originPath' => $path, 'originRoot' => $root['path'], 'originError' => null, 'status' => [] === $result['issues'] ? 'following' : 'gap', 'issues' => $result['issues']]);
    }

    /** @param array<string, mixed> $root */
    private function archive(string $identity, string $path, string $member, array $root, ArchiveReader $archive, string $generation): void
    {
        $identity .= ':member:'.$member;
        $sourceId = LogIndex::sourceId($identity);
        $checkpoint = $this->index->checkpoint($sourceId);
        $complete = ($checkpoint['generation'] ?? '') === $generation && ($checkpoint['complete'] ?? false);
        $this->source($identity, ['name' => basename($path).':'.$member, 'archive' => basename($path), 'member' => $member, 'originPath' => $path, 'originRoot' => $root['path'], 'originError' => null, 'service' => $root['service'], 'kind' => 'archive', 'status' => $complete ? 'archived' : 'catching_up']);
        if ($complete) {
            return;
        }
        $sequence = 0;
        foreach ($archive->records($path, $member) as $raw) {
            $record = (new LogNormalizer())->normalize($raw, ['node' => $this->config->get('nodeId'), 'service' => $root['service'], 'stream' => 'archive']);
            $this->index->append($sourceId, $generation, ++$sequence, $record, ['generation' => $generation, 'complete' => false]);
        }
        if ($sequence > 0) {
            $this->index->append($sourceId, $generation, $sequence, $record, ['generation' => $generation, 'complete' => true]);
        }
        $this->source($identity, ['name' => basename($path).':'.$member, 'archive' => basename($path), 'member' => $member, 'originPath' => $path, 'originRoot' => $root['path'], 'originError' => null, 'service' => $root['service'], 'kind' => 'archive', 'status' => 'archived']);
    }

    public function finalGap(string $containerId): void
    {
        $this->source($this->config->get('nodeId').':checkpoint:'.$containerId, ['service' => 'docker', 'kind' => 'collector', 'available' => false, 'status' => 'gap', 'issues' => ['Final collection deadline expired or collection failed.']]);
    }

    /** @param array<string, mixed> $fields */
    private function source(string $identity, array $fields, bool $preserveGap = true): void
    {
        $id = LogIndex::sourceId($identity);
        $successful = in_array($fields['status'] ?? 'following', ['following', 'archived', 'ready'], true);
        $previous = $this->sourceStates[$id] ?? [];
        if ($preserveGap && 'gap' === ($previous['status'] ?? null)) {
            $fields['status'] = 'gap';
            $fields['issues'] = array_values(array_unique(array_merge($previous['issues'], $fields['issues'] ?? [])));
        }
        $source = array_replace(['id' => $id, 'name' => $identity, 'node' => $this->config->get('nodeId'), 'status' => 'following', 'issues' => []], $previous, $fields, ['lastAttemptAt' => gmdate('c'), 'available' => $fields['available'] ?? true]);
        if ($successful) {
            $source['lastCollectedAt'] = gmdate('c');
        }
        $this->index->upsertSource($source);
        $this->sourceStates[$id] = $source;
    }
}
