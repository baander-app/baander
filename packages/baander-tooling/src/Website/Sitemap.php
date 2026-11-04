<?php

declare(strict_types=1);

namespace Baander\Tooling\Website;

use RuntimeException;

/** Writes the sitemap protocol document and the robots.txt that points crawlers to it. */
final class Sitemap
{
    private const string NAMESPACE = 'http://www.sitemaps.org/schemas/sitemap/0.9';

    /**
     * @param list<array{url: string, lastModified: ?string}> $entries site-relative URLs
     */
    public static function xml(string $origin, array $entries): string
    {
        $origin = rtrim($origin, '/');
        $urls = $entries
            |> self::sorted(...)
            |> (static fn(array $entries): array => array_map(
                static fn(array $entry): string => self::url($origin . $entry['url'], $entry['lastModified']),
                $entries,
            ))
            |> implode(...);

        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<urlset xmlns="' . self::NAMESPACE . '">' . "\n"
            . $urls
            . "</urlset>\n";
    }

    public static function robots(string $origin): string
    {
        $sitemap = rtrim($origin, '/') . '/sitemap.xml';

        return <<<ROBOTS
            User-agent: *
            Allow: /

            Sitemap: {$sitemap}

            ROBOTS;
    }

    /**
     * @param list<array{url: string, lastModified: ?string}> $entries
     * @return list<array{url: string, lastModified: ?string}>
     */
    private static function sorted(array $entries): array
    {
        usort($entries, static fn(array $a, array $b): int => $a['url'] <=> $b['url']);
        foreach ($entries as $index => $entry) {
            if (!str_starts_with($entry['url'], '/') || $entry['url'] === ($entries[$index - 1]['url'] ?? null)) {
                throw new RuntimeException('Invalid or duplicate sitemap URL: ' . $entry['url']);
            }
        }

        return $entries;
    }

    private static function url(string $location, ?string $lastModified): string
    {
        $lastmod = $lastModified === null ? '' : '<lastmod>' . self::escape($lastModified) . '</lastmod>';

        return '  <url><loc>' . self::escape($location) . '</loc>' . $lastmod . "</url>\n";
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
