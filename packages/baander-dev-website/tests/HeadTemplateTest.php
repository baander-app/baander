<?php

declare(strict_types=1);

namespace Baander\DevWebsite\Tests;

use Baander\DevWebsite\Metadata\SearchMetadata;
use Dom\HTMLDocument;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HeadTemplateTest extends TestCase
{
    private const string ORIGIN = 'https://baander.app';

    /** @return iterable<string,array{SearchMetadata,string}> */
    public static function pages(): iterable
    {
        yield 'home' => [SearchMetadata::home(self::ORIGIN), '/'];
        yield 'manual chapter' => [SearchMetadata::page(self::ORIGIN, '/docs/operator-guide/', 'Operator manual', 'Run Bånder.', [], null), '/docs/operator-guide/'];
        yield 'not found' => [SearchMetadata::notFound(self::ORIGIN), '/404.html'];
    }

    #[DataProvider('pages')]
    public function testTheSiteIconIsTheFaviconMarkDrawnForSmallSizes(SearchMetadata $meta, string $url): void
    {
        $icons = self::head(['meta' => $meta, 'url' => $url])->querySelectorAll('link[rel~="icon"]');

        self::assertCount(1, $icons);
        $icon = $icons->item(0);
        self::assertNotNull($icon);
        self::assertSame('image/svg+xml', $icon->getAttribute('type'));
        self::assertSame('/assets/site/favicon.svg', $icon->getAttribute('href'));
        self::assertStringContainsString(
            'simplified for 16-32px',
            (string) file_get_contents(dirname(__DIR__, 3) . '/ui/web/public/favicon.svg'),
            'The linked file is the mark drawn for browser-tab sizes',
        );
    }

    /**
     * Renders the site head template with the variables the website builder gives every template.
     *
     * @param array{meta:SearchMetadata,url:string} $page
     */
    private static function head(array $page): HTMLDocument
    {
        $site = ['origin' => self::ORIGIN];
        $render = static function (string $template) use ($page, $site): string {
            ob_start();
            try {
                include $template;
                return (string) ob_get_clean();
            } catch (\Throwable $error) {
                ob_end_clean();
                throw $error;
            }
        };
        $html = $render(dirname(__DIR__, 3) . '/ui/site/templates/head.php') . '</body></html>';

        return HTMLDocument::createFromString($html, LIBXML_NOERROR);
    }
}
