<?php

declare(strict_types=1);

namespace MusicBrainz;

use MusicBrainz\Api\Browse;
use MusicBrainz\Api\Lookup;
use MusicBrainz\Api\Search;
use MusicBrainz\HttpAdapter\AbstractHttpAdapter;

/**
 * Provides methods to get classes for each type of API call: browse, lookup and search.
 *
 * @see https://musicbrainz.org/doc/Development/XML_Web_Service/Version_2/
 */
class Api
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
     * Returns the browse API.
     *
     * @return Browse
     */
    public function browse()
    {
        return new Browse($this->httpAdapter, $this->config);
    }

    /**
     * Returns the lookup API.
     *
     * @return Lookup
     */
    public function lookup()
    {
        return new Lookup($this->httpAdapter, $this->config);
    }

    /**
     * Returns the search API.
     *
     * @return Search
     */
    public function search()
    {
        return new Search($this->httpAdapter, $this->config);
    }
}
