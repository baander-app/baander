<?php

declare(strict_types=1);

namespace Baander\Tooling\Website;

use Dom\HTMLDocument;

/**
 * One feature data file, read and checked against the shape its values must have. It keeps every
 * problem it finds, each under the file's name and the place of the value, and what the file takes
 * from elsewhere: its pictures, its links to manual pages and the manual blocks it borrows.
 *
 * A shape is a FeatureField, a map of named parts whose optional names end in `?`, or a list
 * written as `[entry]` or `[entry, least, most]`. A list holds at least one entry unless its name
 * is optional.
 */
final class FeatureFile
{
    /** A picture file may be this many bytes at most. */
    private const int PICTURE_BYTES = 500_000;
    private const array PICTURE = ['picture' => FeatureField::Id, 'alt' => FeatureField::Text, 'caption' => FeatureField::Text];
    private const array MANUAL_BLOCK = ['manual' => FeatureField::Text, 'contains' => FeatureField::Text];
    private const string KEBAB_CASE = '/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/';
    /** Prose is parsed between these, so markup that stays open swallows or displaces the closing mark. */
    private const string PROSE_OPENING = '<!DOCTYPE html><html><head><title>Prose</title></head><body><div>';
    private const string PROSE_CLOSING = '</div><i id="closing"></i></body></html>';

    /** @var array<mixed> what the file returns; empty when it does not return an array */
    public private(set) array $data = [];
    /** @var list<string> */
    public private(set) array $errors = [];
    /** @var list<string> the manual pages and headings the file links to, as site URLs */
    public private(set) array $links = [];
    /** @var list<array{manual: string, contains: string, into: list<int|string>}> the manual blocks the file borrows, each with the place of its figure in the data */
    public private(set) array $blocks = [];
    /** @var array<string, array{width: int, height: int}> the dimensions of each picture the file shows, by name */
    public private(set) array $pictures = [];
    /** @var list<string> */
    private array $alternativeTexts = [];

    private function __construct(public readonly string $path, private readonly string $root) {}

    /**
     * @param string $path the file's repository path
     * @param array<string, mixed> $shape
     */
    public static function read(string $root, string $path, array $shape): self
    {
        $file = new self($path, $root);
        $absolute = $root . '/' . $path;
        if (!is_file($absolute)) {
            $file->fail([], 'does not exist');

            return $file;
        }
        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($absolute, true);
        }
        $data = (static fn(): mixed => require $absolute)();
        if (!is_array($data)) {
            $file->fail([], 'must return an array');

            return $file;
        }
        $file->data = $data;
        $file->check($data, $shape, []);

        return $file;
    }

    /** @param list<int|string> $at the place of the value in the data; empty for the file as a whole */
    public function fail(array $at, string $problem): void
    {
        $place = array_reduce($at, static fn(string $place, int|string $key): string => match (true) {
            is_int($key) => $place . '[' . $key . ']',
            $place === '' => $key,
            default => $place . '.' . $key,
        }, '');
        $this->errors[] = $this->path . ': ' . ($place === '' ? '' : $place . ': ') . $problem;
    }

    /**
     * @param FeatureField|array<mixed> $shape
     * @param list<int|string> $at
     */
    private function check(mixed $value, FeatureField|array $shape, array $at, bool $optional = false): void
    {
        match (true) {
            $shape instanceof FeatureField => $this->field($value, $shape, $at),
            array_is_list($shape) => $this->entries($value, $shape, $at, $optional),
            default => $this->parts($value, $shape, $at),
        };
    }

    /**
     * @param array<mixed> $shape
     * @param list<int|string> $at
     */
    private function parts(mixed $value, array $shape, array $at): void
    {
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            $this->fail($at, 'must be an array with named keys');

            return;
        }
        foreach ($shape as $key => $part) {
            $name = rtrim((string) $key, '?');
            if (array_key_exists($name, $value)) {
                $this->check($value[$name], $part, [...$at, $name], $name !== $key);
            } elseif ($name === $key) {
                $this->fail([...$at, $name], 'missing');
            }
        }
        $known = array_map(static fn(int|string $key): string => rtrim((string) $key, '?'), array_keys($shape));
        foreach (array_diff(array_keys($value), $known) as $unknown) {
            $this->fail([...$at, $unknown], 'unknown key');
        }
    }

    /**
     * @param array<mixed> $shape
     * @param list<int|string> $at
     */
    private function entries(mixed $value, array $shape, array $at, bool $optional): void
    {
        [$entry, $least, $most] = $shape + [1 => $optional ? 0 : 1, 2 => null];
        if (!is_array($value) || !array_is_list($value)) {
            $this->fail($at, 'must be a list');

            return;
        }
        $count = count($value);
        if ($count < $least || ($most !== null && $count > $most)) {
            $this->fail($at, $count . ' listed; it takes ' . ($most === null ? 'at least ' . $least : $least . ' to ' . $most));
        }
        foreach ($value as $position => $item) {
            $this->check($item, $entry, [...$at, $position]);
        }
    }

    /** @param list<int|string> $at */
    private function field(mixed $value, FeatureField $kind, array $at): void
    {
        if ($kind === FeatureField::Figure) {
            $this->figure($value, $at);

            return;
        }
        if (!is_string($value) || trim($value) === '') {
            $this->fail($at, 'must be a non-empty string');

            return;
        }
        if ($kind === FeatureField::Html) {
            $this->prose($value, $at);

            return;
        }
        $problem = match ($kind) {
            FeatureField::Text => null,
            FeatureField::Description => mb_strlen($value) <= SearchMetadata::DESCRIPTION_LIMIT
                ? null
                : 'has ' . mb_strlen($value) . ' characters; a search description has at most ' . SearchMetadata::DESCRIPTION_LIMIT,
            FeatureField::Id => preg_match(self::KEBAB_CASE, $value) === 1 ? null : 'must be kebab-case',
            FeatureField::ManualUrl => str_starts_with($value, '/docs/') ? null : 'must be a manual page URL that starts with /docs/',
            FeatureField::Layout => in_array($value, ['side', 'wide'], true) ? null : 'must be "side" or "wide"',
        };
        if ($problem !== null) {
            $this->fail($at, $problem);
        } elseif ($kind === FeatureField::ManualUrl) {
            $this->links[] = $value;
        }
    }

    /**
     * Prose must parse as HTML without an error and leave what follows it in place, and none of
     * its links may leave the site.
     *
     * @param list<int|string> $at
     */
    private function prose(string $html, array $at): void
    {
        $previous = libxml_use_internal_errors(true);
        try {
            libxml_clear_errors();
            $document = HTMLDocument::createFromString(self::PROSE_OPENING . $html . self::PROSE_CLOSING, overrideEncoding: 'UTF-8');
            $error = array_first(libxml_get_errors())?->message;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $closing = $document->body?->lastElementChild;
        $closed = $document->body?->childNodes->length === 2 && $closing?->id === 'closing' && $closing->childNodes->length === 0;
        if ($error !== null || !$closed) {
            $this->fail($at, 'is not well-formed HTML (' . match (true) {
                $error === null => 'a tag, attribute or comment stays open',
                preg_match('/^(?:tree|tokenizer) error (\S+)/', $error, $match) === 1 => $match[1],
                default => trim($error),
            } . ')');

            return;
        }
        foreach ($document->querySelectorAll('[href]') as $link) {
            $href = (string) $link->getAttribute('href');
            if (preg_match('~^(?:[a-z][a-z0-9+.-]*:|//)~i', $href) === 1) {
                $this->fail($at, 'the link to ' . $href . ' leaves the site');
            } elseif (str_starts_with($href, '/docs/')) {
                $this->links[] = $href;
            }
        }
    }

    /** @param list<int|string> $at */
    private function figure(mixed $value, array $at): void
    {
        $kinds = is_array($value) ? array_intersect(['picture', 'manual'], array_keys($value)) : [];
        if (count($kinds) !== 1) {
            $this->fail($at, 'must be a picture or a manual block');

            return;
        }
        $picture = array_first($kinds) === 'picture';
        $known = count($this->errors);
        $this->parts($value, $picture ? self::PICTURE : self::MANUAL_BLOCK, $at);
        if (count($this->errors) > $known) {
            return;
        }
        if ($picture) {
            $this->picture($value['picture'], $value['alt'], $at);
        } else {
            $this->blocks[] = ['manual' => $value['manual'], 'contains' => $value['contains'], 'into' => $at];
        }
    }

    /**
     * A picture needs its dark and its light file, both of one size with an even width and
     * height, neither above the size ceiling, and an alternative text no other picture of the
     * page has.
     *
     * @param list<int|string> $at
     */
    private function picture(string $name, string $alternativeText, array $at): void
    {
        if (in_array($alternativeText, $this->alternativeTexts, true)) {
            $this->fail([...$at, 'alt'], 'repeats the alternative text of another picture on the page');
        }
        $this->alternativeTexts[] = $alternativeText;
        $sizes = [];
        foreach (Screenshots::files($name) as $variant => $path) {
            $absolute = $this->root . '/' . $path;
            $size = Screenshots::size($absolute);
            if ($size === null) {
                $this->fail($at, 'picture "' . $name . '" has ' . (is_file($absolute) ? 'a ' . $variant . ' variant that is not a picture: ' : 'no ' . $variant . ' variant ') . $path);
                continue;
            }
            $sizes[$variant] = $size;
            $bytes = (int) filesize($absolute);
            if ($bytes > self::PICTURE_BYTES) {
                $this->fail($at, 'picture "' . $name . '": ' . $path . ' is ' . $bytes . ' bytes; a picture file has at most ' . self::PICTURE_BYTES);
            }
        }
        if (count($sizes) !== 2) {
            return;
        }
        $measure = static fn(array $size): string => $size['width'] . ' × ' . $size['height'];
        if ($sizes['dark'] !== $sizes['light']) {
            $this->fail($at, 'picture "' . $name . '" is ' . $measure($sizes['dark']) . ' in its dark variant and ' . $measure($sizes['light']) . ' in its light variant; both must have one size');
        } elseif ($sizes['dark']['width'] % 2 !== 0 || $sizes['dark']['height'] % 2 !== 0) {
            $this->fail($at, 'picture "' . $name . '" is ' . $measure($sizes['dark']) . '; its width and height must be even');
        }
        $this->pictures[$name] = $sizes['dark'];
    }
}
