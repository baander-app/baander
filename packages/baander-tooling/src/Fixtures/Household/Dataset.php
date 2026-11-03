<?php

declare(strict_types=1);

namespace Baander\Tooling\Fixtures\Household;

use Baander\Tooling\Docker\Client;
use Baander\Tooling\Fixtures\Runtime;
use Baander\Tooling\Resources\DisposableContainer;
use InvalidArgumentException;
use RuntimeException;

final class Dataset
{
    /** @return array<string,mixed> */
    public static function plan(string $run, int $playbackSeconds = 1860): array
    {
        if (preg_match('/\A[a-z0-9][a-z0-9-]{0,47}\z/D', $run) !== 1 || $playbackSeconds < 1860) {
            throw new InvalidArgumentException('Use an owned run name and at least 1860 playback seconds.');
        }
        $files = [];
        $albums = [];
        $artists = [];
        for ($index = 1; $index <= 2000; ++$index) {
            $album = (int) ceil($index / 20);
            $albumTitle = sprintf('Household %s Album %03d', $run, $album);
            $artistTitle = sprintf('Household %s Artist %03d', $run, $album);
            $albums[$albumTitle] = self::identifier($run . ':album:' . $album);
            $artists[$artistTitle] = self::identifier($run . ':artist:' . $album);
            $title = $index === 1 ? '000 Household ' . $run . ' Continuous' : sprintf('Household %s Song %04d', $run, $index);
            $audioCopy = $index === 2;
            $files[] = [
                'kind' => 'song', 'title' => $title, 'album' => $albumTitle, 'artist' => $artistTitle,
                'track' => ($index - 1) % 20 + 1,
                'path' => sprintf('%s/music/album-%03d/%s.%s', $run, $album, $title, $audioCopy ? 'aac' : 'flac'),
                'template' => $audioCopy ? 'audio-copy' : ($index === 1 ? 'audio-long' : 'audio'),
                'duration' => $index === 1 ? $playbackSeconds : 30,
                'videoCodec' => null, 'audioCodec' => $audioCopy ? 'aac' : 'flac',
                'lyricsPath' => sprintf('%s/music/album-%03d/%s.lrc', $run, $album, $title),
                'metadata' => ['id' => self::identifier($run . ':song:' . $index)],
            ];
        }
        for ($index = 1; $index <= 300; ++$index) {
            $episode = $index > 50;
            $number = $episode ? $index - 50 : $index;
            $title = sprintf('Household %s %s %03d', $run, $episode ? 'Episode' : 'Movie', $number);
            $template = match ($index) {
                1, 2 => 'video-original-long',
                3, 4 => 'video-copy-long',
                5 => 'video-no-audio',
                6 => 'video-irregular',
                7 => 'video-offset',
                8 => 'video-negative-audio',
                default => 'video',
            };
            $extension = in_array($template, ['video-copy-long', 'video-no-audio', 'video-irregular', 'video-offset', 'video-negative-audio'], true) ? 'mkv' : 'mp4';
            $files[] = [
                'kind' => $episode ? 'episode' : 'movie', 'title' => $title,
                'path' => sprintf('%s/%s/%s/source.%s', $run, $episode ? 'episodes' : 'movies', $title, $extension),
                'template' => $template, 'duration' => $index <= 4 ? $playbackSeconds : 60,
                'videoCodec' => 'h264', 'audioCodec' => $index === 5 ? null : ($index === 8 ? 'ac3' : 'aac'),
                'metadata' => ['id' => self::identifier($run . ':video:' . $index), 'tmdbId' => 700000000 + (hexdec(substr(hash('sha256', $run), 0, 5)) % 1000000) * 1000 + $index],
            ];
        }
        return [
            'version' => 1, 'run' => $run, 'preparedOutsideMeasurement' => true,
            'libraries' => [
                'music' => ['type' => 'music', 'path' => $run . '/music', 'expectedRecords' => 2000],
                'movies' => ['type' => 'movie', 'path' => $run . '/movies', 'expectedRecords' => 50],
                'episodes' => ['type' => 'movie', 'path' => $run . '/episodes', 'expectedRecords' => 250],
            ],
            'files' => $files, 'albums' => $albums, 'artists' => $artists,
            'playback' => [
                'originalVideos' => [$files[2000]['path'], $files[2001]['path']],
                'copyVideos' => [$files[2002]['path'], $files[2003]['path']],
                'music' => $files[0]['path'], 'audioOnlyCopy' => $files[1]['path'],
                'noAudio' => $files[2004]['path'], 'irregularKeyframes' => $files[2005]['path'],
                'nonzeroTimestamps' => $files[2006]['path'], 'unsupportedAudio' => $files[2007]['path'],
                'burnIn' => ['path' => $files[2000]['path'], 'subtitleBurnIn' => true],
            ],
            'limitations' => [
                'Episode-shaped files use individual movie-library directories; this does not qualify TV-library support.',
                'Repetitive synthetic pictures and tones qualify this recorded workload, not arbitrary media complexity.',
                'Five continuous sources are long; other songs are 30 seconds and other videos are 60 seconds.',
                'Unique embedded titles change source bytes; all measured hashes must remain distinct.',
                'Audio-only AAC copy requires destination containers excluding ADTS and including MP4.',
            ],
        ];
    }

    private static function identifier(string $value): string
    {
        $hex = hash('sha256', $value);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-5' . substr($hex, 13, 3) . '-a' . substr($hex, 17, 3) . '-' . substr($hex, 20, 12);
    }

    /** @param array<string,mixed> $plan */
    public static function containerProgram(array $plan): string
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/resources/household/generate.php');
        if ($source === false) {
            throw new RuntimeException('Household generation resource is missing.');
        }
        return str_replace("'HOUSEHOLD_PLAN_BASE64'", var_export(base64_encode(json_encode($plan, JSON_THROW_ON_ERROR)), true), $source);
    }

    /** @return array<string,mixed> */
    public static function prepare(Client $docker, Runtime $runtime, string $immutableImage, string $sourceVolume, string $artifacts, string $run, int $playbackSeconds = 1860): array
    {
        if (preg_match('/(?:\Asha256:|@sha256:)[a-f0-9]{64}\z/D', $immutableImage) !== 1
            || preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_.-]*\z/D', $sourceVolume) !== 1) {
            throw new InvalidArgumentException('Dataset preparation requires an immutable image and named source volume.');
        }
        $plan = self::plan($run, $playbackSeconds);
        DisposableContainer::run(
            $docker,
            $artifacts . '/dataset-container.json',
            [
                '--network', 'none', '--user', '0',
                '--mount', 'type=volume,source=' . $sourceVolume . ',target=/media/source,volume-nocopy',
                '--entrypoint', '/usr/local/bin/php', $immutableImage,
            ],
            $runtime->environment,
            $runtime->configuration->budgets->seconds('cleanup'),
            input: self::containerProgram($plan),
            timeout: $runtime->configuration->budgets->seconds('fixture'),
            logPath: $artifacts . '/dataset.json',
            stderrLogPath: $artifacts . '/dataset.stderr',
        );
        $contents = file_get_contents($artifacts . '/dataset.json', length: 16 * 1024 * 1024 + 1);
        if ($contents === false || strlen($contents) > 16 * 1024 * 1024) {
            throw new RuntimeException('Dataset preparation log is missing or exceeds 16 MiB.');
        }
        $manifest = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($manifest) || ($manifest['run'] ?? null) !== $run || count($manifest['files'] ?? []) !== 2300) {
            throw new RuntimeException('Dataset preparation returned an incomplete manifest.');
        }
        return $manifest;
    }
}
