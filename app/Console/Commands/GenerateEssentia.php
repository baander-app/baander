<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

class GenerateEssentia extends Command
{
    protected $signature = 'essentia:generate 
                           {--output-path=app/Modules/Essentia : Output directory for generated classes}
                           {--python-path=python3 : Path to Python executable}
                           {--include=* : Include only algorithms matching these patterns}
                           {--exclude=* : Exclude algorithms matching these patterns}
                           {--categories=* : Include only specific categories}
                           {--min-count=0 : Minimum number of algorithms per category to include}
                           {--dry-run : Show what would be generated without creating files}
                           {--force : Overwrite existing files}
                           {--skip-python : Skip Python introspection (header-only discovery)}
                           {--header-paths=* : Additional header search paths}';

    protected $description = 'Generate PHP FFI wrapper classes for Essentia audio analysis algorithms';

    private array $algorithmMetadata = [];
    private array $discoveredAlgorithms = [];
    private Collection $filteredAlgorithms;

    public function handle(): int
    {
        $this->info('Essentia PHP FFI Generator Starting...');

        $outputPath = $this->option('output-path');

        // Discovery phase
        if (!$this->option('skip-python')) {
            $this->discoverAlgorithmsViaPython();
        }

        $this->discoverAlgorithmsViaHeaders();
        $this->processIntrospectionData();

        if ($this->option('verbose')) {
            $this->displayDiscoveryResults();
        }

        // Filtering phase
        $this->filteredAlgorithms = $this->filterAlgorithms();

        if ($this->filteredAlgorithms->isEmpty()) {
            $this->warn('No algorithms match the specified criteria.');
            return self::FAILURE;
        }

        $this->info("Selected {$this->filteredAlgorithms->count()} algorithms for generation.");

        if ($this->option('dry-run')) {
            $this->showDryRun();
            return self::SUCCESS;
        }

        // Generation phase
        $this->generateClasses($outputPath);

        $this->info('Essentia FFI classes generated successfully!');
        return self::SUCCESS;
    }

    private function discoverAlgorithmsViaPython(): void
    {
        $this->info('Discovering algorithms via Python introspection...');

        $pythonPath = $this->option('python-path');
        $pythonScript = $this->getPythonIntrospectionScript();

        try {
            $result = Process::run([
                $pythonPath,
                '-c',
                $pythonScript,
            ])->throw();

            $data = json_decode($result->output(), true, 512, JSON_THROW_ON_ERROR);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \RuntimeException('Invalid JSON from Python script: ' . json_last_error_msg());
            }

            if (isset($data['error'])) {
                $this->warn("Python introspection failed: {$data['error']}");
                return;
            }

            $this->algorithmMetadata = array_merge($this->algorithmMetadata, $data);
            $this->line("✓ Discovered " . count($data) . " algorithms via Python");

        } catch (\Exception $e) {
            $this->warn("Python introspection failed: {$e->getMessage()}");
            $this->line('Continuing with header-based discovery...');
        }
    }

    private function discoverAlgorithmsViaHeaders(): void
    {
        $this->info('🔍 Discovering algorithms via header analysis...');

        $headerPaths = $this->option('header-paths') ?: $this->findEssentiaSources();
        $discovered = 0;

        foreach ($headerPaths as $path) {
            if (is_dir($path)) {
                $discovered += $this->scanHeaderDirectory($path);
            }
        }

        $this->line("✓ Analyzed headers, found {$discovered} additional algorithms");
    }

    private function findEssentiaSources(): array
    {
        $possiblePaths = [
            '/usr/include/essentia',
            '/usr/local/include/essentia',
            '/opt/homebrew/include/essentia',
            '/usr/include/essentia/algorithms',
            '/usr/local/include/essentia/algorithms',
            getcwd() . '/essentia/src/algorithms',
        ];

        return collect($possiblePaths)
            ->filter(fn($path) => is_dir($path))
            ->values()
            ->toArray();
    }

    private function scanHeaderDirectory(string $directory): int
    {
        $discovered = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() === 'h') {
                $algorithms = $this->analyzeHeaderFile($file->getPathname());
                $discovered += count($algorithms);

                foreach ($algorithms as $algorithm) {
                    $category = $this->categorizeByPath($file->getPathname());
                    $this->algorithmMetadata[$algorithm] = [
                        'name'     => $algorithm,
                        'category' => $category,
                        'mode'     => 'standard',
                        'source'   => 'header',
                        'file'     => $file->getPathname(),
                    ];
                }
            }
        }

        return $discovered;
    }

    private function analyzeHeaderFile(string $filePath): array
    {
        $content = file_get_contents($filePath);
        $algorithms = [];

        // Match class declarations
        if (preg_match_all('/class\s+(\w+)\s*:.*?Algorithm/m', $content, $matches)) {
            $algorithms = array_merge($algorithms, $matches[1]);
        }

        return array_unique($algorithms);
    }

    private function categorizeByPath(string $filePath): string
    {
        $categoryMap = [
            'audioProblems'   => 'AudioProblems',
            'complex'         => 'Complex',
            'extractor'       => 'Extractor',
            'filters'         => 'Filters',
            'highlevel'       => 'HighLevel',
            'io'              => 'Io',
            'machinelearning' => 'MachineLearning',
            'rhythm'          => 'Rhythm',
            'sfx'             => 'Sfx',
            'spectral'        => 'Spectral',
            'standard'        => 'Standard',
            'stats'           => 'Stats',
            'synthesis'       => 'Synthesis',
            'temporal'        => 'Temporal',
            'tonal'           => 'Tonal',
        ];

        foreach ($categoryMap as $pathPattern => $category) {
            if (stripos($filePath, $pathPattern) !== false) {
                return $category;
            }
        }

        return 'Standard';
    }

    private function processIntrospectionData(): void
    {
        $this->discoveredAlgorithms = array_keys($this->algorithmMetadata);

        // Merge and deduplicate
        $this->discoveredAlgorithms = array_unique($this->discoveredAlgorithms);
        sort($this->discoveredAlgorithms);

        $this->line("Total unique algorithms discovered: " . count($this->discoveredAlgorithms));
    }

    private function displayDiscoveryResults(): void
    {
        $byCategory = collect($this->algorithmMetadata)
            ->groupBy('category')
            ->map(fn($algorithms) => $algorithms->count())
            ->sortDesc();

        $this->info('Algorithms by category:');
        $byCategory->each(fn($count, $category) => $this->line("{$category}: {$count}"),
        );
    }

    private function filterAlgorithms(): Collection
    {
        $algorithms = collect($this->discoveredAlgorithms);

        // Apply include patterns
        if ($includePatterns = $this->option('include')) {
            $algorithms = $algorithms->filter(function ($algorithm) use ($includePatterns) {
                return collect($includePatterns)->some(fn($pattern) => fnmatch($pattern, $algorithm, FNM_CASEFOLD),
                );
            });
        }

        // Apply exclude patterns
        if ($excludePatterns = $this->option('exclude')) {
            $algorithms = $algorithms->reject(function ($algorithm) use ($excludePatterns) {
                return collect($excludePatterns)->some(fn($pattern) => fnmatch($pattern, $algorithm, FNM_CASEFOLD),
                );
            });
        }

        // Apply category filter
        if ($categories = $this->option('categories')) {
            $algorithms = $algorithms->filter(function ($algorithm) use ($categories) {
                $category = $this->algorithmMetadata[$algorithm]['category'] ?? 'Standard';
                return in_array($category, $categories);
            });
        }

        // Apply minimum count filter
        if ($minCount = $this->option('min-count')) {
            $byCategory = $algorithms->groupBy(fn($algorithm) => $this->algorithmMetadata[$algorithm]['category'] ?? 'Standard',
            );

            $validCategories = $byCategory
                ->filter(fn($algorithms) => $algorithms->count() >= $minCount)
                ->keys();

            $algorithms = $algorithms->filter(function ($algorithm) use ($validCategories) {
                $category = $this->algorithmMetadata[$algorithm]['category'] ?? 'Standard';
                return $validCategories->contains($category);
            });
        }

        return $algorithms;
    }

    private function showDryRun(): void
    {
        $this->info('Dry run - Files that would be generated:');

        $byCategory = $this->filteredAlgorithms
            ->groupBy(fn($algorithm) => $this->algorithmMetadata[$algorithm]['category'] ?? 'Standard');

        $byCategory->each(function ($algorithms, $category) {
            $this->line("{$category}/");
            $algorithms->each(fn($algorithm) => $this->line("  📄 {$algorithm}.php"));
        });

        $this->info("Base classes:");
        $this->line("EssentiaFFI.php");
        $this->line("BaseAlgorithm.php");
        $this->line("AudioVector.php");
        $this->line("AlgorithmFactory.php");
        $this->line("AudioAnalyzer.php");
    }

    private function generateClasses(string $outputPath): void
    {
        $this->info('Generating classes...');

        $this->createDirectoryStructure($outputPath);
        $this->generateBaseClasses($outputPath);

        $progressBar = $this->output->createProgressBar($this->filteredAlgorithms->count());
        $progressBar->setFormat('Generating: %current%/%max% [%bar%] %percent:3s%% %message%');
        $progressBar->start();

        $this->filteredAlgorithms->each(function ($algorithm) use ($outputPath, $progressBar) {
            $progressBar->setMessage($algorithm);
            $this->generateIntelligentAlgorithmClass($algorithm, $outputPath);
            $progressBar->advance();
        });

        $progressBar->setMessage('Generating factory and utility classes...');
        $this->generateIntelligentFactoryClass($this->filteredAlgorithms->toArray(), $outputPath);
        $this->generateIntelligentAnalyzerClass($outputPath);
        $this->generateExceptionClasses($outputPath);
        $this->generateUtilityClasses($outputPath);

        $progressBar->finish();
        $this->newLine();
    }

    private function createDirectoryStructure(string $outputPath): void
    {
        $directories = [
            $outputPath,
            ...collect($this->filteredAlgorithms)->map(fn($algorithm) => $outputPath . '/Algorithms/' . ($this->algorithmMetadata[$algorithm]['category'] ?? 'Standard'),
            )->unique(),
            "{$outputPath}/Types",
            "{$outputPath}/Exceptions",
            "{$outputPath}/Utils",
        ];

        collect($directories)->each(function ($directory) {
            if (!File::exists($directory)) {
                File::makeDirectory($directory, 0755, true);
            }
        });
    }

    private function generateBaseClasses(string $outputPath): void
    {
        $this->generateEssentiaFFIClass($outputPath);
        $this->generateBaseAlgorithmClass($outputPath);
        $this->generateAudioVectorClass($outputPath);
    }

    private function generateEssentiaFFIClass(string $outputPath): void
    {
        $content = <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Modules\Essentia;

use App\Modules\Essentia\Exceptions\EssentiaException;
use FFI;

/**
 * FFI wrapper for Essentia C++ library
 */
class EssentiaFFI
{
    private static ?FFI $ffi = null;
    private static ?string $libraryPath = null;

    public function __construct(?string $libraryPath = null)
    {
        self::$libraryPath = $libraryPath ?? $this->findEssentiaLibrary();
        
        if (!self::$ffi) {
            $this->initializeFFI();
        }
    }

    public function getFFI(): FFI
    {
        if (!self::$ffi) {
            throw new EssentiaException('FFI not initialized');
        }
        
        return self::$ffi;
    }

    private function initializeFFI(): void
    {
        try {
            $headerFile = $this->findHeaderFile();
            $header = file_get_contents($headerFile);
            
            self::$ffi = FFI::cdef($header, self::$libraryPath);
            
        } catch (\Exception $e) {
            throw new EssentiaException("Failed to initialize Essentia FFI: {$e->getMessage()}", 0, $e);
        }
    }

    private function findEssentiaLibrary(): string
    {
        $possiblePaths = config('services.essentia.library_path');

        foreach ($possiblePaths as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }

        throw new EssentiaException('Essentia library not found. Please install Essentia or specify the library path.');
    }

    private function findHeaderFile(): string
    {
        $possiblePaths = config('services.essentia.header_path');

        foreach ($possiblePaths as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }

        throw new EssentiaException('Essentia C header file not found.');
    }

    public static function version(): string
    {
        return '1.0.0';
    }
}
PHP;

        $this->writeFile($outputPath . '/EssentiaFFI.php', $content);
    }

    private function generateBaseAlgorithmClass(string $outputPath): void
    {
        $content = <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Modules\Essentia\Algorithms;

use App\Modules\Essentia\EssentiaFFI;
use App\Modules\Essentia\Exceptions\{EssentiaException, ConfigurationException, AlgorithmException};
use App\Modules\Essentia\Types\AudioVector;

/**
 * Base class for all Essentia algorithm wrappers
 */
abstract class BaseAlgorithm
{
    protected EssentiaFFI $essentia;
    protected array $parameters = [];
    protected string $algorithmName = '';
    protected string $mode = 'standard';
    protected string $category = 'Standard';

    public function __construct(array $parameters = [])
    {
        $this->essentia = new EssentiaFFI();
        $this->parameters = $parameters;
        $this->configure($parameters);
    }

    abstract public function compute($input): array;

    protected function configure(array $parameters): void
    {
        foreach ($parameters as $key => $value) {
            if (!$this->isValidParameter($key)) {
                throw new ConfigurationException("Invalid parameter: {$key}");
            }
            $this->parameters[$key] = $value;
        }
    }

    protected function isValidParameter(string $parameter): bool
    {
        // This would be overridden by specific algorithms
        return true;
    }

    protected function validateInput($input, string $expectedType): void
    {
        switch ($expectedType) {
            case 'array':
                if (!is_array($input)) {
                    throw new AlgorithmException('Expected array input');
                }
                break;
            case 'AudioVector':
                if (!($input instanceof AudioVector)) {
                    throw new AlgorithmException('Expected AudioVector input');
                }
                break;
            case 'numeric':
                if (!is_numeric($input)) {
                    throw new AlgorithmException('Expected numeric input');
                }
                break;
        }
    }

    public function getAlgorithmName(): string
    {
        return $this->algorithmName;
    }

    public function getMode(): string
    {
        return $this->mode;
    }

    public function getCategory(): string
    {
        return $this->category;
    }

    public function getParameters(): array
    {
        return $this->parameters;
    }

    public function setParameter(string $key, $value): self
    {
        if (!$this->isValidParameter($key)) {
            throw new ConfigurationException("Invalid parameter: {$key}");
        }
        
        $this->parameters[$key] = $value;
        return $this;
    }
}
PHP;

        $this->writeFile($outputPath . '/Algorithms/BaseAlgorithm.php', $content);
    }

    private function generateAudioVectorClass(string $outputPath): void
    {
        $content = <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Modules\Essentia\Types;

use App\Modules\Essentia\Exceptions\EssentiaException;
use Illuminate\Support\Facades\Process;
use FFI;

/**
 * Represents audio data for Essentia processing
 */
class AudioVector
{
    private array $data;
    private int $sampleRate;
    private int $channels;

    public function __construct(array $data, int $sampleRate = 44100, int $channels = 1)
    {
        if (empty($data)) {
            throw new EssentiaException('Audio data cannot be empty');
        }

        $this->data = $data;
        $this->sampleRate = $sampleRate;
        $this->channels = $channels;
    }

    /**
     * Load audio from any file format supported by FFmpeg
     */
    public static function fromFile(string $filePath, int $sampleRate = 44100, int $channels = 1): self
    {
        if (!file_exists($filePath)) {
            throw new EssentiaException("Audio file not found: {$filePath}");
        }

        $tempFile = tempnam(sys_get_temp_dir(), 'audio_') . '.raw';
        
        try {
            // Use FFmpeg to convert to raw PCM data
            $command = [
                'ffmpeg',
                '-i', $filePath,
                '-f', 'f32le',  // 32-bit float little-endian
                '-acodec', 'pcm_f32le',
                '-ar', (string) $sampleRate,
                '-ac', (string) $channels,
                '-y',  // Overwrite output file
                $tempFile
            ];

            $result = Process::run($command);

            if (!$result->successful()) {
                throw new EssentiaException("FFmpeg conversion failed: " . $result->errorOutput());
            }

            // Read the raw PCM data
            $rawData = file_get_contents($tempFile);
            if ($rawData === false) {
                throw new EssentiaException("Failed to read converted audio data");
            }

            // Convert binary data to float array
            $data = self::unpackFloatArray($rawData);

            // Handle multi-channel audio by converting to mono if needed
            if ($channels > 1) {
                $data = self::convertToMono($data, $channels);
            }

            return new self($data, $sampleRate, 1);

        } finally {
            // Clean up temporary file
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
        }
    }

    /**
     * Load audio specifically from WAV files (optimized path)
     */
    public static function fromWav(string $filePath, int $sampleRate = 44100): self
    {
        if (!file_exists($filePath)) {
            throw new EssentiaException("WAV file not found: {$filePath}");
        }

        // Check if it's actually a WAV file
        $pathInfo = pathinfo($filePath);
        if (strtolower($pathInfo['extension'] ?? '') !== 'wav') {
            throw new EssentiaException("File is not a WAV file: {$filePath}");
        }

        return self::fromFile($filePath, $sampleRate, 1);
    }

    /**
     * Load audio from a URL or stream
     */
    public static function fromUrl(string $url, int $sampleRate = 44100, int $maxDuration = 300): self
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'audio_stream_') . '.raw';
        
        try {
            $command = [
                'ffmpeg',
                '-i', $url,
                '-f', 'f32le',
                '-acodec', 'pcm_f32le',
                '-ar', (string) $sampleRate,
                '-ac', '1',  // Force mono
                '-t', (string) $maxDuration,  // Limit duration
                '-y',
                $tempFile
            ];

            $result = Process::timeout(60)->run($command);

            if (!$result->successful()) {
                throw new EssentiaException("Failed to load audio from URL: " . $result->errorOutput());
            }

            $rawData = file_get_contents($tempFile);
            if ($rawData === false) {
                throw new EssentiaException("Failed to read streamed audio data");
            }

            $data = self::unpackFloatArray($rawData);
            return new self($data, $sampleRate, 1);

        } finally {
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
        }
    }

    /**
     * Get audio metadata using FFprobe
     */
    public static function getAudioInfo(string $filePath): array
    {
        if (!file_exists($filePath)) {
            throw new EssentiaException("Audio file not found: {$filePath}");
        }

        $command = [
            'ffprobe',
            '-v', 'quiet',
            '-print_format', 'json',
            '-show_format',
            '-show_streams',
            $filePath
        ];

        $result = Process::run($command);

        if (!$result->successful()) {
            throw new EssentiaException("Failed to get audio info: " . $result->errorOutput());
        }

        $info = json_decode($result->output(), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new EssentiaException("Invalid JSON from ffprobe: " . json_last_error_msg());
        }

        // Extract audio stream information
        $audioStream = null;
        foreach ($info['streams'] ?? [] as $stream) {
            if ($stream['codec_type'] === 'audio') {
                $audioStream = $stream;
                break;
            }
        }

        if (!$audioStream) {
            throw new EssentiaException("No audio stream found in file");
        }

        return [
            'duration' => (float) ($info['format']['duration'] ?? 0),
            'sample_rate' => (int) ($audioStream['sample_rate'] ?? 44100),
            'channels' => (int) ($audioStream['channels'] ?? 1),
            'codec' => $audioStream['codec_name'] ?? 'unknown',
            'bitrate' => (int) ($audioStream['bit_rate'] ?? 0),
            'format' => $info['format']['format_name'] ?? 'unknown',
            'size' => (int) ($info['format']['size'] ?? 0),
        ];
    }

    public static function fromArray(array $data, int $sampleRate = 44100): self
    {
        return new self($data, $sampleRate);
    }

    /**
     * Create AudioVector from raw PCM binary data
     */
    public static function fromRawPCM(string $rawData, int $sampleRate = 44100, string $format = 'f32le'): self
    {
        $data = match($format) {
            'f32le' => self::unpackFloatArray($rawData),
            'i16le' => self::unpackInt16Array($rawData),
            'i24le' => self::unpackInt24Array($rawData),
            'i32le' => self::unpackInt32Array($rawData),
            default => throw new EssentiaException("Unsupported PCM format: {$format}")
        };

        return new self($data, $sampleRate);
    }

    /**
     * Resample audio to a different sample rate using FFmpeg
     */
    public function resample(int $newSampleRate): self
    {
        if ($newSampleRate === $this->sampleRate) {
            return $this;
        }

        $tempInput = tempnam(sys_get_temp_dir(), 'audio_in_') . '.raw';
        $tempOutput = tempnam(sys_get_temp_dir(), 'audio_out_') . '.raw';

        try {
            // Write current data to temp file
            file_put_contents($tempInput, $this->packFloatArray($this->data));

            $command = [
                'ffmpeg',
                '-f', 'f32le',
                '-ar', (string) $this->sampleRate,
                '-ac', '1',
                '-i', $tempInput,
                '-f', 'f32le',
                '-ar', (string) $newSampleRate,
                '-ac', '1',
                '-y',
                $tempOutput
            ];

            $result = Process::run($command);

            if (!$result->successful()) {
                throw new EssentiaException("Resampling failed: " . $result->errorOutput());
            }

            $rawData = file_get_contents($tempOutput);
            if ($rawData === false) {
                throw new EssentiaException("Failed to read resampled audio data");
            }

            $newData = self::unpackFloatArray($rawData);
            return new self($newData, $newSampleRate, 1);

        } finally {
            foreach ([$tempInput, $tempOutput] as $file) {
                if (file_exists($file)) {
                    unlink($file);
                }
            }
        }
    }

    public function getData(): array
    {
        return $this->data;
    }

    public function getSampleRate(): int
    {
        return $this->sampleRate;
    }

    public function getChannels(): int
    {
        return $this->channels;
    }

    public function getLength(): int
    {
        return count($this->data);
    }

    public function getDuration(): float
    {
        return $this->getLength() / $this->sampleRate;
    }

    public function slice(int $start, int $length): self
    {
        $slicedData = array_slice($this->data, $start, $length);
        return new self($slicedData, $this->sampleRate, $this->channels);
    }

    /**
     * Extract a time-based slice
     */
    public function sliceTime(float $startSeconds, float $durationSeconds): self
    {
        $startSample = (int) ($startSeconds * $this->sampleRate);
        $lengthSamples = (int) ($durationSeconds * $this->sampleRate);
        
        return $this->slice($startSample, $lengthSamples);
    }

    public function normalize(): self
    {
        $max = max(array_map('abs', $this->data));
        if ($max > 0) {
            $normalizedData = array_map(fn($sample) => $sample / $max, $this->data);
            return new self($normalizedData, $this->sampleRate, $this->channels);
        }
        return $this;
    }

    /**
     * Apply gain (amplification/attenuation)
     */
    public function applyGain(float $gainDb): self
    {
        $gainLinear = pow(10, $gainDb / 20);
        $amplifiedData = array_map(fn($sample) => $sample * $gainLinear, $this->data);
        return new self($amplifiedData, $this->sampleRate, $this->channels);
    }

    /**
     * Apply fade in/out
     */
    public function applyFade(float $fadeInSeconds = 0, float $fadeOutSeconds = 0): self
    {
        $data = $this->data;
        $length = count($data);
        $fadeInSamples = (int) ($fadeInSeconds * $this->sampleRate);
        $fadeOutSamples = (int) ($fadeOutSeconds * $this->sampleRate);

        // Apply fade in
        for ($i = 0; $i < min($fadeInSamples, $length); $i++) {
            $factor = $i / $fadeInSamples;
            $data[$i] *= $factor;
        }

        // Apply fade out
        for ($i = 0; $i < min($fadeOutSamples, $length); $i++) {
            $factor = $i / $fadeOutSamples;
            $data[$length - 1 - $i] *= $factor;
        }

        return new self($data, $this->sampleRate, $this->channels);
    }

    public function toCArray(FFI $ffi): FFI\CData
    {
        // Convert PHP array to C array for FFI
        $size = count($this->data);
        $cArray = $ffi->new("float[{$size}]");
        
        for ($i = 0; $i < $size; $i++) {
            $cArray[$i] = (float) $this->data[$i];
        }
        
        return $cArray;
    }

    /**
     * Save audio data to file using FFmpeg
     */
    public function saveToFile(string $filePath, string $format = 'wav'): void
    {
        $tempInput = tempnam(sys_get_temp_dir(), 'audio_save_') . '.raw';

        try {
            // Write raw PCM data
            file_put_contents($tempInput, $this->packFloatArray($this->data));

            $command = [
                'ffmpeg',
                '-f', 'f32le',
                '-ar', (string) $this->sampleRate,
                '-ac', '1',
                '-i', $tempInput,
                '-c:a', match($format) {
                    'wav' => 'pcm_s16le',
                    'flac' => 'flac',
                    'mp3' => 'libmp3lame',
                    'ogg' => 'libvorbis',
                    default => 'pcm_s16le'
                },
                '-y',
                $filePath
            ];

            $result = Process::run($command);

            if (!$result->successful()) {
                throw new EssentiaException("Failed to save audio file: " . $result->errorOutput());
            }

        } finally {
            if (file_exists($tempInput)) {
                unlink($tempInput);
            }
        }
    }

    // Private helper methods

    private static function unpackFloatArray(string $binaryData): array
    {
        if (strlen($binaryData) % 4 !== 0) {
            throw new EssentiaException("Invalid float binary data length");
        }

        return array_values(unpack('f*', $binaryData));
    }

    private static function unpackInt16Array(string $binaryData): array
    {
        if (strlen($binaryData) % 2 !== 0) {
            throw new EssentiaException("Invalid int16 binary data length");
        }

        $intData = array_values(unpack('s*', $binaryData));
        // Convert to float range [-1.0, 1.0]
        return array_map(fn($sample) => $sample / 32768.0, $intData);
    }

    private static function unpackInt24Array(string $binaryData): array
    {
        if (strlen($binaryData) % 3 !== 0) {
            throw new EssentiaException("Invalid int24 binary data length");
        }

        $data = [];
        for ($i = 0; $i < strlen($binaryData); $i += 3) {
            $bytes = substr($binaryData, $i, 3);
            $value = unpack('V', $bytes . "\x00")[1];
            
            // Handle sign extension for 24-bit
            if ($value >= 0x800000) {
                $value -= 0x1000000;
            }
            
            $data[] = $value / 8388608.0; // Convert to float range [-1.0, 1.0]
        }

        return $data;
    }

    private static function unpackInt32Array(string $binaryData): array
    {
        if (strlen($binaryData) % 4 !== 0) {
            throw new EssentiaException("Invalid int32 binary data length");
        }

        $intData = array_values(unpack('l*', $binaryData));
        // Convert to float range [-1.0, 1.0]
        return array_map(fn($sample) => $sample / 2147483648.0, $intData);
    }

    private function packFloatArray(array $data): string
    {
        return pack('f*', ...$data);
    }

    private static function convertToMono(array $data, int $channels): array
    {
        if ($channels === 1) {
            return $data;
        }

        $monoData = [];
        $samplesPerChannel = count($data) / $channels;

        for ($i = 0; $i < $samplesPerChannel; $i++) {
            $sum = 0;
            for ($ch = 0; $ch < $channels; $ch++) {
                $sum += $data[$i * $channels + $ch];
            }
            $monoData[] = $sum / $channels;
        }

        return $monoData;
    }

    public function __toString(): string
    {
        return sprintf(
            'AudioVector(length=%d, sampleRate=%d, channels=%d, duration=%.2fs)',
            $this->getLength(),
            $this->sampleRate,
            $this->channels,
            $this->getDuration()
        );
    }
}
PHP;

        $this->writeFile($outputPath . '/Types/AudioVector.php', $content);
    }

    private function generateExceptionClasses(string $outputPath): void
    {
        // EssentiaException
        $essentiaException = <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Modules\Essentia\Exceptions;

use Exception;

/**
 * Base exception for all Essentia-related errors
 */
class EssentiaException extends Exception
{
    public function __construct(string $message = "", int $code = 0, ?Exception $previous = null)
    {
        parent::__construct("Essentia Error: " . $message, $code, $previous);
    }
}
PHP;

        $this->writeFile($outputPath . '/Exceptions/EssentiaException.php', $essentiaException);

        // ConfigurationException
        $configException = <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Modules\Essentia\Exceptions;

/**
 * Exception for algorithm configuration errors
 */
class ConfigurationException extends EssentiaException
{
    public function __construct(string $message = "", int $code = 0, ?EssentiaException $previous = null)
    {
        parent::__construct("Configuration Error: " . $message, $code, $previous);
    }
}
PHP;

        $this->writeFile($outputPath . '/Exceptions/ConfigurationException.php', $configException);

        // AlgorithmException
        $algorithmException = <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Modules\Essentia\Exceptions;

/**
 * Exception for algorithm execution errors
 */
class AlgorithmException extends EssentiaException
{
    public function __construct(string $message = "", int $code = 0, ?EssentiaException $previous = null)
    {
        parent::__construct("Algorithm Error: " . $message, $code, $previous);
    }
}
PHP;

        $this->writeFile($outputPath . '/Exceptions/AlgorithmException.php', $algorithmException);
    }

    private function generateUtilityClasses(string $outputPath): void
    {
        $utilityClass = <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Modules\Essentia\Utils;

/**
 * Utility functions for Essentia processing
 */
class AudioUtils
{
    /**
     * Convert frequency to musical note
     */
    public static function frequencyToNote(float $frequency): string
    {
        if ($frequency <= 0) {
            return 'N/A';
        }

        $noteNames = ['C', 'C#', 'D', 'D#', 'E', 'F', 'F#', 'G', 'G#', 'A', 'A#', 'B'];
        $A4 = 440.0;
        
        $semitones = round(12 * log($frequency / $A4) / log(2));
        $octave = 4 + intval($semitones / 12);
        $noteIndex = ($semitones % 12 + 12) % 12;
        
        return $noteNames[$noteIndex] . $octave;
    }

    /**
     * Convert BPM to beat period in seconds
     */
    public static function bpmToPeriod(float $bpm): float
    {
        return 60.0 / $bpm;
    }

    /**
     * Convert beat period to BPM
     */
    public static function periodToBpm(float $period): float
    {
        return 60.0 / $period;
    }

    /**
     * Apply windowing function to audio data
     */
    public static function applyWindow(array $data, string $windowType = 'hann'): array
    {
        $length = count($data);
        $window = self::generateWindow($length, $windowType);
        
        $result = [];
        for ($i = 0; $i < $length; $i++) {
            $result[] = $data[$i] * $window[$i];
        }
        
        return $result;
    }

    /**
     * Generate window function
     */
    public static function generateWindow(int $length, string $type = 'hann'): array
    {
        $window = [];
        
        switch ($type) {
            case 'hann':
            case 'hanning':
                for ($i = 0; $i < $length; $i++) {
                    $window[] = 0.5 * (1 - cos(2 * M_PI * $i / ($length - 1)));
                }
                break;
                
            case 'hamming':
                for ($i = 0; $i < $length; $i++) {
                    $window[] = 0.54 - 0.46 * cos(2 * M_PI * $i / ($length - 1));
                }
                break;
                
            case 'blackman':
                for ($i = 0; $i < $length; $i++) {
                    $window[] = 0.42 - 0.5 * cos(2 * M_PI * $i / ($length - 1)) + 0.08 * cos(4 * M_PI * $i / ($length - 1));
                }
                break;
                
            default:
                // Rectangular window
                $window = array_fill(0, $length, 1.0);
        }
        
        return $window;
    }

    /**
     * Calculate RMS of audio data
     */
    public static function calculateRMS(array $data): float
    {
        if (empty($data)) {
            return 0.0;
        }

        $sum = array_sum(array_map(fn($sample) => $sample * $sample, $data));
        return sqrt($sum / count($data));
    }

    /**
     * Find peaks in audio data
     */
    public static function findPeaks(array $data, float $threshold = 0.1): array
    {
        $peaks = [];
        $length = count($data);
        
        for ($i = 1; $i < $length - 1; $i++) {
            if ($data[$i] > $data[$i - 1] && 
                $data[$i] > $data[$i + 1] && 
                $data[$i] > $threshold) {
                $peaks[] = [
                    'index' => $i,
                    'value' => $data[$i]
                ];
            }
        }
        
        return $peaks;
    }
}
PHP;

        $this->writeFile($outputPath . '/Utils/AudioUtils.php', $utilityClass);
    }

    private function generateAlgorithmSpecificExecution(string $algorithm, string $category): string
    {
        $cases = [];

        // Generate specific cases for known algorithms
        $algorithmExecutions = [
            'AudioLoader' => "
            case 'AudioLoader':
                // Audio loading algorithm
                if (!is_string(\$input)) {
                    throw new AlgorithmException('AudioLoader expects file path');
                }
                
                \$audioData = \$ffi->new('float**');
                \$sampleRate = \$ffi->new('int');
                \$channels = \$ffi->new('int');
                \$length = \$ffi->new('int');
                
                \$result = \$ffi->essentia_audio_loader(\$input, \$audioData, \$sampleRate, \$channels, \$length);
                
                if (\$result != 0) {
                    throw new AlgorithmException('Failed to load audio file');
                }
                
                \$phpArray = [];
                for (\$i = 0; \$i < \$length->cdata; \$i++) {
                    \$phpArray[] = \$audioData[0][\$i];
                }
                
                \$outputs = [
                    'audio' => \$phpArray,
                    'sampleRate' => \$sampleRate->cdata,
                    'channels' => \$channels->cdata
                ];
                break;",

            'MonoLoader' => "
            case 'MonoLoader':
                // Mono audio loading
                if (!is_string(\$input)) {
                    throw new AlgorithmException('MonoLoader expects file path');
                }
                
                \$audioData = \$ffi->new('float*');
                \$sampleRate = \$ffi->new('int');
                \$length = \$ffi->new('int');
                
                \$result = \$ffi->essentia_mono_loader(\$input, \$audioData, \$sampleRate, \$length);
                
                if (\$result != 0) {
                    throw new AlgorithmException('Failed to load mono audio file');
                }
                
                \$phpArray = [];
                for (\$i = 0; \$i < \$length->cdata; \$i++) {
                    \$phpArray[] = \$audioData[\$i];
                }
                
                \$outputs = [
                    'audio' => \$phpArray,
                    'sampleRate' => \$sampleRate->cdata
                ];
                break;",

            'SpectralCentroid' => "
            case 'SpectralCentroid':
                // Spectral centroid calculation
                \$inputSize = is_array(\$input) ? count(\$input) : (\$input instanceof AudioVector ? \$input->getLength() : 1024);
                \$centroid = \$ffi->new('float');
                
                \$result = \$ffi->essentia_spectral_centroid(\$input, \$inputSize, \$centroid);
                
                if (\$result != 0) {
                    throw new AlgorithmException('Spectral centroid computation failed');
                }
                
                \$outputs = [\$centroid->cdata];
                break;",

            'MFCC' => "
            case 'MFCC':
                // MFCC computation
                \$inputSize = is_array(\$input) ? count(\$input) : (\$input instanceof AudioVector ? \$input->getLength() : 1024);
                \$numCoeffs = \$this->parameters['numCoeffs'] ?? 13;
                \$coefficients = \$ffi->new(\"float[\$numCoeffs]\");
                
                \$result = \$ffi->essentia_mfcc(\$input, \$inputSize, \$coefficients, \$numCoeffs);
                
                if (\$result != 0) {
                    throw new AlgorithmException('MFCC computation failed');
                }
                
                \$outputs = [];
                for (\$i = 0; \$i < \$numCoeffs; \$i++) {
                    \$outputs[] = \$coefficients[\$i];
                }
                break;",

            'PitchYin' => "
            case 'PitchYin':
                // Pitch detection using Yin algorithm
                \$inputSize = is_array(\$input) ? count(\$input) : (\$input instanceof AudioVector ? \$input->getLength() : 1024);
                \$pitch = \$ffi->new('float');
                \$confidence = \$ffi->new('float');
                
                \$result = \$ffi->essentia_pitch_yin(\$input, \$inputSize, \$pitch, \$confidence);
                
                if (\$result != 0) {
                    throw new AlgorithmException('Pitch detection failed');
                }
                
                \$outputs = [\$pitch->cdata, \$confidence->cdata];
                break;",

            'PitchYinFFT' => "
            case 'PitchYinFFT':
                // Pitch detection using Yin FFT algorithm
                \$inputSize = is_array(\$input) ? count(\$input) : (\$input instanceof AudioVector ? \$input->getLength() : 1024);
                \$pitch = \$ffi->new('float');
                \$confidence = \$ffi->new('float');
                
                \$result = \$ffi->essentia_pitch_yin_fft(\$input, \$inputSize, \$pitch, \$confidence);
                
                if (\$result != 0) {
                    throw new AlgorithmException('Pitch YIN FFT detection failed');
                }
                
                \$outputs = [\$pitch->cdata, \$confidence->cdata];
                break;",

            'RhythmExtractor' => "
            case 'RhythmExtractor':
                // Rhythm extraction
                \$inputSize = is_array(\$input) ? count(\$input) : (\$input instanceof AudioVector ? \$input->getLength() : 1024);
                \$bpm = \$ffi->new('float');
                \$maxBeats = 1000;
                \$beats = \$ffi->new(\"float[\$maxBeats]\");
                \$numBeats = \$ffi->new('int');
                
                \$result = \$ffi->essentia_rhythm_extractor(\$input, \$inputSize, \$bpm, \$beats, \$maxBeats, \$numBeats);
                
                if (\$result != 0) {
                    throw new AlgorithmException('Rhythm extraction failed');
                }
                
                \$beatArray = [];
                for (\$i = 0; \$i < \$numBeats->cdata; \$i++) {
                    \$beatArray[] = \$beats[\$i];
                }
                
                \$outputs = [\$bpm->cdata, ...\$beatArray];
                break;",

            'Energy' => "
            case 'Energy':
                // Energy computation
                \$inputSize = is_array(\$input) ? count(\$input) : (\$input instanceof AudioVector ? \$input->getLength() : 1024);
                \$energy = \$ffi->new('float');
                
                \$result = \$ffi->essentia_energy(\$input, \$inputSize, \$energy);
                
                if (\$result != 0) {
                    throw new AlgorithmException('Energy computation failed');
                }
                
                \$outputs = [\$energy->cdata];
                break;",

            'RMS' => "
            case 'RMS':
                // RMS computation
                \$inputSize = is_array(\$input) ? count(\$input) : (\$input instanceof AudioVector ? \$input->getLength() : 1024);
                \$rms = \$ffi->new('float');
                
                \$result = \$ffi->essentia_rms(\$input, \$inputSize, \$rms);
                
                if (\$result != 0) {
                    throw new AlgorithmException('RMS computation failed');
                }
                
                \$outputs = [\$rms->cdata];
                break;",

            'ZeroCrossingRate' => "
            case 'ZeroCrossingRate':
                // Zero crossing rate computation
                \$inputSize = is_array(\$input) ? count(\$input) : (\$input instanceof AudioVector ? \$input->getLength() : 1024);
                \$zcr = \$ffi->new('float');
                
                \$result = \$ffi->essentia_zero_crossing_rate(\$input, \$inputSize, \$zcr);
                
                if (\$result != 0) {
                    throw new AlgorithmException('Zero crossing rate computation failed');
                }
                
                \$outputs = [\$zcr->cdata];
                break;",

            'SpectralRolloff' => "
            case 'SpectralRolloff':
                // Spectral rolloff computation
                \$inputSize = is_array(\$input) ? count(\$input) : (\$input instanceof AudioVector ? \$input->getLength() : 1024);
                \$rolloff = \$ffi->new('float');
                
                \$result = \$ffi->essentia_spectral_rolloff(\$input, \$inputSize, \$rolloff);
                
                if (\$result != 0) {
                    throw new AlgorithmException('Spectral rolloff computation failed');
                }
                
                \$outputs = [\$rolloff->cdata];
                break;",

            'MelBands' => "
            case 'MelBands':
                // Mel bands computation
                \$inputSize = is_array(\$input) ? count(\$input) : (\$input instanceof AudioVector ? \$input->getLength() : 1024);
                \$numBands = \$this->parameters['numberBands'] ?? 24;
                \$bands = \$ffi->new(\"float[\$numBands]\");
                
                \$result = \$ffi->essentia_mel_bands(\$input, \$inputSize, \$bands, \$numBands);
                
                if (\$result != 0) {
                    throw new AlgorithmException('Mel bands computation failed');
                }
                
                \$outputs = [];
                for (\$i = 0; \$i < \$numBands; \$i++) {
                    \$outputs[] = \$bands[\$i];
                }
                break;",

            'FFT' => "
            case 'FFT':
                // FFT computation
                \$inputSize = is_array(\$input) ? count(\$input) : (\$input instanceof AudioVector ? \$input->getLength() : 1024);
                \$fftSize = \$this->parameters['size'] ?? \$inputSize;
                \$real = \$ffi->new(\"float[\$fftSize]\");
                \$imag = \$ffi->new(\"float[\$fftSize]\");
                
                \$result = \$ffi->essentia_fft(\$input, \$inputSize, \$real, \$imag, \$fftSize);
                
                if (\$result != 0) {
                    throw new AlgorithmException('FFT computation failed');
                }
                
                \$realArray = [];
                \$imagArray = [];
                for (\$i = 0; \$i < \$fftSize; \$i++) {
                    \$realArray[] = \$real[\$i];
                    \$imagArray[] = \$imag[\$i];
                }
                
                \$outputs = [
                    'real' => \$realArray,
                    'imaginary' => \$imagArray
                ];
                break;",

            'Windowing' => "
            case 'Windowing':
                // Windowing computation
                \$inputSize = is_array(\$input) ? count(\$input) : (\$input instanceof AudioVector ? \$input->getLength() : 1024);
                \$windowSize = \$this->parameters['size'] ?? \$inputSize;
                \$output = \$ffi->new(\"float[\$windowSize]\");
                
                \$result = \$ffi->essentia_windowing(\$input, \$inputSize, \$output, \$windowSize);
                
                if (\$result != 0) {
                    throw new AlgorithmException('Windowing computation failed');
                }
                
                \$outputs = [];
                for (\$i = 0; \$i < \$windowSize; \$i++) {
                    \$outputs[] = \$output[\$i];
                }
                break;",
        ];

        // Only include the case for the current algorithm
        if (isset($algorithmExecutions[$algorithm])) {
            $cases[] = $algorithmExecutions[$algorithm];
        }

        return implode("\n", $cases);
    }

    private function generateValidParameters(string $algorithm, string $category): string
    {
        // Algorithm-specific parameter definitions
        $parameterSets = [
            // Audio I/O
            'AudioLoader' => "['filename', 'computeMD5', 'sampleRate']",
            'MonoLoader' => "['filename', 'sampleRate', 'downmix']",
            'EasyLoader' => "['filename', 'sampleRate', 'startTime', 'endTime']",
            'AudioWriter' => "['filename', 'format', 'sampleRate']",
            'MonoWriter' => "['filename', 'format', 'sampleRate']",

            // Audio Problems
            'StartStopCut' => "['frameSize', 'hopSize', 'maximumStartTime', 'maximumStopTime', 'sampleRate', 'threshold']",
            'ClickDetector' => "['frameSize', 'hopSize', 'order', 'powerEstimationThreshold', 'detectionThreshold', 'silenceThreshold']",
            'DiscontinuityDetector' => "['frameSize', 'hopSize', 'detectionThreshold', 'energy_threshold', 'kernel_size', 'order']",
            'FalseStereoDetector' => "['frameSize', 'hopSize', 'correlationThreshold']",
            'GapsDetector' => "['frameSize', 'hopSize', 'silenceThreshold', 'minimumGapLength']",
            'HumDetector' => "['frameSize', 'hopSize', 'minimumDuration', 'tolerance', 'sampleRate']",
            'SaturationDetector' => "['frameSize', 'hopSize', 'differentialThreshold', 'energyThreshold', 'minimumDuration']",
            'SNR' => "['frameSize', 'noiseThreshold', 'useBroadbandNoise']",

            // Spectral
            'MFCC' => "['numCoeffs', 'sampleRate', 'numberBands', 'lowFrequencyBound', 'highFrequencyBound', 'inputSize', 'type', 'weighting', 'warpingFormula', 'logType', 'normalize', 'dctType', 'liftering']",
            'SpectralCentroid' => "['sampleRate']",
            'SpectralRolloff' => "['sampleRate', 'cutoff']",
            'SpectralFlux' => "['sampleRate', 'halfRectify']",
            'SpectralContrast' => "['sampleRate', 'frameSize', 'numberBands', 'lowFrequencyBound', 'highFrequencyBound', 'neighbourRatio', 'staticDistribution']",
            'MelBands' => "['sampleRate', 'inputSize', 'numberBands', 'lowFrequencyBound', 'highFrequencyBound', 'warpingFormula', 'weighting', 'normalize', 'type', 'log']",
            'BarkBands' => "['sampleRate', 'inputSize', 'numberBands', 'normalize']",
            'ERBBands' => "['sampleRate', 'inputSize', 'numberBands', 'lowFrequencyBound', 'highFrequencyBound', 'normalize', 'type', 'width']",
            'SpectralPeaks' => "['sampleRate', 'threshold', 'minFrequency', 'maxFrequency', 'maxPeaks', 'interpolate', 'magnitudeThreshold', 'orderBy']",
            'FFT' => "['size']",
            'IFFT' => "['size']",
            'Spectrum' => "['size']",
            'PowerSpectrum' => "['size']",

            // Temporal
            'DynamicComplexity' => "['frameSize', 'sampleRate']",
            'Energy' => "[]",
            'EnergyBand' => "['sampleRate', 'startCutoffFrequency', 'stopCutoffFrequency']",
            'EnergyBandRatio' => "['sampleRate', 'startFrequency', 'stopFrequency']",
            'Envelope' => "['applyRectification', 'attackTime', 'releaseTime', 'sampleRate']",
            'InstantPower' => "[]",
            'Intensity' => "['sampleRate']",
            'Larm' => "['attackTime', 'power', 'releaseTime', 'sampleRate']",
            'Leq' => "[]",
            'Loudness' => "[]",
            'LoudnessEBUR128' => "['hopSize', 'sampleRate', 'startAtZero']",
            'LoudnessVickers' => "['sampleRate']",
            'ReplayGain' => "['sampleRate']",
            'RMS' => "[]",
            'ZeroCrossingRate' => "['threshold']",

            // Tonal
            'PitchYin' => "['frameSize', 'sampleRate', 'tolerance', 'interpolate', 'minFrequency', 'maxFrequency']",
            'PitchYinFFT' => "['frameSize', 'sampleRate', 'tolerance', 'interpolate', 'minFrequency', 'maxFrequency']",
            'PitchYinProbabilistic' => "['frameSize', 'sampleRate', 'tolerance', 'lowRMS', 'outputUnvoiced', 'preciseTime']",
            'Key' => "['sampleRate', 'numHarmonics', 'slope', 'profileType', 'usePolyphony', 'useThreeChords']",
            'HPCP' => "['sampleRate', 'minFrequency', 'maxFrequency', 'referenceFrequency', 'nonLinear', 'normalized', 'size', 'windowSize', 'maxShifted', 'splitFrequency', 'harmonics', 'bandPreset', 'bandSplitFrequency', 'weightType']",
            'Chromagram' => "['sampleRate', 'minFrequency', 'maxFrequency', 'referenceFrequency', 'binsPerOctave', 'normalizeType', 'threshold']",

            // Rhythm
            'RhythmExtractor' => "['method', 'minTempo', 'maxTempo', 'frameHop']",
            'RhythmExtractor2013' => "['method', 'minTempo', 'maxTempo']",
            'BeatTrackerDegara' => "['maxTempo', 'minTempo']",
            'BeatTrackerMultiFeature' => "['maxTempo', 'minTempo']",
            'OnsetDetection' => "['method', 'sampleRate']",
            'OnsetDetectionGlobal' => "['method', 'sampleRate', 'frameSize', 'hopSize']",
            'TempoTap' => "['frameHop', 'frameSize', 'maxTempo', 'minTempo', 'numberFrames', 'sampleRate', 'tempoHints']",
            'Onsets' => "['alpha', 'delay', 'frameRate', 'maxTempo', 'minTempo', 'silenceThreshold']",

            // Filters
            'BandPass' => "['bandwidth', 'cutoffFrequency', 'sampleRate']",
            'BandReject' => "['bandwidth', 'cutoffFrequency', 'sampleRate']",
            'HighPass' => "['cutoffFrequency', 'sampleRate']",
            'LowPass' => "['cutoffFrequency', 'sampleRate']",
            'AllPass' => "['bandwidth', 'cutoffFrequency', 'sampleRate', 'order']",
            'EqualLoudness' => "['sampleRate']",
            'DCRemoval' => "['cutoffFrequency', 'sampleRate']",
            'IIR' => "['numerator', 'denominator']",

            // Statistics
            'Mean' => "[]",
            'Variance' => "[]",
            'Centroid' => "['range']",
            'CentralMoments' => "['range', 'mode']",
            'RawMoments' => "['range', 'mode']",
            'DistributionShape' => "[]",
            'Decrease' => "['range']",
            'Flatness' => "[]",
            'Crest' => "[]",
            'Entropy' => "[]",

            // Extractors
            'LowLevelSpectralExtractor' => "['frameSize', 'hopSize', 'sampleRate']",
            'TonalExtractor' => "['frameSize', 'hopSize', 'sampleRate']",
            'RhythmDescriptors' => "['frameSize', 'hopSize', 'sampleRate']",
            'FreesoundExtractor' => "['frameSize', 'hopSize', 'sampleRate']",

            // Machine Learning
            'TensorflowInputMusiCNN' => "['patchHopSize']",
            'TensorflowInputVGGish' => "[]",
            'TensorflowInputTempoCNN' => "[]",
            'TensorflowInputFSDSINet' => "[]",

            // Synthesis
            'SineModelAnal' => "['sampleRate', 'maxnSines', 'magnitudeThreshold', 'minSineDur', 'freqDevOffset', 'freqDevSlope']",
            'SineModelSynth' => "['sampleRate', 'fftSize', 'hopSize']",
            'HarmonicModelAnal' => "['sampleRate', 'maxnSines', 'magnitudeThreshold', 'minSineDur', 'nHarmonics', 'harmThreshold']",
            'HprModelAnal' => "['sampleRate', 'maxnSines', 'magnitudeThreshold', 'minSineDur', 'nHarmonics', 'harmThreshold', 'freqDevOffset', 'freqDevSlope']",
            'HpsModelAnal' => "['sampleRate', 'maxnSines', 'magnitudeThreshold', 'minSineDur', 'nHarmonics', 'harmThreshold', 'freqDevOffset', 'freqDevSlope', 'stocf']",
            'SprModelAnal' => "['sampleRate', 'maxnSines', 'magnitudeThreshold', 'minSineDur', 'freqDevOffset', 'freqDevSlope', 'stocf']",
            'SpsModelAnal' => "['sampleRate', 'maxnSines', 'magnitudeThreshold', 'minSineDur', 'freqDevOffset', 'freqDevSlope', 'stocf']",

            // Utility
            'FrameCutter' => "['frameSize', 'hopSize', 'silentFrames', 'startFromZero', 'validFrameThresholdRatio']",
            'Windowing' => "['type', 'size', 'zeroPadding', 'zeroPhase', 'normalized']",
            'OverlapAdd' => "['frameSize', 'hopSize', 'gain']",
            'Resample' => "['inputSampleRate', 'outputSampleRate', 'quality']",
            'Scale' => "['factor', 'maxAbsValue', 'clipping']",
            'UnaryOperator' => "['type', 'shift', 'scale']",
            'BinaryOperator' => "['type']",
            'Clipper' => "['min', 'max']",
            'NoiseAdder' => "['level', 'fixSeed']",
        ];

        return $parameterSets[$algorithm] ?? "[]";
    }

    private function generateIntelligentAlgorithmClass(string $algorithm, string $outputPath): void
    {
        $metadata = $this->algorithmMetadata[$algorithm] ?? [];
        $category = $metadata['category'] ?? 'Standard';
        $mode = $metadata['mode'] ?? 'standard';
        $description = $metadata['description'] ?? "Auto-generated wrapper for {$algorithm} algorithm";

        // Generate algorithm-specific execution cases
        $algorithmExecutionCases = $this->generateAlgorithmSpecificExecution($algorithm, $category);
        $validParameters = $this->generateValidParameters($algorithm, $category);

        $classContent = <<<PHP
<?php

declare(strict_types=1);

namespace App\\Modules\\Essentia\\Algorithms\\{$category};

use App\\Modules\\Essentia\\Algorithms\\BaseAlgorithm;
use App\\Modules\\Essentia\\Exceptions\\{AlgorithmException, ConfigurationException};
use App\\Modules\\Essentia\\Types\\AudioVector;
use FFI;

/**
 * {$description}
 * 
 * Category: {$category}
 * Mode: {$mode}
 */
class {$algorithm} extends BaseAlgorithm
{
    protected string \$algorithmName = '{$algorithm}';
    protected string \$mode = '{$mode}';
    protected string \$category = '{$category}';
    
    private ?\FFI\CData \$algorithmHandle = null;
    private bool \$configured = false;

    public function __destruct()
    {
        if (\$this->algorithmHandle) {
            \$this->cleanupAlgorithm();
        }
    }

    public function compute(\$input): array
    {
        try {
            // Lazy initialization of the algorithm
            if (!\$this->algorithmHandle) {
                \$this->initializeAlgorithm();
            }
            
            // Input validation based on algorithm type
            \$this->validateAlgorithmInput(\$input);
            
            // Convert input to appropriate format
            \$processedInput = \$this->prepareInput(\$input);
            
            // Execute the algorithm
            \$result = \$this->executeAlgorithm(\$processedInput);
            
            return \$this->processOutput(\$result);
            
        } catch (\\Exception \$e) {
            throw new AlgorithmException(
                "Failed to compute {$algorithm}: " . \$e->getMessage(),
                0,
                \$e
            );
        }
    }

    private function initializeAlgorithm(): void
    {
        \$ffi = \$this->essentia->getFFI();
        
        try {
            // Create algorithm instance
            \$this->algorithmHandle = \$ffi->{\$this->getAlgorithmCreateFunction()}();
            
            if (!\$this->algorithmHandle) {
                throw new AlgorithmException("Failed to create {$algorithm} algorithm instance");
            }
            
            // Configure algorithm parameters
            \$this->configureAlgorithmParameters();
            \$this->configured = true;
            
        } catch (\\FFI\\Exception \$e) {
            throw new AlgorithmException("FFI error initializing {$algorithm}: " . \$e->getMessage(), 0, \$e);
        }
    }

    private function getAlgorithmCreateFunction(): string
    {
        // Convert algorithm name to C function name
        \$functionName = 'essentia_create_' . strtolower(\$this->algorithmName);
        return \$functionName;
    }

    private function configureAlgorithmParameters(): void
    {
        if (empty(\$this->parameters)) {
            return;
        }
        
        \$ffi = \$this->essentia->getFFI();
        
        foreach (\$this->parameters as \$key => \$value) {
            try {
                \$this->setAlgorithmParameter(\$ffi, \$key, \$value);
            } catch (\\Exception \$e) {
                throw new ConfigurationException("Failed to set parameter '\$key': " . \$e->getMessage(), 0, \$e);
            }
        }
    }

    private function setAlgorithmParameter(FFI \$ffi, string \$key, \$value): void
    {
        // Parameter setting logic based on value type
        switch (gettype(\$value)) {
            case 'integer':
                \$ffi->essentia_algorithm_set_int_parameter(\$this->algorithmHandle, \$key, \$value);
                break;
            case 'double':
                \$ffi->essentia_algorithm_set_real_parameter(\$this->algorithmHandle, \$key, (float) \$value);
                break;
            case 'string':
                \$ffi->essentia_algorithm_set_string_parameter(\$this->algorithmHandle, \$key, \$value);
                break;
            case 'boolean':
                \$ffi->essentia_algorithm_set_bool_parameter(\$this->algorithmHandle, \$key, \$value);
                break;
            case 'array':
                \$this->setArrayParameter(\$ffi, \$key, \$value);
                break;
            default:
                throw new ConfigurationException("Unsupported parameter type for '\$key': " . gettype(\$value));
        }
    }

    private function setArrayParameter(FFI \$ffi, string \$key, array \$value): void
    {
        if (empty(\$value)) {
            return;
        }
        
        \$firstElement = reset(\$value);
        
        if (is_numeric(\$firstElement)) {
            // Numeric array
            \$size = count(\$value);
            \$cArray = \$ffi->new("float[\$size]");
            
            for (\$i = 0; \$i < \$size; \$i++) {
                \$cArray[\$i] = (float) \$value[\$i];
            }
            
            \$ffi->essentia_algorithm_set_real_vector_parameter(\$this->algorithmHandle, \$key, \$cArray, \$size);
        } else {
            // String array
            \$size = count(\$value);
            \$cArray = \$ffi->new("char*[\$size]");
            
            for (\$i = 0; \$i < \$size; \$i++) {
                \$cArray[\$i] = \$ffi->new("char[" . (strlen(\$value[\$i]) + 1) . "]");
                FFI::memcpy(\$cArray[\$i], \$value[\$i], strlen(\$value[\$i]));
            }
            
            \$ffi->essentia_algorithm_set_string_vector_parameter(\$this->algorithmHandle, \$key, \$cArray, \$size);
        }
    }

    private function validateAlgorithmInput(\$input): void
    {
        // Category-specific input validation
        switch (\$this->category) {
            case 'Spectral':
            case 'Temporal':
            case 'Tonal':
                if (!is_array(\$input) && !(\$input instanceof AudioVector)) {
                    throw new AlgorithmException('{$category} algorithms expect array or AudioVector input');
                }
                break;
                
            case 'Io':
                if (in_array(\$this->algorithmName, ['AudioLoader', 'MonoLoader', 'EasyLoader'])) {
                    if (!is_string(\$input)) {
                        throw new AlgorithmException('Loader algorithms expect string file path');
                    }
                    if (!file_exists(\$input)) {
                        throw new AlgorithmException("Audio file not found: \$input");
                    }
                } elseif (in_array(\$this->algorithmName, ['AudioWriter', 'MonoWriter'])) {
                    if (!(\$input instanceof AudioVector) && !is_array(\$input)) {
                        throw new AlgorithmException('Writer algorithms expect AudioVector or array input');
                    }
                }
                break;
                
            case 'Rhythm':
                \$this->validateInput(\$input, 'array');
                break;
                
            case 'Filters':
                \$this->validateInput(\$input, 'array');
                break;
                
            case 'MachineLearning':
                // ML algorithms may have different input requirements
                if (!is_array(\$input) && !(\$input instanceof AudioVector)) {
                    throw new AlgorithmException('ML algorithms expect array or AudioVector input');
                }
                break;
                
            case 'Standard':
                // Most flexible category
                if (!is_array(\$input) && !(\$input instanceof AudioVector) && !is_numeric(\$input) && !is_string(\$input)) {
                    throw new AlgorithmException('Unsupported input type');
                }
                break;
                
            default:
                // Generic validation
                if (!is_array(\$input) && !(\$input instanceof AudioVector) && !is_numeric(\$input)) {
                    throw new AlgorithmException('Unsupported input type for algorithm');
                }
        }
    }

    private function prepareInput(\$input)
    {
        if (\$input instanceof AudioVector) {
            return \$input->toCArray(\$this->essentia->getFFI());
        }
        
        if (is_array(\$input)) {
            \$ffi = \$this->essentia->getFFI();
            \$size = count(\$input);
            \$cArray = \$ffi->new("float[\$size]");
            
            for (\$i = 0; \$i < \$size; \$i++) {
                \$cArray[\$i] = (float) \$input[\$i];
            }
            
            return \$cArray;
        }
        
        return \$input;
    }

    private function executeAlgorithm(\$input): array
    {
        \$ffi = \$this->essentia->getFFI();
        
        try {
            // Algorithm-specific execution logic
            return \$this->executeSpecificAlgorithm(\$ffi, \$input);
            
        } catch (\\FFI\\Exception \$e) {
            throw new AlgorithmException("FFI execution error: " . \$e->getMessage(), 0, \$e);
        }
    }

    private function executeSpecificAlgorithm(FFI \$ffi, \$input): array
    {
        // This method contains algorithm-specific execution logic
        \$outputs = [];
        
        switch (\$this->algorithmName) {
{$algorithmExecutionCases}
            
            default:
                // Generic execution for unknown algorithms
                \$outputs = \$this->executeGenericAlgorithm(\$ffi, \$input);
        }
        
        return \$outputs;
    }

    private function executeGenericAlgorithm(FFI \$ffi, \$input): array
    {
        // Generic algorithm execution - assumes single input/output
        try {
            // Prepare output buffers
            \$outputSize = \$this->estimateOutputSize(\$input);
            \$output = \$ffi->new("float[\$outputSize]");
            \$actualSize = \$ffi->new("int");
            
            // Execute algorithm
            \$result = \$ffi->essentia_algorithm_compute(\$this->algorithmHandle, \$input, \$output, \$actualSize);
            
            if (\$result != 0) {
                throw new AlgorithmException("Algorithm execution failed with code: \$result");
            }
            
            // Convert output to PHP array
            \$phpOutput = [];
            for (\$i = 0; \$i < \$actualSize->cdata; \$i++) {
                \$phpOutput[] = \$output[\$i];
            }
            
            return \$phpOutput;
            
        } catch (\\Exception \$e) {
            throw new AlgorithmException("Generic execution failed: " . \$e->getMessage(), 0, \$e);
        }
    }

    private function estimateOutputSize(\$input): int
    {
        // Estimate output size based on algorithm category and input
        switch (\$this->category) {
            case 'Spectral':
                // Spectral algorithms often output half the input size (FFT)
                return is_array(\$input) ? count(\$input) / 2 : 1024;
                
            case 'Temporal':
            case 'Tonal':
                // Temporal/tonal algorithms often output similar or smaller size
                return is_array(\$input) ? count(\$input) : 1024;
                
            case 'Stats':
                // Statistical algorithms often output small fixed sizes
                return 16;
                
            case 'Rhythm':
                // Rhythm algorithms vary widely
                return 256;
                
            default:
                // Conservative default
                return is_array(\$input) ? count(\$input) : 1024;
        }
    }

    private function processOutput(array \$result): array
    {
        // Post-process the output based on algorithm characteristics
        switch (\$this->category) {
            case 'Spectral':
                return \$this->processSpectralOutput(\$result);
                
            case 'Temporal':
                return \$this->processTemporalOutput(\$result);
                
            case 'Tonal':
                return \$this->processTonalOutput(\$result);
                
            case 'Rhythm':
                return \$this->processRhythmOutput(\$result);
                
            case 'Stats':
                return \$this->processStatsOutput(\$result);
                
            default:
                // Return as-is for unknown categories
                return \$result;
        }
    }

    private function processSpectralOutput(array \$result): array
    {
        // Process spectral algorithm outputs
        switch (\$this->algorithmName) {
            case 'SpectralCentroid':
            case 'SpectralRolloff':
                return ['value' => \$result[0] ?? 0.0];
                
            case 'MFCC':
                return ['coefficients' => \$result];
                
            case 'MelBands':
            case 'BarkBands':
                return ['bands' => \$result];
                
            case 'SpectralPeaks':
                // Usually returns frequencies and magnitudes
                \$half = count(\$result) / 2;
                return [
                    'frequencies' => array_slice(\$result, 0, \$half),
                    'magnitudes' => array_slice(\$result, \$half)
                ];
                
            default:
                return ['spectrum' => \$result];
        }
    }

    private function processTemporalOutput(array \$result): array
    {
        switch (\$this->algorithmName) {
            case 'Energy':
            case 'RMS':
            case 'ZeroCrossingRate':
                return ['value' => \$result[0] ?? 0.0];
                
            case 'Envelope':
                return ['envelope' => \$result];
                
            default:
                return ['values' => \$result];
        }
    }

    private function processTonalOutput(array \$result): array
    {
        switch (\$this->algorithmName) {
            case 'PitchYin':
            case 'PitchYinFFT':
                return [
                    'pitch' => \$result[0] ?? 0.0,
                    'confidence' => \$result[1] ?? 0.0
                ];
                
            case 'Key':
                return [
                    'key' => \$result[0] ?? 'C',
                    'scale' => \$result[1] ?? 'major',
                    'strength' => \$result[2] ?? 0.0
                ];
                
            case 'HPCP':
            case 'Chromagram':
                return ['chroma' => \$result];
                
            default:
                return ['tonal_features' => \$result];
        }
    }

    private function processRhythmOutput(array \$result): array
    {
        switch (\$this->algorithmName) {
            case 'RhythmExtractor':
                return [
                    'bpm' => \$result[0] ?? 0.0,
                    'beats' => array_slice(\$result, 1) ?? []
                ];
                
            case 'OnsetDetection':
                return ['onsets' => \$result];
                
            case 'TempoTap':
                return ['tempo' => \$result[0] ?? 0.0];
                
            default:
                return ['rhythm_features' => \$result];
        }
    }

    private function processStatsOutput(array \$result): array
    {
        switch (\$this->algorithmName) {
            case 'Mean':
            case 'Variance':
            case 'Centroid':
                return ['value' => \$result[0] ?? 0.0];
                
            case 'DistributionShape':
                return [
                    'spread' => \$result[0] ?? 0.0,
                    'skewness' => \$result[1] ?? 0.0,
                    'kurtosis' => \$result[2] ?? 0.0
                ];
                
            default:
                return ['statistics' => \$result];
        }
    }

    private function cleanupAlgorithm(): void
    {
        if (\$this->algorithmHandle) {
            try {
                \$ffi = \$this->essentia->getFFI();
                \$ffi->essentia_delete_algorithm(\$this->algorithmHandle);
            } catch (\\Exception \$e) {
                // Ignore cleanup errors
            }
            \$this->algorithmHandle = null;
        }
    }

    protected function isValidParameter(string \$parameter): bool
    {
        // Algorithm-specific parameter validation
        \$validParams = \$this->getValidParameters();
        return empty(\$validParams) || in_array(\$parameter, \$validParams);
    }

    private function getValidParameters(): array
    {
        // Return algorithm-specific valid parameters
        return {$validParameters};
    }
}
PHP;

        $filePath = $outputPath . "/Algorithms/{$category}/{$algorithm}.php";
        $this->writeFile($filePath, $classContent);
    }

    private function generateIntelligentFactoryClass(array $algorithms, string $outputPath): void
    {
        $algorithmImports = [];
        $algorithmCases = [];

        foreach ($algorithms as $algorithm) {
            $metadata = $this->algorithmMetadata[$algorithm] ?? [];
            $category = $metadata['category'] ?? 'Misc';

            $algorithmImports[] = "use App\\Modules\\Essentia\\Algorithms\\{$category}\\{$algorithm};";
            $algorithmCases[] = "            '{$algorithm}' => {$algorithm}::class,";
        }

        $imports = implode("\n", array_unique($algorithmImports));
        $cases = implode("\n", $algorithmCases);

        $factoryContent = <<<PHP
<?php

declare(strict_types=1);

namespace App\\Modules\\Essentia;

use App\\Modules\\Essentia\\Exceptions\\EssentiaException;
{$imports}


class AlgorithmFactory
{
    private const ALGORITHM_MAP = [
{$cases}
    ];

    public static function create(string \$algorithmName, array \$parameters = []): object
    {
        if (!isset(self::ALGORITHM_MAP[\$algorithmName])) {
            throw new EssentiaException("Unknown algorithm: {\$algorithmName}");
        }

        \$algorithmClass = self::ALGORITHM_MAP[\$algorithmName];
        
        return new \$algorithmClass(\$parameters);
    }

    public static function getAvailableAlgorithms(): array
    {
        return array_keys(self::ALGORITHM_MAP);
    }

    public static function getAlgorithmsByCategory(): array
    {
        \$byCategory = [];
        
        foreach (self::ALGORITHM_MAP as \$name => \$class) {
            \$instance = new \$class();
            \$category = \$instance->getCategory();
            \$byCategory[\$category][] = \$name;
        }
        
        return \$byCategory;
    }

    public static function algorithmExists(string \$algorithmName): bool
    {
        return isset(self::ALGORITHM_MAP[\$algorithmName]);
    }
}
PHP;

        $this->writeFile($outputPath . '/AlgorithmFactory.php', $factoryContent);
    }

    private function generateIntelligentAnalyzerClass(string $outputPath): void
    {
        $analyzerContent = <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Modules\Essentia;

use App\Modules\Essentia\Types\AudioVector;use App\Modules\Essentia\Utils\AudioUtils;

class AudioAnalyzer
{
    private AlgorithmFactory $factory;
    private float $lastEnergy = 0;

    public function __construct()
    {
        $this->factory = new AlgorithmFactory();
    }

    /**
     * Perform comprehensive audio analysis
     */
    public function analyze(AudioVector $audio): array
    {
        return [
            'basic' => $this->analyzeBasicFeatures($audio),
            'spectral' => $this->analyzeSpectralFeatures($audio),
            'temporal' => $this->analyzeTemporalFeatures($audio),
            'rhythm' => $this->analyzeRhythmFeatures($audio),
            'tonal' => $this->analyzeTonalFeatures($audio),
        ];
    }

    public function analyzeBasicFeatures(AudioVector $audio): array
    {
        $data = $audio->getData();
        
        return [
            'duration' => count($data) / $audio->getSampleRate(),
            'sample_rate' => $audio->getSampleRate(),
            'length' => $audio->getLength(),
            'rms' => AudioUtils::calculateRMS($data),
            'peaks' => count(AudioUtils::findPeaks($data)),
        ];
    }

    public function analyzeSpectralFeatures(AudioVector $audio): array
    {
        $results = [];
        
        try {
            // Spectral Centroid - measures the "brightness" of a sound
            $spectralCentroid = $this->factory->create('SpectralCentroid');
            $results['spectral_centroid'] = $spectralCentroid->compute($audio);
        } catch (\Exception $e) {
            $results['spectral_centroid'] = $this->calculateSpectralCentroid($audio);
        }
        
        try {
            // Spectral Rolloff - frequency below which 85% of energy is contained
            $spectralRolloff = $this->factory->create('SpectralRolloff');
            $results['spectral_rolloff'] = $spectralRolloff->compute($audio);
        } catch (\Exception $e) {
            $results['spectral_rolloff'] = $this->calculateSpectralRolloff($audio);
        }
        
        try {
            // MFCC - Mel Frequency Cepstral Coefficients for audio fingerprinting
            $mfcc = $this->factory->create('MFCC');
            $results['mfcc'] = $mfcc->compute($audio);
        } catch (\Exception $e) {
            $results['mfcc'] = $this->calculateMFCC($audio);
        }
        
        try {
            // Chroma - pitch class profiles for harmonic analysis
            $chroma = $this->factory->create('ChromaSTFT');
            $results['chroma'] = $chroma->compute($audio);
        } catch (\Exception $e) {
            $results['chroma'] = $this->calculateChroma($audio);
        }
        
        return $results;
    }

    public function analyzeTemporalFeatures(AudioVector $audio): array
    {
        $results = [];
        
        try {
            // Zero Crossing Rate - how often signal changes sign (indicates pitch)
            $zcr = $this->factory->create('ZeroCrossingRate');
            $results['zero_crossing_rate'] = $zcr->compute($audio);
        } catch (\Exception $e) {
            $results['zero_crossing_rate'] = $this->calculateZeroCrossingRate($audio);
        }
        
        try {
            // Energy - signal energy over time
            $energy = $this->factory->create('Energy');
            $results['energy'] = $energy->compute($audio);
        } catch (\Exception $e) {
            $results['energy'] = $this->calculateEnergy($audio);
        }
        
        try {
            // Loudness - perceptual loudness measurement
            $loudness = $this->factory->create('Loudness');
            $results['loudness'] = $loudness->compute($audio);
        } catch (\Exception $e) {
            $results['loudness'] = $this->calculateLoudness($audio);
        }
        
        return $results;
    }

    public function analyzeRhythmFeatures(AudioVector $audio): array
    {
        $results = [];
        
        try {
            // Tempo - beats per minute estimation
            $rhythmExtractor = $this->factory->create('RhythmExtractor');
            $rhythmResult = $rhythmExtractor->compute($audio);
            $results['tempo'] = $rhythmResult['bpm'] ?? null;
            $results['beats'] = $rhythmResult['beats'] ?? null;
        } catch (\Exception $e) {
            $results['tempo'] = $this->estimateTempo($audio);
            $results['beats'] = $this->detectBeats($audio);
        }
        
        try {
            // Onset Rate - how frequently new notes/events start
            $onsetRate = $this->factory->create('OnsetRate');
            $results['onset_rate'] = $onsetRate->compute($audio);
        } catch (\Exception $e) {
            $results['onset_rate'] = $this->calculateOnsetRate($audio);
        }
        
        return $results;
    }

    public function analyzeTonalFeatures(AudioVector $audio): array
    {
        $results = [];
        
        try {
            // Key Detection - musical key estimation
            $keyExtractor = $this->factory->create('Key');
            $keyResult = $keyExtractor->compute($audio);
            $results['key'] = $keyResult['key'] ?? null;
            $results['scale'] = $keyResult['scale'] ?? null;
        } catch (\Exception $e) {
            $results['key'] = $this->estimateKey($audio);
            $results['scale'] = 'unknown';
        }
        
        try {
            // Pitch Detection - fundamental frequency estimation
            $pitchYin = $this->factory->create('PitchYin');
            $results['pitch'] = $pitchYin->compute($audio);
        } catch (\Exception $e) {
            $results['pitch'] = $this->estimatePitch($audio);
        }
        
        try {
            // Harmonic Ratio - ratio of harmonic to total energy
            $harmonicRatio = $this->factory->create('HarmonicRatio');
            $results['harmonic_ratio'] = $harmonicRatio->compute($audio);
        } catch (\Exception $e) {
            $results['harmonic_ratio'] = $this->calculateHarmonicRatio($audio);
        }
        
        return $results;
    }

    public function extractFeature(string $algorithmName, AudioVector $audio, array $parameters = []): array
    {
        $algorithm = $this->factory->create($algorithmName, $parameters);
        return $algorithm->compute($audio);
    }

    public function extractFeatures(array $algorithmNames, AudioVector $audio): array
    {
        $results = [];
        
        foreach ($algorithmNames as $algorithmName) {
            try {
                $results[$algorithmName] = $this->extractFeature($algorithmName, $audio);
            } catch (\Exception $e) {
                $results[$algorithmName] = ['error' => $e->getMessage()];
            }
        }
        
        return $results;
    }

    // Fallback implementations when Essentia algorithms are not available

    private function calculateSpectralCentroid(AudioVector $audio): float
    {
        $data = $audio->getData();
        $spectrum = $this->computeSpectrum($data);
        
        $weightedSum = 0;
        $magnitudeSum = 0;
        
        for ($i = 0; $i < count($spectrum); $i++) {
            $frequency = $i * $audio->getSampleRate() / (2 * count($spectrum));
            $magnitude = abs($spectrum[$i]);
            
            $weightedSum += $frequency * $magnitude;
            $magnitudeSum += $magnitude;
        }
        
        return $magnitudeSum > 0 ? $weightedSum / $magnitudeSum : 0;
    }

    private function calculateSpectralRolloff(AudioVector $audio, float $threshold = 0.85): float
    {
        $data = $audio->getData();
        $spectrum = $this->computeSpectrum($data);
        
        $totalEnergy = array_sum(array_map(fn($x) => $x * $x, $spectrum));
        $targetEnergy = $totalEnergy * $threshold;
        
        $cumulativeEnergy = 0;
        for ($i = 0; $i < count($spectrum); $i++) {
            $cumulativeEnergy += $spectrum[$i] * $spectrum[$i];
            if ($cumulativeEnergy >= $targetEnergy) {
                return $i * $audio->getSampleRate() / (2 * count($spectrum));
            }
        }
        
        return $audio->getSampleRate() / 2; // Nyquist frequency
    }

    private function calculateZeroCrossingRate(AudioVector $audio): float
    {
        $data = $audio->getData();
        $crossings = 0;
        
        for ($i = 1; $i < count($data); $i++) {
            if (($data[$i] >= 0 && $data[$i - 1] < 0) || ($data[$i] < 0 && $data[$i - 1] >= 0)) {
                $crossings++;
            }
        }
        
        return $crossings / (count($data) * 2); // Normalize by length
    }

    private function calculateEnergy(AudioVector $audio): float
    {
        $data = $audio->getData();
        return array_sum(array_map(fn($x) => $x * $x, $data)) / count($data);
    }

    private function calculateLoudness(AudioVector $audio): float
    {
        // Simplified A-weighted loudness approximation
        $rms = AudioUtils::calculateRMS($audio->getData());
        return 20 * log10($rms + 1e-10); // Convert to dB with small epsilon
    }

    private function estimateTempo(AudioVector $audio): ?float
    {
        // Simplified tempo estimation using onset detection
        $onsets = $this->detectOnsets($audio);
        if (count($onsets) < 2) return null;
        
        $intervals = [];
        for ($i = 1; $i < count($onsets); $i++) {
            $intervals[] = $onsets[$i] - $onsets[$i - 1];
        }
        
        if (empty($intervals)) return null;
        
        $avgInterval = array_sum($intervals) / count($intervals);
        return 60.0 / $avgInterval; // Convert to BPM
    }

    private function detectBeats(AudioVector $audio): array
    {
        // Simplified beat detection based on energy peaks
        return $this->detectOnsets($audio);
    }

    private function detectOnsets(AudioVector $audio): array
    {
        $data = $audio->getData();
        $windowSize = 1024;
        $hopSize = 512;
        $onsets = [];
        
        for ($i = 0; $i < count($data) - $windowSize; $i += $hopSize) {
            $window = array_slice($data, $i, $windowSize);
            $energy = array_sum(array_map(fn($x) => $x * $x, $window));
            
            // Simple onset detection based on energy increase
            if ($i > 0 && $energy > $this->lastEnergy * 1.5) {
                $onsets[] = $i / $audio->getSampleRate();
            }
            $this->lastEnergy = $energy;
        }
        
        return $onsets;
    }

    private function calculateOnsetRate(AudioVector $audio): float
    {
        $onsets = $this->detectOnsets($audio);
        $duration = $audio->getLength() / $audio->getSampleRate();
        
        return count($onsets) / $duration; // Onsets per second
    }

    private function estimateKey(AudioVector $audio): ?string
    {
        // Simplified key estimation using chroma features
        $chroma = $this->calculateChroma($audio);
        if (empty($chroma)) return null;
        
        $keys = ['C', 'C#', 'D', 'D#', 'E', 'F', 'F#', 'G', 'G#', 'A', 'A#', 'B'];
        $maxIndex = array_search(max($chroma), $chroma);
        
        return $keys[$maxIndex % 12] ?? null;
    }

    private function estimatePitch(AudioVector $audio): ?float
    {
        // Simplified pitch estimation using autocorrelation
        $data = $audio->getData();
        $sampleRate = $audio->getSampleRate();
        
        // Take a window for analysis
        $windowSize = min(2048, count($data));
        $window = array_slice($data, 0, $windowSize);
        
        $maxCorrelation = 0;
        $bestPeriod = 0;
        
        // Check periods corresponding to reasonable pitch range (80Hz - 1000Hz)
        $minPeriod = $sampleRate / 1000;
        $maxPeriod = $sampleRate / 80;
        
        for ($period = $minPeriod; $period <= $maxPeriod; $period++) {
            $correlation = 0;
            $count = $windowSize - $period;
            
            for ($i = 0; $i < $count; $i++) {
                $correlation += $window[$i] * $window[$i + $period];
            }
            
            if ($correlation > $maxCorrelation) {
                $maxCorrelation = $correlation;
                $bestPeriod = $period;
            }
        }
        
        return $bestPeriod > 0 ? $sampleRate / $bestPeriod : null;
    }

    private function calculateHarmonicRatio(AudioVector $audio): float
    {
        $spectrum = $this->computeSpectrum($audio->getData());
        $pitch = $this->estimatePitch($audio);
        
        if (!$pitch) return 0.0;
        
        $harmonicEnergy = 0;
        $totalEnergy = array_sum(array_map(fn($x) => $x * $x, $spectrum));
        
        // Sum energy at harmonic frequencies
        for ($harmonic = 1; $harmonic <= 10; $harmonic++) {
            $frequency = $pitch * $harmonic;
            $bin = round($frequency * count($spectrum) * 2 / $audio->getSampleRate());
            
            if ($bin < count($spectrum)) {
                $harmonicEnergy += $spectrum[$bin] * $spectrum[$bin];
            }
        }
        
        return $totalEnergy > 0 ? $harmonicEnergy / $totalEnergy : 0.0;
    }

    private function calculateMFCC(AudioVector $audio): array
    {
        // Simplified MFCC calculation (normally requires complex mel-scale processing)
        $spectrum = $this->computeSpectrum($audio->getData());
        
        // Return first 13 coefficients (standard for MFCC)
        return array_slice($spectrum, 0, 13);
    }

    private function calculateChroma(AudioVector $audio): array
    {
        $spectrum = $this->computeSpectrum($audio->getData());
        $chroma = array_fill(0, 12, 0.0); // 12 pitch classes
        
        $sampleRate = $audio->getSampleRate();
        $A4 = 440.0;
        
        for ($i = 1; $i < count($spectrum); $i++) {
            $frequency = $i * $sampleRate / (2 * count($spectrum));
            if ($frequency > 0) {
                $semitones = 12 * log($frequency / $A4) / log(2);
                $pitchClass = ((int)round($semitones) % 12 + 12) % 12;
                $chroma[$pitchClass] += abs($spectrum[$i]);
            }
        }
        
        return $chroma;
    }

    private function computeSpectrum(array $data): array
    {
        $N = count($data);
        $spectrum = [];
        
        for ($k = 0; $k < $N / 2; $k++) {
            $real = 0;
            $imag = 0;
            
            for ($n = 0; $n < $N; $n++) {
                $angle = -2 * M_PI * $k * $n / $N;
                $real += $data[$n] * cos($angle);
                $imag += $data[$n] * sin($angle);
            }
            
            $spectrum[] = sqrt($real * $real + $imag * $imag);
        }
        
        return $spectrum;
    }
}
PHP;

        $this->writeFile($outputPath . '/AudioAnalyzer.php', $analyzerContent);
    }

    private function getPythonIntrospectionScript(): string
    {
        return <<<'PYTHON'
import json
import sys
import inspect

def categorize_algorithm(name_lower):
    """Categorize algorithm based on name patterns"""
    category_patterns = {
        'AudioProblems': ['click', 'gap', 'silence', 'noise', 'burst', 'hum', 'saturation', 'discontinuity', 'falsestereo', 'fade', 'startstop', 'truepeak'],
        'Complex': ['cartesian', 'polar', 'magnitude', 'fftc', 'ifftc'],
        'Extractor': ['musicextractor', 'freesoundextractor', 'levelextractor', 'barkextractor', 'lowlevelspectralextractor', 'lowlevelspectraleqloudextractor', 'tonalextractor', 'rhythmextractor', 'keyextractor', 'tuningfrequencyextractor'],
        'Filters': ['allpass', 'bandpass', 'bandreject', 'highpass', 'lowpass', 'bpf', 'iir', 'dcremoval', 'equalloudness', 'maxfilter', 'medianfilter', 'movingaverage', 'loudnessebur128filter'],
        'HighLevel': ['danceability', 'meter', 'coversong', 'similarity', 'gaiatransform', 'highlevelfeatures', 'highresolutionfeatures'],
        'Io': ['loader', 'writer', 'file', 'audio', 'mono', 'eqloud', 'easy', 'metadatareader', 'yamlinput', 'yamloutput', 'fileoutput', 'vectorinput'],
        'MachineLearning': ['tensorflow', 'musicnn', 'vggish', 'fsdsinet', 'svm', 'pca', 'gaia', 'tensor', 'pooltotensor', 'tensortopool', 'tensornormalize', 'tensortranspose', 'tensorflowinput', 'vectorrealtotensor', 'tensortovectorreal'],
        'Rhythm': ['tempo', 'beat', 'rhythm', 'onset', 'bpm', 'novelty', 'beattracker', 'tempotap', 'rhythmextractor', 'rhythmdescriptors', 'rhythmtransform', 'onsetdetection', 'onsetrate', 'beatsloudness', 'singlebeatloudness', 'temposcalebands', 'percivalbpm', 'danceability', 'audioonsetsmarker', 'chordsdetectionbeats', 'tensorflowinputtempocnn', 'harmonicbpm', 'loopbpm', 'bpmhistogram', 'bpmrubato', 'beatogram'],
        'Sfx': ['derivative', 'flatness', 'crest', 'decrease', 'logattacktime', 'strongdecay', 'strongpeak', 'tcto', 'oddtoeven', 'aftermaxto', 'maxto', 'minto', 'derivativesfx', 'flatnesssfx'],
        'Spectral': ['spectral', 'spectrum', 'centroid', 'rolloff', 'flux', 'mfcc', 'bark', 'mel', 'fft', 'ifft', 'powerspectrum', 'logspectrum', 'triangularbark', 'superflux', 'spectralpeaks', 'spectralwhitening', 'spectralcontrast', 'spectralcomplexity', 'melbanks', 'barkbands', 'triangularbands', 'hfc', 'multipitch', 'pitchcontours', 'erbbands', 'frequencybands', 'bfcc', 'gfcc', 'constantq', 'nsg', 'welch', 'spectrumcq', 'spectrumtocent'],
        'Standard': ['window', 'windowing', 'frame', 'framecutter', 'framebuffer', 'frametoreal', 'framegenerator', 'resample', 'resamplefft', 'overlapadd', 'clipper', 'scale', 'trimmer', 'slicer', 'stereotrimmer', 'stereodemuxer', 'stereomuxer', 'monomixer', 'panning', 'noiseadder', 'binary', 'unary', 'operator', 'multiplexer', 'duration', 'effectiveduration'],
        'Stats': ['mean', 'variance', 'median', 'histogram', 'distribution', 'central', 'raw', 'moments', 'geometricmean', 'powermean', 'minmax', 'mintototal', 'maxtototal', 'entropy', 'singlegaussian', 'poolaggregator', 'crosscorrelation', 'autocorrelation', 'crosssimilarity', 'chromacrosssimilarity'],
        'Synthesis': ['synthesis', 'synth', 'model', 'anal', 'sine', 'stochastic', 'spr', 'sps', 'hpr', 'hps', 'sinesubtraction', 'lpc', 'harmonicmodel', 'sinemodel', 'stochasticmodel'],
        'Temporal': ['rms', 'energy', 'zero', 'crossing', 'envelope', 'instantpower', 'dynamiccomplexity', 'intensity', 'loudness', 'vickers', 'replaygain', 'leq', 'larm', 'ebur128'],
        'Tonal': ['chroma', 'key', 'chord', 'pitch', 'harmonic', 'tonal', 'chromagram', 'chromaprinter', 'chordsdetection', 'chordsdescriptors', 'harmonicmask', 'harmonicpeaks', 'inharmonicity', 'nnlschroma', 'pitchyin', 'pitchsalience', 'pitchfilter', 'pitch2midi', 'audio2pitch', 'multipitklapuri', 'tuningfrequency', 'percivalenhanceharmonics', 'pitchyinprobabilistic', 'pitchyinprobabilities', 'hpcp', 'dissonance', 'tristimulus', 'vibrato', 'tonicindianartmusic'],
    }

    for category, patterns in category_patterns.items():
        for pattern in patterns:
            if pattern in name_lower:
                return category

    # Additional pattern checks
    if name_lower.endswith('descriptors'):
        return 'Stats'
    elif name_lower.endswith(('detector', 'detection')):
        return 'AudioProblems'
    elif 'spline' in name_lower or 'cubic' in name_lower:
        return 'Standard'
    elif 'viterbi' in name_lower or 'sbic' in name_lower:
        return 'MachineLearning'
    elif 'peak' in name_lower:
        return 'Spectral'

    return 'Standard'

try:
    import essentia
    import essentia.standard as std
    import essentia.streaming as streaming
    
    algorithms = {}
    
    # Get standard algorithms
    std_algorithms = [name for name in dir(std) if not name.startswith('_')]
    
    for algo_name in std_algorithms:
        try:
            algo_class = getattr(std, algo_name)
            if inspect.isclass(algo_class):
                try:
                    instance = algo_class()
                    
                    algo_info = {
                        'name': algo_name,
                        'mode': 'standard',
                        'category': 'Standard',
                        'inputs': {},
                        'outputs': {},
                        'parameters': {}
                    }
                    
                    if hasattr(instance, '__doc__') and instance.__doc__:
                        algo_info['description'] = instance.__doc__.strip()
                    
                    # Categorization
                    name_lower = algo_name.lower()
                    algo_info['category'] = categorize_algorithm(name_lower)
                    
                    if hasattr(instance, 'parameterNames'):
                        try:
                            param_names = instance.parameterNames()
                            for param in param_names:
                                algo_info['parameters'][param] = 'unknown'
                        except:
                            pass
                    
                    algorithms[algo_name] = algo_info
                    
                except Exception as e:
                    algorithms[algo_name] = {
                        'name': algo_name,
                        'mode': 'standard',
                        'category': 'Standard',
                        'error': str(e)
                    }
                    
        except Exception:
            continue
            
    # Check streaming algorithms
    streaming_algorithms = [name for name in dir(streaming) if not name.startswith('_')]
    
    for algo_name in streaming_algorithms:
        if algo_name not in algorithms:
            try:
                algo_class = getattr(streaming, algo_name)
                if inspect.isclass(algo_class):
                    algorithms[algo_name] = {
                        'name': algo_name,
                        'mode': 'streaming',
                        'category': 'Streaming'
                    }
            except:
                continue
    
    print(json.dumps(algorithms, indent=2))
    
except ImportError as e:
    print(json.dumps({"error": f"Essentia not available: {str(e)}"}), file=sys.stderr)
    sys.exit(1)
except Exception as e:
    print(json.dumps({"error": f"Unexpected error: {str(e)}"}), file=sys.stderr)
    sys.exit(1)
PYTHON;
    }

    private function writeFile(string $path, string $content): void
    {
        $directory = dirname($path);
        if (!File::exists($directory)) {
            File::makeDirectory($directory, 0755, true);
        }

        if (!$this->option('force') && File::exists($path)) {
            $this->warn("File exists, skipping: $path");
            return;
        }

        File::put($path, $content);
        $this->info("Created: $path");
    }
}