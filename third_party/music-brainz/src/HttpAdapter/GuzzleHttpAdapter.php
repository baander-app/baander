<?php

declare(strict_types=1);

namespace MusicBrainz\HttpAdapter;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use MusicBrainz\Config;
use MusicBrainz\Exception;

use function is_array;

/**
 * Adapter for Guzzle 7 HTTP Client.
 *
 * @see http://docs.guzzlephp.org
 */
class GuzzleHttpAdapter extends AbstractHttpAdapter
{
    /**
     * @var ClientInterface
     */
    private ClientInterface $client;

    /**
     * Initialize class
     *
     * @param ClientInterface $client A Guzzle HTTP client
     */
    public function __construct(ClientInterface $client)
    {
        $this->client = $client;
    }

    /**
     * Performs an HTTP request on MusicBrainz API.
     *
     * @param string $path
     * @param Config $config
     * @param array  $params
     * @param bool   $isAuthRequired
     *
     * @throws Exception
     *
     * @return array
     */
    public function call(
        string $path,
        Config $config,
        array $params = [],
        bool $isAuthRequired = false
    ): array {
        if (empty($config->getUserAgent())) {
            throw new Exception('You must set a valid User Agent before accessing the MusicBrainz API');
        }

        $requestOptions = [
            'headers' => [
                'Accept'     => 'application/json',
                'User-Agent' => $config->getUserAgent(),
            ],
            'query' => urldecode(http_build_query($params)),
        ];

        if ($isAuthRequired) {
            if (empty($config->getUsername()) || empty($config->getPassword())) {
                throw new Exception('Authentication is required');
            }

            $requestOptions['auth'] = [
                $config->getUsername(),
                $config->getPassword(),
                'digest',
            ];
        }

        /**
         * @todo Implement caching last request time, such that MusicBrainz rules are followed.
         *
         * @see https://musicbrainz.org/doc/XML_Web_Service/Rate_Limiting
         *
         * @todo Implement loop to retry in case of 503
         */
        try {
            $request = $this->client->request('GET', $config->getApiUrl() . '/' . $path, $requestOptions);
        } catch (GuzzleException $exception) {
            throw new Exception($exception->getMessage());
        }

        $array = json_decode($request->getBody()->getContents(), true);

        if (!is_array($array)) {
            throw new Exception('Unexpected response from the webservice: ' . $request->getBody()->getContents());
        }

        return $array;
    }
}
