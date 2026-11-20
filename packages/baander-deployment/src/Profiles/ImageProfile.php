<?php

declare(strict_types=1);

namespace Baander\Deployment\Profiles;

use Baander\Configuration\ApplicationLayout;
use Baander\Configuration\Sources\JsonDocument;
use Baander\Containers\Docker\Client;
use Baander\Containers\Resources\DisposableContainer;

final readonly class ImageProfile
{
    public const array CAPABILITIES = ['transcode', 'remux'];
    /** The default deployment's settings: the 16 GB transcode starting point with the image's slot defaults. */
    public const array DEFAULT_SETTINGS = [
        DeploymentField::Capability->value => 'transcode',
        DeploymentField::WebWorkers->value => 4,
        DeploymentField::DoctrinePoolSlots->value => 10,
        DeploymentField::OrdinaryConsumerLimit->value => 4,
        DeploymentField::SharedCopySlots->value => 0,
        DeploymentField::VideoEncoderSlots->value => null,
        DeploymentField::AudioEncoderSlots->value => null,
        DeploymentField::Hardware->value => null,
    ];

    private const string PROGRAM = <<<'PHP'
require $argv[2] . '/vendor/autoload.php';
try {
    $settings = json_decode($argv[1], true, 4, JSON_THROW_ON_ERROR);
    $hardware = $settings['hardware'] === null ? null : new \App\Shared\Application\DTO\HardwareEncoder($settings['hardware']['accelerator'], $settings['hardware']['device']);
    $profile = new \App\Shared\Application\DTO\DeploymentProfile(
        $settings['capability'],
        $settings['webWorkers'],
        $settings['doctrinePoolSlots'],
        $settings['ordinaryConsumerLimit'],
        $settings['sharedCopySlots'],
        $settings['videoEncoderSlots'],
        $settings['audioEncoderSlots'],
        $hardware,
    );
} catch (\InvalidArgumentException|\JsonException|\TypeError $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(2);
}
$autoscaling = new \App\Shared\Application\DTO\WorkerAutoscalingProfile();
echo json_encode([
    'version' => 2,
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

    /** @param array<string,mixed> $deployment Integer settings, the capability and the hardware encoder or null.
     * @param array<string,int> $autoscaling
     * @param array<string,int> $runtime
     */
    private function __construct(public array $deployment, public array $autoscaling, public array $runtime)
    {
        $this->scheduledConsoleMiB = $runtime['scheduledConsoleMiB'];
        $this->phpHeapMiB = $runtime['phpHeapMiB'];
        $this->webWorkers = DeploymentField::WebWorkers->path()->integer($deployment);
        $this->doctrinePoolSlots = DeploymentField::DoctrinePoolSlots->path()->integer($deployment);
    }

    /**
     * Asks the image for the deployment profile built from the configured settings; null slots
     * take the image's defaults, and settings the image's profile rejects fail with its reason.
     *
     * @param array{capability:string,webWorkers:int,doctrinePoolSlots:int,ordinaryConsumerLimit:int,sharedCopySlots:int,videoEncoderSlots:?int,audioEncoderSlots:?int,hardware:array{accelerator:string,device:?string}|null} $settings
     * @param array<string,string> $environment
     * @param array{bootstrap:string,onCompletion:\Closure,path:string}|null $measurement
     */
    public static function resolve(Client $docker, string $imageId, ApplicationLayout $layout, array $settings, string $receiptPath, array $environment, float $cleanupSeconds, ?array $measurement = null): self
    {
        if (preg_match('/^sha256:[a-f0-9]{64}$/D', $imageId) !== 1 || !in_array($settings['capability'], self::CAPABILITIES, true)) {
            throw new \InvalidArgumentException('Profile resolution requires an immutable image and a supported deployment capability.');
        }
        $docker->validateIdentity();
        $result = DisposableContainer::run($docker, $receiptPath, [
            '--network', 'none', '--read-only', '--cap-drop', 'ALL', '--cgroupns', 'private',
            '--security-opt', 'no-new-privileges', '--workdir', $layout->root,
            '--entrypoint', 'php', $imageId, '-r', ($measurement['bootstrap'] ?? '') . "\n" . self::PROGRAM,
            json_encode($settings, JSON_THROW_ON_ERROR), $layout->root,
        ], $environment, $cleanupSeconds, timeout: 30, onCompletion: $measurement['onCompletion'] ?? null);
        if (strlen($result->stdout) > 8192) {
            throw new \RuntimeException('The image deployment profile exceeds its bounded response size.');
        }
        $document = json_decode($result->stdout, true, 8, JSON_THROW_ON_ERROR);
        if (!is_array($document)) {
            throw new \RuntimeException('The image did not return a deployment profile object.');
        }
        $profile = self::fromArray($document);
        foreach ($settings as $name => $value) {
            if ($name !== 'hardware' && $value !== null && ($profile->deployment[$name] ?? null) !== $value) {
                throw new \RuntimeException('The image returned a different deployment profile.');
            }
        }
        if ($profile->deployment['hardware'] !== $settings['hardware']) {
            throw new \RuntimeException('The image returned a different deployment profile.');
        }
        return $profile;
    }

    /** @param array<string,mixed> $document */
    public static function fromArray(array $document): self
    {
        JsonDocument::keys($document, ['version', 'deployment', 'autoscaling', 'runtime'], 'image profile');
        if (($document['version'] ?? null) !== 2 || !is_array($document['deployment'] ?? null)) {
            throw new \InvalidArgumentException('Unsupported image deployment profile.');
        }
        $capability = $document['deployment']['capability'] ?? null;
        if (!is_string($capability) || !in_array($capability, self::CAPABILITIES, true)) {
            throw new \InvalidArgumentException('Unknown image deployment capability.');
        }
        $fields = $document['deployment'];
        if (!array_key_exists('hardware', $fields)) {
            throw new \InvalidArgumentException('Image deployment profile omits its hardware encoder.');
        }
        $hardware = self::hardware($fields['hardware']);
        unset($fields['capability'], $fields['hardware']);
        $deployment = self::integerMap($fields);
        $autoscaling = self::integerMap($document['autoscaling'] ?? null);
        $runtime = self::integerMap($document['runtime'] ?? null);
        self::requirePositive($deployment, 'webWorkers');
        self::requirePositive($deployment, 'doctrinePoolSlots');
        self::requirePositive($autoscaling, 'minimumConcurrency');
        self::requirePositive($autoscaling, 'maximumConcurrency');
        self::requirePositive($runtime, 'phpHeapMiB');
        self::requirePositive($runtime, 'scheduledConsoleMiB');
        return new self(['capability' => $capability, 'hardware' => $hardware, ...$deployment], $autoscaling, $runtime);
    }

    /** @return array{accelerator:string,device:?string}|null */
    private static function hardware(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }
        if (!is_array($value) || array_keys($value) !== ['accelerator', 'device'] || !is_string($value['accelerator'])
            || !(is_string($value['device']) || $value['device'] === null)) {
            throw new \InvalidArgumentException('Image profile hardware encoder requires an accelerator and a device or null.');
        }
        return $value;
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
        return ['version' => 2, 'deployment' => $this->deployment, 'autoscaling' => $this->autoscaling, 'runtime' => $this->runtime];
    }

    public function fingerprint(): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR) |> (static fn(string $json): string => hash('sha256', $json));
    }
}
