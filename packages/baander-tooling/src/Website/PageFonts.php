<?php

declare(strict_types=1);

namespace Baander\Tooling\Website;

use Dom\Element;
use Dom\HTMLDocument;
use Dom\Node;
use Dom\Text;
use RuntimeException;

/**
 * Chooses the Fontsource faces a rendered page, or search results drawn from many pages, need. Each character follows its element's font stack
 * the way a browser does: the first family with a face whose unicode-range covers the character supplies
 * it, and among that family's overlapping faces the last declared wins. Later families are never reached,
 * so the Latin faces every Noto package also declares, and the Han faces of the other CJK families, stay
 * unpublished.
 *
 * @phpstan-type Face array{family:string,package:string,file:string,range:string,ranges:list<array{int,int}>}
 */
final readonly class PageFonts
{
    /** The shared stack variables the page reads, by the variant a language selects. */
    private const array STACKS = ['ui' => '--font-ui', 'mono' => '--font-mono'];

    /** Languages that move their own Han family to the front of the stacks, as `:lang()` matches them. */
    private const array LANGUAGE_VARIANTS = ['ja' => '-ja', 'ko' => '-ko', 'zh-hant' => '-hant', 'zh-tw' => '-hant', 'zh-hk' => '-hant', 'zh-mo' => '-hant'];

    /** Elements that set text in the mono stack without a page class. */
    private const string MONO_ELEMENTS = 'code, kbd, samp, pre';

    /**
     * @param array<string,list<string>> $stacks family names in fallback order, by stack variable name
     * @param array<string,list<Face>> $faces Fontsource faces by family, in declaration order
     */
    private function __construct(private array $stacks, private array $faces) {}

    /** Reads the shared font stacks from the built design tokens and the faces of every installed Fontsource package. */
    public static function load(string $root): self
    {
        $theme = file_get_contents($root . '/ui/design-tokens/build/web/theme.css')
            ?: throw new RuntimeException('The shared font stacks are missing; rebuild design tokens.');
        $stacks = [];
        preg_match_all('/(--font-(?:ui|mono)(?:-[a-z]+)?):\s*([^;]+);/', $theme, $declarations, PREG_SET_ORDER);
        foreach ($declarations as [, $name, $value]) {
            $stacks[$name] ??= array_map(static fn(string $family): string => trim($family, " \t\n\"'"), explode(',', $value));
        }
        $faces = [];
        foreach (glob($root . '/ui/site/node_modules/@fontsource-variable/*/index.css') ?: [] as $index) {
            foreach (self::faces($index) as $face) {
                $faces[$face['family']][] = $face;
            }
        }
        return new self($stacks, $faces);
    }

    /**
     * The faces the page's rendered text reaches, in package declaration order.
     *
     * @param string $monoSelector the page's elements, besides code, kbd, samp and pre, that set the mono stack
     * @return list<Face>
     */
    public function select(string $html, string $monoSelector): array
    {
        $document = HTMLDocument::createFromString($html, LIBXML_NOERROR);
        $characters = [];
        foreach (self::texts($document->body ?? $document) as $text) {
            $element = $text->parentElement;
            if ($element === null) {
                continue;
            }
            $mono = $element->closest(self::MONO_ELEMENTS . ', ' . $monoSelector) !== null;
            $stack = self::STACKS[$mono ? 'mono' : 'ui'] . self::variant($element->closest('[lang]')?->getAttribute('lang') ?? '');
            foreach (mb_str_split($text->data) as $character) {
                $characters[$stack][mb_ord($character)] = true;
            }
        }
        return $this->resolve($characters);
    }

    /**
     * The faces search results reach when they show the pages' indexed text: each `data-pagefind-body`
     * element without its `data-pagefind-ignore` parts. Results set every excerpt, code included, in the
     * interface stack, without the languages of the elements it came from.
     *
     * @param iterable<string> $pages rendered pages
     * @return list<Face>
     */
    public function selectIndexed(iterable $pages): array
    {
        $codes = [];
        foreach ($pages as $html) {
            $document = HTMLDocument::createFromString($html, LIBXML_NOERROR);
            foreach ($document->querySelectorAll('[data-pagefind-body]') as $body) {
                foreach (self::texts($body) as $text) {
                    if ($text->parentElement?->closest('[data-pagefind-ignore]') === null) {
                        foreach (mb_str_split($text->data) as $character) {
                            $codes[mb_ord($character)] = true;
                        }
                    }
                }
            }
        }
        return $this->resolve([self::STACKS['ui'] => $codes]);
    }

    /**
     * @param array<string,array<int,true>> $characters code points by the stack variable that sets them
     * @return list<Face>
     */
    private function resolve(array $characters): array
    {
        $selected = [];
        foreach ($characters as $stack => $codes) {
            $families = $this->stacks[$stack] ?? throw new RuntimeException('The shared font stack ' . $stack . ' is missing.');
            foreach (array_keys($codes) as $code) {
                $face = $this->face($families, $code);
                if ($face !== null) {
                    $selected[$face['file']] = $face;
                }
            }
        }
        $order = array_flip(array_column(array_merge(...array_values($this->faces)), 'file'));
        uksort($selected, static fn(string $left, string $right): int => $order[$left] <=> $order[$right]);
        return array_values($selected);
    }

    /**
     * @param list<string> $families
     * @return Face|null
     */
    private function face(array $families, int $code): ?array
    {
        foreach ($families as $family) {
            $covering = array_filter($this->faces[$family] ?? [], static fn(array $face): bool => self::covers($face['ranges'], $code));
            if ($covering !== []) {
                return $covering[array_key_last($covering)];
            }
        }
        return null;
    }

    /** @param list<array{int,int}> $ranges */
    private static function covers(array $ranges, int $code): bool
    {
        return array_any($ranges, static fn(array $range): bool => $code >= $range[0] && $code <= $range[1]);
    }

    /** The stack variant whose Han order a language selects; other languages keep the default order. */
    private static function variant(string $language): string
    {
        $tag = strtolower($language);
        foreach (self::LANGUAGE_VARIANTS as $range => $variant) {
            if ($tag === $range || str_starts_with($tag, $range . '-')) {
                return $variant;
            }
        }
        return '';
    }

    /** @return iterable<Text> the rendered text below the node, without script, style or template content */
    private static function texts(Node $node): iterable
    {
        foreach ($node->childNodes as $child) {
            if ($child instanceof Text) {
                yield $child;
            } elseif ($child instanceof Element && !in_array($child->localName, ['script', 'style', 'template'], true)) {
                yield from self::texts($child);
            }
        }
    }

    /** @return list<Face> */
    private static function faces(string $index): array
    {
        $css = file_get_contents($index) ?: throw new RuntimeException('Cannot read font package: ' . $index);
        $package = basename(dirname($index));
        preg_match_all('/@font-face\s*\{([^}]*)\}/', $css, $blocks);
        $faces = [];
        foreach ($blocks[1] as $block) {
            if (!preg_match("/font-family:\s*'([^']+)'/", $block, $family)
                || !preg_match('/url\(\.\/files\/([^)]+\.woff2)\)/', $block, $file)
                || !preg_match('/unicode-range:\s*([^;]+);/', $block, $range)) {
                throw new RuntimeException('Unexpected font face in ' . $index);
            }
            $faces[] = [
                'family' => $family[1],
                'package' => $package,
                'file' => dirname($index) . '/files/' . $file[1],
                'range' => trim($range[1]),
                'ranges' => array_map(self::range(...), explode(',', $range[1])),
            ];
        }
        return $faces;
    }

    /** @return array{int,int} */
    private static function range(string $range): array
    {
        [$start, $end] = explode('-', substr(trim($range), 2)) + [1 => null];
        return [(int) hexdec($start), (int) hexdec($end ?? $start)];
    }
}
