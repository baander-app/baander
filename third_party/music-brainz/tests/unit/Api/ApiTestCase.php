<?php

declare(strict_types=1);

namespace MusicBrainz\Test\Api;

use MusicBrainz\HttpAdapter\AbstractHttpAdapter;
use MusicBrainz\MusicBrainz;
use PHPUnit\Framework\TestCase;
use PHPUnit_Framework_MockObject_MockObject;

/**
 * Unit tests for the lookup API.
 */
abstract class ApiTestCase extends TestCase
{
    /**
     * A mock object of the abstract HTTP adapter
     *
     * @var PHPUnit_Framework_MockObject_MockObject|AbstractHttpAdapter
     */
    private AbstractHttpAdapter $httpAdapter;

    /**
     * The MusicBrainz API client
     *
     * @var MusicBrainz
     */
    protected MusicBrainz $musicBrainz;

    /**
     * Sets up a mock object of the abstract HTTP adapter and the MusicBrainz API client to be tested.
     *
     * @return void
     */
    public function setUp(): void
    {
        $this->httpAdapter = $this->getMockBuilder('\MusicBrainz\HttpAdapter\AbstractHttpAdapter')
            ->onlyMethods(['call'])
            ->getMockForAbstractClass();

        $this->musicBrainz = new \MusicBrainz\MusicBrainz($this->httpAdapter);
    }

    /**
     * Setting up the mock object of the abstract HTTP adapter by expecting an API call and defining a fixture as result.
     *
     * @param string $path       An expected path
     * @param array  $parameters A list of expected parameters
     * @param string $fixture    A fixture placed in Fixtures/Search
     *
     * @return void
     */
    protected function expectApiCall(string $path, array $parameters, string $fixture): void
    {
        $this->httpAdapter->expects($this->once())
            ->method('call')
            ->with(
                $path,
                $this->musicBrainz->config(),
                $parameters
            )
            ->willReturn(
                json_decode(file_get_contents(__DIR__ . '/../Fixtures/' . $fixture), true)
            );
    }
}
