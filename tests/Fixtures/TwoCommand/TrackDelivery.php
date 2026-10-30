<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\TwoCommand;

use App\Tests\Fixtures\WebRuntime\HttpExchange;
use RuntimeException;
use Symfony\Component\Process\Process;

final class TrackDelivery
{
    /**
     * @param array<string,array{initUrl:string,segmentUrl:string}> $resources
     * @return array{availableAt:float,tracks:array<string,array<string,mixed>>}
     */
    public static function inspect(array $resources, string $pathPrefix): array
    {
        $captured = [];
        $exchanges = [];
        foreach ($resources as $kind => $resource) {
            $exchanges[$kind . '-init'] = new HttpExchange($resource['initUrl'], []);
            $exchanges[$kind . '-segment'] = new HttpExchange($resource['segmentUrl'], []);
        }
        HttpExchange::completeAll($exchanges);
        foreach ($resources as $kind => $resource) {
            $captured[$kind] = self::captureTrack($kind, $resource['initUrl'], $resource['segmentUrl'], $exchanges[$kind . '-init'], $exchanges[$kind . '-segment']);
        }
        $availableAt = hrtime(true) / 1e9;
        $tracks = [];
        foreach ($captured as $kind => $resource) {
            $tracks[$kind] = self::verifyTrack($kind, $resource, $pathPrefix . '-' . $kind . '.mp4');
        }
        return ['availableAt' => $availableAt, 'tracks' => $tracks];
    }

    /** @return array{initUrl:string,segmentUrl:string,initBody:string,segmentBody:string} */
    private static function captureTrack(string $kind, string $initUrl, string $segmentUrl, HttpExchange $init, HttpExchange $segment): array
    {
        self::ensure($init->status() === 200 && $segment->status() === 200, sprintf(
            '%s init or fragment is unavailable (init HTTP %d, fragment HTTP %d).',
            $kind,
            $init->status(),
            $segment->status(),
        ));
        self::ensure(str_contains($initUrl, '/tracks/' . $kind . '/init.mp4') && str_contains($segmentUrl, '/tracks/' . $kind . '/segment.m4s'), 'Track resource identity is absent.');
        return ['initUrl' => $initUrl, 'segmentUrl' => $segmentUrl, 'initBody' => $init->body, 'segmentBody' => $segment->body];
    }

    /**
     * @param array{initUrl:string,segmentUrl:string,initBody:string,segmentBody:string} $resource
     * @return array<string,mixed>
     */
    private static function verifyTrack(string $kind, array $resource, string $path): array
    {
        $initUrl = $resource['initUrl'];
        $segmentUrl = $resource['segmentUrl'];
        file_put_contents($path, $resource['initBody'] . $resource['segmentBody']);
        $probe = new Process(['ffprobe', '-v', 'error', '-show_streams', '-of', 'json', $path]);
        $probe->setTimeout(10);
        $probe->mustRun();
        $streams = json_decode($probe->getOutput(), true, flags: JSON_THROW_ON_ERROR)['streams'];
        self::ensure(count($streams) === 1 && $streams[0]['codec_type'] === $kind, 'Fragment must contain exactly its declared track.');
        $tampering = [
            'track' => str_replace('/tracks/' . $kind . '/', '/tracks/' . ($kind === 'video' ? 'audio' : 'video') . '/', $segmentUrl),
            'index' => $segmentUrl . '&index=999999',
            'generation' => $segmentUrl . '&generation=999999',
        ];
        $statuses = [];
        foreach ($tampering as $field => $altered) {
            $response = (new HttpExchange($altered, []))->complete(5);
            self::ensure(in_array($response->status(), [400, 403, 404], true), 'Altered ' . $field . ' signature was accepted.');
            $statuses[$field] = $response->status();
        }
        return ['initUrl' => $initUrl, 'segmentUrl' => $segmentUrl, 'bytes' => strlen($resource['initBody']) + strlen($resource['segmentBody']),
            'codec' => $streams[0]['codec_name'], 'timeBase' => $streams[0]['time_base'], 'tamperedStatuses' => $statuses];
    }

    private static function ensure(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }
}
