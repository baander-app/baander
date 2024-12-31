<?php

declare(strict_types=1);

namespace MusicBrainz\Api;

use MusicBrainz\Config;
use MusicBrainz\HttpAdapter\AbstractHttpAdapter;
use MusicBrainz\Supplement\Fields;
use MusicBrainz\Supplement\Lookup\AreaFields;
use MusicBrainz\Supplement\Lookup\ArtistFields;
use MusicBrainz\Supplement\Lookup\EventFields;
use MusicBrainz\Supplement\Lookup\InstrumentFields;
use MusicBrainz\Supplement\Lookup\LabelFields;
use MusicBrainz\Supplement\Lookup\PlaceFields;
use MusicBrainz\Supplement\Lookup\RecordingFields;
use MusicBrainz\Supplement\Lookup\ReleaseFields;
use MusicBrainz\Supplement\Lookup\ReleaseGroupFields;
use MusicBrainz\Supplement\Lookup\SeriesFields;
use MusicBrainz\Supplement\Lookup\UrlFields;
use MusicBrainz\Supplement\Lookup\WorkFields;
use MusicBrainz\Value\Area;
use MusicBrainz\Value\Artist;
use MusicBrainz\Value\Collection;
use MusicBrainz\Value\EntityType;
use MusicBrainz\Value\Event;
use MusicBrainz\Value\Instrument;
use MusicBrainz\Value\Label;
use MusicBrainz\Value\MBID;
use MusicBrainz\Value\Place;
use MusicBrainz\Value\Recording;
use MusicBrainz\Value\Release;
use MusicBrainz\Value\ReleaseGroup;
use MusicBrainz\Value\Series;
use MusicBrainz\Value\URL;
use MusicBrainz\Value\Work;
use src\Supplement\Lookup\CollectionFields;

/**
 * Lookups are direct queries for entities specified by a MusicBrainz Identifier (MBID).
 *
 * @link https://musicbrainz.org/doc/Development/XML_Web_Service/Version_2#Lookups
 */
class Lookup
{
    /**
     * An HTTP adapter
     *
     * @var AbstractHttpAdapter
     */
    private AbstractHttpAdapter $httpAdapter;

    /**
     * The API client configuration
     *
     * @var Config
     */
    private Config $config;

    /**
     * Constructs the search API.
     *
     * @param AbstractHttpAdapter $httpAdapter An HTTP adapter
     * @param Config              $config      The API client configuration
     */
    public function __construct(AbstractHttpAdapter $httpAdapter, Config $config)
    {
        $this->httpAdapter = $httpAdapter;
        $this->config      = $config;
    }

    /**
     * Looks up for an area and returns the result.
     *
     * @param MBID       $mbid       A Music Brainz Identifier (MBID) of an area
     * @param AreaFields $areaFields List of fields to be included in the response
     *
     * @return Area
     */
    public function area(MBID $mbid, AreaFields $areaFields): Area
    {
        $result = $this->lookup(new EntityType(EntityType::AREA), $mbid, $areaFields);

        return new Area($result);
    }

    /**
     * Looks up for an artist and returns the result.
     *
     * @param MBID         $mbid         A Music Brainz Identifier (MBID) of an artist
     * @param ArtistFields $artistFields List of fields to be included in the response
     * @return Artist
     */
    public function artist(MBID $mbid, ArtistFields $artistFields): Artist
    {
        $result = $this->lookup(new EntityType(EntityType::ARTIST), $mbid, $artistFields);

        return new Artist($result);
    }

    /**
     * Looks up for a collection and returns the result.
     *
     * @param MBID $mbid A Music Brainz Identifier (MBID) of a collection
     *
     * @return Collection
     */
    public function collection(MBID $mbid): Collection
    {
        $result = $this->lookup(new EntityType(EntityType::COLLECTION), $mbid, new CollectionFields(), true);

        return new Collection($result);
    }

    /**
     * Looks up for an event and returns the result.
     *
     * @param MBID        $mbid        A Music Brainz Identifier (MBID) of an event
     * @param EventFields $eventFields List of fields to be included in the response
     *
     * @return Event
     */
    public function event(MBID $mbid, EventFields $eventFields)
    {
        $result = $this->lookup(new EntityType(EntityType::EVENT), $mbid, $eventFields);

        return new Event($result);
    }

    /**
     * Looks up for an instrument and returns the result.
     *
     * @param MBID             $mbid             A Music Brainz Identifier (MBID) of an instrument
     * @param InstrumentFields $instrumentFields List of fields to be included in the response
     *
     * @return Instrument
     */
    public function instrument(MBID $mbid, InstrumentFields $instrumentFields): Instrument
    {
        $result = $this->lookup(new EntityType(EntityType::INSTRUMENT), $mbid, $instrumentFields);

        return new Instrument($result);
    }

    /**
     * Looks up for a label and returns the result.
     *
     * @param MBID $mbid A Music Brainz Identifier (MBID) of a label
     *
     * @return Label
     */
    public function label(MBID $mbid, LabelFields $labelFields): Label
    {
        $result = $this->lookup(new EntityType(EntityType::LABEL), $mbid, $labelFields);

        return new Label($result);
    }

    /**
     * Looks up for a place and returns the result.
     *
     * @param MBID        $mbid        A Music Brainz Identifier (MBID) of a label
     * @param PlaceFields $placeFields List of fields to be included in the response
     *
     * @return Place
     */
    public function place(MBID $mbid, PlaceFields $placeFields): Place
    {
        $result = $this->lookup(new EntityType(EntityType::PLACE), $mbid, $placeFields);

        return new Place($result);
    }

    /**
     * Looks up for a recording and returns the result.
     *
     * @param MBID            $mbid            A Music Brainz Identifier (MBID) of a recording
     * @param RecordingFields $recordingFields List of fields to be included in the response
     *
     * @return Recording
     */
    public function recording(MBID $mbid, RecordingFields $recordingFields): Recording
    {
        $result = $this->lookup(new EntityType(EntityType::RECORDING), $mbid, $recordingFields);

        return new Recording($result);
    }

    /**
     * Looks up for a release and returns the result.
     *
     * @param MBID          $mbid          A Music Brainz Identifier (MBID) of a release
     * @param ReleaseFields $releaseFields List of fields to be included in the response
     *
     * @return Release
     */
    public function release(MBID $mbid, ReleaseFields $releaseFields): Release
    {
        $result = $this->lookup(new EntityType(EntityType::RELEASE), $mbid, $releaseFields);

        return new Release($result);
    }

    /**
     * Looks up for a release group and returns the result.
     *
     * @param MBID               $mbid               A Music Brainz Identifier (MBID) of a release
     * @param ReleaseGroupFields $releaseGroupFields List of fields to be included in the response
     *
     * @return ReleaseGroup
     */
    public function releaseGroup(MBID $mbid, ReleaseGroupFields $releaseGroupFields): ReleaseGroup
    {
        $result = $this->lookup(new EntityType(EntityType::RELEASE_GROUP), $mbid, $releaseGroupFields);

        return new ReleaseGroup($result);
    }

    /**
     * Looks up for an URL and returns the result.
     *
     * @param MBID         $mbid         A Music Brainz Identifier (MBID) of a release
     * @param SeriesFields $seriesFields List of fields to be included in the response
     *
     * @return Series
     */
    public function series(MBID $mbid, SeriesFields $seriesFields): Series
    {
        $result = $this->lookup(new EntityType(EntityType::SERIES), $mbid, $seriesFields);

        return new Series($result);
    }

    /**
     * Looks up for an URL and returns the result.
     *
     * @param MBID         $mbid          A Music Brainz Identifier (MBID) of a release
     * @param UrlFields $urlFields List of fields to be included in the response
     *
     * @return URL
     */
    public function url(MBID $mbid, $urlFields): URL
    {
        $result = $this->lookup(new EntityType(EntityType::URL), $mbid, $urlFields);

        return new URL($result);
    }

    /**
     * Looks up for a work and returns the result.
     *
     * @param MBID $mbid A Music Brainz Identifier (MBID) of a work
     *
     * @return Work
     */
    public function work(MBID $mbid, WorkFields $workFields): Work
    {
        $result = $this->lookup(new EntityType(EntityType::WORK), $mbid, $workFields);

        return new Work($result);
    }

    /**
     * Looks up for an entity by performing a request to MusicBrainz webservice.
     *
     * @link http://musicbrainz.org/doc/XML_Web_Service
     *
     * @param EntityType $entityType   An entity type
     * @param MBID       $mbid         A MusicBrainz Identifier (MBID)
     * @param Fields     $includes     A list of include parameters
     * @param bool       $authRequired True, if user authentication is required
     *
     * @return array
     */
    private function lookup(EntityType $entityType, MBID $mbid, Fields $includes, bool $authRequired = false)
    {
        $includes = (string) $includes;

        $params = [
            'inc' => (string) $includes,
            'fmt' => 'json',
        ];

        if (!empty($includes)) {
            $params['inc'] = $includes;
        }

        $authRequired = $authRequired || stripos($includes, 'user');

        $response = $this->httpAdapter->call(
            str_replace('_', '-', (string) $entityType) .
            '/' .
            $mbid,
            $this->config,
            $params,
            $authRequired
        );

        return $response;
    }
}
