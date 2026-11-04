<?php

declare(strict_types=1);

namespace Baander\Tooling\Fixtures\Worker;

use InvalidArgumentException;
use Baander\Tooling\Configuration\{CheckpointSelection, ToolingConfiguration};
use RuntimeException;

final readonly class OperatorConfiguration
{
    public bool $container;
    public bool $twoCommand;
    public bool $developmentSource;
    public bool $mediaObservation;
    public bool $mediaCost;
    public string $mediaCostEncoder;
    /** The load proof's encode path, software or hardware, or empty outside the load proof. */
    public string $mediaLoad;
    /** The host's 1080p video the load proof cuts its sources from. */
    public string $mediaLoadSource;
    public bool $browserControls;
    public bool $movieEndpoint;
    public bool $useImage;
    public string $lifecyclePhase;
    public ?CheckpointSelection $checkpoint;

    /** @param array<string,string> $environment */
    public function __construct(public array $environment, ?ToolingConfiguration $tooling = null)
    {
        $this->container = $this->flag('BAANDER_TEST_OPERATOR_IN_CONTAINER', 1);
        $this->twoCommand = $this->flag('BAANDER_TEST_TWO_COMMAND');
        $this->developmentSource = $this->flag('BAANDER_TEST_DEV_SOURCE_BIND');
        $this->mediaObservation = $this->flag('BAANDER_TEST_MEDIA_CONTROL_OBSERVATION');
        $this->mediaCost = $this->flag('BAANDER_TEST_MEDIA_COST');
        $this->mediaCostEncoder = $environment['BAANDER_TEST_MEDIA_COST_ENCODER'] ?? 'libx264';
        if (!in_array($this->mediaCostEncoder, ['libx264', 'libx265'], true)) {
            throw new InvalidArgumentException('BAANDER_TEST_MEDIA_COST_ENCODER must be libx264 or libx265', 2);
        }
        if ($this->mediaCost && !$this->mediaObservation) {
            throw new InvalidArgumentException('Media cost measurement requires native launch observation', 2);
        }
        $this->mediaLoad = $environment['BAANDER_TEST_MEDIA_LOAD'] ?? '';
        $this->mediaLoadSource = $environment['BAANDER_TEST_MEDIA_LOAD_SOURCE'] ?? '';
        if (!in_array($this->mediaLoad, ['', 'software', 'hardware'], true)) {
            throw new InvalidArgumentException('BAANDER_TEST_MEDIA_LOAD must be software or hardware', 2);
        }
        if ($this->mediaLoad !== '' && (!$this->mediaObservation || !$this->twoCommand || $this->mediaCost)) {
            throw new InvalidArgumentException('The load proof requires two-command mode and native launch observation without cost mode', 2);
        }
        if ($this->mediaLoad !== '' && !is_file($this->mediaLoadSource)) {
            throw new InvalidArgumentException('BAANDER_TEST_MEDIA_LOAD_SOURCE must name a 1080p video file on this host', 2);
        }
        $this->browserControls = $this->flag('BAANDER_TEST_BROWSER_CLIENT_CONTROLS');
        $this->movieEndpoint = $this->flag('BAANDER_TEST_MOVIE_ENDPOINT');
        $this->useImage = $this->flag('BAANDER_TEST_USE_IMAGE', 1);
        $this->lifecyclePhase = $environment['BAANDER_TEST_WORKER_LIFECYCLE_PHASE'] ?? '';
        if (!in_array($this->lifecyclePhase, ['', 'redis', 'postgres'], true)) {
            throw new InvalidArgumentException('Invalid worker lifecycle phase');
        }
        $this->checkpoint = ($tooling ?? ToolingConfiguration::resolve(environment: $environment))->checkpoint;
        $capacity = $this->checkpoint !== null;
        if ($this->developmentSource && !$this->container) {
            throw new InvalidArgumentException('Development source qualification requires the container operator');
        }
        if ($this->useImage && (!$this->container || ($environment['BAANDER_TEST_CHECKOUT_IN_IMAGE'] ?? '0') !== '1')) {
            throw new InvalidArgumentException(
                'BAANDER_TEST_USE_IMAGE requires container operator mode and checkout in the image',
                1,
            );
        }
        if ($this->mediaObservation && $this->useImage) {
            throw new InvalidArgumentException('Native launch observation requires a disposable fixture image');
        }
        if ($this->checkpoint?->name === 'autoscaling' && $this->useImage) {
            throw new InvalidArgumentException('Autoscaling qualification requires a disposable fixture image');
        }
        if ($this->lifecyclePhase !== '' && ($this->twoCommand || $capacity)) {
            throw new InvalidArgumentException('Worker lifecycle qualification requires standalone operator mode');
        }
        $checkpoint = $environment['BAANDER_TEST_TWO_COMMAND_CHECKPOINT'] ?? '';
        if (($this->movieEndpoint || $this->browserControls) && (!$this->twoCommand || !is_executable($checkpoint))) {
            throw new InvalidArgumentException('Browser qualification requires two-command mode and an executable browser checkpoint');
        }
        if ($this->movieEndpoint && ($this->browserControls || $this->mediaObservation || $capacity || $this->lifecyclePhase !== '')) {
            throw new InvalidArgumentException('Movie endpoint qualification requires only two-command mode and an executable browser checkpoint');
        }

    }

    /**
     * Logical CPUs a container must be allowed to use. Worker units run on every CPU; cost
     * measurement needs room for eight concurrent encoders beside the control traffic, and the
     * load proof for every default slot.
     */
    public function minimumCpus(): int
    {
        return $this->mediaLoad !== '' ? 12 : ($this->mediaCost ? 10 : 4);
    }

    /**
     * Host memory the qualification needs in GiB. Worker units carry no memory limit, so the host
     * must hold the pair, its dependencies and, for cost measurement, eight 1080p encoders at
     * about 510 MiB each; the load proof adds room for the larger slot counts and their media children.
     */
    public function minimumHostMemoryGiB(): int
    {
        return ($this->twoCommand ? 15 : 13) + ($this->mediaCost ? 5 : 0) + ($this->mediaLoad !== '' ? 10 : 0)
            + (($this->environment['BAANDER_TEST_AUTOSCALING_MAXIMUM'] ?? '1') === '4' ? 1 : 0);
    }

    private function flag(string $name, int $status = 2): bool
    {
        $value = $this->environment[$name] ?? '0';
        if (!in_array($value, ['0', '1'], true)) {
            throw new InvalidArgumentException($name . ' must be 0 or 1', $status);
        }
        return $value === '1';
    }

    public function budget(): string
    {
        return match (true) {
            $this->lifecyclePhase !== '' => 'workerLifecycle',
            $this->mediaLoad !== '' => 'workerMediaLoad',
            $this->twoCommand || $this->mediaObservation || $this->checkpoint !== null => 'workerCapacity',
            default => 'workerOperator',
        };
    }

    public static function workerName(string $namespace, string $boot): string
    {
        return 'baander-worker-' . substr(hash('sha256', $namespace), 0, 32) . '-' . $boot . '-execution';
    }

    /** @return array<mixed> */
    public static function json(string $path): array
    {
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException('Cannot read JSON evidence: ' . $path);
        }
        $value = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($value)) {
            throw new RuntimeException('Expected JSON object: ' . $path);
        }
        return $value;
    }

    /** @param array<mixed> $value */
    public static function write(string $path, array $value): void
    {
        if (file_put_contents($path, json_encode($value, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n") === false || !chmod($path, 0600)) {
            throw new RuntimeException('Cannot write JSON evidence: ' . $path);
        }
    }

    public static function require(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }
}
