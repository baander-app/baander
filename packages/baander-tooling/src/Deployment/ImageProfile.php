<?php

declare(strict_types=1);

namespace Baander\Tooling\Deployment;

use Baander\Tooling\Configuration\{ApplicationLayout, JsonObject};
use Baander\Tooling\Docker\Client;
use Baander\Tooling\Resources\DisposableContainer;

final readonly class ImageProfile
{
    private const string PROGRAM = <<<'PHP'
require $argv[2] . '/vendor/autoload.php';
$profile = new \App\Shared\Application\DTO\DeploymentProfile($argv[1]);
$autoscaling = new \App\Shared\Application\DTO\WorkerAutoscalingProfile();
echo json_encode([
    'version' => 1,
    'deployment' => json_decode($profile->toJson(), true, flags: JSON_THROW_ON_ERROR),
    'autoscaling' => json_decode($autoscaling->toJson(), true, flags: JSON_THROW_ON_ERROR),
    'runtime' => [
        'phpHeapMiB' => $profile->phpHeapMiB(),
        'phpProcessMemoryMiB' => $profile->phpProcessMemoryMiB(),
        'nativeManagerHeapMiB' => $profile->nativeManagerHeapMiB(),
        'nativeCommandLimit' => $profile->nativeCommandLimit(),
        'nativeResultLimit' => $profile->nativeResultLimit(),
        'nativeClientLimit' => $profile->nativeClientLimit(),
        'mediaHelperLimit' => $profile->mediaHelperLimit(),
        'scheduledConsoleMiB' => $profile->phpProcessMemoryMiB(),
    ],
], JSON_THROW_ON_ERROR);
PHP;

    public int $scheduledConsoleMiB;
    public int $phpHeapMiB;
    public int $webWorkers;
    public int $doctrinePoolSlots;

    /** @param array<string,int|string> $deployment
     * @param array<string,int> $autoscaling
     * @param array<string,int> $runtime
     */
    private function __construct(public array $deployment, public array $autoscaling, public array $runtime)
    {
        $this->scheduledConsoleMiB = $runtime['scheduledConsoleMiB'];
        $this->phpHeapMiB = $runtime['phpHeapMiB'];
        $this->webWorkers = (int) $deployment['webWorkers'];
        $this->doctrinePoolSlots = (int) $deployment['doctrinePoolSlots'];
    }

    /** @param array<string,string> $environment
     * @param array{bootstrap:string,onCompletion:\Closure,path:string}|null $measurement
     */
    public static function resolve(Client $docker, string $imageId, ApplicationLayout $layout, string $profileId, string $receiptPath, array $environment, float $cleanupSeconds, ?array $measurement = null): self
    {
        if (preg_match('/^sha256:[a-f0-9]{64}$/D', $imageId) !== 1 || !in_array($profileId, ['full', 'household'], true)) {
            throw new \InvalidArgumentException('Profile resolution requires an immutable image and a supported deployment profile.');
        }
        $docker->validateIdentity();
        $result = DisposableContainer::run($docker, $receiptPath, [
            '--network', 'none', '--read-only', '--cap-drop', 'ALL', '--cgroupns', 'private',
            '--security-opt', 'no-new-privileges', '--workdir', $layout->root,
            '--entrypoint', 'php', $imageId, '-r', ($measurement['bootstrap'] ?? '') . "\n" . self::PROGRAM, $profileId, $layout->root,
        ], $environment, $cleanupSeconds, timeout: 30, onCompletion: $measurement['onCompletion'] ?? null);
        if (strlen($result->stdout) > 8192) {
            throw new \RuntimeException('The image deployment profile exceeds its bounded response size.');
        }
        $document = json_decode($result->stdout, true, 8, JSON_THROW_ON_ERROR);
        if (!is_array($document)) {
            throw new \RuntimeException('The image did not return a deployment profile object.');
        }
        $profile = self::fromArray($document);
        if ($profile->deployment['identifier'] !== $profileId) {
            throw new \RuntimeException('The image returned a different deployment profile.');
        }
        return $profile;
    }

    /** @param array<string,mixed> $document */
    public static function fromArray(array $document): self
    {
        JsonObject::keys($document, ['version', 'deployment', 'autoscaling', 'runtime'], 'image profile');
        if (($document['version'] ?? null) !== 1 || !is_array($document['deployment'] ?? null)) {
            throw new \InvalidArgumentException('Unsupported image deployment profile.');
        }
        $identifier = $document['deployment']['identifier'] ?? null;
        if (!is_string($identifier) || !in_array($identifier, ['full', 'household'], true)) {
            throw new \InvalidArgumentException('Unknown image deployment profile.');
        }
        $fields = $document['deployment'];
        unset($fields['identifier']);
        $deployment = self::integerMap($fields);
        $autoscaling = self::integerMap($document['autoscaling'] ?? null);
        $runtime = self::integerMap($document['runtime'] ?? null);
        self::requirePositive($deployment, 'webWorkers');
        self::requirePositive($deployment, 'doctrinePoolSlots');
        self::requirePositive($autoscaling, 'minimumConcurrency');
        self::requirePositive($autoscaling, 'maximumConcurrency');
        self::requirePositive($runtime, 'phpHeapMiB');
        self::requirePositive($runtime, 'scheduledConsoleMiB');
        return new self(['identifier' => $identifier, ...$deployment], $autoscaling, $runtime);
    }

    /** @return array<string,int> */
    private static function integerMap(mixed $value): array
    {
        if (!is_array($value) || $value === [] || array_is_list($value)) {
            throw new \InvalidArgumentException('Image profile settings require named integer fields.');
        }
        foreach ($value as $key => $number) {
            if (!is_string($key) || !is_int($number) || $number < 0) {
                throw new \InvalidArgumentException('Image profile settings require non-negative integers.');
            }
        }
        ksort($value, SORT_STRING);
        return $value;
    }

    /** @param array<string,int> $values */
    private static function requirePositive(array $values, string $key): void
    {
        if (($values[$key] ?? 0) < 1) {
            throw new \InvalidArgumentException('Image profile is missing a positive ' . $key . '.');
        }
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return ['version' => 1, 'deployment' => $this->deployment, 'autoscaling' => $this->autoscaling, 'runtime' => $this->runtime];
    }

    public function fingerprint(): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR) |> (static fn(string $json): string => hash('sha256', $json));
    }
}
