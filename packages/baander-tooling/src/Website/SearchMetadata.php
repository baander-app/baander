<?php

declare(strict_types=1);

namespace Baander\Tooling\Website;

use League\CommonMark\Extension\CommonMark\Node\Block\Heading;
use League\CommonMark\Node\Block\Document;
use League\CommonMark\Node\Block\Paragraph;
use League\CommonMark\Node\Inline\Newline;
use League\CommonMark\Node\Node;
use League\CommonMark\Node\RawMarkupContainerInterface;
use League\CommonMark\Node\StringContainerInterface;

/**
 * What search engines and link previews read about one page: its title and description,
 * the breadcrumb trail to it, and the schema.org structured data that describes it.
 */
final readonly class SearchMetadata
{
    public const string SITE_NAME = 'Bånder';
    public const string REPOSITORY_URL = 'https://github.com/baander-app/baander';
    public const string SOCIAL_IMAGE = '/assets/site/social-card.png';
    public const array SOCIAL_IMAGE_SIZE = [1200, 630];
    private const string HOME_TITLE = 'Bånder — A home for your media';
    private const string HOME_DESCRIPTION = 'Browse and play your music and movies with Bånder, a media server you host. '
        . 'Read the installation and operator manuals.';
    private const int TITLE_LIMIT = 60;
    private const int DESCRIPTION_LIMIT = 155;
    /** Opening text shorter than this is extended with the following paragraph. */
    private const int DESCRIPTION_MINIMUM = 120;

    /**
     * @param list<array{name: string, url: string}> $breadcrumbs ancestors of the page, outermost first
     */
    private function __construct(
        public string $title,
        public string $description,
        public string $url,
        public string $type,
        public array $breadcrumbs,
        public ?string $structuredData,
        public bool $indexable = true,
    ) {}

    public static function home(string $origin): self
    {
        $url = $origin . '/';
        $software = [
            '@type' => 'SoftwareApplication',
            'name' => self::SITE_NAME,
            'alternateName' => 'Baander',
            'description' => self::HOME_DESCRIPTION,
            'url' => $url,
            'applicationCategory' => 'MultimediaApplication',
            'operatingSystem' => 'Linux',
            'license' => 'https://www.apache.org/licenses/LICENSE-2.0',
            'sameAs' => [self::REPOSITORY_URL],
            'image' => $origin . self::SOCIAL_IMAGE,
        ];
        $website = [
            '@type' => 'WebSite',
            'name' => self::SITE_NAME,
            'alternateName' => 'Baander',
            'url' => $url,
            'inLanguage' => 'en',
        ];

        return new self(self::HOME_TITLE, self::HOME_DESCRIPTION, $url, 'website', [], self::graph([$website, $software]));
    }

    /**
     * A manual or public page below the homepage.
     *
     * @param list<array{name: string, url: string}> $trail site-relative ancestors below the homepage
     */
    public static function page(
        string $origin,
        string $url,
        string $heading,
        string $description,
        array $trail,
        ?string $lastModified,
        string $schemaType = 'TechArticle',
    ): self {
        $breadcrumbs = [['name' => self::SITE_NAME, 'url' => '/'], ...$trail];
        $section = $trail === [] ? null : end($trail)['name'];
        $absolute = $origin . $url;
        $article = array_filter([
            '@type' => $schemaType,
            'headline' => $heading,
            'name' => $heading,
            'description' => $description,
            'url' => $absolute,
            'inLanguage' => 'en',
            'dateModified' => $lastModified,
            'isPartOf' => ['@type' => 'WebSite', 'name' => self::SITE_NAME, 'url' => $origin . '/'],
        ], static fn(mixed $value): bool => $value !== null);
        $list = [
            '@type' => 'BreadcrumbList',
            'itemListElement' => [...$breadcrumbs, ['name' => $heading, 'url' => $url]]
                |> (static fn(array $items): array => array_map(
                    static fn(array $item, int $index): array => [
                        '@type' => 'ListItem',
                        'position' => $index + 1,
                        'name' => $item['name'],
                        'item' => $origin . $item['url'],
                    ],
                    $items,
                    array_keys($items),
                )),
        ];

        return new self(
            self::title($heading, $section),
            $description,
            $absolute,
            'article',
            $breadcrumbs,
            self::graph([$article, $list]),
        );
    }

    public static function notFound(string $origin): self
    {
        return new self(
            'Page not found — ' . self::SITE_NAME,
            'This page does not exist. Return to the Bånder homepage or search the manuals.',
            $origin . '/404.html',
            'website',
            [],
            null,
            indexable: false,
        );
    }

    /**
     * The page's opening prose: top-level paragraphs before the first subsection, extended until
     * it says enough, as plain text cut at a word boundary.
     */
    public static function description(Document $document): ?string
    {
        $paragraphs = [];
        foreach ($document->children() as $node) {
            if ($node instanceof Heading && $node->getLevel() > 1 && $paragraphs !== []) {
                break;
            }
            if (!$node instanceof Paragraph) {
                continue;
            }
            $paragraphs[] = self::plainText($node);
            if (mb_strlen(implode(' ', $paragraphs)) >= self::DESCRIPTION_MINIMUM) {
                break;
            }
        }

        return $paragraphs === [] ? null : implode(' ', $paragraphs) |> self::shorten(...);
    }

    /** The page's name, then the section it belongs to while the whole stays short enough to show in full. */
    private static function title(string $heading, ?string $section): string
    {
        $full = implode(' — ', array_filter([$heading, $section, self::SITE_NAME]));

        return $section === null || mb_strlen($full) <= self::TITLE_LIMIT ? $full : $heading . ' — ' . self::SITE_NAME;
    }

    private static function plainText(Node $node): string
    {
        $text = '';
        foreach ($node->children() as $child) {
            $text .= match (true) {
                $child instanceof RawMarkupContainerInterface => '',
                $child instanceof Newline => ' ',
                $child instanceof StringContainerInterface => $child->getLiteral(),
                default => self::plainText($child),
            };
        }

        return $text;
    }

    private static function shorten(string $text): string
    {
        $text = preg_replace('/\s+/u', ' ', trim($text)) ?? $text;
        if (mb_strlen($text) <= self::DESCRIPTION_LIMIT) {
            return $text;
        }

        return mb_substr($text, 0, self::DESCRIPTION_LIMIT - 1)
            |> (static fn(string $cut): string => preg_replace('/\s+\S*$/u', '', $cut) ?? $cut)
            |> (static fn(string $cut): string => rtrim($cut, " ,;:—-") . '…');
    }

    /** @param list<array<string, mixed>> $nodes */
    private static function graph(array $nodes): string
    {
        // Escaping < and > keeps the JSON from closing its script element.
        return json_encode(
            ['@context' => 'https://schema.org', '@graph' => $nodes],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP,
        );
    }
}
