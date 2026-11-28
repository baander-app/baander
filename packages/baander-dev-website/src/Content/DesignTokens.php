<?php

declare(strict_types=1);

namespace Baander\DevWebsite\Content;

use RuntimeException;

/**
 * The design tokens as the application builds them. Colors, spacing, radii and durations come from the
 * generated web theme, so each value is the one the application ships; the type roles, the accent
 * identities, the mood names and the contrast targets come from the token inputs the generator reads.
 *
 * The generated theme holds one block of colors per mood and accent. The default accent is the one
 * whose block also answers for a mood chosen without an accent, `:root[data-theme="…"]:not([data-accent])`.
 *
 * Several readers join the tokens with the rules `ui/DESIGN.md` states about them and fail, naming
 * both sides, where the two disagree.
 *
 * @phpstan-type TypeRole array{desktop: string, phone: string, weight: string, tracking: string, lineHeight: string, transform: ?string, mono: bool, use: string}
 * @phpstan-type ColorRole array{variables: list<string>, use: string, detail: ?string}
 * @phpstan-type ScaleStep array{name: string, value: string, px: float, uses: list<string>}
 * @phpstan-type LowestContrast array{ratio: float, surface: string}
 * @phpstan-type AccentDerivation array{identity: string, primary: string, primaryForeground: string, fill: float, accentText: string, text: LowestContrast, ring: string, ringContrast: LowestContrast}
 */
final readonly class DesignTokens
{
    public const string THEME = 'ui/design-tokens/build/web/theme.css';
    public const string TYPE = 'ui/design-tokens/tokens/base/type.json';
    public const string PALETTE = 'ui/design-tokens/tokens/accent/palette.json';
    public const string SEMANTICS = 'ui/design-tokens/tokens/web/semantic-colors.json';

    /** The surfaces the generator requires text, actions and indicators to contrast with, by color variable. */
    public const array SURFACES = [
        'background' => '--color-background',
        'card' => '--color-card',
        'popover' => '--color-popover',
        'sidebar' => '--color-sidebar',
        'secondary' => '--color-secondary',
        'muted' => '--color-muted',
        'highlight' => '--color-highlight',
    ];

    /** A selector of one mood with one accent in the generated theme. */
    private const string THEME_SELECTOR = '/^:root\[data-theme="([a-z]+)"\]\[data-accent="([a-z]+)"\]$/';
    /** A selector of one mood chosen without an accent. */
    private const string DEFAULT_SELECTOR = '/^:root\[data-theme="([a-z]+)"\]:not\(\[data-accent\]\)$/';

    /**
     * @param array<string, string> $moods mood labels by mood, in the generator's order
     * @param array<string, string> $accents accent identities by accent, in palette order
     * @param array<string, array<string, array<string, string>>> $colors color variables by mood and accent
     * @param array<string, string> $root the variables the generated theme declares for every mood
     * @param array<string, array<string, mixed>> $type the type tokens by name
     * @param array{text: float, action: float, indicator: float} $targets
     */
    private function __construct(
        private array $moods,
        private array $accents,
        private string $defaultAccent,
        private array $colors,
        private array $root,
        private array $type,
        private array $targets,
    ) {}

    public static function load(string $root): self
    {
        $root = rtrim($root, '/');
        $semantics = self::json($root, self::SEMANTICS);
        $moods = $semantics['moods'] ?? throw new RuntimeException(self::SEMANTICS . ' names no moods.');
        $accents = array_map(
            static fn(array $token): string => $token['value'],
            self::json($root, self::PALETTE)['accent'] ?? throw new RuntimeException(self::PALETTE . ' names no accents.'),
        );
        $css = file_get_contents($root . '/' . self::THEME)
            ?: throw new RuntimeException('The generated web theme ' . self::THEME . ' is missing; rebuild design tokens.');
        $colors = [];
        $declarations = [];
        $defaults = [];
        foreach (self::rules($css, topLevel: true) as [$selectors, $block]) {
            if ($selectors === [':root']) {
                $declarations = $block;
            }
            $themes = self::captures(self::THEME_SELECTOR, $selectors);
            foreach ($themes as [$mood, $accent]) {
                $colors[$mood][$accent] = array_filter($block, static fn(string $name): bool => str_starts_with($name, '--color-'), ARRAY_FILTER_USE_KEY);
            }
            foreach (self::captures(self::DEFAULT_SELECTOR, $selectors) as [$mood]) {
                $defaults[$mood] = $themes[0][1] ?? throw new RuntimeException(self::THEME . ' gives the ' . $mood . ' mood without an accent a block that names no accent.');
            }
        }
        foreach (array_keys($moods) as $mood) {
            foreach (array_keys($accents) as $accent) {
                if (!isset($colors[$mood][$accent])) {
                    throw new RuntimeException(self::THEME . ' has no colors for the ' . $mood . ' mood with the ' . $accent . ' accent; rebuild design tokens.');
                }
            }
        }
        $default = array_unique($defaults) |> array_values(...);
        if (count($default) !== 1 || count($defaults) !== count($moods)) {
            throw new RuntimeException(self::THEME . ' must give every mood the same default accent, not ' . (json_encode($defaults, JSON_THROW_ON_ERROR)) . '.');
        }

        return new self(
            $moods,
            $accents,
            $default[0],
            $colors,
            $declarations,
            self::json($root, self::TYPE)['text'] ?? throw new RuntimeException(self::TYPE . ' holds no text tokens.'),
            [
                'text' => (float) ($semantics['normalTextContrast'] ?? throw new RuntimeException(self::SEMANTICS . ' sets no normal text contrast.')),
                'action' => (float) ($semantics['actionContrast'] ?? throw new RuntimeException(self::SEMANTICS . ' sets no action contrast.')),
                'indicator' => (float) ($semantics['indicatorContrast'] ?? throw new RuntimeException(self::SEMANTICS . ' sets no indicator contrast.')),
            ],
        );
    }

    /** @return array<string, string> mood labels by mood, in the generator's order */
    public function moods(): array
    {
        return $this->moods;
    }

    /** @return array<string, string> each accent's identity color by accent, in palette order */
    public function accents(): array
    {
        return $this->accents;
    }

    public function defaultAccent(): string
    {
        return $this->defaultAccent;
    }

    /** @return array{text: float, action: float, indicator: float} the contrast ratios the generator enforces */
    public function targets(): array
    {
        return $this->targets;
    }

    /**
     * The generated color variables of one mood with one accent, the default accent when none is named.
     *
     * @return array<string, string> values by variable name, such as `--color-primary`
     */
    public function colors(string $mood, ?string $accent = null): array
    {
        $accent ??= $this->defaultAccent;

        return $this->colors[$mood][$accent]
            ?? throw new RuntimeException(self::THEME . ' has no colors for the ' . $mood . ' mood with the ' . $accent . ' accent.');
    }

    /**
     * The variables of one scale, smallest first, each with the items of a `ui/DESIGN.md` list that state
     * its size. An item counts when it reads `Label: …` and the sentence after the label names the step's
     * size in pixels; the use is the label. With `$subjects`, an item counts only when its label or that
     * sentence also names one of those words, so a list that mixes uses of the scale with component
     * dimensions of the same size credits only the uses.
     *
     * @param string $prefix the variable prefix: `space` or `radius`
     * @param list<string> $items the list items that state sizes
     * @param list<string> $subjects words one of which an item must name, such as `padding`; none admits every item
     * @return list<ScaleStep>
     */
    public function scale(string $prefix, array $items = [], array $subjects = []): array
    {
        $describes = static fn(string $text): bool => $subjects === []
            || preg_match('/\b(?:' . implode('|', array_map(static fn(string $subject): string => preg_quote($subject, '/'), $subjects)) . ')\b/iu', $text) === 1;
        $documented = [];
        foreach ($items as $item) {
            if (preg_match('/^([^:]+):\s*([^.]*)/u', $item, $match) === 1 && $describes($match[1] . ' ' . $match[2])) {
                preg_match_all('/(?<![\d.])(\d+(?:\.\d+)?)px\b/', $match[2], $sizes);
                foreach ($sizes[1] as $size) {
                    $documented[(string) (float) $size][] = trim($match[1]);
                }
            }
        }
        $steps = [];
        foreach ($this->root as $name => $value) {
            if (str_starts_with($name, '--' . $prefix . '-')) {
                $px = self::pixels($name, $value);
                $steps[] = ['name' => $name, 'value' => $value, 'px' => $px, 'uses' => $documented[(string) $px] ?? []];
            }
        }
        if ($steps === []) {
            throw new RuntimeException(self::THEME . ' declares no --' . $prefix . '-* variables.');
        }
        usort($steps, static fn(array $left, array $right): int => $left['px'] <=> $right['px']);

        return $steps;
    }

    /** @return array<string, string> the duration variables by name, such as `--duration-hover` */
    public function durations(): array
    {
        return array_filter($this->root, static fn(string $name): bool => str_starts_with($name, '--duration-'), ARRAY_FILTER_USE_KEY);
    }

    /**
     * The ten type roles in the order of the Roles table in `ui/DESIGN.md`, with the tokens' values and
     * the table's use. The table's sizes, weights, tracking and line-heights must equal the tokens.
     *
     * @return array<string, TypeRole>
     */
    public function typeRoles(DesignDocument $design): array
    {
        $headings = ['Typography', 'Roles'];
        $columns = ['Role', 'Desktop', 'Phone (≤767px)', 'Weight', 'Tracking', 'Line-height', 'Use'];
        $roles = [];
        foreach ($design->table($headings, $columns) as $row) {
            $role = $row['Role'];
            $name = (str_replace('-', '', ucwords($role, '-')) |> lcfirst(...));
            $token = fn(string $property): array => $this->type[$name . $property]
                ?? throw new RuntimeException(self::TYPE . ' has no ' . $property . ' token for the type role ' . $role . '.');
            $values = [
                'desktop' => $token('Size')['value'],
                'phone' => $token('Size')['mobile'] ?? $token('Size')['value'],
                'weight' => $token('Weight')['value'],
                'tracking' => $token('Tracking')['value'],
                'lineHeight' => $token('LineHeight')['value'],
                'transform' => isset($this->type[$name . 'Transform']) ? $token('Transform')['value'] : null,
            ];
            $stated = [
                'Desktop' => [$row['Desktop'], $values['desktop']],
                'Phone (≤767px)' => [$row['Phone (≤767px)'], $values['phone']],
                'Weight' => [explode(',', $row['Weight'])[0], $values['weight']],
                'Tracking' => [self::tracking(explode(',', $row['Tracking'])[0]), self::tracking($values['tracking'])],
                'Line-height' => [$row['Line-height'], $values['lineHeight']],
            ];
            foreach ($stated as $column => [$document, $tokens]) {
                if (trim($document) !== $tokens) {
                    throw new RuntimeException($design->path() . ' › ' . implode(' › ', $headings) . ' gives ' . $role . ' the ' . $column . ' ' . trim($document) . ', but ' . self::TYPE . ' sets ' . $tokens . '.');
                }
            }
            $roles[$role] = $values + [
                'mono' => str_contains($row['Weight'], 'mono'),
                'use' => $row['Use'],
            ];
        }

        return $roles;
    }

    /**
     * The color roles `ui/DESIGN.md` documents: the variables its Surfaces list names, then the rows of
     * its token table. Every variable must exist for every mood and accent.
     *
     * @return list<ColorRole>
     */
    public function colorRoles(DesignDocument $design): array
    {
        $roles = [];
        foreach ($design->items(['Layout', 'Surfaces']) as $item) {
            if (preg_match('/^([^:]+):\s*var\((--color-[a-z0-9-]+)\)\s*(?:—\s*)?(.*)$/u', $item, $match) === 1) {
                $roles[] = ['variables' => [$match[2]], 'use' => trim($match[1]), 'detail' => $match[3] === '' ? null : $match[3]];
            }
        }
        foreach ($design->table(['Color', 'Token authority'], ['Token', 'Use']) as $row) {
            preg_match_all('/--color-[a-z0-9-]+/', $row['Token'], $variables);
            $roles[] = ['variables' => $variables[0], 'use' => $row['Use'], 'detail' => null];
        }
        foreach ($roles as $role) {
            foreach ($role['variables'] as $variable) {
                foreach ($this->colors as $mood => $accents) {
                    foreach ($accents as $accent => $colors) {
                        if (!isset($colors[$variable])) {
                            throw new RuntimeException($design->path() . ' documents ' . $variable . ', but ' . self::THEME . ' does not set it for the ' . $mood . ' mood with the ' . $accent . ' accent.');
                        }
                    }
                }
            }
        }

        return $roles;
    }

    /**
     * What each mood derives from each accent, with the contrast of each derived color. The label on a
     * filled action is measured against that fill; accent text and the focus ring are measured against
     * every surface, and the lowest ratio counts. A ratio below the generator's target fails.
     *
     * @return array<string, array<string, AccentDerivation>> by mood, then accent
     */
    public function accentDerivations(): array
    {
        $derivations = [];
        foreach (array_keys($this->moods) as $mood) {
            foreach ($this->accents as $accent => $identity) {
                $colors = $this->colors($mood, $accent);
                $surfaces = array_map(static fn(string $variable): string => $colors[$variable], self::SURFACES);
                $derivation = [
                    'identity' => $identity,
                    'primary' => $colors['--color-primary'],
                    'primaryForeground' => $colors['--color-primary-foreground'],
                    'fill' => Contrast::ratio($colors['--color-primary-foreground'], $colors['--color-primary']),
                    'accentText' => $colors['--color-accent-text'],
                    'text' => Contrast::lowest($colors['--color-accent-text'], $surfaces),
                    'ring' => $colors['--color-ring'],
                    'ringContrast' => Contrast::lowest($colors['--color-ring'], $surfaces),
                ];
                $checks = [
                    'the primary label on its fill' => [$derivation['fill'], 'primary', $this->targets['text']],
                    '--color-accent-text' => [$derivation['text']['ratio'], $derivation['text']['surface'], $this->targets['text']],
                    '--color-ring' => [$derivation['ringContrast']['ratio'], $derivation['ringContrast']['surface'], $this->targets['indicator']],
                ];
                foreach ($checks as $subject => [$ratio, $surface, $target]) {
                    if ($ratio < $target) {
                        throw new RuntimeException(self::THEME . ': in the ' . $mood . ' mood with the ' . $accent . ' accent, ' . $subject . ' reaches ' . Contrast::format($ratio) . ':1 against ' . $surface . ', below ' . $target . ':1.');
                    }
                }
                $derivations[$mood][$accent] = $derivation;
            }
        }

        return $derivations;
    }

    /**
     * The rows of the Motion table in `ui/DESIGN.md`. The hover row's duration must equal `--duration-hover`.
     *
     * @return list<array{transition: string, duration: string, easing: string}>
     */
    public function motion(DesignDocument $design): array
    {
        $rows = array_map(
            static fn(array $row): array => ['transition' => $row['Transition'], 'duration' => $row['Duration'], 'easing' => $row['Easing']],
            $design->table(['Motion'], ['Transition', 'Duration', 'Easing']),
        );
        $hover = array_find($rows, static fn(array $row): bool => $row['transition'] === 'Hover state')
            ?? throw new RuntimeException($design->path() . ' › Motion has no Hover state row.');
        $token = $this->root['--duration-hover'] ?? throw new RuntimeException(self::THEME . ' declares no --duration-hover.');
        if ($hover['duration'] !== $token) {
            throw new RuntimeException($design->path() . ' › Motion gives Hover state ' . $hover['duration'] . ', but ' . self::THEME . ' sets --duration-hover to ' . $token . '.');
        }

        return $rows;
    }

    /**
     * One declaration of a stylesheet rule, read from the rule whose selector list holds the selector.
     *
     * @param string $path the stylesheet's repository path, for the message when the rule or property is missing
     */
    public static function declaration(string $css, string $path, string $selector, string $property): string
    {
        foreach (self::rules($css, topLevel: false) as [$selectors, $block]) {
            if (in_array($selector, $selectors, true) && isset($block[$property])) {
                return $block[$property];
            }
        }
        throw new RuntimeException($path . ' sets no ' . $property . ' for ' . $selector . '.');
    }

    /**
     * Each rule of a stylesheet with its selectors and declarations, comments removed. With `$topLevel`,
     * rules inside at-rules are left out.
     *
     * @return list<array{list<string>, array<string, string>}>
     */
    private static function rules(string $css, bool $topLevel): array
    {
        $css = (string) preg_replace('~/\*.*?\*/~s', '', $css);
        if ($topLevel) {
            $css = (string) preg_replace('/@[a-z-]+[^{]*\{(?:[^{}]*\{[^{}]*\})*[^{}]*\}/', '', $css);
        }
        preg_match_all('/([^{}]+)\{([^{}]*)\}/', $css, $matches, PREG_SET_ORDER);
        $rules = [];
        foreach ($matches as [, $selectors, $body]) {
            $declarations = [];
            preg_match_all('/(--?[a-zA-Z][\w-]*|[a-z-]+)\s*:\s*([^;]+);?/', $body, $properties, PREG_SET_ORDER);
            foreach ($properties as [, $name, $value]) {
                $declarations[$name] = trim($value);
            }
            $rules[] = [
                explode(',', $selectors) |> (static fn(array $parts): array => array_map(static fn(string $part): string => (string) preg_replace('/\s+/', ' ', trim($part)), $parts)),
                $declarations,
            ];
        }

        return $rules;
    }

    /**
     * The groups each matching selector captures.
     *
     * @param list<string> $selectors
     * @return list<list<string>>
     */
    private static function captures(string $pattern, array $selectors): array
    {
        $captures = [];
        foreach ($selectors as $selector) {
            if (preg_match($pattern, $selector, $match) === 1) {
                $captures[] = array_slice($match, 1);
            }
        }

        return $captures;
    }

    /** @return array<string, mixed> */
    private static function json(string $root, string $path): array
    {
        $json = file_get_contents($root . '/' . $path) ?: throw new RuntimeException('The design token file ' . $path . ' is missing.');
        $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        return is_array($data) ? $data : throw new RuntimeException($path . ' must hold a JSON object.');
    }

    private static function pixels(string $name, string $value): float
    {
        return match (true) {
            preg_match('/^(\d+(?:\.\d+)?)rem$/', $value, $match) === 1 => (float) $match[1] * 16,
            preg_match('/^(\d+(?:\.\d+)?)px$/', $value, $match) === 1 => (float) $match[1],
            default => throw new RuntimeException(self::THEME . ' sets ' . $name . ' to ' . $value . ', which is not a rem or px length.'),
        };
    }

    /** A tracking value as a signed em length without a plus sign, so `0`, `0em` and `+0em` compare equal. */
    private static function tracking(string $value): string
    {
        $normalized = str_replace(['−', '+'], ['-', ''], trim($value));
        $number = (float) $normalized;

        if ($number === 0.0) {
            return '0em';
        }

        return number_format($number, 4, '.', '')
            |> (static fn(string $digits): string => rtrim($digits, '0'))
            |> (static fn(string $digits): string => rtrim($digits, '.') . 'em');
    }
}
