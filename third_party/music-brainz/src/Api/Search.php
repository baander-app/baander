<?php

declare(strict_types=1);

namespace MusicBrainz\Api;

use MusicBrainz\Config;
use MusicBrainz\Exception;
use MusicBrainz\Filter\PageFilter;
use MusicBrainz\Filter\Search\AbstractFilter;
use MusicBrainz\Filter\Search\AnnotationFilter;
use MusicBrainz\Filter\Search\AreaFilter;
use MusicBrainz\Filter\Search\ArtistFilter;
use MusicBrainz\Filter\Search\CdStubFilter;
use MusicBrainz\Filter\Search\LabelFilter;
use MusicBrainz\Filter\Search\PlaceFilter;
use MusicBrainz\Filter\Search\RecordingFilter;
use MusicBrainz\Filter\Search\ReleaseFilter;
use MusicBrainz\Filter\Search\ReleaseGroupFilter;
use MusicBrainz\Filter\Search\TagFilter;
use MusicBrainz\Filter\Search\WorkFilter;
use MusicBrainz\HttpAdapter\AbstractHttpAdapter;
use MusicBrainz\Value\Page\SearchResult\AnnotationListPage;
use MusicBrainz\Value\Page\SearchResult\AreaListPage;
use MusicBrainz\Value\Page\SearchResult\ArtistListPage;
use MusicBrainz\Value\Page\SearchResult\CdStubListPage;
use MusicBrainz\Value\Page\SearchResult\LabelListPage;
use MusicBrainz\Value\Page\SearchResult\PlaceListPage;
use MusicBrainz\Value\Page\SearchResult\RecordingListPage;
use MusicBrainz\Value\Page\SearchResult\ReleaseGroupListPage;
use MusicBrainz\Value\Page\SearchResult\ReleaseListPage;
use MusicBrainz\Value\Page\SearchResult\TagListPage;
use MusicBrainz\Value\Page\SearchResult\WorkListPage;
use MusicBrainz\Value\SearchResult;

/**
 * The search API provides methods for searching entities based on the parameters supplied in the filter objects.
 *
 * @link https://musicbrainz.org/doc/Development/XML_Web_Service/Version_2/Search
 */
class Search
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
     * Search for annotations and returns the result.
     *
     * @param AnnotationFilter $annotationFilter An annotation filter
     * @param PageFilter       $pageFilter       A page filter
     *
     * @return SearchResult\Annotation[]|AnnotationListPage
     *
     * @throws Exception
     */
    public function annotation(AnnotationFilter $annotationFilter, PageFilter $pageFilter): AnnotationListPage
    {
        $params   = $this->getParameters($annotationFilter, $pageFilter);
        $response = $this->httpAdapter->call('annotation' . '/', $this->config, $params, false);

        return AnnotationListPage::make($response, 'annotation');
    }

    /**
     * Searches for areas and returns the result.
     *
     * @param AreaFilter $areaFilter An area filter
     * @param PageFilter $pageFilter A page filter
     *
     * @return SearchResult[]|AreaListPage
     *
     * @throws Exception
     */
    public function area(AreaFilter $areaFilter, PageFilter $pageFilter): AreaListPage
    {
        $params   = $this->getParameters($areaFilter, $pageFilter);
        $response = $this->httpAdapter->call('area' . '/', $this->config, $params, false);

        return AreaListPage::make($response, 'area');
    }

    /**
     * Searches for artists and returns the result.
     *
     * @param ArtistFilter $artistFilter An artist filter
     * @param PageFilter   $pageFilter   A page filter
     *
     * @return SearchResult\Artist[]|ArtistListPage
     *
     * @throws Exception
     */
    public function artist(ArtistFilter $artistFilter, PageFilter $pageFilter): ArtistListPage
    {
        $params   = $this->getParameters($artistFilter, $pageFilter);
        $response = $this->httpAdapter->call('artist' . '/', $this->config, $params);

        return ArtistListPage::make($response, 'artist');
    }

    /**
     * Searches for CD stubs and returns the result.
     *
     * @param CdStubFilter $cdStubFilter A CD stub filter
     * @param PageFilter   $pageFilter   A page filter
     *
     * @return SearchResult\CdStub[]|CdStubListPage
     *
     * @throws Exception
     */
    public function cdStub(CdStubFilter $cdStubFilter, PageFilter $pageFilter): CdStubListPage
    {
        $params   = $this->getParameters($cdStubFilter, $pageFilter);
        $response = $this->httpAdapter->call('cdstub' . '/', $this->config, $params);

        return CdStubListPage::make($response, 'cdstub');
    }

    /**
     * Searches for labels and returns the result.
     *
     * @param LabelFilter $labelFilter A label filter
     * @param PageFilter  $pageFilter  A page filter
     *
     * @return SearchResult\Label[]|LabelListPage
     *
     * @throws Exception
     */
    public function label(LabelFilter $labelFilter, PageFilter $pageFilter): LabelListPage
    {
        $params   = $this->getParameters($labelFilter, $pageFilter);
        $response = $this->httpAdapter->call('label' . '/', $this->config, $params);

        return LabelListPage::make($response, 'label');
    }

    /**
     * Searches for places and returns the result.
     *
     * @param PlaceFilter $placeFilter A place filter
     * @param PageFilter  $pageFilter  A page filter
     *
     * @return SearchResult\Place[]|PlaceListPage
     *
     * @throws Exception
     */
    public function place(PlaceFilter $placeFilter, PageFilter $pageFilter): PlaceListPage
    {
        $params   = $this->getParameters($placeFilter, $pageFilter);
        $response = $this->httpAdapter->call('place' . '/', $this->config, $params);

        return PlaceListPage::make($response, 'place');
    }

    /**
     * Searches for recording and returns the result.
     *
     * @param RecordingFilter $recordingFilter A recording filter
     * @param PageFilter      $pageFilter      A page filter
     *
     * @return SearchResult\Recording[]|RecordingListPage
     *
     * @throws Exception
     */
    public function recording(RecordingFilter $recordingFilter, PageFilter $pageFilter): RecordingListPage
    {
        $params   = $this->getParameters($recordingFilter, $pageFilter);
        $response = $this->httpAdapter->call('recording' . '/', $this->config, $params);

        return RecordingListPage::make($response, 'recording');
    }

    /**
     * Searches for releases and returns the result.
     *
     * @param ReleaseFilter $releaseFilter A release group filter
     * @param PageFilter    $pageFilter    A page filter
     *
     * @return SearchResult\Release[]|ReleaseListPage
     *
     * @throws Exception
     */
    public function release(ReleaseFilter $releaseFilter, PageFilter $pageFilter): ReleaseListPage
    {
        $params   = $this->getParameters($releaseFilter, $pageFilter);
        $response = $this->httpAdapter->call('release' . '/', $this->config, $params);

        return ReleaseListPage::make($response, 'release');
    }

    /**
     * Searches for release groups and returns the result.
     *
     * @param ReleaseGroupFilter $releaseGroupFilter A release group filter
     * @param PageFilter         $pageFilter         A page filter
     *
     * @return SearchResult\ReleaseGroup[]|ReleaseGroupListPage
     *
     * @throws Exception
     */
    public function releaseGroup(ReleaseGroupFilter $releaseGroupFilter, PageFilter $pageFilter): ReleaseGroupListPage
    {
        $params   = $this->getParameters($releaseGroupFilter, $pageFilter);
        $response = $this->httpAdapter->call('release-group' . '/', $this->config, $params);

        return ReleaseGroupListPage::make($response, 'release-group');
    }

    /**
     * Searches for tags and returns the result.
     *
     * @param TagFilter  $tagFilter  A tag filter
     * @param PageFilter $pageFilter A page filter
     *
     * @return TagListPage[]|TagListPage
     *
     * @throws Exception
     */
    public function tag(TagFilter $tagFilter, PageFilter $pageFilter): TagListPage
    {
        $params   = $this->getParameters($tagFilter, $pageFilter);
        $response = $this->httpAdapter->call('tag' . '/', $this->config, $params);

        return new TagListPage($response);
    }

    /**
     * Searches for works and returns the result.
     *
     * @param WorkFilter $workFilter A work filter
     * @param PageFilter $pageFilter A page filter
     *
     * @return SearchResult\Work[]|WorkListPage
     *
     * @throws Exception
     */
    public function work(WorkFilter $workFilter, PageFilter $pageFilter): WorkListPage
    {
        $params   = $this->getParameters($workFilter, $pageFilter);
        $response = $this->httpAdapter->call('work' . '/', $this->config, $params, false);

        return new WorkListPage($response);
    }

    /**
     * Returns a list of parameters.
     *
     * @param AbstractFilter $searchFilter A search filter
     * @param PageFilter     $pageFilter   A page filter
     *
     * @return array
     *
     * @throws Exception
     */
    private function getParameters(AbstractFilter $searchFilter, PageFilter $pageFilter): array
    {
        if (empty((string) $searchFilter)) {
            throw new Exception('The filter needs at least one argument to create a query.');
        }

        return [
            'limit'  => $pageFilter->getLimit(),
            'offset' => $pageFilter->getOffset(),
            'fmt'    => 'json',
            'query'  => (string) $searchFilter,
        ];
    }
}
