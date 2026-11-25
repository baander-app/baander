<?php

declare(strict_types=1);

namespace App\Shared\Domain\ValueObject;

/**
 * The containers a library may hold: the file extensions the scanner discovers, the
 * FFmpeg demuxer that must recognize the content, and the media type of the original.
 * The demuxer is one element of ffprobe's comma-separated `format_name`; where one
 * demuxer reads several containers, the file extension tells them apart.
 */
enum MediaContainer
{
    case Mp3;
    case Flac;
    case Ogg;
    case Opus;
    case Wav;
    case Aac;
    case Mpeg4Audio;
    case Wma;
    case Aiff;
    case Mpeg4;
    case QuickTime;
    case Matroska;
    case WebM;
    case Avi;
    case Wmv;

    public function kind(): MediaKind
    {
        return match ($this) {
            self::Mp3, self::Flac, self::Ogg, self::Opus, self::Wav, self::Aac,
            self::Mpeg4Audio, self::Wma, self::Aiff => MediaKind::Audio,
            self::Mpeg4, self::QuickTime, self::Matroska, self::WebM, self::Avi, self::Wmv => MediaKind::Video,
        };
    }

    /** @return non-empty-list<string> lower-case, without the dot */
    public function extensions(): array
    {
        return match ($this) {
            self::Mp3 => ['mp3'],
            self::Flac => ['flac'],
            self::Ogg => ['ogg', 'oga'],
            self::Opus => ['opus'],
            self::Wav => ['wav', 'wave'],
            self::Aac => ['aac'],
            self::Mpeg4Audio => ['m4a', 'mp4a', 'm4b'],
            self::Wma => ['wma'],
            self::Aiff => ['aif', 'aiff', 'aifc'],
            self::Mpeg4 => ['mp4', 'm4v'],
            self::QuickTime => ['mov'],
            self::Matroska => ['mkv'],
            self::WebM => ['webm'],
            self::Avi => ['avi'],
            self::Wmv => ['wmv'],
        };
    }

    public function demuxer(): string
    {
        return match ($this) {
            self::Mp3 => 'mp3',
            self::Flac => 'flac',
            self::Ogg, self::Opus => 'ogg',
            self::Wav => 'wav',
            self::Aac => 'aac',
            self::Mpeg4Audio, self::Mpeg4, self::QuickTime => 'mov',
            self::Wma, self::Wmv => 'asf',
            self::Aiff => 'aiff',
            self::Matroska, self::WebM => 'matroska',
            self::Avi => 'avi',
        };
    }

    public function mediaType(): string
    {
        return match ($this) {
            self::Mp3 => 'audio/mpeg',
            self::Flac => 'audio/flac',
            self::Ogg => 'audio/ogg',
            self::Opus => 'audio/opus',
            self::Wav => 'audio/wav',
            self::Aac => 'audio/aac',
            self::Mpeg4Audio => 'audio/mp4',
            self::Wma => 'audio/x-ms-wma',
            self::Aiff => 'audio/aiff',
            self::Mpeg4 => 'video/mp4',
            self::QuickTime => 'video/quicktime',
            self::Matroska => 'video/x-matroska',
            self::WebM => 'video/webm',
            self::Avi => 'video/x-msvideo',
            self::Wmv => 'video/x-ms-wmv',
        };
    }

    public static function forExtension(string $extension): ?self
    {
        $extension = strtolower($extension);
        return array_find(self::cases(), static fn(self $container): bool => in_array($extension, $container->extensions(), true));
    }

    /** @return non-empty-list<string> the demuxers of one kind, in table order */
    public static function demuxers(MediaKind $kind): array
    {
        return self::ofKind($kind)
            |> (static fn(array $containers): array => array_map(static fn(self $container): string => $container->demuxer(), $containers))
            |> array_unique(...)
            |> array_values(...);
    }

    /**
     * The container of a file of one kind, from the probed `format_name` and the file
     * extension. Without a probed format the extension decides; a probed format no
     * container of the kind is read by gives null.
     */
    public static function identify(MediaKind $kind, ?string $formatName, string $extension): ?self
    {
        $candidates = self::ofKind($kind);
        $extension = strtolower($extension);
        $named = static fn(self $container): bool => in_array($extension, $container->extensions(), true);
        if ($formatName === null) {
            return array_find($candidates, $named);
        }
        $family = explode(',', $formatName);
        $probed = array_values(array_filter(
            $candidates,
            static fn(self $container): bool => in_array($container->demuxer(), $family, true),
        ));
        return array_find($probed, $named) ?? array_first($probed);
    }

    /** @return list<self> */
    private static function ofKind(MediaKind $kind): array
    {
        return array_values(array_filter(self::cases(), static fn(self $container): bool => $container->kind() === $kind));
    }
}
