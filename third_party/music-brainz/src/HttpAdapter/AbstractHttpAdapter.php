<?php

declare(strict_types=1);

namespace MusicBrainz\HttpAdapter;

use MusicBrainz\Config;

/**
 * MusicBrainz HTTP Client interface
 */
abstract class AbstractHttpAdapter
{
    /**
     * @var string
     */
    public string $endpoint = 'https://musicbrainz.org/ws/2';

    /**
     * Perform an HTTP request on MusicBrainz
     *
     * @param  string  $path
     * @param  Config  $config
     * @param  array   $params
     * @param  boolean $isAuthRequired
     *
     * @return array
     */
    abstract public function call(
        string $path,
        Config $config,
        array $params = [],
        bool $isAuthRequired = false
    ): array;
}
