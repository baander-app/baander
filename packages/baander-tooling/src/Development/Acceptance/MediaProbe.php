<?php

declare(strict_types=1);

namespace Baander\Tooling\Development\Acceptance;

use Baander\Tooling\Development\StateStore;
use Baander\Tooling\Filesystem\AtomicFile;
use Baander\Tooling\Filesystem\PrivateDirectory;

final class MediaProbe
{
    /** @return array<string,mixed> */
    public static function decodedAudio(string $path): array
    {
        $metadata = json_decode(
            Support::run(
                ['ffprobe', '-v', 'error', '-show_streams', '-show_format', '-of', 'json', $path],
                timeout: 60,
            )->stdout,
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $audio = $metadata['streams']
            |> (static fn(array $streams): array => array_filter(
                $streams,
                static fn(array $stream): bool => $stream['codec_type'] === 'audio',
            ))
            |> array_values(...);
        Support::check(count($audio) === 1, 'Playback response must contain exactly one audio stream.');
        $pcm = Support::run([
            'ffmpeg',
            '-v',
            'error',
            '-xerror',
            '-i',
            $path,
            '-map',
            '0:a:0',
            '-ac',
            '1',
            '-ar',
            '8000',
            '-f',
            's16le',
            '-',
        ], timeout: 60)->stdout;
        $duration = strlen($pcm) / 16000;
        Support::check(
            abs($duration - 8) <= 0.05 && trim($pcm, "\0") !== '',
            'Audio did not decode the complete eight second tone: ' . $duration . 's.',
        );
        return [
            'codec' => $audio[0]['codec_name'],
            'decodedSeconds' => $duration,
            'decodedSamples' => intdiv(strlen($pcm), 2),
            'nonSilent' => true,
            'decoderReachedEof' => true,
        ];
    }

    /** @param array<string,string|bool> $args
     * @return array<string,mixed>
     */
    public static function qualify(array $args): array
    {
        $workspace = Support::path((string) $args['workspace']);
        Support::check(
            is_file($workspace . '/disposable-qualification'),
            'Media qualification requires a marked disposable workspace.',
        );
        $media = $workspace . '/media';
        PrivateDirectory::assertNoSymlinks($media);
        if (!is_dir($media)) {
            mkdir($media, 0700, true);
        }
        $fixture = $media . '/workflow-qualification-tone.flac';
        PrivateDirectory::assertNoSymlinks($fixture);
        if (!file_exists($fixture)) {
            Support::run([
                'ffmpeg',
                '-v',
                'error',
                '-nostdin',
                '-n',
                '-f',
                'lavfi',
                '-i',
                'sine=frequency=440:sample_rate=48000:duration=8',
                '-metadata',
                'title=Workflow qualification tone',
                '-metadata',
                'artist=Baander qualification',
                '-metadata',
                'album=Development workflow',
                '-c:a',
                'flac',
                $fixture,
            ], timeout: 60);
        }
        $sourceHash = hash_file('sha256', $fixture);
        $store = new StateStore((string) $args['state-root']);
        $instance = $store->load((string) $args['instance']);
        Support::check($instance !== null, 'The acceptance instance does not exist.');
        Support::check(
            ($instance['repository'] ?? null) === $workspace . '/origin.git',
            'The instance must belong to this disposable qualification remote.',
        );
        $mount = null;
        foreach ($instance['media'] ?? [] as $item) {
            if (Support::path($item['source']) === Support::path($media)) {
                $mount = $item;
                break;
            }
        }
        Support::check(
            $mount !== null,
            'The owned fixture directory must be mounted in the acceptance instance.',
        );
        $compose = AtomicFile::readJson($instance['compose_file']);
        $configured = null;
        foreach ($compose['services']['app']['volumes'] as $item) {
            if (is_array($item) && ($item['target'] ?? null) === $mount['target']) {
                $configured = $item;
                break;
            }
        }
        Support::check(
            $configured !== null && ($configured['read_only'] ?? null) === true,
            'The application fixture mount must be read-only.',
        );
        $client = new MediaClient(
            'https://' . $instance['hostname'],
            (string) ($args['ca-file'] ?: $store->root . '/host-setup/ca/rootCA.pem'),
        );
        [, $login] = $client->request(
            '/api/auth/login',
            'POST',
            ['email' => 'admin@baander.test', 'password' => 'admin', 'totpCode' => ''],
        );
        $client->token = $login['data']['accessToken'];
        [$settingStatus, $settings] = $client->request(
            '/api/admin/settings',
            'PATCH',
            ['settings' => ['transcode.enabled' => true]],
        );
        $setting = null;
        foreach ($settings['data'] as $item) {
            if ($item['key'] === 'transcode.enabled') {
                $setting = $item;
                break;
            }
        }
        Support::check(
            $settingStatus === 200 && $setting !== null && $setting['value'] === true,
            'The disposable qualification instance did not enable audio transcoding.',
        );
        [, $libraries] = $client->request('/api/libraries');
        $library = null;
        foreach ($libraries['data'] as $item) {
            if ($item['path'] === $mount['target'] && $item['type'] === 'music') {
                $library = $item;
                break;
            }
        }
        if ($library === null) {
            [$status, $created] = $client->request('/api/libraries', 'POST', [
                'name' => 'Workflow qualification music',
                'path' => $mount['target'],
                'type' => 'music',
                'filesystemType' => 'local',
            ]);
            Support::check($status === 201, 'The music library was not created.');
            $library = $created['data'];
        }
        $identifier = $library['publicId'];
        if ($args['existing-scan']) {
            $queuedStatus = null;
            $queued = ['data' => $library];
            Support::check(
                $library['scanStatus'] === 'completed',
                'Catalog resume requires an already completed scan.',
            );
        } else {
            [$queuedStatus, $queued] = $client->request(
                '/api/libraries/' . $identifier . '/scan',
                'POST',
                [],
            );
            Support::check($queuedStatus === 202, 'The HTTP scan did not return the queued response.');
        }
        $deadline = Support::now() + (int) $args['timeout'];
        $current = null;
        do {
            [, $current] = $client->request('/api/libraries/' . $identifier);
            Support::check($current['data']['scanStatus'] !== 'failed', 'The queued library scan failed.');
            if ($current['data']['scanStatus'] === 'completed') {
                break;
            }
            sleep(1);
        } while (Support::now() < $deadline);
        Support::check(
            $current['data']['scanStatus'] === 'completed',
            'The queued scan did not complete before the deadline.',
        );
        $song = null;
        $stats = [];
        do {
            [, $stats] = $client->request('/api/libraries/' . $identifier . '/stats');
            [, $songs] = $client->request('/api/songs/?limit=100&q=' . rawurlencode('Workflow qualification tone'));
            foreach ($songs['data'] as $item) {
                if (
                    str_ends_with((string) ($item['path'] ?? ''), basename($fixture))
                    && ($item['available'] ?? false) === true
                ) {
                    $song = $item;
                    break;
                }
            }
            if ($song !== null && $stats['data']['songs'] >= 1) {
                break;
            }
            sleep(1);
        } while (Support::now() < $deadline);
        Support::check(
            $song !== null && $stats['data']['songs'] >= 1,
            'The completed scan did not catalog the fixture before the deadline.',
        );
        $evidence = $workspace . '/playback-evidence';
        PrivateDirectory::assertNoSymlinks($evidence);
        if (!is_dir($evidence)) {
            mkdir($evidence, 0700);
        }
        $responses = [];
        foreach (['original' => '', 'transcoded' => '&format=opus&bitrate=96000'] as $name => $suffix) {
            $destination = $evidence . '/' . $name . ($name === 'original' ? '.flac' : '.opus');
            PrivateDirectory::assertNoSymlinks($destination);
            $result = $client->request(
                '/api/stream/track?id=' . $song['publicId'] . $suffix,
                destination: $destination,
            );
            $result = array_replace($result, self::decodedAudio($destination));
            Support::check(
                $result['status'] === 200
                    && $result['bytes'] > 0
                    && str_starts_with((string) $result['contentType'], 'audio/'),
                'The audio endpoint did not serve a complete audio response.',
            );
            $responses[$name] = $result;
        }
        Support::check(
            $responses['original']['sha256'] === $sourceHash,
            'Original playback bytes differ from the read-only source.',
        );
        Support::check(
            $responses['transcoded']['codec'] === 'opus',
            'The requested Opus conversion was not served.',
        );
        Support::check(
            hash_file('sha256', $fixture) === $sourceHash,
            'The source fixture changed during qualification.',
        );
        return [
            'passed' => true,
            'origin' => $client->origin,
            'tlsVerified' => true,
            'authentication' => 'seeded-development-account-dpop',
            'libraryId' => $identifier,
            'transcodeSettingStatus' => $settingStatus,
            'transcodeEnabled' => $setting['value'],
            'scanQueuedStatus' => $queuedStatus,
            'resumedExistingScan' => $args['existing-scan'],
            'scanQueuedState' => $queued['data']['scanStatus'],
            'sourceMountReadOnly' => true,
            'scanStatus' => $current['data']['scanStatus'],
            'catalogedSongs' => $stats['data']['songs'],
            'songId' => $song['publicId'],
            'sourceUnchanged' => true,
            'responses' => $responses,
        ];
    }
}
