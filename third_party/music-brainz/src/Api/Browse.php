<?php

declare(strict_types=1);

namespace MusicBrainz\Api;

use MusicBrainz\Config;
use MusicBrainz\Filter\Browse\Relation\AbstractRelation;
use MusicBrainz\Filter\Browse\Relation\Entity\AreaRelation;
use MusicBrainz\Filter\Browse\Relation\Entity\ArtistRelation;
use MusicBrainz\Filter\Browse\Relation\Entity\CollectionRelation;
use MusicBrainz\Filter\Browse\Relation\Entity\EventRelation;
use MusicBrainz\Filter\Browse\Relation\Entity\InstrumentRelation;
use MusicBrainz\Filter\Browse\Relation\Entity\LabelRelation;
use MusicBrainz\Filter\Browse\Relation\Entity\PlaceRelation;
use MusicBrainz\Filter\Browse\Relation\Entity\RecordingRelation;
use MusicBrainz\Filter\Browse\Relation\Entity\ReleaseGroupRelation;
use MusicBrainz\Filter\Browse\Relation\Entity\ReleaseRelation;
use MusicBrainz\Filter\Browse\Relation\Entity\SeriesRelation;
use MusicBrainz\Filter\Browse\Relation\Entity\WorkRelation;
use MusicBrainz\Filter\PageFilter;
use MusicBrainz\HttpAdapter\AbstractHttpAdapter;
use MusicBrainz\Supplement\Browse\AreaFields;
use MusicBrainz\Supplement\Browse\ArtistFields;
use MusicBrainz\Supplement\Browse\CollectionFields;
use MusicBrainz\Supplement\Browse\EventFields;
use MusicBrainz\Supplement\Browse\InstrumentFields;
use MusicBrainz\Supplement\Browse\LabelFields;
use MusicBrainz\Supplement\Browse\PlaceFields;
use MusicBrainz\Supplement\Browse\RecordingFields;
use MusicBrainz\Supplement\Browse\ReleaseFields;
use MusicBrainz\Supplement\Browse\ReleaseGroupFields;
use MusicBrainz\Supplement\Browse\SeriesFields;
use MusicBrainz\Supplement\Browse\WorkFields;
use MusicBrainz\Supplement\Fields;
use MusicBrainz\Value\EntityType;
use MusicBrainz\Value\Page\AreaListPage;
use MusicBrainz\Value\Page\ArtistListPage;
use MusicBrainz\Value\Page\CollectionListPage;
use MusicBrainz\Value\Page\EventListPage;
use MusicBrainz\Value\Page\InstrumentListPage;
use MusicBrainz\Value\Page\LabelListPage;
use MusicBrainz\Value\Page\PlaceListPage;
use MusicBrainz\Value\Page\RecordingListPage;
use MusicBrainz\Value\Page\ReleaseGroupListPage;
use MusicBrainz\Value\Page\ReleaseListPage;
use MusicBrainz\Value\Page\SeriesListPage;
use MusicBrainz\Value\Page\WorkListPage;

/**
 * Browse requests are a direct lookup of all the entities directly linked to another entity.
 *
 * @link https://musicbrainz.org/doc/Development/XML_Web_Service/Version_2#Browse
 */
class Browse
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
     * Constructs the browse API.
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
     * Looks up for all areas standing in a certain relation.
     *
     * @param AreaRelation $areaRelation A relation, the requested areas stand in
     * @param AreaFields   $areaFields   A list of properties of the areas to be included in the response
     * @param PageFilter   $pageFilter   A page filter
     *
     * @return AreaListPage
     */
    public function area(AreaRelation $areaRelation, AreaFields $areaFields, PageFilter $pageFilter): AreaListPage
    {
        $result = $this->browse(
            new EntityType(EntityType::AREA),
            $areaRelation,
            $areaFields,
            $pageFilter,
            (string) $areaRelation->getEntityType() === EntityType::COLLECTION
        );

        return AreaListPage::make($result, 'area');
    }

    /**
     * Looks up for all artists standing in a certain relation.
     *
     * @param ArtistRelation $artistRelation A relation, the requested artists stand in
     * @param ArtistFields   $artistFields   A list of properties of the artists to be included in the response
     * @param PageFilter     $pageFilter     A page filter
     *
     * @return ArtistListPage
     */
    public function artist(ArtistRelation $artistRelation, ArtistFields $artistFields, PageFilter $pageFilter): ArtistListPage
    {
        $result = $this->browse(
            new EntityType(EntityType::ARTIST),
            $artistRelation,
            $artistFields,
            $pageFilter
        );

        return ArtistListPage::make($result, 'artist');
    }

    /**
     * Looks up for all collections standing in a certain relation.
     *
     * The browse request for collection doesn't support any "inc" parameter.
     *
     * @param CollectionRelation $collectionRelation A relation, the requested collections stand in
     * @param PageFilter         $pageFilter         A page filter
     *
     * @return CollectionListPage
     */
    public function collection(CollectionRelation $collectionRelation, PageFilter $pageFilter): CollectionListPage
    {
        $result = $this->browse(
            new EntityType(EntityType::COLLECTION),
            $collectionRelation,
            new CollectionFields(),
            $pageFilter
        );

        return CollectionListPage::make($result, 'collection');
    }

    /**
     * Looks up for all events standing in a certain relation.
     *
     * @param EventRelation $eventRelation A relation, the requested events stand in
     * @param EventFields   $eventFields   A list of properties of the events to be included in the response
     * @param PageFilter    $pageFilter    A page filter
     *
     * @return EventListPage
     */
    public function event(EventRelation $eventRelation, EventFields $eventFields, PageFilter $pageFilter): EventListPage
    {
        $result = $this->browse(
            new EntityType(EntityType::EVENT),
            $eventRelation,
            $eventFields,
            $pageFilter
        );

        return EventListPage::make($result, 'event');
    }

    /**
     * Looks up for all instruments standing in a certain relation.
     *
     * @param InstrumentRelation $instrumentRelation A relation, the requested instruments stand in
     * @param InstrumentFields   $instrumentFields   A list of properties of the instrument to be included in the response
     * @param PageFilter         $pageFilter         A page filter
     *
     * @return InstrumentListPage
     */
    public function instrument(InstrumentRelation $instrumentRelation, InstrumentFields $instrumentFields, PageFilter $pageFilter): InstrumentListPage
    {
        $result = $this->browse(
            new EntityType(EntityType::INSTRUMENT),
            $instrumentRelation,
            $instrumentFields,
            $pageFilter
        );

        return InstrumentListPage::make($result, 'instrument');
    }

    /**
     * Looks up for all labels standing in a certain relation.
     *
     * @param LabelRelation $labelRelation A relation, the requested labels stand in
     * @param LabelFields   $labelFields   A list of properties of the labels to be included in the response
     * @param PageFilter    $pageFilter    A page filter
     *
     * @return LabelListPage
     */
    public function label(LabelRelation $labelRelation, LabelFields $labelFields, PageFilter $pageFilter): LabelListPage
    {
        $result = $this->browse(
            new EntityType(EntityType::LABEL),
            $labelRelation,
            $labelFields,
            $pageFilter
        );

        return LabelListPage::make($result, 'label');
    }

    /**
     * Looks up for all places standing in a certain relation.
     *
     * @param PlaceRelation $placeRelation A relation, the requested place stand in
     * @param PlaceFields   $placeFields   A list of properties of the places to be included in the response
     * @param PageFilter    $pageFilter    A page filter
     *
     * @return PlaceListPage
     */
    public function place(PlaceRelation $placeRelation, PlaceFields $placeFields, PageFilter $pageFilter): PlaceListPage
    {
        $result = $this->browse(
            new EntityType(EntityType::PLACE),
            $placeRelation,
            $placeFields,
            $pageFilter
        );

        return PlaceListPage::make($result, 'place');
    }

    /**
     * Looks up for all recordings standing in a certain relation.
     *
     * @param RecordingRelation $recordingRelation A relation, the requested recording stand in
     * @param RecordingFields   $recordingFields   A list of properties of the recordings to be included in the response
     * @param PageFilter        $pageFilter        A page filter
     *
     * @return RecordingListPage
     */
    public function recording(RecordingRelation $recordingRelation, RecordingFields $recordingFields, PageFilter $pageFilter): RecordingListPage
    {
        $result = $this->browse(
            new EntityType(EntityType::RECORDING),
            $recordingRelation,
            $recordingFields,
            $pageFilter
        );

        return RecordingListPage::make($result, 'recording');
    }

    /**
     * Looks up for all releases standing in a certain relation.
     *
     * @param ReleaseRelation $releaseRelation A relation, the requested releases stand in
     * @param ReleaseFields   $releaseFields   A list of properties of the releases to be included in the response
     * @param PageFilter      $pageFilter      A page filter
     *
     * @return ReleaseListPage
     */
    public function release(ReleaseRelation $releaseRelation, ReleaseFields $releaseFields, PageFilter $pageFilter): ReleaseListPage
    {
        $result = $this->browse(
            new EntityType(EntityType::RELEASE),
            $releaseRelation,
            $releaseFields,
            $pageFilter
        );

        return ReleaseListPage::make($result, 'release');
    }

    /**
     * Looks up for all release groups standing in a certain relation.
     *
     * @param ReleaseGroupRelation $releaseRelation    A relation, the requested release groups stand in
     * @param ReleaseGroupFields   $releaseGroupFields A list of properties of the release groups to be included in the
     *                                                 response
     * @param PageFilter           $pageFilter         A page filter
     *
     * @return ReleaseGroupListPage
     */
    public function releaseGroup(
        ReleaseGroupRelation $releaseRelation,
        ReleaseGroupFields $releaseGroupFields,
        PageFilter $pageFilter
    ): ReleaseGroupListPage {
        $result = $this->browse(
            new EntityType(EntityType::RELEASE_GROUP),
            $releaseRelation,
            $releaseGroupFields,
            $pageFilter
        );

        return ReleaseGroupListPage::make($result, 'release-group');
    }

    /**
     * Looks up for all series standing in a certain relation.
     *
     * @param SeriesRelation $seriesRelation A relation, the requested series stand in
     * @param SeriesFields   $seriesFields   A list of properties of the series to be included in the response
     * @param PageFilter      $pageFilter    A page filter
     *
     * @return SeriesListPage
     */
    public function series(SeriesRelation $seriesRelation, SeriesFields $seriesFields, PageFilter $pageFilter): SeriesListPage
    {
        $result = $this->browse(
            new EntityType(EntityType::SERIES),
            $seriesRelation,
            $seriesFields,
            $pageFilter
        );

        return SeriesListPage::make($result, 'series');
    }

    /**
     * @todo Implement browse URL!
     */

    /**
     * Looks up for all works standing in a certain relation.
     *
     * @param WorkRelation $workRelation A relation, the requested series stand in
     * @param WorkFields   $workFields   A list of properties of the series to be included in the response
     * @param PageFilter   $pageFilter   A page filter
     *
     * @return WorkListPage
     */
    public function work(WorkRelation $workRelation, WorkFields $workFields, PageFilter $pageFilter): WorkListPage
    {
        $result = $this->browse(
            new EntityType(EntityType::WORK),
            $workRelation,
            $workFields,
            $pageFilter
        );

        return WorkListPage::make($result, 'work');
    }

    /**
     * Looks up for entities standing in a certain relation.
     *
     * @param EntityType       $entity       The type of the requested entities
     * @param AbstractRelation $relation     The type of the related entity
     * @param Fields           $includes     A list of properties of the requested entities to be included in the response
     * @param PageFilter       $pageFilter   A page filter
     * @param bool             $authRequired True, if user authentication is required
     *
     * @return array
     */
    private function browse(
        EntityType $entity,
        AbstractRelation $relation,
        Fields $includes,
        PageFilter $pageFilter,
        bool $authRequired = false
    ) {
        $includes = (string) $includes;

        $params = [
            (string) $relation->getEntityType()  => (string) $relation->getEntityId(),
            'limit'                              => $pageFilter->getLimit(),
            'offset'                             => $pageFilter->getOffset(),
            'fmt'                                => 'json',
        ];

        if (!empty($includes)) {
            $params['inc'] = $includes;
        }

        $authRequired = $authRequired || stripos($includes, 'user');

        $response = $this->httpAdapter->call(str_replace('_', '-', (string) $entity), $this->config, $params, $authRequired);

        return $response;
    }
}
