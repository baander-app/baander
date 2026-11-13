<?php

declare(strict_types=1);

namespace App\Shared\Interface\Console;

use App\Shared\Application\DTO\DeploymentProfile;
use App\Shared\Application\DTO\HardwareEncoder;
use App\Shared\Application\DTO\WorkerRuntimeConfiguration;
use App\Shared\Application\Exception\InvalidInputException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Prints a deployment profile and the limits Baander derives from it, so a source install can
 * set the web environment and the worker manifest's profile without writing the JSON by hand.
 * Without options it prints the profile this process runs. Options build another one: settings
 * left out keep the effective profile's values when the capability stays the same, and take the
 * transcode defaults or require a value when it changes. A hardware option resets unset slot
 * counts to the defaults for that encoder.
 */
#[AsCommand(
    name: 'app:deployment:profile:show',
    description: 'Print a deployment profile and the limits derived from its capability and size.',
)]
final class DeploymentProfileShowCommand extends Command
{
    public function __construct(
        private readonly DeploymentProfile $effective,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('capability', null, InputOption::VALUE_REQUIRED, 'Media work the deployment runs: transcode or remux')
            ->addOption('web-workers', null, InputOption::VALUE_REQUIRED, sprintf('Web worker processes, 1 to %d', DeploymentProfile::MAXIMUM_WEB_WORKERS))
            ->addOption('database-slots', null, InputOption::VALUE_REQUIRED, sprintf('Database connection slots per web worker, 1 to %d', DeploymentProfile::MAXIMUM_DOCTRINE_POOL_SLOTS))
            ->addOption('ordinary-consumers', null, InputOption::VALUE_REQUIRED, sprintf('Resident ordinary background consumers, 1 to %d', DeploymentProfile::MAXIMUM_ORDINARY_CONSUMERS))
            ->addOption('copy-slots', null, InputOption::VALUE_REQUIRED, sprintf('Concurrent stream copy jobs of a remux deployment, 1 to %d', DeploymentProfile::MAXIMUM_COPY_SLOTS))
            ->addOption('video-slots', null, InputOption::VALUE_REQUIRED, 'Concurrent video jobs of a transcode deployment; 0 disables video conversion and copy')
            ->addOption('audio-slots', null, InputOption::VALUE_REQUIRED, 'Concurrent audio jobs of a transcode deployment; 0 disables audio conversion and copy')
            ->addOption('hardware-accelerator', null, InputOption::VALUE_REQUIRED, 'Hardware encoder of a transcode deployment: ' . implode(', ', HardwareEncoder::ACCELERATORS))
            ->addOption('hardware-device', null, InputOption::VALUE_REQUIRED, 'Device path the hardware encoder opens, such as /dev/dri/renderD128; nvenc takes none')
            ->addOption(AdminCommandSupport::JSON_OPTION, null, InputOption::VALUE_NONE, 'Print the profile, its fingerprint and its limits as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            $profile = $this->profile($input);
        } catch (InvalidInputException $failure) {
            return AdminCommandSupport::fail($io, $failure);
        }
        $limits = self::limits($profile);
        if (AdminCommandSupport::wantsJson($input)) {
            return AdminCommandSupport::json($io, [
                'profile' => json_decode($profile->toJson(), true, flags: JSON_THROW_ON_ERROR),
                'fingerprint' => $profile->fingerprint(),
                'limits' => $limits,
            ]);
        }

        $io->writeln("BAANDER_DEPLOYMENT_PROFILE='" . $profile->toJson() . "'", OutputInterface::OUTPUT_RAW);
        $io->newLine();
        $io->definitionList(
            ['Fingerprint' => $profile->fingerprint()],
            ['Capability' => $profile->capability],
            ['Web workers' => sprintf('%d, each with %d database connection slots', $profile->webWorkers, $profile->doctrinePoolSlots)],
            ['Ordinary consumers' => (string) $profile->ordinaryConsumerLimit],
            ['Hardware encoder' => $profile->hardware === null ? 'none (software)' : $profile->hardware->accelerator . ($profile->hardware->device === null ? '' : ' on ' . $profile->hardware->device)],
            ['Video slots' => self::slots($limits['videoSlots'], $limits['maximumVideoSlots'])],
            ['Audio slots' => self::slots($limits['audioSlots'], $limits['maximumAudioSlots'])],
            ['Copy slots' => (string) $limits['sharedCopySlots']],
            ['Native commands' => (string) $limits['nativeCommandLimit']],
            ['Native results' => (string) $limits['nativeResultLimit']],
            ['Native clients' => (string) $limits['nativeClientLimit']],
            ['Native daemon peers' => (string) $limits['nativePeerLimit']],
            ['Control supervisor children' => (string) $limits['supervisorChildCeiling']['control']],
            ['Execution supervisor children' => (string) $limits['supervisorChildCeiling']['execution']],
        );

        return Command::SUCCESS;
    }

    /** @throws InvalidInputException when the options do not form a valid profile */
    private function profile(InputInterface $input): DeploymentProfile
    {
        $capability = AdminCommandSupport::stringOption($input, 'capability');
        $settings = [
            'webWorkers' => AdminCommandSupport::integerOption($input, 'web-workers'),
            'doctrinePoolSlots' => AdminCommandSupport::integerOption($input, 'database-slots'),
            'ordinaryConsumerLimit' => AdminCommandSupport::integerOption($input, 'ordinary-consumers'),
            'sharedCopySlots' => AdminCommandSupport::integerOption($input, 'copy-slots'),
            'videoEncoderSlots' => AdminCommandSupport::integerOption($input, 'video-slots'),
            'audioEncoderSlots' => AdminCommandSupport::integerOption($input, 'audio-slots'),
        ];
        $accelerator = AdminCommandSupport::stringOption($input, 'hardware-accelerator');
        $device = AdminCommandSupport::stringOption($input, 'hardware-device');
        if ($capability === null && $accelerator === null && $device === null && array_filter($settings, static fn (?int $value): bool => $value !== null) === []) {
            return $this->effective;
        }
        $capability ??= $this->effective->capability;
        $base = $capability === $this->effective->capability ? $this->effective : null;
        try {
            if ($accelerator === null && $device !== null) {
                throw new \InvalidArgumentException('A hardware device requires --hardware-accelerator.');
            }
            $hardware = $accelerator === null ? $base?->hardware : new HardwareEncoder($accelerator, $device);
            // A new hardware encoder brings its own video default instead of the effective slot count.
            $inherited = $accelerator === null ? array_keys($settings) : ['webWorkers', 'doctrinePoolSlots', 'ordinaryConsumerLimit', 'sharedCopySlots'];
            foreach ($inherited as $name) {
                $settings[$name] ??= $base?->{$name};
            }

            return new DeploymentProfile(
                $capability,
                ...array_filter(['hardware' => $hardware] + $settings, static fn (mixed $value): bool => $value !== null),
            );
        } catch (\InvalidArgumentException $error) {
            throw new InvalidInputException($error->getMessage(), previous: $error);
        }
    }

    /**
     * The execution supervisor's ceiling is shown at the profile's largest ordinary
     * concurrency with scheduled console execution enabled, the most a manifest can admit.
     *
     * @return array{videoSlots:int, audioSlots:int, sharedCopySlots:int, maximumVideoSlots:?int, maximumAudioSlots:?int,
     *     nativeCommandLimit:int, nativeResultLimit:int, nativeClientLimit:int, nativePeerLimit:int,
     *     supervisorChildCeiling:array{control:int, execution:int}}
     */
    private static function limits(DeploymentProfile $profile): array
    {
        $bounds = $profile->mediaSlotBounds();

        return [
            'videoSlots' => $profile->videoEncoderSlots,
            'audioSlots' => $profile->audioEncoderSlots,
            'sharedCopySlots' => $profile->sharedCopySlots,
            'maximumVideoSlots' => $bounds?->maximumVideoSlots,
            'maximumAudioSlots' => $bounds?->maximumAudioSlots,
            'nativeCommandLimit' => $profile->nativeCommandLimit(),
            'nativeResultLimit' => $profile->nativeResultLimit(),
            'nativeClientLimit' => $profile->nativeClientLimit(),
            'nativePeerLimit' => $profile->nativePeerLimit(),
            'supervisorChildCeiling' => [
                'control' => WorkerRuntimeConfiguration::childProcessCeilingFor('control', $profile, $profile->ordinaryConsumerLimit, false),
                'execution' => WorkerRuntimeConfiguration::childProcessCeilingFor('execution', $profile, $profile->ordinaryConsumerLimit, true),
            ],
        ];
    }

    private static function slots(int $slots, ?int $maximum): string
    {
        return $maximum === null ? $slots . ' (remux runs copy slots)' : sprintf('%d (at most %d)', $slots, $maximum);
    }
}
