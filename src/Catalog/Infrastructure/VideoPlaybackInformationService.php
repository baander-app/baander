<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure;

use App\Catalog\Application\Port\VideoPlaybackInformationPort;
use App\Catalog\Application\Port\VideoPortInterface;
use App\Transcode\Application\Port\VideoTimelinePreviewPort;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\ValueObject\LibraryReadScope;

final readonly class VideoPlaybackInformationService implements VideoPlaybackInformationPort
{
    private const array TEXT_SUBTITLE_CODECS = [
        'subrip', 'srt', 'ass', 'ssa', 'webvtt', 'mov_text', 'text', 'microdvd',
        'mpl2', 'jacosub', 'sami', 'realtext', 'subviewer', 'subviewer1', 'vplayer', 'pjs',
    ];

    public function __construct(
        private VideoPortInterface $videos,
        private VideoTimelinePreviewPort $previews,
    ) {
    }

    public function information(PublicId $id, LibraryReadScope $scope): ?array
    {
        $source = $this->videos->getSourceMetadata($id, $scope);
        if ($source === null) {
            return null;
        }
        $probe = $this->videos->findByPublicId($id)?->getProbe() ?? [];
        $audio = [];
        foreach ($probe['audioStreams'] ?? [] as $stream) {
            $audio[] = [
                'index' => $stream['streamIndex'] ?? null,
                'language' => $stream['language'] ?? 'und',
                'title' => $stream['title'] ?? '',
                'codec' => $stream['codec'] ?? 'unknown',
                'channels' => $stream['channels'] ?? 0,
                'isDefault' => $stream['isDefault'] ?? false,
            ];
        }
        $subtitles = [];
        foreach ($probe['subtitleStreams'] ?? [] as $stream) {
            $subtitles[] = [
                'index' => $stream['index'] ?? 0,
                'language' => $stream['language'] ?? 'und',
                'title' => $stream['title'] ?? '',
                'codec' => $stream['codec'] ?? 'unknown',
                'isDefault' => $stream['isDefault'] ?? false,
                'isForced' => $stream['isForced'] ?? false,
                'isText' => in_array(
                    $stream['codec'] ?? '',
                    self::TEXT_SUBTITLE_CODECS,
                    true,
                ),
            ];
        }

        return [
            'audioTracks' => $audio,
            'subtitleTracks' => $subtitles,
            'chapters' => $probe['chapters'] ?? [],
            'preview' => $this->previews->describe($source),
        ];
    }

    public function image(PublicId $id, LibraryReadScope $scope): ?string
    {
        $source = $this->videos->getSourceMetadata($id, $scope);

        return $source === null ? null : $this->previews->image($source);
    }
}
