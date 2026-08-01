<?php

declare(strict_types=1);

namespace App\Tests\Functional\Metadata\Application;

use App\Metadata\Application\AlbumMetadataEnricher;
use App\Tests\Functional\TestCase;

final class AlbumMetadataEnricherTest extends TestCase
{
    private AlbumMetadataEnricher $enricher;

    protected function setUp(): void
    {
        parent::setUp();

        $container = static::getContainer();
        $this->enricher = $container->get(AlbumMetadataEnricher::class);
    }

    public function testNormalizeTitleWithDisambiguationExtractsSuffix(): void
    {
        $method = new \ReflectionMethod($this->enricher, 'normalizeTitleWithDisambiguation');

        $title = 'Ten Thousand Fists [Reprise Records,9362-49433-2,EU]';
        [$normalizedTitle, $extractedData] = $method->invoke($this->enricher, $title);

        $this->assertSame('Ten Thousand Fists', $normalizedTitle);
        $this->assertSame('Reprise Records', $extractedData['label']);
        $this->assertSame('9362-49433-2', $extractedData['catalogNumber']);
        $this->assertSame('EU', $extractedData['country']);
    }

    public function testNormalizeTitleWithDisambiguationHandlesTitleWithoutSuffix(): void
    {
        $method = new \ReflectionMethod($this->enricher, 'normalizeTitleWithDisambiguation');

        $title = 'Ten Thousand Fists';
        [$normalizedTitle, $extractedData] = $method->invoke($this->enricher, $title);

        $this->assertSame('Ten Thousand Fists', $normalizedTitle);
        $this->assertSame([], $extractedData);
    }

    public function testNormalizeTitleWithDisambiguationHandlesMalformedSuffix(): void
    {
        $method = new \ReflectionMethod($this->enricher, 'normalizeTitleWithDisambiguation');

        $title = 'Album Title [invalid format';
        [$normalizedTitle, $extractedData] = $method->invoke($this->enricher, $title);

        $this->assertSame('Album Title [invalid format', $normalizedTitle);
        $this->assertSame([], $extractedData);
    }

    public function testNormalizeTitleWithDisambiguationHandlesPartialSuffix(): void
    {
        $method = new \ReflectionMethod($this->enricher, 'normalizeTitleWithDisambiguation');

        $title = 'Album [Label]';
        [$normalizedTitle, $extractedData] = $method->invoke($this->enricher, $title);

        $this->assertSame('Album', $normalizedTitle);
        $this->assertSame('Label', $extractedData['label']);
        $this->assertArrayNotHasKey('catalogNumber', $extractedData);
        $this->assertArrayNotHasKey('country', $extractedData);
    }

    public function testNormalizeTitleWithDisambiguationHandlesWhitespace(): void
    {
        $method = new \ReflectionMethod($this->enricher, 'normalizeTitleWithDisambiguation');

        $title = 'Album [ Label , Catalog# ] ';
        [$normalizedTitle, $extractedData] = $method->invoke($this->enricher, $title);

        $this->assertSame('Album', $normalizedTitle);
        $this->assertSame('Label', $extractedData['label']);
        $this->assertSame('Catalog#', $extractedData['catalogNumber']);
    }

    public function testNormalizeTitleWithDisambiguationHandlesEmptyParts(): void
    {
        $method = new \ReflectionMethod($this->enricher, 'normalizeTitleWithDisambiguation');

        $title = 'Album [Label,,Country]';
        [$normalizedTitle, $extractedData] = $method->invoke($this->enricher, $title);

        $this->assertSame('Album', $normalizedTitle);
        $this->assertSame('Label', $extractedData['label']);
        // catalogNumber is empty string, should not be set
        $this->assertArrayNotHasKey('catalogNumber', $extractedData);
        $this->assertSame('Country', $extractedData['country']);
    }
}
