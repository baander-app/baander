<?php

declare(strict_types=1);

namespace Baander\Tooling\Website;

use RuntimeException;

/**
 * The site's reference pages, each built from a tracked Markdown file of the repository. Each
 * `ui/site/reference/<slug>.php` holds one page at `/<slug>/`: the repository path of its source,
 * its title, its search description and the website opening shown below the source's title.
 *
 * The page built from the deployment preset guide also compares the six example presets in
 * `deploy/preset/` by their size settings. Their memory size comes from the file name only, so a
 * preset file the comparison does not expect, a size setting it does not know or lacks, and a
 * capability the file name contradicts each fail the build.
 *
 * @phpstan-import-type PublicPage from WebsiteBuilder
 */
final class ReferencePages
{
    public const string DIRECTORY = 'ui/site/reference';
    public const string PRESETS = 'deploy/preset';
    private const string PRESET_GUIDE = self::PRESETS . '/README.md';
    private const string TEMPLATE = 'reference';
    private const array SHAPE = [
        'source' => FeatureField::Text,
        'title' => FeatureField::Text,
        'description' => FeatureField::Description,
        'opening' => FeatureField::Html,
    ];
    /** The example presets' memory sizes in gigabytes, in the comparison's order. */
    private const array SIZES = [4, 8, 16];
    /** The size settings every preset sets, then the slots each capability adds, in the comparison's row order. */
    private const array SHARED_SETTINGS = ['webWorkers', 'databaseSlots', 'ordinaryConsumers', 'sharedBuffersMiB', 'workMemMiB'];
    private const array SLOTS = ['remux' => ['copySlots'], 'transcode' => ['videoSlots', 'audioSlots']];

    /** @return list<PublicPage> one page per data file, in file-name order */
    public static function load(string $root): array
    {
        $root = rtrim($root, '/');
        $files = array_map(
            static fn(string $path): FeatureFile => FeatureFile::read($root, self::DIRECTORY . '/' . basename($path), self::SHAPE),
            glob($root . '/' . self::DIRECTORY . '/*.php') ?: [],
        );
        $errors = array_merge(...array_map(static fn(FeatureFile $file): array => $file->errors, $files));
        $presets = null;
        if (in_array(self::PRESET_GUIDE, array_map(static fn(FeatureFile $file): mixed => $file->data['source'] ?? null, $files), true)) {
            [$presets, $presetErrors] = self::presets($root);
            $errors = [...$errors, ...$presetErrors];
        }
        if ($errors !== []) {
            throw new RuntimeException(implode("\n", $errors));
        }

        return array_map(static fn(FeatureFile $file): array => self::page($file, $presets), $files);
    }

    /**
     * @param ?array{columns: list<string>, rows: list<array{setting: string, values: list<?int>}>} $presets
     * @return PublicPage
     */
    private static function page(FeatureFile $file, ?array $presets): array
    {
        ['source' => $source, 'title' => $title] = $file->data;
        $comparesPresets = $source === self::PRESET_GUIDE;

        return [
            'template' => self::TEMPLATE,
            'url' => '/' . basename($file->path, '.php') . '/',
            'title' => $title,
            'description' => $file->data['description'],
            'markdown' => $source,
            'sources' => [$file->path, 'ui/site/templates/' . self::TEMPLATE . '.php', $comparesPresets ? self::PRESETS : $source],
            'references' => ['file' => $file->path, 'links' => array_values(array_unique($file->links)), 'blocks' => []],
            'data' => ['reference' => [
                'label' => $title,
                'opening' => $file->data['opening'],
                'presets' => $comparesPresets ? $presets : null,
            ]],
        ];
    }

    /**
     * The size settings of the six example presets: remux then transcode, each from the smallest
     * memory size up, with null where a preset does not use a setting.
     *
     * @return array{0: array{columns: list<string>, rows: list<array{setting: string, values: list<?int>}>}, 1: list<string>}
     */
    private static function presets(string $root): array
    {
        $expected = [];
        foreach (array_keys(self::SLOTS) as $capability) {
            foreach (self::SIZES as $gigabytes) {
                $expected[self::presetPath($gigabytes, $capability)] = ['gigabytes' => $gigabytes, 'capability' => $capability];
            }
        }
        $errors = array_map(
            static fn(string $path): string => self::PRESETS . '/' . basename($path) . ': not one of the six example presets, ' . implode(', ', array_map(basename(...), array_keys($expected))),
            array_filter(
                glob($root . '/' . self::PRESETS . '/*.json') ?: [],
                static fn(string $path): bool => !isset($expected[self::PRESETS . '/' . basename($path)]),
            ),
        );
        $sizes = [];
        foreach ($expected as $path => ['capability' => $capability]) {
            [$sizes[$path], $problems] = self::presetSize($root, $path, $capability);
            $errors = [...$errors, ...$problems];
        }
        $settings = [...self::SHARED_SETTINGS, ...array_merge(...array_values(self::SLOTS))];

        return [[
            'columns' => array_values(array_map(static fn(array $preset): string => $preset['gigabytes'] . ' GB ' . $preset['capability'], $expected)),
            'rows' => array_map(static fn(string $setting): array => [
                'setting' => $setting,
                'values' => array_values(array_map(static fn(array $size): ?int => $size[$setting] ?? null, $sizes)),
            ], $settings),
        ], $errors];
    }

    private static function presetPath(int $gigabytes, string $capability): string
    {
        return self::PRESETS . '/example-' . $gigabytes . 'gb-' . $capability . '.json';
    }

    /**
     * One preset's size settings, which must be exactly the shared settings and the slots of the
     * capability its file name names, each a whole number.
     *
     * @return array{0: array<string, int>, 1: list<string>}
     */
    private static function presetSize(string $root, string $path, string $capability): array
    {
        if (!is_file($root . '/' . $path)) {
            return [[], [$path . ': does not exist']];
        }
        try {
            $preset = json_decode((string) file_get_contents($root . '/' . $path), true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            return [[], [$path . ': is not valid JSON (' . $error->getMessage() . ')']];
        }
        $errors = [];
        $stated = is_array($preset) ? $preset['capability'] ?? null : null;
        if ($stated !== $capability) {
            $errors[] = $path . ': capability: ' . json_encode($stated, JSON_UNESCAPED_SLASHES) . ' disagrees with the file name, which names "' . $capability . '"';
        }
        $size = is_array($preset) && is_array($preset['size'] ?? null) ? $preset['size'] : [];
        $known = [...self::SHARED_SETTINGS, ...self::SLOTS[$capability]];
        foreach ($known as $setting) {
            $errors[] = match (true) {
                !array_key_exists($setting, $size) => $path . ': size.' . $setting . ': missing',
                !is_int($size[$setting]) => $path . ': size.' . $setting . ': must be a whole number',
                default => null,
            };
        }
        foreach (array_diff(array_keys($size), $known) as $unknown) {
            $errors[] = $path . ': size.' . $unknown . ': unknown key';
        }

        return [array_intersect_key($size, array_flip($known)), array_values(array_filter($errors, is_string(...)))];
    }
}
