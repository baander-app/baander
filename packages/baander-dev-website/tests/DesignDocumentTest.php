<?php

declare(strict_types=1);

namespace Baander\DevWebsite\Tests;

use Baander\DevWebsite\Content\DesignDocument;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DesignDocumentTest extends TestCase
{
    private const string DOCUMENT = <<<'MARKDOWN'
        # Design

        ## Layout

        ### Spacing

        - Page padding: `24px` — applied
          by each page.
        - Section gap: `32px`

        ### Surfaces

        Surfaces are flat.

        | Token | Use |
        |---|---|
        | `--color-card` | Cards |
        | `--color-muted` / `--color-secondary` | Subtle surfaces |

        #### Shadows

        Only overlays cast one.

        Put one item in each element.

        ## Color

        ### Spacing

        Colors have no spacing.

        ### Links

        See [the archive](https://baander.app/archive) or [the rules](../rules.md).

        ### Markup

        A <b>bold</b> claim.

        ## Motion

        Motion is brief.
        MARKDOWN;

    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/baander-design-document-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/ui', 0700, true);
        file_put_contents($this->root . '/ui/DESIGN.md', self::DOCUMENT);
    }

    protected function tearDown(): void
    {
        unlink($this->root . '/ui/DESIGN.md');
        rmdir($this->root . '/ui');
        rmdir($this->root);
    }

    public function testATableComesBackAsTextUnderItsColumnNames(): void
    {
        self::assertSame(
            [
                ['Token' => '--color-card', 'Use' => 'Cards'],
                ['Token' => '--color-muted / --color-secondary', 'Use' => 'Subtle surfaces'],
            ],
            $this->document()->table(['Layout', 'Surfaces'], ['Token', 'Use']),
        );
    }

    public function testATableWithOtherColumnsFailsNamingThem(): void
    {
        $this->expectExceptionMessage('ui/DESIGN.md › Layout › Surfaces has a table with the columns Token, Use, not Variable, Use.');
        $this->document()->table(['Layout', 'Surfaces'], ['Variable', 'Use']);
    }

    public function testListItemsComeBackAsTextWithLineBreaksAsSpaces(): void
    {
        self::assertSame(
            ['Page padding: 24px — applied by each page.', 'Section gap: 32px'],
            $this->document()->items(['Layout', 'Spacing']),
        );
    }

    public function testASectionLeavesOutItsHeadingAndMovesItsSubsectionsUpToH3(): void
    {
        $section = $this->document()->section(['Layout', 'Surfaces']);

        self::assertSame('Surfaces', $section['title']);
        self::assertStringNotContainsString('<h3>Surfaces', $section['html']);
        self::assertStringContainsString('<h3>Shadows</h3>', $section['html']);
        self::assertStringContainsString('<code>--color-card</code>', $section['html']);
        self::assertStringContainsString('Only overlays cast one.', $section['html']);
        self::assertStringNotContainsString('Colors have no spacing', $section['html']);
    }

    public function testASectionCanLeaveOutItsTablesAndStartAtAGivenBlock(): void
    {
        $document = $this->document();

        self::assertStringNotContainsString('<table>', $document->section(['Layout', 'Surfaces'], tables: false)['html']);
        self::assertSame(
            '<p>Put one item in each element.</p>',
            $document->section(['Layout', 'Surfaces', 'Shadows'], from: 'Put one item')['html'],
        );
    }

    public function testAnOuterHeadingTellsTwoSectionsOfTheSameNameApart(): void
    {
        self::assertStringContainsString('Colors have no spacing', $this->document()->section(['Color', 'Spacing'])['html']);
    }

    public function testAnHttpsLinkIsKept(): void
    {
        file_put_contents($this->root . '/ui/DESIGN.md', str_replace(' or [the rules](../rules.md)', '', self::DOCUMENT));

        self::assertStringContainsString(
            '<a href="https://baander.app/archive">the archive</a>',
            $this->document()->section(['Color', 'Links'])['html'],
        );
    }

    /** @return iterable<string, array{list<string>, string}> */
    public static function unusableSections(): iterable
    {
        yield 'missing' => [['Layout', 'Grids'], 'ui/DESIGN.md › Layout › Grids does not exist.'];
        yield 'ambiguous' => [['Spacing'], 'ui/DESIGN.md › Spacing names 2 sections.'];
        yield 'relative link' => [['Color', 'Links'], 'ui/DESIGN.md › Color › Links links to ../rules.md, which the site cannot resolve; link to an https: address.'];
        yield 'raw markup' => [['Color', 'Markup'], 'ui/DESIGN.md › Color › Markup holds raw HTML.'];
    }

    /** @param list<string> $headings */
    #[DataProvider('unusableSections')]
    public function testASectionThatCannotBeBorrowedFailsNamingTheFileAndHeadings(array $headings, string $message): void
    {
        $this->expectExceptionMessage($message);
        $this->document()->section($headings);
    }

    public function testAMissingStartingBlockFailsNamingIt(): void
    {
        $this->expectExceptionMessage('ui/DESIGN.md › Motion has no block starting with "Motion is slow".');
        $this->document()->section(['Motion'], from: 'Motion is slow');
    }

    public function testTheRepositoryDesignDocumentEndsASectionAtTheNextHeadingOfItsLevel(): void
    {
        $spacing = DesignDocument::load(dirname(__DIR__, 3), 'ui/DESIGN.md')->section(['Layout', 'Spacing'])['html'];

        self::assertStringContainsString('Page padding', $spacing);
        self::assertStringNotContainsString('Families', $spacing);
    }

    private function document(): DesignDocument
    {
        return DesignDocument::load($this->root, 'ui/DESIGN.md');
    }
}
