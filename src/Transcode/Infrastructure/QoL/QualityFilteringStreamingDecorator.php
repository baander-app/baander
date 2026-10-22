<?php

declare(strict_types=1);

namespace App\Transcode\Infrastructure\QoL;

use App\QoL\Application\Port\AllowedQualityTiersPortInterface;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Transcode\Application\DTO\MediaTrack;
use App\Transcode\Application\Port\TranscodeStreamingPortInterface;
use App\Transcode\Domain\Service\QualityLadder;
use Psr\Log\LoggerInterface;

/**
 * Decorator that filters manifests to the tiers QoL currently allows.
 *
 * Chain position: alias → THIS → TranscodeStreamingService
 * decoration_priority: -1 (closer to core than cache).
 *
 * Only filters getMasterManifest() and getDashManifest() — all other methods
 * pass through to inner service without modification.
 */
final readonly class QualityFilteringStreamingDecorator implements TranscodeStreamingPortInterface
{
    public function __construct(
        private TranscodeStreamingPortInterface  $inner,
        private AllowedQualityTiersPortInterface $allowedTiers,
        private LoggerInterface                  $logger,
    )
    {
    }

    public function getPlaybackState(Uuid $videoId, array $allowedJobPublicIds): ?array
    {
        return $this->inner->getPlaybackState($videoId, $allowedJobPublicIds);
    }

    public function getMasterManifest(Uuid $videoId, array $renditions): string
    {
        $manifest = $this->inner->getMasterManifest($videoId, $renditions);

        return $this->filterHlsManifest($manifest);
    }

    /**
     * Filter HLS master manifest — remove #EXT-X-STREAM-INF lines for disallowed tiers.
     *
     * HLS master manifest format:
     *   #EXT-X-STREAM-INF:BANDWIDTH=...,RESOLUTION=...,CODECS=...
     *   /api/stream/{publicId}/media.m3u8?tier=1080p
     *
     * We strip the STREAM-INF + URL line pair when the URL's tier parameter
     * is not in the allowed set.
     */
    private function filterHlsManifest(string $manifest): string
    {
        $allowed = $this->allowedTiers->allowedTierNames();

        // During learning, all tiers are allowed — no filtering needed
        if (count($allowed) === count(QualityLadder::defaultTiers())) {
            return $manifest;
        }

        $allowedSet = array_flip($allowed);
        $lines = explode("\n", $manifest);
        $filtered = [];
        $removedCount = 0;

        for ($i = 0; $i < count($lines); $i++) {
            $line = $lines[$i];

            // Detect #EXT-X-STREAM-INF followed by URL line
            if (str_starts_with($line, '#EXT-X-STREAM-INF:')) {
                $urlLine = $lines[$i + 1] ?? '';

                if (preg_match('/tier=([^&\s]+)/', $urlLine, $matches)) {
                    $tier = $matches[1];
                    if (!isset($allowedSet[$tier])) {
                        // Skip both STREAM-INF and URL line
                        $i++; // Skip the URL line too
                        $removedCount++;
                        continue;
                    }
                }
            }

            $filtered[] = $line;
        }

        if ($removedCount > 0) {
            $this->logger->debug('QualityFilter: removed HLS tiers from manifest', [
                'removed' => $removedCount,
                'allowed' => $allowed,
            ]);
        }

        return implode("\n", $filtered);
    }

    public function getMediaManifest(PublicId $jobPublicId, MediaTrack $track, int $generation): string
    {
        return $this->inner->getMediaManifest($jobPublicId, $track, $generation);
    }

    public function getSegment(PublicId $jobPublicId, MediaTrack $track, int $segmentIndex, int $generation): ?string
    {
        return $this->inner->getSegment($jobPublicId, $track, $segmentIndex, $generation);
    }

    public function getInitSegment(PublicId $jobPublicId, MediaTrack $track, int $generation): ?string
    {
        return $this->inner->getInitSegment($jobPublicId, $track, $generation);
    }

    public function getSegmentPath(PublicId $jobPublicId, MediaTrack $track, int $segmentIndex, int $generation): ?string
    {
        return $this->inner->getSegmentPath($jobPublicId, $track, $segmentIndex, $generation);
    }

    public function resolveSegmentAvailability(PublicId $jobPublicId, MediaTrack $track, int $segmentIndex, int $generation): ?array
    {
        return $this->inner->resolveSegmentAvailability($jobPublicId, $track, $segmentIndex, $generation);
    }

    public function getInitSegmentPath(PublicId $jobPublicId, MediaTrack $track, int $generation): ?string
    {
        return $this->inner->getInitSegmentPath($jobPublicId, $track, $generation);
    }

    public function getDashManifest(Uuid $videoId, array $renditions): string
    {
        $manifest = $this->inner->getDashManifest($videoId, $renditions);

        return $this->filterDashManifest($manifest);
    }

    /** Remove disallowed video tiers while preserving the canonical audio adaptation. */
    private function filterDashManifest(string $manifest): string
    {
        $allowed = array_flip($this->allowedTiers->allowedTierNames());
        $xml = new \DOMDocument();
        if (!$xml->loadXML($manifest, LIBXML_NONET)) {
            return $manifest;
        }
        $xpath = new \DOMXPath($xml);
        $xpath->registerNamespace('mpd', 'urn:mpeg:dash:schema:mpd:2011');
        $videos = $xpath->query('//mpd:AdaptationSet[@contentType="video"]/mpd:Representation');
        if ($videos !== false) {
            foreach ($videos as $representation) {
                if ($representation instanceof \DOMElement && !isset($allowed[$representation->getAttribute('id')])) {
                    $representation->parentNode?->removeChild($representation);
                }
            }
        }
        $remaining = $xpath->query('//mpd:AdaptationSet[@contentType="video"]/mpd:Representation');
        if ($remaining !== false && $remaining->length === 0) {
            $adaptations = $xpath->query('//mpd:AdaptationSet');
            if ($adaptations !== false) {
                foreach ($adaptations as $adaptation) {
                    $adaptation->parentNode?->removeChild($adaptation);
                }
            }
        }
        return $xml->saveXML();
    }

    /**
     * @return list<array{name: string, height: int, width: int, bitrate: int, codec: string}>
     */
    public function getQualityLadderForVideo(Uuid $videoId, array $allowedJobPublicIds): array
    {
        return $this->inner->getQualityLadderForVideo($videoId, $allowedJobPublicIds);
    }

    public function getSubtitleManifest(PublicId $jobPublicId, string $language, int $generation): string
    {
        return $this->inner->getSubtitleManifest($jobPublicId, $language, $generation);
    }

    public function getSubtitleSegment(PublicId $jobPublicId, string $language, string $segmentName, int $generation): ?string
    {
        return $this->inner->getSubtitleSegment($jobPublicId, $language, $segmentName, $generation);
    }

    public function getSubtitleSegmentPath(PublicId $jobPublicId, string $language, string $segmentName, int $generation): ?string
    {
        return $this->inner->getSubtitleSegmentPath($jobPublicId, $language, $segmentName, $generation);
    }
}
