<?php

namespace App\Jobs\Library\Music;

use App\Jobs\BaseJob;
use App\Models\Artist;
use App\Modules\Logging\Attributes\LogChannel;
use App\Modules\Logging\Channel;
use App\Modules\Metadata\MetadataSyncService;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\{InteractsWithQueue, SerializesModels};
use Psr\Log\LoggerInterface;

class SyncArtistMetadataJob extends BaseJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    #[LogChannel(
        channel: Channel::Metadata,
    )]
    private LoggerInterface $logger;

    public function __construct(
        private readonly int  $artistId,
        private readonly bool $forceUpdate = false,
    )
    {
    }

    public function handle(): void
    {
        $metadataSyncService = app(MetadataSyncService::class);
        $artist = (new \App\Models\Artist)->find($this->artistId);

        if (!$artist) {
            $this->getLogger()->warning('Artist not found for metadata sync', ['artist_id' => $this->artistId]);
            return;
        }

        try {
            $results = $metadataSyncService->syncArtist($artist);

            // Use a lower threshold if we're getting valuable identifiers
            $hasIdentifiers = !empty($results['artist']['mbid']) || !empty($results['artist']['discogs_id']);
            $qualityThreshold = $hasIdentifiers ? 0.6 : 0.7;

            if ($results['quality_score'] >= $qualityThreshold) {
                $this->updateArtistMetadata($artist, $results);

                $this->getLogger()->info('Artist metadata synced successfully', [
                    'artist_id'       => $artist->id,
                    'source'          => $results['source'],
                    'quality_score'   => $results['quality_score'],
                    'has_identifiers' => $hasIdentifiers,
                ]);
            } else {
                $this->getLogger()->warning('Artist metadata sync rejected due to low quality', [
                    'artist_id'      => $artist->id,
                    'quality_score'  => $results['quality_score'],
                    'threshold_used' => $qualityThreshold,
                ]);
            }

        } catch (Exception $e) {
            $this->getLogger()->error('Artist metadata sync job failed', [
                'artist_id' => $this->artistId,
                'error'     => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    private function updateArtistMetadata(Artist $artist, array $results): void
    {
        $this->logger->debug('Updating artist metadata', [
            'raw_results' => $results,
        ]);
        if ($results['artist']) {
            $updateData = [];
            $identifiersUpdated = false;

            // First, handle identifiers - be more aggressive if quality score is high
            $highConfidence = $results['quality_score'] >= 0.8;

            if (isset($results['artist']['mbid'])) {
                if (!$artist->mbid) {
                    $updateData['mbid'] = $results['artist']['mbid'];
                    $identifiersUpdated = true;
                } else if ($this->forceUpdate || $highConfidence) {
                    $updateData['mbid'] = $results['artist']['mbid'];
                }
            }

            if (isset($results['artist']['discogs_id'])) {
                // Fix: Check for discogs_id instead of mbid
                if (!$artist->discogs_id) {
                    $updateData['discogs_id'] = $results['artist']['discogs_id'];
                    $identifiersUpdated = true;
                } else if ($this->forceUpdate || $highConfidence) {
                    $updateData['discogs_id'] = $results['artist']['discogs_id'];
                }
            }

            // Handle other metadata fields
            if (isset($results['artist']['name']) && $results['artist']['name']) {
                if ($this->forceUpdate) {
                    $updateData['name'] = $results['artist']['name'];
                }
            }

            if (isset($results['artist']['country']) && $results['artist']['country']) {
                if ($this->forceUpdate || !$artist->country) {
                    $updateData['country'] = $results['artist']['country'];
                }
            }

            if (isset($results['artist']['gender']) && $results['artist']['gender']) {
                if ($this->forceUpdate || !$artist->gender) {
                    $updateData['gender'] = $results['artist']['gender'];
                }
            }

            if (isset($results['artist']['type']) && $results['artist']['type']) {
                if ($this->forceUpdate || !$artist->type) {
                    $updateData['type'] = $results['artist']['type'];
                }
            }

            if (isset($results['artist']['life_span'])) {
                $lifeSpan = $results['artist']['life_span'];

                if (isset($lifeSpan['begin']) && $lifeSpan['begin']) {
                    if ($this->forceUpdate || !$artist->life_span_begin) {
                        $updateData['life_span_begin'] = $lifeSpan['begin'];
                    }
                }

                if (isset($lifeSpan['end']) && $lifeSpan['end']) {
                    if ($this->forceUpdate || !$artist->life_span_end) {
                        $updateData['life_span_end'] = $lifeSpan['end'];
                    }
                }
            }

            if (isset($results['artist']['disambiguation']) && $results['artist']['disambiguation']) {
                if ($this->forceUpdate || !$artist->disambiguation) {
                    $updateData['disambiguation'] = $results['artist']['disambiguation'];
                }
            }

            if (isset($results['artist']['sort_name']) && $results['artist']['sort_name']) {
                if ($this->forceUpdate || !$artist->sort_name) {
                    $updateData['sort_name'] = $results['artist']['sort_name'];
                }
            }

            if (!empty($updateData)) {
                $artist->update($updateData);

                $this->getLogger()->info('Artist metadata updated', [
                    'artist_id'           => $artist->id,
                    'updated_fields'      => array_keys($updateData),
                    'source'              => $results['source'],
                    'identifiers_updated' => $identifiersUpdated,
                    'raw_results'         => $results,
                ]);

                // If we updated identifiers with high confidence, trigger additional metadata sync
                if ($identifiersUpdated && $highConfidence) {
                    $this->scheduleEnhancedMetadataSync($artist);
                }
            }
        }

        //        if (!empty($results['aliases'])) {
        //            $this->updateArtistAliases($artist, $results['aliases']);
        //        }
    }

    /**
     * Schedule additional metadata sync using the newly populated identifiers
     */
    private function scheduleEnhancedMetadataSync(Artist $artist): void
    {
        $this->getLogger()->info('Scheduling enhanced metadata sync for artist with new identifiers', [
            'artist_id'  => $artist->id,
            'mbid'       => $artist->mbid,
            'discogs_id' => $artist->discogs_id,
        ]);

        SyncArtistJob::syncIdentifierBased($artist->id)
            ->delay(now()->addMinutes(2))
            ->dispatch($artist->id, $this->forceUpdate);
    }

}