<?php

declare(strict_types=1);

namespace MusicBrainz\SpecificationTest;

use DirectoryIterator;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use PHPUnit\Framework\TestCase;
use SplFileInfo;
use Symfony\Component\DomCrawler\Crawler;

use function array_keys;
use function array_map;
use function explode;
use function implode;
use function sort;
use function str_starts_with;
use function strpos;
use function substr;
use function ucfirst;

class RelationTest extends TestCase
{
    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = new Client();
    }

    /**
     * This test reads the documentation for {@see https://musicbrainz.org/relationships relationships} and
     * counts the linked pages for more specific relationship types (area-area, area-event...).
     *
     * For the 12 main entities of MusicBrainz, there should be 91 (12+11+10...) possible relationship types.
     *
     * @return string[] Links to overview pages for each relationship type (area-area, area-event...)
     *
     * @throws GuzzleException
     */
    public function testCountPossibleRelationshipTypes(): array
    {
        $response = $this->client->get('https://musicbrainz.org/relationships');
        $crawler = new Crawler($response->getBody()->getContents());
        $links = $crawler->filterXPath('//a');

        foreach ($links as $link) {
            $href = $link->getAttribute('href');
            if (str_starts_with($href, '/relationships/')) {
                $hrefs[] = $href;
            }
        }

        self::assertCount(91, $hrefs ?? []);

        return $hrefs;
    }

    /**
     * @depends testCountPossibleRelationshipTypes
     *
     * In general there are relationships between the main entities of MusicBrainz, but for some pairs of
     * entities, there are no relationships defined (yet).
     * When this test fails, there might be new relationships that have to be implemented in the client.
     *
     * @param string[] $hrefs Links to overview pages for each relationship type (area-area, area-event...)
     *
     * @return array<string, Crawler> Links to overview pages for each relationship type (area-area, area-event...)
     *                                and associated crawler
     *
     * @throws GuzzleException
     */
    public function testCountActualRelationshipTypes(array $hrefs): array
    {
        foreach ($hrefs as $href) {
            $response = $this->client->get('https://musicbrainz.org/' . $href);
            $crawler = new Crawler($response->getBody()->getContents());
            /**
             * For some combination of entities, there are no relations defined. We can ignore these pages.
             *
             * @example https://musicbrainz.org/relationships/area-artist
             */
            if (strpos($crawler->text(), 'relationship types found.') === false) {
                $relationTypes[$href] = $crawler;
            }
        }

        self::assertCount(65, $relationTypes);

        return $relationTypes;
    }

    /**
     * @depends testCountActualRelationshipTypes
     *
     * Tests that for each relationship type (area-area, area-event...) there is a corresponding folder
     * in `src/Relation/Type`.
     *
     * @param array<string, Crawler> $relationTypes Links to overview pages for each relationship type
     *                                              (area-area, area-event...) and associated crawler
     *
     * @return array<string, Crawler> Links to overview pages for each relationship type (area-area, area-event...)
     *                                 and associated crawler
     */
    public function testCountRelationTypeDirectories(array $relationTypes): array
    {
        $directories = self::getDirectories(__DIR__ . '/../../src/Relation/Type/');

        $expectedDirectories = array_map(
            function (string $path): string {
                [$baseType, $targetType] = explode('-', substr($path, 15));

                return self::upperCase($baseType) . '/' . self::upperCase($targetType);
            },
            array_keys($relationTypes)
        );

        sort($expectedDirectories);
        sort($directories);

        self::assertSame(
            $expectedDirectories,
            $directories
        );

        return $relationTypes;
    }

    private static function upperCase(string $string): string
    {
        return implode(
            array_map(
                fn(string $string): string => ucfirst($string),
                explode('_', $string)
            )
        );
    }

    private static function getDirectories(string $path, int $minDepth = 2, int $maxDepth = 2): array
    {
        $maxDepth--;
        $minDepth--;

        $dir = new DirectoryIterator($path);
        foreach ($dir as $fileinfo) {
            /** @var SplFileInfo $fileinfo */
            if ($fileinfo->isDir() && !$fileinfo->isDot()) {
                if ($minDepth <= 0) {
                    $directories[] = $fileinfo->getFilename();
                }
                if ($maxDepth > 0) {
                    foreach (self::getDirectories($fileinfo->getRealPath(), $minDepth, $maxDepth) as $subDir) {
                        $directories[] = $fileinfo->getFilename() . '/' . $subDir;
                    }
                }
            }
        }

        return $directories ?? [];
    }
}
