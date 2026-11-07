<?php

declare(strict_types=1);

namespace Baander\Tooling\Fixtures\Household;

use Baander\Tooling\Deployment\ImageProfile;

final readonly class RunContract
{
    /** Measured runs; a deployment replacement is a lifecycle check with its own receipt. */
    public const array SCENARIOS = ['cold-startup', 'original-overlap', 'fresh-copy-overlap', 'mixed-overlap', 'recovery', 'insufficient-memory'];
    /** Scenarios that run real players while normal background work runs. */
    public const array PLAYER_SCENARIOS = ['original-overlap', 'fresh-copy-overlap', 'mixed-overlap', 'recovery', 'insufficient-memory'];

    /** @param array<string,mixed> $document */
    private function __construct(private array $document) {}

    /** @param array<string,mixed> $document */
    public static function fromArray(array $document): self
    {
        foreach (['version', 'scenario', 'capability', 'size', 'players', 'imageId', 'startedMonotonic', 'sampleIntervalSeconds', 'maxSampleGapSeconds', 'durationSeconds', 'maintenanceDeadlineSeconds', 'requiredRoles', 'configuredLimits', 'dataset', 'schedule'] as $key) {
            self::require(array_key_exists($key, $document), 'Run contract is missing ' . $key . '.');
        }
        self::require($document['version'] === 2, 'Unknown run contract version.');
        self::require(in_array($document['scenario'], self::SCENARIOS, true), 'Invalid scenario.');
        self::require(in_array($document['capability'], ImageProfile::CAPABILITIES, true), 'Invalid deployment capability.');
        $size = $document['size'];
        self::require(is_array($size) && $size !== [] && !array_is_list($size)
            && !array_any($size, static fn(mixed $value): bool => !is_int($value) || $value < 0), 'Run requires the deployment size.');
        $players = in_array($document['scenario'], self::PLAYER_SCENARIOS, true) ? [1, 2] : [0];
        self::require(in_array($document['players'], $players, true), 'Playback scenarios run one or two players; other runs run none.');
        self::require(is_string($document['imageId']) && preg_match('/^sha256:[a-f0-9]{64}$/D', $document['imageId']) === 1, 'Run image must be immutable.');
        foreach (['startedMonotonic', 'sampleIntervalSeconds', 'maxSampleGapSeconds', 'durationSeconds', 'maintenanceDeadlineSeconds'] as $key) {
            self::require((is_int($document[$key]) || is_float($document[$key])) && is_finite((float) $document[$key]) && $document[$key] >= 0, 'Invalid run timing: ' . $key . '.');
        }
        self::require($document['sampleIntervalSeconds'] > 0 && $document['maxSampleGapSeconds'] >= $document['sampleIntervalSeconds'], 'Sampling gap must cover the sampling interval.');
        self::require($document['durationSeconds'] > 0 && $document['maxSampleGapSeconds'] < $document['durationSeconds'] && $document['maintenanceDeadlineSeconds'] >= $document['durationSeconds'], 'Maintenance deadline must include the load interval.');
        self::require(!in_array($document['scenario'], self::PLAYER_SCENARIOS, true) || $document['durationSeconds'] >= 1800, 'Playback scenarios require at least 1800 seconds.');
        $roles = $document['requiredRoles'];
        self::require(is_array($roles) && array_is_list($roles) && $roles !== [] && count(array_unique($roles, SORT_REGULAR)) === count($roles), 'Run requires distinct service roles.');
        foreach ($roles as $role) {
            self::require(is_string($role) && $role !== '', 'Invalid service role.');
        }
        self::require(is_array($document['configuredLimits']) && !array_is_list($document['configuredLimits']), 'Configured limits must be recorded separately.');
        foreach ($roles as $role) {
            $limits = $document['configuredLimits'][$role] ?? null;
            self::require(is_array($limits) && ((is_int($limits['memoryMaxBytes'] ?? null) && $limits['memoryMaxBytes'] > 0) || ($limits['memoryMaxBytes'] ?? null) === 'max'), 'Missing configured memory limit for ' . $role . '.');
        }
        $dataset = $document['dataset'];
        self::require(is_array($dataset) && ($dataset['songs'] ?? null) === 2000 && ($dataset['movies'] ?? null) === 50 && ($dataset['episodes'] ?? null) === 250, 'Dataset must contain 2000 songs, 50 movies and 250 episodes.');
        self::require(($dataset['preparedBeforeRun'] ?? null) === true && is_string($dataset['fixtureDigest'] ?? null) && preg_match('/^[a-f0-9]{64}$/D', $dataset['fixtureDigest']) === 1, 'Dataset preparation must precede the measured run and have a digest.');
        self::require(is_array($document['schedule']) && array_is_list($document['schedule']) && $document['schedule'] !== [], 'Traffic schedule must be declared before the run.');
        $previous = -1;
        foreach ($document['schedule'] as $action) {
            self::require(is_array($action) && (is_float($action['atSeconds'] ?? null) || is_int($action['atSeconds'] ?? null)) && is_finite((float) $action['atSeconds'])
                && $action['atSeconds'] >= $previous && $action['atSeconds'] <= $document['maintenanceDeadlineSeconds'] && is_string($action['action'] ?? null) && $action['action'] !== '', 'Invalid declared traffic action.');
            $previous = $action['atSeconds'];
        }
        return new self($document);
    }

    /** Overlap runs must keep every player progressing; recovery and insufficient-memory runs record playback without requiring it. */
    public function requiresPlayback(): bool
    {
        return str_ends_with($this->document['scenario'], '-overlap');
    }

    public function players(): int
    {
        return $this->document['players'];
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return $this->document;
    }

    public function fingerprint(): string
    {
        return hash('sha256', json_encode(self::canonical($this->document), JSON_THROW_ON_ERROR));
    }

    /** @param array<array-key,mixed> $value
     * @return array<array-key,mixed>
     */
    private static function canonical(array $value): array
    {
        if (!array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as &$item) {
            if (is_array($item)) {
                $item = self::canonical($item);
            }
        }
        return $value;
    }

    private static function require(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new \InvalidArgumentException($message);
        }
    }
}
