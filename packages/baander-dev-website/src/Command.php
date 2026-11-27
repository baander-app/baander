<?php

declare(strict_types=1);

namespace Baander\DevWebsite;

use Baander\Configuration\ToolingConfiguration;
use Baander\Process\Execution\EngineExecutor;
use Baander\Process\Execution\ExecutionContext;
use Baander\Process\Execution\ProcessSpec;
use Baander\DevWebsite\Content\DesignDocument;
use Baander\DevWebsite\Content\DesignSystemPages;
use Baander\DevWebsite\Content\DesignTokens;
use Baander\DevWebsite\Content\FeaturePages;
use Baander\DevWebsite\Content\ReferencePages;
use Baander\DevWebsite\Assets\Screenshots;
use Baander\DevWebsite\Markdown\BlockRendering;
use Baander\DevWebsite\Assets\AssetPublication;
use Baander\DevWebsite\Evidence\AacEvidence;
use Baander\DevWebsite\Metadata\SourceHistory;
use Baander\DevWebsite\Content\SiteMenu;
use Baander\DevWebsite\Evidence\AacCharts;

final class Command
{
    /**
     * Repository paths whose commit dates become the sitemap's last-modified values; the reference pages add the
     * documents they publish.
     */
    private const array SITE_SOURCES = [
        'docs-book',
        'ui/site/templates',
        FeaturePages::DIRECTORY,
        ReferencePages::DIRECTORY,
        ReferencePages::PRESETS,
        Screenshots::DIRECTORY,
        'ui/design-tokens/tokens',
        DesignTokens::THEME,
        ...DesignSystemPages::DOCUMENTS,
        ...DesignSystemPages::SOURCES,
        'packages/baander-audio-core/bench/results',
    ];

    /**
     * The site's elements, besides code, kbd, samp and pre, whose styles set the mono stack; a typography
     * sample without the numeral rule uses the interface stack.
     */
    private const string MONO_SELECTOR = '.step-number, .type-timecode:not(.typography-without-numerals), .type-mono-sm';

    private const string DESIGN = 'ui/DESIGN.md';

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
            $codecRevision = $executor->run(new ProcessSpec(['git', 'log', '-1', '--format=%H', '--', 'packages/baander-audio-core']))->stdout |> trim(...);
            if (!preg_match('/^[a-f0-9]{40}$/', $codecRevision)) {
                throw new \RuntimeException('Cannot resolve the Bånder Audio Core source revision.');
            }
            $blocks = new BlockRendering($root, $executor, AssetPublication::theme($root));
            $revisionUrl = 'https://github.com/baander-app/baander/tree/' . $revision;
            $evidence = new AacEvidence()->load($root);
            $features = FeaturePages::load($root);
            $references = ReferencePages::load($root);
            $designSystem = DesignSystemPages::load($root);
            $history = $executor->run(new ProcessSpec([...SourceHistory::GIT_LOG, '--', ...self::SITE_SOURCES, ...array_column($references, 'markdown')]))->stdout
                |> SourceHistory::fromGitLog(...);
            $typography = [
                'template' => 'typography',
                'url' => '/typography/',
                'title' => 'Typography',
                'description' => 'How Bånder sets Latin, Vietnamese, Cyrillic, Thai, Burmese, Korean, Chinese and Japanese titles: fonts, glyph forms, script rules and type roles.',
                'stylesheet' => 'typography.css',
                'sources' => ['ui/site/templates/typography.php', 'ui/site/assets/site.css', 'ui/site/assets/typography.css', DesignTokens::TYPE, self::DESIGN],
                'data' => ['typography' => self::typography($root)],
            ];
            $pages = new WebsiteBuilder($root, $revisionUrl, SiteMenu::load($root, $features))->build(
                static function (string $staging) use ($executor, $root): void {
                    $latinFaces = AssetPublication::assets($root, $staging);
                    AssetPublication::pageFonts($root, $staging, self::MONO_SELECTOR, $latinFaces);
                    $executor->run(new ProcessSpec(
                        ['corepack', 'yarn', 'build', $staging . '/assets/site/search.js'],
                        cwd: $root . '/ui/site',
                    ));
                    $executor->run(new ProcessSpec(
                        ['corepack', 'yarn', 'pagefind', '--site', $staging, '--output-subdir', 'pagefind'],
                        cwd: $root . '/ui/site',
                    ));
                    AssetPublication::package($staging);
                },
                $blocks->render(...),
                [[
                    'template' => 'bac',
                    'url' => '/bac/',
                    'title' => 'Bånder Audio Core',
                    'description' => 'Bånder’s AAC and lossless FLAC codec library: the server transcoder’s FFmpeg encoder, Electron integration, and recorded quality, size and speed comparisons.',
                    'stylesheet' => 'bac.css',
                    'script' => 'bac-charts.js',
                    'sources' => ['ui/site/templates/bac.php', 'packages/baander-audio-core/bench/results'],
                    'data' => ['aac' => [
                        'evidence' => $evidence,
                        'charts' => new AacCharts()->render($evidence),
                        'sourceBase' => 'https://github.com/baander-app/baander/blob/' . $codecRevision . '/packages/baander-audio-core/',
                    ]],
                ], $typography, ...$designSystem, ...$features, ...$references],
                $history,
            );
            fwrite(STDOUT, 'Built ' . count($pages) . ' manual pages in var/website.' . "\n");
            return 0;
        } finally {
            $executor->close();
        }
    }

    /**
     * The typography page's samples, in the order the page shows them, and its type roles as the design
     * tokens and the Roles table of `ui/DESIGN.md` state them, so the scale it shows is the scale the tokens build.
     *
     * @return array<string,mixed>
     */
    private static function typography(string $root): array
    {
        $roles = DesignSystemPages::typeRoles(DesignTokens::load($root), DesignDocument::load($root, self::DESIGN));
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
