<?php

namespace App\Modules\Development\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

class MakeLogChannelCommand extends Command
{
    protected $signature = 'make:log-channel {name} {--types=* : The channel types to create (file, otel, daily)}';

    protected $description = 'Create logging channels with specified types and a stack channel that combines them all';

    private array $availableTypes = ['file', 'otel', 'daily'];

    public function handle(): int
    {
        $name = $this->argument('name');
        $types = $this->option('types');

        if (empty($types)) {
            $this->error('You must specify at least one type using --types option');
            $this->info('Available types: ' . implode(', ', $this->availableTypes));
            return self::FAILURE;
        }

        // Validate types
        $invalidTypes = array_diff($types, $this->availableTypes);
        if (!empty($invalidTypes)) {
            $this->error('Invalid types: ' . implode(', ', $invalidTypes));
            $this->info('Available types: ' . implode(', ', $this->availableTypes));
            return self::FAILURE;
        }

        $this->updateLoggingConfig($name, $types);
        $this->updateChannelEnum($name, $types);

        $this->info("Successfully generated logging channels for '{$name}' with types: " . implode(', ', $types));

        return self::SUCCESS;
    }

    private function updateLoggingConfig(string $name, array $types): void
    {
        $configPath = config_path('logging.php');
        $config = file_get_contents($configPath);

        $channels = [];

        // Generate individual type channels
        foreach ($types as $type) {
            $channelName = "{$name}_{$type}";
            $channels[] = $this->generateChannelConfig($channelName, $type, $name);
        }

        // Generate stack channel that combines all types
        $typeChannels = array_map(fn($type) => "'{$name}_{$type}'", $types);
        $stackChannel = $this->generateStackChannel($name, $typeChannels);
        $channels[] = $stackChannel;

        $channelConfigs = implode("\n\n", $channels);

        // Find the position to insert new channels (before the closing bracket of channels array)
        $pattern = '/(\s+)(\/\/.*)?(\s+\],\s*\];)$/m';
        if (preg_match($pattern, $config, $matches, PREG_OFFSET_CAPTURE)) {
            $insertPosition = $matches[0][1];
            $indentation = $matches[1][0];

            $newChannels = "\n\n" . $channelConfigs . "\n" . $indentation;
            $config = substr_replace($config, $newChannels, $insertPosition, 0);

            file_put_contents($configPath, $config);
            $this->info("Updated config/logging.php");
        } else {
            $this->error("Could not find insertion point in logging.php");
        }
    }

    private function updateChannelEnum(string $name, array $types): void
    {
        $enumPath = app_path('Modules/Logging/Channel.php');
        $enumContent = file_get_contents($enumPath);

        $enumCases = [];

        // Generate cases for individual type channels
        foreach ($types as $type) {
            $channelName = "{$name}_{$type}";
            $enumCases[] = $this->generateEnumCase($channelName);
        }

        // Generate case for stack channel
        $enumCases[] = $this->generateEnumCase($name);

        $newCases = implode("\n", $enumCases);

        // Find the last case and insert after it
        $pattern = '/(case\s+\w+\s*=\s*\'[^\']+\';)(\s*})/';
        if (preg_match($pattern, $enumContent, $matches, PREG_OFFSET_CAPTURE)) {
            $insertPosition = $matches[1][1] + strlen($matches[1][0]);
            $newEnumContent = substr_replace($enumContent, "\n" . $newCases, $insertPosition, 0);

            file_put_contents($enumPath, $newEnumContent);
            $this->info("Updated app/Modules/Logging/Channel.php");
        } else {
            $this->error("Could not find insertion point in Channel.php");
        }
    }

    private function generateChannelConfig(string $channelName, string $type, string $baseName): string
    {
        return match ($type) {
            'file' => $this->generateFileChannel($channelName, $baseName),
            'otel' => $this->generateOtelChannel($channelName),
            'daily' => $this->generateDailyChannel($channelName, $baseName),
        };
    }

    private function generateFileChannel(string $channelName, string $baseName): string
    {
        return <<<PHP
        '{$channelName}' => [
            'driver'               => 'single',
            'path'                 => storage_path('logs/{$baseName}.log'),
            'level'                => env('LOG_LEVEL', 'debug'),
            'replace_placeholders' => true,
        ],
PHP;
    }

    private function generateOtelChannel(string $channelName): string
    {
        return <<<PHP
        '{$channelName}' => [
            'driver' => 'custom',
            'via'    => OpenTelemetryMonolog::class,
            'name'   => '{$channelName}',
            'level'  => LogLevel::DEBUG,
            'bubble' => true,
        ],
PHP;
    }

    private function generateDailyChannel(string $channelName, string $baseName): string
    {
        return <<<PHP
        '{$channelName}' => [
            'driver'               => 'daily',
            'path'                 => storage_path('logs/{$baseName}.log'),
            'level'                => env('LOG_LEVEL', 'debug'),
            'days'                 => 14,
            'replace_placeholders' => true,
        ],
PHP;
    }

    private function generateStackChannel(string $name, array $channels): string
    {
        $channelsList = implode(', ', $channels);

        return <<<PHP
        '{$name}' => [
            'driver'            => 'stack',
            'channels'          => [{$channelsList}],
            'ignore_exceptions' => false,
        ],
PHP;
    }

    private function generateEnumCase(string $channelName): string
    {
        $caseName = Str::studly($channelName);
        return "    case {$caseName} = '{$channelName}';";
    }
}