<?php

declare(strict_types=1);

namespace Baander\Tooling\Tests\Website;

use Baander\Tooling\Website\Sitemap;
use PHPUnit\Framework\TestCase;

final class SitemapTest extends TestCase
{
    public function testListsSortedAbsoluteUrlsWithOptionalLastModified(): void
    {
        $xml = Sitemap::xml('https://baander.app/', [
            ['url' => '/docs/', 'lastModified' => '2026-11-04T17:00:00+01:00'],
            ['url' => '/', 'lastModified' => null],
            ['url' => '/docs/a&b/', 'lastModified' => null],
        ]);
        $document = new \DOMDocument();
        self::assertTrue($document->loadXML($xml));
        self::assertSame('http://www.sitemaps.org/schemas/sitemap/0.9', $document->documentElement?->namespaceURI);
        $xpath = new \DOMXPath($document);
        $xpath->registerNamespace('s', 'http://www.sitemaps.org/schemas/sitemap/0.9');
        $locations = array_map(static fn(\DOMNode $node): string => $node->textContent, iterator_to_array($xpath->query('//s:loc')));
        self::assertSame(['https://baander.app/', 'https://baander.app/docs/', 'https://baander.app/docs/a&b/'], $locations);
        self::assertSame(['2026-11-04T17:00:00+01:00'], array_map(static fn(\DOMNode $node): string => $node->textContent, iterator_to_array($xpath->query('//s:lastmod'))));
    }

    public function testRejectsDuplicateAndRelativeUrls(): void
    {
        foreach ([[['url' => '/', 'lastModified' => null], ['url' => '/', 'lastModified' => null]], [['url' => 'docs/', 'lastModified' => null]]] as $entries) {
            try {
                Sitemap::xml('https://baander.app', $entries);
                self::fail('Expected an invalid sitemap entry to be rejected');
            } catch (\RuntimeException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testRobotsPointsCrawlersToTheSitemap(): void
    {
        self::assertSame("User-agent: *\nAllow: /\nDisallow: /pagefind/\n\nSitemap: https://baander.app/sitemap.xml\n", Sitemap::robots('https://baander.app/'));
    }
}
