<?php

namespace App\Jobs\Library;

use App\Jobs\BaseJob;
use App\Models\Album;
use App\Models\Artist;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use MusicBrainz\Filter\PageFilter;
use MusicBrainz\Filter\Search\ReleaseFilter;
use MusicBrainz\MusicBrainz;
use MusicBrainz\Value\ArtistCredit;
use MusicBrainz\Value\ArtistCreditList;

class MetaDataMusicBrainzAlbumJob extends BaseJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(public Album $album)
    {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $musicBrainz = app(MusicBrainz::class);

        $releaseFilter = new ReleaseFilter();
        $releaseFilter->addReleaseName($this->album->title);

        $pageFilter = new PageFilter(0, 1);
        try {
            $artistList = $musicBrainz->api()->search()->release($releaseFilter, $pageFilter);
        } catch (\Throwable $e) {
            $this->logger()->warning($e->getMessage());
            $this->delete();
            return;
        }

        if (empty($artistList)) {
            $this->delete();
            return;
        }

        $release = $artistList[0]->getRelease();

        if (!$this->album->year && $release->getDate()) {
            $this->album->year = Carbon::parse($release->getDate())->year;
        }

        $artists = $this->processCredits($release->getArtistCredits());
        $this->album->artist()->sync($artists);


        $this->album->update();
    }

    /**
     * @param ArtistCredit[]|ArtistCreditList $credits
     * @return Artist[]
     */
    private function processCredits(array|ArtistCreditList $credits)
    {
        $artists = [];

        foreach ($credits as $credit) {
            /** @var ArtistCredit $credit */

            $artists[] = Artist::firstOrCreate([
                'name' => $credit->getName(),
            ])->id;
        }

        return $artists;
    }
}
