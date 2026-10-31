<?php

declare(strict_types=1);

namespace Baander\Tooling\Website;

use Baander\Tooling\Process\EngineExecutor;
use Baander\Tooling\Process\ProcessSpec;

final readonly class BlockRendering
{
    public function __construct(private string $root, private EngineExecutor $executor) {}

    /**
     * @param list<array{id:string,source:string,language:string,page:string,ordinal:int}> $blocks
     * @return array<string,string>
     */
    public function render(array $blocks, string $staging): array
    {
        if ($blocks === []) {
            return [];
        }
        $scratch = $this->root . '/var/website-render-' . bin2hex(random_bytes(8));
        if (!mkdir($scratch, 0700, true)) {
            throw new \RuntimeException('Cannot create website rendering scratch directory.');
        }
        $fontPath = $this->root . '/ui/site/node_modules/@fontsource/inter/files/inter-latin-400-normal.woff2';
        try {
            file_put_contents($scratch . '/request.json', json_encode([
                'blocks' => $blocks,
                'fontPath' => $fontPath,
                'themeCssPath' => $this->root . '/ui/design-tokens/build/web/theme.css',
            ], JSON_THROW_ON_ERROR));
            $this->executor->run(new ProcessSpec(
                ['node', $this->root . '/ui/site/scripts/render-blocks.mjs', $scratch . '/request.json', $scratch . '/result.json'],
                cwd: $this->root . '/ui/site',
            ));
            $raw = is_file($scratch . '/result.json') ? file_get_contents($scratch . '/result.json') : false;
            $results = $raw === false ? null : json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
            if (!is_array($results) || count($results) !== count($blocks)) {
                throw new \RuntimeException('Rendering helper returned an incomplete result.');
            }
            $html = [];
            foreach ($blocks as $block) {
                try {
                    if (preg_match('/^[a-zA-Z0-9_-]+$/D', $block['id']) !== 1) {
                        throw new \RuntimeException('Invalid render block identifier.');
                    }
                    $result = $results[$block['id']] ?? null;
                    if (!is_array($result)) {
                        throw new \RuntimeException('Rendering helper omitted the block.');
                    }
                    if ($block['language'] === 'mermaid') {
                        $html[$block['id']] = $this->diagram($block, $result, $staging, $fontPath);
                    } elseif (($result['kind'] ?? null) === 'code' && is_string($result['html'] ?? null)) {
                        $this->validateCode($result['html'], $block['source']);
                        $html[$block['id']] = '<pre><code class="language-' . self::escape($block['language']) . '">' . $result['html'] . '</code></pre>';
                    } else {
                        throw new \RuntimeException('Rendering helper returned an invalid code result.');
                    }
                } catch (\Throwable $error) {
                    throw new \RuntimeException($block['page'] . ', block ' . $block['ordinal'] . ': ' . $error->getMessage(), 0, $error);
                }
            }
            return $html;
        } catch (\Throwable $error) {
            throw new \RuntimeException($blocks[0]['page'] . ', block ' . $blocks[0]['ordinal'] . ': website rendering failed: ' . $error->getMessage(), 0, $error);
        } finally {
            foreach (glob($scratch . '/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($scratch);
        }
    }

    private function validateCode(string $html, string $source): void
    {
        $withoutSpans = preg_replace('/<span class="tok-(?:keyword|string|number|comment|variable|operator|function|type)">|<\/span>/', '', $html) ?? $html;
        if (str_contains($withoutSpans, '<') || preg_match('/&(?!amp;|lt;|gt;|quot;|apos;|#[0-9]+;|#x[0-9a-f]+;)/i', $withoutSpans) === 1 || html_entity_decode($withoutSpans, ENT_QUOTES | ENT_HTML5, 'UTF-8') !== $source) {
            throw new \RuntimeException('Highlighted code changed the source or contains unsafe markup.');
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $document = new \DOMDocument();
            $xml = '<code>' . str_replace("\r", '&#13;', $html) . '</code>';
            if (!$document->loadXML($xml, LIBXML_NONET) || $document->documentElement?->textContent !== $source) {
                throw new \RuntimeException('Highlighted code contains invalid token spans or changed source.');
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /**
     * @param array{id:string,source:string,language:string,page:string,ordinal:int} $block
     * @param array<mixed> $result
     */
    private function diagram(array $block, array $result, string $staging, string $fontPath): string
    {
        if (($result['kind'] ?? null) !== 'diagram' || !is_string($result['light'] ?? null) || !is_string($result['dark'] ?? null)) {
            throw new \RuntimeException('Rendering helper returned an invalid diagram result.');
        }
        foreach (['width', 'height'] as $dimension) {
            if ((!is_int($result[$dimension] ?? null) && !is_float($result[$dimension] ?? null)) || !is_finite($result[$dimension]) || $result[$dimension] <= 0) {
                throw new \RuntimeException('Rendering helper returned invalid diagram dimensions.');
            }
        }
        $font = is_file($fontPath) ? file_get_contents($fontPath) : false;
        if ($font === false) {
            throw new \RuntimeException('Cannot read local Inter font.');
        }
        $directory = $staging . '/assets/diagrams';
        if (!is_dir($directory) && !mkdir($directory, 0755, true)) {
            throw new \RuntimeException('Cannot create diagram assets directory.');
        }
        $title = preg_match('/^\s*accTitle\s*:\s*(.+)$/m', $block['source'], $match) === 1 ? trim($match[1]) : $block['page'] . ', diagram ' . $block['ordinal'];
        $description = preg_match('/^\s*accDescr\s*:\s*(.+)$/m', $block['source'], $match) === 1 ? trim($match[1]) : $title;
        if (preg_match('/^\s*accDescr\s*\{([\s\S]*?)\}/m', $block['source'], $match) === 1) {
            $description = trim($match[1]);
        }
        $images = '';
        $links = '';
        foreach (['light', 'dark'] as $appearance) {
            $asset = '/assets/diagrams/' . $block['id'] . '-' . $appearance . '.svg';
            $svg = new SvgDiagram()->sanitize($result[$appearance], $font);
            if (file_put_contents($staging . $asset, $svg) === false) {
                throw new \RuntimeException('Cannot write diagram asset.');
            }
            $images .= '<img class="diagram-' . $appearance . '" src="' . $asset . '" alt="' . self::escape($description) . '" width="' . ceil($result['width']) . '" height="' . ceil($result['height']) . '">';
            $links .= '<a class="diagram-' . $appearance . '" href="' . $asset . '">Open full-size diagram</a>';
        }
        return '<figure class="diagram" id="' . $block['id'] . '"><figcaption id="' . $block['id'] . '-caption">' . self::escape($title) . '</figcaption>'
            . '<div class="diagram-viewport" tabindex="0" role="region" aria-label="' . self::escape($title) . '">' . $images . '</div>' . $links
            . '<details data-pagefind-ignore="all"><summary>Diagram source</summary><pre><code>' . self::escape($block['source']) . '</code></pre></details></figure>';
    }

    private static function escape(string $source): string
    {
        return htmlspecialchars($source, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
