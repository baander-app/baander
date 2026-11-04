<?php

declare(strict_types=1);

namespace Baander\Tooling\Tests\Website;

use Baander\Tooling\Website\SearchMetadata;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Parser\MarkdownParser;
use PHPUnit\Framework\TestCase;

final class SearchMetadataTest extends TestCase
{
    public function testDescriptionIsTheOpeningProseAsPlainText(): void
    {
        $description = $this->description(<<<'MD'
            # Enable

            Enable a disabled account with `app:user:enable`. Read
            [the guide](guide.md) <span>first</span>.

            ## Usage

            Later text is not part of the description.
            MD);
        self::assertSame('Enable a disabled account with app:user:enable. Read the guide first.', $description);
    }

    public function testShortOpeningsBorrowTheNextParagraphAndLongOnesStopAtAWord(): void
    {
        self::assertSame('Short opening. Second paragraph adds context.', $this->description("# T\n\nShort opening.\n\nSecond paragraph adds context."));
        $long = $this->description('# T' . "\n\n" . str_repeat('measured words ', 30));
        self::assertLessThanOrEqual(155, mb_strlen($long));
        self::assertStringEndsWith('words…', $long);
        self::assertNull($this->description("# Only a heading\n\n- a list item"));
    }

    public function testManualPageTitlesNameTheirSectionWhileTheyFit(): void
    {
        $page = SearchMetadata::page('https://baander.app', '/docs/a/', 'app:user:enable', 'Enables a user.', [['name' => 'CLI Commands', 'url' => '/docs/c/']], null);
        self::assertSame('app:user:enable — CLI Commands — Bånder', $page->title);
        $long = SearchMetadata::page('https://baander.app', '/docs/b/', str_repeat('Long heading ', 4), 'D.', [['name' => 'Operator manual', 'url' => '/docs/o/']], null);
        self::assertSame(str_repeat('Long heading ', 4) . ' — Bånder', $long->title);
        self::assertSame([['name' => 'Bånder', 'url' => '/'], ['name' => 'CLI Commands', 'url' => '/docs/c/']], $page->breadcrumbs);
    }

    public function testStructuredDataIsValidJsonThatCannotCloseItsScriptElement(): void
    {
        $page = SearchMetadata::page('https://baander.app', '/docs/x/', '</script><b>', 'A & B', [], '2026-11-04T10:00:00+00:00');
        self::assertStringNotContainsString('</script', $page->structuredData);
        self::assertStringNotContainsString('<b>', $page->structuredData);
        $graph = json_decode($page->structuredData, true, flags: JSON_THROW_ON_ERROR)['@graph'];
        self::assertSame('TechArticle', $graph[0]['@type']);
        self::assertSame('</script><b>', $graph[0]['headline']);
        self::assertSame('2026-11-04T10:00:00+00:00', $graph[0]['dateModified']);
        self::assertSame(['Bånder', '</script><b>'], array_column($graph[1]['itemListElement'], 'name'));
        self::assertSame('https://baander.app/docs/x/', $graph[1]['itemListElement'][1]['item']);
        self::assertArrayNotHasKey('dateModified', json_decode(
            SearchMetadata::page('https://baander.app', '/docs/y/', 'Y', 'D', [], null)->structuredData,
            true,
            flags: JSON_THROW_ON_ERROR,
        )['@graph'][0]);
    }

    public function testHomeDescribesTheSiteAndSoftwareAndTheNotFoundPageIsNotIndexed(): void
    {
        $home = SearchMetadata::home('https://baander.app');
        $types = json_decode($home->structuredData, true, flags: JSON_THROW_ON_ERROR)['@graph'] |> (static fn(array $graph): array => array_column($graph, '@type'));
        self::assertSame(['WebSite', 'SoftwareApplication'], $types);
        self::assertSame('https://baander.app/', $home->url);
        $missing = SearchMetadata::notFound('https://baander.app');
        self::assertFalse($missing->indexable);
        self::assertNull($missing->structuredData);
    }

    private function description(string $markdown): ?string
    {
        $environment = new Environment();
        $environment->addExtension(new CommonMarkCoreExtension());

        return new MarkdownParser($environment)->parse($markdown) |> SearchMetadata::description(...);
    }
}
