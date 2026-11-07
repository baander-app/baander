<?php

declare(strict_types=1);

namespace Baander\Tooling\Fixtures\Qualification;

use Baander\Tooling\Deployment\ImageProfile;
use Baander\Tooling\Configuration\ApplicationLayout;

final class NativeAudit
{
    /** @param list<string> $arguments */
    public static function classify(string $executable, array $arguments): string
    {
        if ($executable === 'ffprobe') {
            return 'probe';
        }
        if ($executable !== 'ffmpeg') {
            return 'unknown';
        }
        if ($arguments === ['-encoders'] || $arguments === ['-hwaccels']) {
            return 'probe';
        }
        $codecs = [];
        $format = $frames = $audioFilter = null;
        $videoDisabled = $audioDisabled = $videoFilter = false;
        for ($index = 0; $index < count($arguments); ++$index) {
            $argument = $arguments[$index];
            if (preg_match('/^-(?:c|codec)(?::(.+))?$/D', $argument, $match) === 1
                || in_array($argument, ['-vcodec', '-acodec', '-scodec'], true)) {
                $specifier = $match[1] ?? null;
                $stream = $specifier === null ? match ($argument) {
                    '-vcodec' => 'v', '-acodec' => 'a', '-scodec' => 's', default => 'all',
                } : (preg_match('/^[vasVAS](?::.*)?$/D', $specifier) === 1 ? strtolower($specifier[0]) : 'unknown');
                $value = $arguments[++$index] ?? '';
                $codecs[$stream][] = $value;
            } elseif ($argument === '-f') {
                $format = $arguments[++$index] ?? null;
            } elseif (in_array($argument, ['-frames:v', '-vframes'], true)) {
                $frames = $arguments[++$index] ?? null;
            } elseif (in_array($argument, ['-af', '-filter:a'], true)) {
                $audioFilter = $arguments[++$index] ?? null;
            } elseif (in_array($argument, ['-vf', '-filter:v', '-filter_complex'], true)) {
                $videoFilter = true;
            } elseif ($argument === '-vn') {
                $videoDisabled = true;
            } elseif ($argument === '-an') {
                $audioDisabled = true;
            }
        }
        $video = $codecs['v'] ?? [];
        $audio = $codecs['a'] ?? [];
        $all = $codecs['all'] ?? [];
        if (isset($codecs['unknown'])) {
            return 'unknown';
        }
        if ($audioDisabled && $frames === '1' && in_array($format, ['image2', 'image2pipe'], true)
            && count($video) === 1 && in_array($video[0], ['mjpeg', 'png', 'bmp', 'webp'], true)
            && $audio === [] && $all === []) {
            return 'image';
        }
        foreach ([...$video, ...$audio, ...$all] as $codec) {
            if ($codec !== 'copy') {
                return 'encode';
            }
        }
        if ($format === 'null' && is_string($audioFilter) && !$videoFilter
            && preg_match('/^(?:ebur128|loudnorm|volumedetect)(?:=[^,;]*)?$/D', $audioFilter) === 1) {
            return 'analysis';
        }
        if ($videoFilter || $audioFilter !== null) {
            return 'unknown';
        }
        if ($videoDisabled && $audioDisabled && (($codecs['s'] ?? []) !== [] || $format === 'webvtt')) {
            return 'subtitle';
        }
        return ($videoDisabled || $video !== [] || $all !== []) && ($audioDisabled || $audio !== [] || $all !== [])
            ? 'copy' : 'unknown';
    }

    /** A remux deployment forbids every encoder launch; a transcode deployment records them.
     * @return array<string,mixed>
     */
    public static function read(string $path, bool $verifiedBefore, bool $verifiedAfter, string $capability): array
    {
        if (!in_array($capability, ImageProfile::CAPABILITIES, true)) {
            throw new \InvalidArgumentException('Unknown native audit deployment capability.');
        }
        $result = ['complete' => false, 'capability' => $capability, 'launches' => 0, 'forbiddenLaunches' => 0, 'unclassifiedLaunches' => 0, 'byOperation' => [], 'errors' => []];
        if (!$verifiedBefore || !$verifiedAfter) {
            $result['errors'][] = 'Native executable wrappers were not verified before and after the run.';
        }
        if (!is_file($path) || filesize($path) > 128 * 1024 * 1024) {
            $result['errors'][] = 'Missing or oversized native launch journal.';
            return $result;
        }
        $input = fopen($path, 'rb');
        if ($input === false) {
            $result['errors'][] = 'Cannot read native launch journal.';
            return $result;
        }
        try {
            while (($line = fgets($input, 65537)) !== false) {
                try {
                    $record = json_decode($line, true, 16, JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    $result['errors'][] = 'Malformed native launch journal.';
                    break;
                }
                if (!str_ends_with($line, "\n") || !is_array($record) || ($record['event'] ?? null) !== 'start'
                    || !is_numeric($record['at'] ?? null) || !is_int($record['pid'] ?? null)
                    || !is_string($record['executable'] ?? null) || !is_array($record['arguments'] ?? null)
                    || !array_is_list($record['arguments']) || array_any($record['arguments'], static fn(mixed $argument): bool => !is_string($argument))
                    || ++$result['launches'] > 100000) {
                    $result['errors'][] = 'Invalid or excessive native launch records.';
                    break;
                }
                $operation = self::classify($record['executable'], $record['arguments']);
                $result['byOperation'][$operation] = ($result['byOperation'][$operation] ?? 0) + 1;
                $result['forbiddenLaunches'] += (int) ($operation === 'encode' && $capability === 'remux');
                $result['unclassifiedLaunches'] += (int) ($operation === 'unknown');
            }
        } finally {
            fclose($input);
        }
        $result['journalSha256'] = hash_file('sha256', $path);
        $result['complete'] = $result['errors'] === [] && $result['unclassifiedLaunches'] === 0;
        return $result;
    }

    public static function wrapper(string $executable, ApplicationLayout $layout): string
    {
        if (!in_array($executable, ['ffmpeg', 'ffprobe'], true)) {
            throw new \InvalidArgumentException('Only the admitted native executables can be audited.');
        }
        $program = <<<'PHP'
#!/usr/local/bin/php
<?php
declare(strict_types=1);
$journal = JOURNAL;
$directory = dirname($journal);
if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
    fwrite(STDERR, "Cannot create native audit directory.\n");
    exit(126);
}
$record = json_encode(['event' => 'start', 'at' => microtime(true), 'pid' => getmypid(), 'executable' => EXECUTABLE, 'arguments' => array_slice($argv, 1)], JSON_THROW_ON_ERROR) . "\n";
if (strlen($record) > 65536 || (is_file($journal) && filesize($journal) > 128 * 1024 * 1024)
    || file_put_contents($journal, $record, FILE_APPEND | LOCK_EX) !== strlen($record)) {
    fwrite(STDERR, "Cannot record native launch.\n");
    exit(126);
}
pcntl_exec(REAL_EXECUTABLE, array_slice($argv, 1));
fwrite(STDERR, "Cannot execute audited native binary.\n");
exit(127);
PHP;
        return strtr($program, [
            'REAL_EXECUTABLE' => var_export('/usr/bin/' . $executable . '.qualification-real', true),
            'EXECUTABLE' => var_export($executable, true),
            'JOURNAL' => var_export($layout->path('storage/qualification-audit/native.jsonl'), true),
        ]) . "\n";
    }
}
