<?php

declare(strict_types=1);

namespace App\Transcode\Infrastructure\QoL;

use App\QoL\Application\Port\AllowedQualityTiersPortInterface;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
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

    public function getPlaybackState(Uuid $videoId, ?string $jobPublicId = null): ?array
    {
        return $this->inner->getPlaybackState($videoId, $jobPublicId);
    }

    public function getMasterManifest(Uuid $videoId, int $generation, ?string $jobPublicId = null): string
    {
        $manifest = $this->inner->getMasterManifest($videoId, $generation, $jobPublicId);

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

    public function getMediaManifest(PublicId $jobPublicId, string $audioProfileName, int $generation): string
    {
        return $this->inner->getMediaManifest($jobPublicId, $audioProfileName, $generation);
    }

    public function getSegment(PublicId $jobPublicId, int $segmentIndex, int $generation): ?string
    {
        return $this->inner->getSegment($jobPublicId, $segmentIndex, $generation);
    }

    public function getInitSegment(PublicId $jobPublicId, int $generation): ?string
    {
        return $this->inner->getInitSegment($jobPublicId, $generation);
    }

    public function getSegmentPath(PublicId $jobPublicId, int $segmentIndex, int $generation): ?string
    {
        return $this->inner->getSegmentPath($jobPublicId, $segmentIndex, $generation);
    }

    public function resolveVideoSegmentAvailability(PublicId $jobPublicId, int $segmentIndex, int $generation): ?array
    {
        return $this->inner->resolveVideoSegmentAvailability($jobPublicId, $segmentIndex, $generation);
    }

    public function getInitSegmentPath(PublicId $jobPublicId, int $generation): ?string
    {
        return $this->inner->getInitSegmentPath($jobPublicId, $generation);
    }

    public function getDashManifest(Uuid $videoId, int $generation, ?string $jobPublicId = null): string
    {
        $manifest = $this->inner->getDashManifest($videoId, $generation, $jobPublicId);

        return $this->filterDashManifest($manifest);
    }

    /**
     * Filter DASH manifest — remove <Representation> elements for disallowed tiers.
     *
     * DashManifestGenerator creates a single <AdaptationSet> containing all
     * <Representation> children keyed by tier name in the id attribute.
     * We strip individual <Representation id="{tier}"> blocks.
     */
    private function filterDashManifest(string $manifest): string
    {
        $allowed = $this->allowedTiers->allowedTierNames();

        // During learning, all tiers are allowed — no filtering needed
        if (count($allowed) === count(QualityLadder::defaultTiers())) {
            return $manifest;
        }

        $allowedSet = array_flip($allowed);
        $removedCount = 0;

        $result = preg_replace_callback(
            '/<Representation\s[^>]*>.*?<\/Representation>/s',
            function (array $match) use ($allowedSet, &$removedCount): string {
                $content = $match[0];

                // Extract id attribute from <Representation id="...">
                if (preg_match('/id="([^"]+)"/', $content, $idMatch)) {
                    $tier = $idMatch[1];
                    if (!isset($allowedSet[$tier])) {
                        $removedCount++;

                        return ''; // Remove this representation
                    }
                }

                return $content;
            },
            $manifest,
        );

        if ($removedCount > 0) {
            $this->logger->debug('QualityFilter: removed DASH representations from manifest', [
                'removed' => $removedCount,
                'allowed' => $allowed,
            ]);
        }

        return $result ?? $manifest;
    }

    /**
     * @return list<array{name: string, height: int, width: int, bitrate: int, codec: string}>
     */
    public function getQualityLadderForVideo(Uuid $videoId): array
    {
        return $this->inner->getQualityLadderForVideo($videoId);
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
