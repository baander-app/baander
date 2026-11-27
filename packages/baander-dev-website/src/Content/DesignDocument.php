<?php

declare(strict_types=1);

namespace Baander\DevWebsite\Content;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Block\Heading;
use League\CommonMark\Extension\CommonMark\Node\Block\HtmlBlock;
use League\CommonMark\Extension\CommonMark\Node\Block\ListBlock;
use League\CommonMark\Extension\CommonMark\Node\Inline\Code;
use League\CommonMark\Extension\CommonMark\Node\Inline\HtmlInline;
use League\CommonMark\Extension\CommonMark\Node\Inline\Link;
use League\CommonMark\Extension\Table\Table;
use League\CommonMark\Extension\Table\TableCell;
use League\CommonMark\Extension\Table\TableExtension;
use League\CommonMark\Extension\Table\TableRow;
use League\CommonMark\Extension\Table\TableSection;
use League\CommonMark\Node\Block\Document;
use League\CommonMark\Node\Inline\Newline;
use League\CommonMark\Node\Inline\Text;
use League\CommonMark\Node\Node;
use League\CommonMark\Parser\MarkdownParser;
use League\CommonMark\Renderer\HtmlRenderer;
use RuntimeException;

/**
 * A design document of the repository, such as `ui/DESIGN.md`, read by heading. A heading path names
 * a section by its heading and, before it, any of the headings it sits under, in order: `['Layout',
 * 'Border radius']` is the "Border radius" heading inside the "Layout" section. A section runs to the
 * next heading of the same or a higher level, and a path must name exactly one.
 *
 * The site publishes these documents' rules as they stand: tables and lists come back as text for
 * pages that add values beside them, and a whole section comes back rendered. A rendered section may
 * link only to `https:` addresses, because nothing rewrites a repository-relative link for the site,
 * and it may hold no raw HTML.
 */
final readonly class DesignDocument
{
    private function __construct(private string $path, private string $markdown, private Environment $environment) {}

    /** @param string $path the document's repository path */
    public static function load(string $root, string $path): self
    {
        $markdown = file_get_contents(rtrim($root, '/') . '/' . $path);
        if ($markdown === false) {
            throw new RuntimeException('The design document ' . $path . ' is missing.');
        }
        $environment = new Environment(['html_input' => 'escape', 'allow_unsafe_links' => false]);
        $environment->addExtension(new CommonMarkCoreExtension());
        $environment->addExtension(new TableExtension());

        return new self($path, $markdown, $environment);
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * The first table of a section, one row per body row, each cell as plain text under its column
     * name. Inline code keeps its text without the backticks.
     *
     * @param list<string> $headings
     * @param list<string> $columns the header cells the table must have, in order
     * @return list<array<string, string>>
     */
    public function table(array $headings, array $columns): array
    {
        $table = $this->first($headings, Table::class);
        $rows = [];
        foreach ($table->children() as $section) {
            if (!$section instanceof TableSection) {
                continue;
            }
            foreach ($section->children() as $row) {
                if (!$row instanceof TableRow) {
                    continue;
                }
                $cells = [...$row->children()]
                    |> (static fn(array $cells): array => array_filter($cells, static fn(Node $cell): bool => $cell instanceof TableCell))
                    |> (static fn(array $cells): array => array_map(self::text(...), $cells))
                    |> array_values(...);
                if ($section->isHead()) {
                    if ($cells !== $columns) {
                        throw $this->problem($headings, 'has a table with the columns ' . implode(', ', $cells) . ', not ' . implode(', ', $columns));
                    }
                    continue;
                }
                $rows[] = array_combine($columns, array_pad(array_slice($cells, 0, count($columns)), count($columns), ''));
            }
        }

        return $rows;
    }

    /**
     * The items of a section's first list, each as plain text.
     *
     * @param list<string> $headings
     * @return list<string>
     */
    public function items(array $headings): array
    {
        return [...$this->first($headings, ListBlock::class)->children()]
            |> (static fn(array $items): array => array_map(self::text(...), $items))
            |> array_values(...);
    }

    /**
     * A section rendered to HTML without its own heading. Headings inside it move up so that its
     * direct subsections become `h3`, below the `h2` the page sets for the section's title.
     *
     * @param list<string> $headings
     * @param bool $tables false leaves the section's tables out, for a page that renders them with values
     * @param ?string $from the opening text of the block the borrowed part starts at; null starts at the section's first block
     * @return array{title: string, html: string}
     */
    public function section(array $headings, bool $tables = true, ?string $from = null): array
    {
        [$heading, $blocks] = $this->blocks($headings, $this->parse());
        if ($from !== null) {
            $start = array_find_key($blocks, static fn(Node $block): bool => str_starts_with(self::text($block), $from))
                ?? throw $this->problem($headings, 'has no block starting with "' . $from . '"');
            $blocks = array_slice($blocks, $start);
        }
        if (!$tables) {
            $blocks = array_values(array_filter($blocks, static fn(Node $block): bool => !$block instanceof Table));
        }
        foreach ($blocks as $block) {
            $this->checkBorrowable($headings, $block);
        }
        foreach ($blocks as $block) {
            foreach ($block->iterator() as $node) {
                if ($node instanceof Heading) {
                    $node->setLevel(min(6, $node->getLevel() - $heading->getLevel() + 2));
                }
            }
        }

        return ['title' => self::text($heading), 'html' => (string) new HtmlRenderer($this->environment)->renderNodes($blocks)];
    }

    /**
     * @template T of Node
     * @param list<string> $headings
     * @param class-string<T> $type
     * @return T
     */
    private function first(array $headings, string $type): Node
    {
        [, $blocks] = $this->blocks($headings, $this->parse());
        $found = array_find($blocks, static fn(Node $block): bool => $block instanceof $type);
        if (!$found instanceof $type) {
            throw $this->problem($headings, 'has no ' . (new \ReflectionClass($type))->getShortName() . ' block');
        }

        return $found;
    }

    /**
     * The heading a path names and the blocks of its section.
     *
     * @param list<string> $headings
     * @return array{Heading, list<Node>}
     */
    private function blocks(array $headings, Document $document): array
    {
        if ($headings === []) {
            throw new RuntimeException('A section of ' . $this->path . ' needs a heading path.');
        }
        $top = [...$document->children()];
        $ancestors = [];
        $matches = [];
        foreach ($top as $index => $block) {
            if (!$block instanceof Heading) {
                continue;
            }
            $ancestors = array_filter($ancestors, static fn(int $level): bool => $level < $block->getLevel(), ARRAY_FILTER_USE_KEY);
            $title = self::text($block);
            if ($title === end($headings) && self::within(array_values($ancestors), array_slice($headings, 0, -1))) {
                $matches[] = $index;
            }
            $ancestors[$block->getLevel()] = $title;
            ksort($ancestors);
        }
        if (count($matches) !== 1) {
            throw $this->problem($headings, $matches === [] ? 'does not exist' : 'names ' . count($matches) . ' sections');
        }
        $heading = $top[$matches[0]];
        assert($heading instanceof Heading);
        $blocks = [];
        foreach (array_slice($top, $matches[0] + 1) as $block) {
            if ($block instanceof Heading && $block->getLevel() <= $heading->getLevel()) {
                break;
            }
            $blocks[] = $block;
        }

        return [$heading, $blocks];
    }

    /**
     * Whether the outer headings appear among the ancestors in the same order.
     *
     * @param list<string> $ancestors
     * @param list<string> $outer
     */
    private static function within(array $ancestors, array $outer): bool
    {
        foreach ($ancestors as $ancestor) {
            if ($outer !== [] && $ancestor === $outer[0]) {
                array_shift($outer);
            }
        }

        return $outer === [];
    }

    /** @param list<string> $headings */
    private function checkBorrowable(array $headings, Node $block): void
    {
        foreach ($block->iterator() as $node) {
            if ($node instanceof Link && !str_starts_with($node->getUrl(), 'https://')) {
                throw $this->problem($headings, 'links to ' . $node->getUrl() . ', which the site cannot resolve; link to an https: address');
            }
            if ($node instanceof HtmlBlock || $node instanceof HtmlInline) {
                throw $this->problem($headings, 'holds raw HTML');
            }
        }
    }

    private function parse(): Document
    {
        return new MarkdownParser($this->environment)->parse($this->markdown);
    }

    /** The node's text, with inline code as its literal and line breaks as spaces. */
    private static function text(Node $node): string
    {
        $text = '';
        foreach ($node->iterator() as $child) {
            $text .= match (true) {
                $child instanceof Text, $child instanceof Code => $child->getLiteral(),
                $child instanceof Newline => ' ',
                default => '',
            };
        }

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /** @param list<string> $headings */
    private function problem(array $headings, string $problem): RuntimeException
    {
        return new RuntimeException($this->path . ' › ' . implode(' › ', $headings) . ' ' . $problem . '.');
    }
}
