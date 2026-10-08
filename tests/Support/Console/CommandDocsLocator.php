<?php

declare(strict_types=1);

namespace App\Tests\Support\Console;

/**
 * Finds the operator docs page of a console command.
 *
 * A command has its own page named after it, with each `:` replaced by `-`
 * (`app:rate-limiter:list` → `app-rate-limiter-list.md`). Commands documented
 * together on a family page instead have a row in the commands index
 * (`README.md`) linking to a heading on that page, such as
 * `app-settings.md#appsettingsget`; the linked file and heading must exist.
 */
final readonly class CommandDocsLocator
{
    public function __construct(
        private string $commandsDirectory,
    ) {
    }

    public static function forProject(): self
    {
        return new self(dirname(__DIR__, 3) . '/docs-book/part-1-operator-guide/commands');
    }

    /**
     * @return string|null the page relative to the commands directory, with an
     *                     anchor when the command is a section of a family page
     */
    public function pageFor(string $command): ?string
    {
        $ownPage = str_replace(':', '-', $command) . '.md';
        if (is_file($this->commandsDirectory . '/' . $ownPage)) {
            return $ownPage;
        }

        $index = $this->read('README.md');
        $pattern = '/\[' . preg_quote($command, '/') . '\]\(([^)#\s]+\.md)(?:#([^)\s]+))?\)/';
        if ($index === null || preg_match_all($pattern, $index, $links, PREG_SET_ORDER) === 0) {
            return null;
        }

        foreach ($links as $link) {
            $page = $this->read($link[1]);
            if ($page === null) {
                continue;
            }
            $anchor = $link[2] ?? '';
            if ($anchor === '') {
                return $link[1];
            }
            if (in_array($anchor, $this->headingAnchors($page), true)) {
                return $link[1] . '#' . $anchor;
            }
        }

        return null;
    }

    private function read(string $page): ?string
    {
        $path = $this->commandsDirectory . '/' . $page;
        if (!is_file($path)) {
            return null;
        }
        $contents = file_get_contents($path);

        return $contents === false ? null : $contents;
    }

    /**
     * Anchors as GitHub and mdBook derive them: lowercase, punctuation removed,
     * spaces turned into hyphens.
     *
     * @return list<string>
     */
    private function headingAnchors(string $markdown): array
    {
        preg_match_all('/^#{1,6}\s+(.+?)\s*#*\s*$/m', $markdown, $headings);

        return array_map(
            static fn (string $heading): string => str_replace(
                ' ',
                '-',
                (string) preg_replace('/[^\p{L}\p{N}\s_-]/u', '', mb_strtolower(trim($heading))),
            ),
            $headings[1],
        );
    }
}
