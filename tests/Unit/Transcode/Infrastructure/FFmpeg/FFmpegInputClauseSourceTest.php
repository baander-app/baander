<?php

declare(strict_types=1);

namespace App\Tests\Unit\Transcode\Infrastructure\FFmpeg;

use App\Transcode\Infrastructure\FFmpeg\FFmpegInputKind;
use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/** Every FFmpeg and ffprobe input is opened by the one input-clause builder. */
final class FFmpegInputClauseSourceTest extends TestCase
{
    private const string BUILDER = 'src/Transcode/Infrastructure/FFmpeg/FFmpegArguments.php';

    public function testNoApplicationSourceWritesAnInputOptionOutsideTheBuilder(): void
    {
        $root = dirname(__DIR__, 5);
        $literals = [];
        foreach ($this->sources($root) as $relative => $path) {
            if ($relative === self::BUILDER) {
                continue;
            }
            foreach (self::inputLiterals((string) file_get_contents($path)) as $line) {
                $literals[] = $relative . ':' . $line;
            }
        }

        self::assertSame([], $literals, "Open media inputs through FFmpegArguments::inputClause():\n" . implode("\n", $literals));
    }

    public function testTheFfmpegCapabilityGateListsEveryWhitelistedDemuxer(): void
    {
        $expected = (string) file_get_contents(dirname(__DIR__, 5) . '/docker/ffmpeg/expected-capabilities.txt');
        preg_match_all('/^demuxer (\S+)$/m', $expected, $matches);
        $whitelisted = FFmpegInputKind::cases()
            |> (static fn(array $kinds): array => array_map(static fn(FFmpegInputKind $kind): ?string => $kind->formatWhitelist(), $kinds))
            |> array_filter(...)
            |> (static fn(array $lists): string => implode(',', $lists))
            |> (static fn(string $list): array => explode(',', $list))
            |> array_unique(...);

        self::assertSame([], array_diff($whitelisted, $matches[1]) |> array_values(...));
    }

    public function testTheScanFindsAnInputOptionInEveryStringForm(): void
    {
        $source = <<<'PHP'
            <?php
            $a = ['ffmpeg', '-i', $path];
            $b = "ffmpeg -i {$path}";
            $c = sprintf('%s -y -i source.mkv', $ffmpeg);
            $d = ['-filter_complex', '-init_hw_device', 'hi-i', '-isync'];
            PHP;

        self::assertSame([2, 3, 4], self::inputLiterals($source));
    }

    /** @return list<int> the lines of string literals that hold a bare `-i` option */
    private static function inputLiterals(string $source): array
    {
        $lines = [];
        foreach (token_get_all($source) as $token) {
            if (
                is_array($token)
                && in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)
                && preg_match('/(?:^|[\s\'"])-i(?:[\s\'"]|$)/', $token[1]) === 1
            ) {
                $lines[] = $token[2];
            }
        }
        return array_unique($lines) |> array_values(...);
    }

    /** @return iterable<string, string> */
    private function sources(string $root): iterable
    {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/src', FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            assert($file instanceof SplFileInfo);
            if ($file->getExtension() === 'php') {
                yield substr($file->getPathname(), strlen($root) + 1) => $file->getPathname();
            }
        }
        foreach (glob($root . '/bin/*.php') ?: [] as $path) {
            yield substr($path, strlen($root) + 1) => $path;
        }
    }
}
