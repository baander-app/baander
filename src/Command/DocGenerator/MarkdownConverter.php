<?php

declare(strict_types=1);

namespace App\Command\DocGenerator;

use Parsedown;

final class MarkdownConverter
{
    private const DIRECTORY_MAP = [
        'part-1-operator-guide' => 'operator-guide',
        'part-2-developer-guide' => 'developer-guide',
    ];

    private Parsedown $parsedown;

    public function __construct()
    {
        $this->parsedown = new Parsedown();
    }

    /**
     * @return list<string> list of generated HTML file paths (relative to output dir)
     */
    public function convert(string $docsBookDir, string $outputDir, string $phpdocDir): array
    {
        $convertedPages = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($docsBookDir, \RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'md') {
                continue;
            }

            $relativePath = substr($file->getRealPath(), strlen(realpath($docsBookDir)) + 1);
            $outputPath = $this->mapOutputPath($relativePath, $outputDir);

            $outputDirPath = dirname($outputPath);
            if (!is_dir($outputDirPath)) {
                mkdir($outputDirPath, 0755, true);
            }

            $markdown = file_get_contents($file->getRealPath());
            $markdown = $this->rewriteLinks($markdown, $relativePath);
            $html = $this->parsedown->text($markdown);

            $pageHtml = $this->wrapInTemplate($html, $this->extractTitle($markdown), $outputDir, $outputPath);

            file_put_contents($outputPath, $pageHtml);
            $convertedPages[] = substr($outputPath, strlen($outputDir) + 1);
        }

        return $convertedPages;
    }

    private function mapOutputPath(string $relativePath, string $outputDir): string
    {
        $parts = explode('/', $relativePath);
        $topDir = $parts[0];

        if (isset(self::DIRECTORY_MAP[$topDir])) {
            $parts[0] = self::DIRECTORY_MAP[$topDir];
        }

        $mapped = implode('/', $parts);

        if (basename($mapped) === 'README.md') {
            $mapped = substr($mapped, 0, -strlen('README.md')) . 'index.html';
        } else {
            $mapped = substr($mapped, 0, -3) . '.html';
        }

        $rootReadme = $outputDir . '/index.html';
        if ($relativePath === 'README.md') {
            return $rootReadme;
        }

        return $outputDir . '/' . $mapped;
    }

    private function rewriteLinks(string $markdown, string $sourceRelativePath): string
    {
        $docsBookDir = dirname($sourceRelativePath);

        return preg_replace_callback(
            '/\[([^\]]+)\]\(([^)]+\.md)(#[^)]*)?\)/',
            function (array $matches) use ($docsBookDir): string {
                $text = $matches[1];
                $link = $matches[2];
                $fragment = $matches[3] ?? '';

                if (str_starts_with($link, 'http://') || str_starts_with($link, 'https://')) {
                    return $matches[0];
                }

                if (str_starts_with($link, '../')) {
                    $resolved = $this->resolveRelativePath($docsBookDir, $link);

                    if ($resolved === null || str_starts_with($resolved, '../')) {
                        return $text . ' *(external)*';
                    }

                    return '[' . $text . '](' . $this->convertToHtmlPath($resolved) . $fragment . ')';
                }

                return '[' . $text . '](' . $this->convertToHtmlPath($link) . $fragment . ')';
            },
            $markdown,
        );
    }

    private function resolveRelativePath(string $fromDir, string $link): ?string
    {
        $parts = explode('/', $fromDir);
        $linkParts = explode('/', $link);

        foreach ($linkParts as $part) {
            if ($part === '..') {
                if (empty($parts)) {
                    return null;
                }
                array_pop($parts);
            } elseif ($part !== '.' && $part !== '') {
                $parts[] = $part;
            }
        }

        $resolved = implode('/', $parts);

        if (str_starts_with($resolved, '../')) {
            return null;
        }

        return $resolved;
    }

    private function convertToHtmlPath(string $path): string
    {
        foreach (self::DIRECTORY_MAP as $from => $to) {
            $path = str_replace($from . '/', $to . '/', $path);
        }

        if (str_ends_with($path, '/README.md')) {
            $path = substr($path, 0, -strlen('README.md')) . 'index.html';
        } elseif (str_ends_with($path, '.md')) {
            $path = substr($path, 0, -3) . '.html';
        }

        return $path;
    }

    private function extractTitle(string $markdown): string
    {
        if (preg_match('/^#\s+(.+)$/m', $markdown, $matches)) {
            return trim($matches[1]);
        }

        return 'Documentation';
    }

    private function wrapInTemplate(string $content, string $title, string $outputDir, string $outputPath): string
    {
        $relativeDepth = $this->computeRelativeDepth($outputPath, $outputDir);
        $cssPath = $relativeDepth . 'css/style.css';
        $indexPath = $relativeDepth . 'index.html';

        $navLinks = $this->buildNavLinks($relativeDepth);

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$this->escape($title)} — Bånder Docs</title>
    <link rel="stylesheet" href="{$cssPath}">
</head>
<body>
<nav>
    <a href="{$indexPath}">Bånder Docs</a>
    {$navLinks}
</nav>
<main>
{$content}
</main>
</body>
</html>
HTML;
    }

    private function buildNavLinks(string $relativeDepth): string
    {
        $links = [
            'API Reference' => 'api-reference.html',
            'Domain Models' => 'domain-models.html',
            'Architecture' => 'architecture-guide.html',
            'Contexts' => 'contexts/',
        ];

        $html = '';
        foreach ($links as $label => $href) {
            $html .= '<a href="' . $relativeDepth . $href . '">' . $this->escape($label) . '</a>';
        }

        return $html;
    }

    private function computeRelativeDepth(string $outputPath, string $outputDir): string
    {
        $relative = substr($outputPath, strlen($outputDir) + 1);
        $depth = substr_count($relative, '/');

        return str_repeat('../', $depth);
    }

    private function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}
