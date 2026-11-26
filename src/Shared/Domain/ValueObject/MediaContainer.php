<?php

declare(strict_types=1);

namespace App\Shared\Domain\ValueObject;

/**
 * The containers a library may hold: the file extensions the scanner discovers, the
 * FFmpeg demuxer that must recognize the content, and the media type of the original.
 * The demuxer is one element of ffprobe's comma-separated `format_name`; where one
 * demuxer reads several containers, the file extension tells them apart, and for the
 * Matroska demuxer the codecs too.
 */
enum MediaContainer
{
    /** The codecs the WebM profile of Matroska admits, as ffprobe names them. */
    private const array WEBM_VIDEO_CODECS = ['vp8', 'vp9', 'av1'];

    private const array WEBM_AUDIO_CODECS = ['vorbis', 'opus'];

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
            self::Opus => 'audio/ogg',
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
     * The container of a file of one kind, from the probed `format_name`, the file
     * extension and the probed codecs. Without a probed format the extension decides; a
     * probed format no container of the kind is read by gives null. WebM is the profile of
     * Matroska that holds only VP8, VP9 or AV1 video with Vorbis or Opus audio, so a probed
     * `.webm` file with any other codec, or with codecs not given, is Matroska.
     *
     * @param list<?string> $audioCodecs one per audio stream
     */
    public static function identify(
        MediaKind $kind,
        ?string $formatName,
        string $extension,
        ?string $videoCodec = null,
        array $audioCodecs = [],
    ): ?self {
        $candidates = self::ofKind($kind);
        $extension = strtolower($extension);
        $named = static fn(self $container): bool => in_array($extension, $container->extensions(), true);
        if ($formatName === null) {
            return array_find($candidates, $named);
        }
        $family = explode(',', $formatName);
        $probed = array_filter(
            $candidates,
            static fn(self $container): bool => in_array($container->demuxer(), $family, true),
        ) |> array_values(...);
        $container = array_find($probed, $named) ?? array_first($probed);

        return $container === self::WebM && !self::holdsWebMCodecs($videoCodec, $audioCodecs) ? self::Matroska : $container;
    }

    /** @param list<?string> $audioCodecs */
    private static function holdsWebMCodecs(?string $videoCodec, array $audioCodecs): bool
    {
        return in_array($videoCodec, self::WEBM_VIDEO_CODECS, true)
            && array_all($audioCodecs, static fn(?string $codec): bool => in_array($codec, self::WEBM_AUDIO_CODECS, true));
    }

    /** @return list<self> */
    private static function ofKind(MediaKind $kind): array
    {
        return array_filter(self::cases(), static fn(self $container): bool => $container->kind() === $kind) |> array_values(...);
    }
}
