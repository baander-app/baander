<?php

declare(strict_types=1);

namespace Baander\Tooling\Website;

use Baander\Tooling\Configuration\ToolingConfiguration;
use Baander\Tooling\Process\EngineExecutor;
use Baander\Tooling\Process\ExecutionContext;
use Baander\Tooling\Process\ProcessSpec;

final class Command
{
    /** Repository paths whose commit dates become the sitemap's last-modified values. */
    private const array SITE_SOURCES = ['docs-book', 'ui/site/templates', 'ui/site/assets/screenshots', 'ui/site/assets/typography.css', 'ui/design-tokens/tokens/base/type.json', 'packages/baander-aac/bench/results'];

    /** The typography page's elements that set the mono stack; a sample without the numeral rule uses the interface stack. */
    private const string TYPOGRAPHY_MONO = '.type-timecode:not(.typography-without-numerals), .type-mono-sm';

    /** The type roles in scale order, with their use and a sample; sizes, weights and spacing come from the tokens. */
    private const array TYPE_ROLES = [
        'hero' => ['use' => 'Hero titles, large browse headers', 'sample' => 'Continue watching'],
        'title-lg' => ['use' => 'Screen titles, shelf headers', 'sample' => 'Albums'],
        'title' => ['use' => 'Card group headers, detail-page titles', 'sample' => 'Recently added'],
        'subtitle' => ['use' => 'Lines under titles, credit rows', 'sample' => 'Season 2 · 8 episodes'],
        'body' => ['use' => 'Descriptions, settings, menus', 'sample' => 'Scan your music folders to add new albums to the library.'],
        'body-sm' => ['use' => 'Dense lists, metadata rows', 'sample' => '12 songs, 48 minutes'],
        'caption' => ['use' => 'Timestamps, badges, helper text', 'sample' => 'Added 3 days ago'],
        'label' => ['use' => 'Overline labels, category chips', 'sample' => 'New release'],
        'timecode' => ['use' => 'Player timecodes, durations', 'sample' => '1:42:07', 'mono' => true],
        'mono-sm' => ['use' => 'Codec and bitrate lines, file names', 'sample' => 'FLAC · 24-bit · 96 kHz', 'mono' => true],
    ];

    /** @param list<string> $arguments
     * @param array<string,string> $environment
     */
    public static function main(array $arguments, string $root, array $environment): int
    {
        if ($arguments !== []) {
            throw new \InvalidArgumentException('Usage: php scripts/build-website.php');
        }
        $configuration = ToolingConfiguration::forRepository($root, $environment);
        $executor = new EngineExecutor(ExecutionContext::bounded($configuration->budgets->seconds('website'), $environment, $root));
        try {
            $revision = $executor->run(new ProcessSpec(['git', 'rev-parse', 'HEAD']))->stdout |> trim(...);
            $codecRevision = $executor->run(new ProcessSpec(['git', 'log', '-1', '--format=%H', '--', 'packages/baander-aac']))->stdout |> trim(...);
            if (!preg_match('/^[a-f0-9]{40}$/', $codecRevision)) {
                throw new \RuntimeException('Cannot resolve the bAAC source revision.');
            }
            $blocks = new BlockRendering($root, $executor, Publication::theme($root));
            $revisionUrl = 'https://github.com/baander-app/baander/tree/' . $revision;
            $evidence = new AacEvidence()->load($root);
            $history = $executor->run(new ProcessSpec([...SourceHistory::GIT_LOG, '--', ...self::SITE_SOURCES]))->stdout
                |> SourceHistory::fromGitLog(...);
            $typography = [
                'template' => 'typography',
                'url' => '/typography/',
                'title' => 'Typography',
                'description' => 'How Bånder sets Latin, Vietnamese, Cyrillic, Thai, Burmese, Korean, Chinese and Japanese titles: fonts, glyph forms, script rules and type roles.',
                'stylesheet' => 'typography.css',
                'sources' => ['ui/site/templates/typography.php', 'ui/site/assets/typography.css', 'ui/design-tokens/tokens/base/type.json'],
                'data' => ['typography' => self::typography($root)],
            ];
            $pages = new WebsiteBuilder($root, $revisionUrl)->build(
                static function (string $staging) use ($executor, $root, $typography): void {
                    Publication::assets($root, $staging);
                    Publication::pageFonts($root, $staging, $typography['url'], $typography['stylesheet'], self::TYPOGRAPHY_MONO);
                    $executor->run(new ProcessSpec(
                        ['corepack', 'yarn', 'build', $staging . '/assets/site/search.js'],
                        cwd: $root . '/ui/site',
                    ));
                    $executor->run(new ProcessSpec(
                        ['corepack', 'yarn', 'pagefind', '--site', $staging, '--output-subdir', 'pagefind'],
                        cwd: $root . '/ui/site',
                    ));
                    Publication::package($staging);
                },
                $blocks->render(...),
                [[
                    'template' => 'baac',
                    'url' => '/baac/',
                    'title' => 'bAAC',
                    'description' => 'Bånder’s AAC codec library: Electron integration, planned transcoder and FFmpeg support, and recorded quality and speed comparisons.',
                    'stylesheet' => 'baac.css',
                    'script' => 'baac-charts.js',
                    'sources' => ['ui/site/templates/baac.php', 'packages/baander-aac/bench/results'],
                    'data' => ['aac' => [
                        'evidence' => $evidence,
                        'charts' => new AacCharts()->render($evidence),
                        'sourceBase' => 'https://github.com/baander-app/baander/blob/' . $codecRevision . '/packages/baander-aac/',
                    ]],
                ], $typography],
                $history,
            );
            fwrite(STDOUT, 'Built ' . count($pages) . ' manual pages in var/website.' . "\n");
            return 0;
        } finally {
            $executor->close();
        }
    }

    /**
     * The typography page's samples, in the order the page shows them, and its type roles read from the
     * design tokens, so the scale it states is the scale the tokens build.
     *
     * @return array<string,mixed>
     */
    private static function typography(string $root): array
    {
        $tokens = json_decode((string) file_get_contents($root . '/ui/design-tokens/tokens/base/type.json'), true, flags: JSON_THROW_ON_ERROR)['text'];
        $roles = [];
        foreach (self::TYPE_ROLES as $role => $entry) {
            $token = static fn(string $property): array => $tokens[lcfirst(str_replace('-', '', ucwords($role, '-'))) . $property]
                ?? throw new \RuntimeException('The type role ' . $role . ' has no ' . $property . ' token.');
            $roles[$role] = $entry + [
                'desktop' => $token('Size')['value'],
                'phone' => $token('Size')['mobile'] ?? $token('Size')['value'],
                'weight' => $token('Weight')['value'],
                'tracking' => $token('Tracking')['value'],
                'lineHeight' => $token('LineHeight')['value'],
                'transform' => $role === 'label' ? $token('Transform')['value'] : null,
                'mono' => $entry['mono'] ?? false,
            ];
        }
        $burmese = ['ဆက်ကြည့်ရန်', 'မြန်မာစာ စမ်းသပ်ခြင်း', 'နေကောင်းပါသလား'];
        return [
            'specimens' => [
                ['script' => 'Latin and Vietnamese', 'family' => 'Geist', 'samples' => [
                    ['title-lg', 'en', 'Dune: Part Two'],
                    ['subtitle', 'en', 'The quick brown fox — "Dune: Part Two" (2024)'],
                    ['body', 'vi', 'Tiếng Việt có dấu: động vật, ứng dụng, đường, ề, ộ, ư'],
                    ['caption', 'vi', 'ề · ộ · ư · ơ'],
                ]],
                ['script' => 'Cyrillic', 'family' => 'Geist', 'samples' => [
                    ['title-lg', 'ru', 'Продолжить просмотр'],
                    ['subtitle', 'ru', 'Сезон 2, серия 3'],
                ]],
                ['script' => 'Thai', 'family' => 'Noto Sans Thai', 'samples' => [
                    ['title-lg', 'th', 'รับชมต่อ'],
                    ['subtitle', 'th', 'ฤดูกาล 2 ตอนที่ 3'],
                    ['body', 'th', 'ภาษาไทยทดสอบการแสดงผล'],
                ]],
                ['script' => 'Burmese', 'family' => 'Noto Sans Myanmar', 'samples' => [
                    ['title-lg', 'my', $burmese[0]],
                    ['subtitle', 'my', $burmese[1]],
                    ['body', 'my', $burmese[2]],
                ]],
                ['script' => 'Korean', 'family' => 'Noto Sans KR', 'samples' => [
                    ['title-lg', 'ko', '이어서 보기'],
                    ['subtitle', 'ko', '시즌 2 제3화'],
                    ['body', 'ko', '한국어 표시 테스트'],
                ]],
                ['script' => 'Simplified Chinese', 'family' => 'Noto Sans SC', 'samples' => [
                    ['title-lg', 'zh-Hans', '继续观看'],
                    ['subtitle', 'zh-Hans', '第3集《约定》'],
                    ['body', 'zh-Hans', '简体中文显示测试'],
                ]],
                ['script' => 'Traditional Chinese', 'family' => 'Noto Sans TC', 'samples' => [
                    ['title-lg', 'zh-Hant', '繼續觀看'],
                    ['subtitle', 'zh-Hant', '第3集《約定》'],
                    ['body', 'zh-Hant', '繁體中文顯示測試'],
                ]],
                ['script' => 'Japanese', 'family' => 'Noto Sans JP', 'samples' => [
                    ['title-lg', 'ja', '続きを見る'],
                    ['subtitle', 'ja', 'シーズン2 第3話「約束」'],
                    ['body', 'ja', '日本語の表示テスト'],
                ]],
            ],
            'han' => '直骨与',
            'forms' => [
                ['language' => 'Japanese', 'lang' => 'ja', 'family' => 'Noto Sans JP'],
                ['language' => 'Simplified Chinese', 'lang' => 'zh-Hans', 'family' => 'Noto Sans SC'],
                ['language' => 'Traditional Chinese', 'lang' => 'zh-Hant', 'family' => 'Noto Sans TC'],
            ],
            'rules' => [
                'tracking' => ['role' => 'label', 'samples' => [['th', 'ฤดูกาล 2'], ['my', $burmese[0]], ['ko', '시즌 2'], ['ja', 'シーズン2']]],
                'line-height' => ['role' => 'body', 'samples' => [
                    ['th', 'รับชมต่อ · ฤดูกาล 2 ตอนที่ 3 · ภาษาไทยทดสอบการแสดงผล'],
                    ['my', implode(' · ', $burmese)],
                    ['ja', '続きを見る · シーズン2 第3話「約束」· 日本語の表示テスト'],
                ]],
                'size' => ['role' => 'body', 'samples' => [['my', $burmese[1]], ['en', 'Season 2 · ' . $burmese[0]]]],
                'numerals' => ['role' => 'timecode', 'samples' => [['en', '1:11:11'], ['en', '0:48:07'], ['en', '2:30:00']]],
            ],
            'roles' => $roles,
        ];
    }
}
