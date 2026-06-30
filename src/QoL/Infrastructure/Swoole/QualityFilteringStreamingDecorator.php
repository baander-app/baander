<?php

declare(strict_types=1);

namespace App\QoL\Infrastructure\Swoole;

use App\QoL\Domain\Service\StreamGovernor;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Transcode\Application\Port\TranscodeStreamingPortInterface;
use App\Transcode\Domain\Service\QualityLadder;
use Psr\Log\LoggerInterface;

/**
 * Decorator that filters manifests based on governor's allowed tiers.
 *
 * Chain position: alias → CachedTranscodeStreamingService → THIS → TranscodeStreamingService
 * decoration_priority: -1 (closer to core than cache).
 *
 * Only filters getMasterManifest() and getDashManifest() — all other methods
 * pass through to inner service without modification.
 */
final readonly class QualityFilteringStreamingDecorator implements TranscodeStreamingPortInterface
{
    public function __construct(
        private TranscodeStreamingPortInterface $inner,
        private StreamGovernor                  $governor,
        private LoggerInterface                 $logger,
    )
    {
    }

    public function getMasterManifest(Uuid $videoId): string
    {
        $manifest = $this->inner->getMasterManifest($videoId);

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
        $allowed = $this->governor->getAllowedTiers();

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

    public function getMediaManifest(PublicId $jobPublicId, string $audioProfileName): string
    {
        return $this->inner->getMediaManifest($jobPublicId, $audioProfileName);
    }

    public function getSegment(PublicId $jobPublicId, int $segmentIndex): ?string
    {
        return $this->inner->getSegment($jobPublicId, $segmentIndex);
    }

    public function getInitSegment(PublicId $jobPublicId): ?string
    {
        return $this->inner->getInitSegment($jobPublicId);
    }

    public function getSegmentPath(PublicId $jobPublicId, int $segmentIndex): ?string
    {
        return $this->inner->getSegmentPath($jobPublicId, $segmentIndex);
    }

    public function getInitSegmentPath(PublicId $jobPublicId): ?string
    {
        return $this->inner->getInitSegmentPath($jobPublicId);
    }

    public function getDashManifest(Uuid $videoId): string
    {
        $manifest = $this->inner->getDashManifest($videoId);

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
        $allowed = $this->governor->getAllowedTiers();

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

    public function getQualityLadderForVideo(Uuid $videoId): array
    {
        return $this->inner->getQualityLadderForVideo($videoId);
    }

    public function getAudioManifest(PublicId $jobPublicId, string $language): string
    {
        return $this->inner->getAudioManifest($jobPublicId, $language);
    }

    public function getAudioInitSegment(PublicId $jobPublicId, string $language): ?string
    {
        return $this->inner->getAudioInitSegment($jobPublicId, $language);
    }

    public function getAudioSegment(PublicId $jobPublicId, string $language, int $segmentIndex): ?string
    {
        return $this->inner->getAudioSegment($jobPublicId, $language, $segmentIndex);
    }

    public function getAudioInitSegmentPath(PublicId $jobPublicId, string $language): ?string
    {
        return $this->inner->getAudioInitSegmentPath($jobPublicId, $language);
    }

    public function getAudioSegmentPath(PublicId $jobPublicId, string $language, int $segmentIndex): ?string
    {
        return $this->inner->getAudioSegmentPath($jobPublicId, $language, $segmentIndex);
    }

    public function getSubtitleManifest(PublicId $jobPublicId, string $language): string
    {
        return $this->inner->getSubtitleManifest($jobPublicId, $language);
    }

    public function getSubtitleSegment(PublicId $jobPublicId, string $language, string $segmentName): ?string
    {
        return $this->inner->getSubtitleSegment($jobPublicId, $language, $segmentName);
    }

    public function getSubtitleSegmentPath(PublicId $jobPublicId, string $language, string $segmentName): ?string
    {
        return $this->inner->getSubtitleSegmentPath($jobPublicId, $language, $segmentName);
    }
}
