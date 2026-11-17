<?php

declare(strict_types=1);

namespace Baander\Tooling\Tests\Website;

use Baander\Tooling\Website\FeaturePages;
use Baander\Tooling\Website\SourceHistory;
use Baander\Tooling\Website\WebsiteBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FeaturePagesTest extends TestCase
{
    private const string INDEX = 'ui/site/features/index.php';
    private const string EQUALIZER = 'ui/site/features/equalizer.php';
    private const string CONSOLE = 'ui/site/features/console.php';
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/baander-feature-pages-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/ui/site/features', 0700, true);
        mkdir($this->root . '/ui/site/assets/screenshots', 0700, true);
    }

    protected function tearDown(): void
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->root);
    }

    public function testAbsentOrEmptyFeatureDirectoryYieldsNoPages(): void
    {
        $withEmptyDirectory = FeaturePages::load($this->root);
        rmdir($this->root . '/ui/site/features');
        self::assertSame([[], []], [$withEmptyDirectory, FeaturePages::load($this->root)]);
    }

    public function testValidDataYieldsTheOverviewThenOnePageDescriptorPerIndexEntry(): void
    {
        $this->site();
        [$overview, $equalizer, $console] = FeaturePages::load($this->root);

        self::assertSame(
            ['feature', '/features/equalizer/', 'Equalizer', 'Tune ten bands and save the result as a profile while the music plays.', 'features.css'],
            [$equalizer['template'], $equalizer['url'], $equalizer['title'], $equalizer['description'], $equalizer['stylesheet']],
        );
        self::assertSame([['name' => 'Features', 'url' => '/features/']], $equalizer['trail']);
        self::assertEquals([
            'feature' => [
                'name' => 'Equalizer',
                'summary' => 'Shape the sound of every song.',
                'description' => 'Tune ten bands and save the result as a profile while the music plays.',
                'headline' => 'Sound, <em>your</em> way.',
                'lead' => ['Open the equalizer from the player.', 'Read <a href="/docs/operator-guide/media/#equalizer">how it processes audio</a>.'],
                'hero' => ['picture' => 'equalizer', 'alt' => 'The equalizer with ten bands.', 'caption' => 'The graphic equalizer', 'width' => 4, 'height' => 2],
                'tour' => [
                    [
                        'id' => 'bands', 'heading' => 'Ten bands', 'text' => ['Drag a band up or down.'], 'layout' => 'wide',
                        'figure' => ['picture' => 'equalizer-bands', 'alt' => 'One band dragged up.', 'caption' => 'One band raised', 'width' => 6, 'height' => 2],
                    ],
                    ['id' => 'profiles', 'heading' => 'Profiles', 'text' => ['Save what you hear as a profile.'], 'layout' => 'side', 'figure' => null],
                ],
                'guide' => ['needs' => 'A running Bånder server.', 'steps' => [['title' => 'Open the player', 'text' => 'Press <kbd>E</kbd>.']]],
                'planned' => [],
                'limits' => ['The equalizer works in signed-in browsers only.'],
                'manual' => [],
            ],
            'group' => ['id' => 'listeners', 'title' => 'For listeners'],
            'previous' => null,
            'next' => ['name' => 'Console', 'url' => '/features/console/'],
            'overview' => '/features/',
        ], $equalizer['data']);
        self::assertSame([
            self::EQUALIZER,
            'ui/site/templates/feature.php',
            'ui/site/templates/features.php',
            'ui/site/templates/feature-figure.php',
            'ui/site/assets/screenshots/equalizer.webp',
            'ui/site/assets/screenshots/equalizer-light.webp',
            'ui/site/assets/screenshots/equalizer-bands.webp',
            'ui/site/assets/screenshots/equalizer-bands-light.webp',
        ], $equalizer['sources']);
        self::assertSame(['file' => self::EQUALIZER, 'links' => ['/docs/operator-guide/media/#equalizer'], 'blocks' => []], $equalizer['references']);

        self::assertSame(['feature', '/features/console/', 'Console'], [$console['template'], $console['url'], $console['title']]);
        self::assertSame(['id' => 'operators', 'title' => 'For operators'], $console['data']['group']);
        self::assertSame(['name' => 'Equalizer', 'url' => '/features/equalizer/'], $console['data']['previous']);
        self::assertNull($console['data']['next']);
        self::assertSame([['title' => 'Shell completion', 'text' => 'Completion scripts are <em>planned</em>.']], $console['data']['feature']['planned']);
        self::assertSame([['label' => 'Command reference', 'href' => '/docs/operator-guide/commands/']], $console['data']['feature']['manual']);
        self::assertSame(
            [self::CONSOLE, 'ui/site/templates/feature.php', 'ui/site/templates/features.php', 'ui/site/templates/feature-figure.php', 'docs-book/part-1-operator-guide/commands.md'],
            $console['sources'],
        );

        self::assertSame(
            ['features', '/features/', 'Features', 'What Bånder does for listeners, operators and developers.', 'features.css'],
            [$overview['template'], $overview['url'], $overview['title'], $overview['description'], $overview['stylesheet']],
        );
        self::assertArrayNotHasKey('trail', $overview);
        self::assertEquals(['features' => [
            'description' => 'What Bånder does for listeners, operators and developers.',
            'opening' => ['headline' => 'Everything Bånder does.', 'lead' => ['Pick a feature.']],
            'groups' => [
                ['id' => 'listeners', 'title' => 'For listeners', 'lead' => 'Play your collection.', 'pages' => [
                    ['name' => 'Equalizer', 'summary' => 'Shape the sound of every song.', 'url' => '/features/equalizer/'],
                ]],
                ['id' => 'operators', 'title' => 'For operators', 'lead' => 'Run the server.', 'pages' => [
                    ['name' => 'Console', 'summary' => 'Run Bånder from the command line.', 'url' => '/features/console/'],
                ]],
            ],
            'more' => [['name' => 'Movies', 'text' => 'Browse and play movies.', 'manual' => ['label' => 'Media', 'href' => '/docs/operator-guide/media/']]],
        ]], $overview['data']);
        self::assertSame(['ui/site/features', 'ui/site/templates/features.php'], $overview['sources']);
        self::assertSame(['file' => self::INDEX, 'links' => ['/docs/operator-guide/media/'], 'blocks' => []], $overview['references']);
    }

    public function testHeroMayBeAPictureOrAManualBlock(): void
    {
        $this->site();
        [, $equalizer, $console] = FeaturePages::load($this->root);
        self::assertSame(['width' => 4, 'height' => 2], array_intersect_key($equalizer['data']['feature']['hero'], ['width' => 0, 'height' => 0]));
        self::assertSame(['manual' => 'part-1-operator-guide/commands.md', 'contains' => 'app:scan'], $console['data']['feature']['hero']);
        self::assertSame(
            [['manual' => 'part-1-operator-guide/commands.md', 'contains' => 'app:scan', 'into' => ['feature', 'hero']]],
            $console['references']['blocks'],
        );

        $this->data('equalizer', self::equalizer(['hero' => ['caption' => 'Neither a picture nor a manual block']]));
        self::assertSame(self::EQUALIZER . ': hero: must be a picture or a manual block', $this->failure());
    }

    public function testPictureWithoutItsLightVariantNamesTheDataFileThePictureAndTheVariant(): void
    {
        $this->site();
        unlink($this->root . '/ui/site/assets/screenshots/equalizer-light.webp');
        self::assertSame(
            self::EQUALIZER . ': hero: picture "equalizer" has no light variant ui/site/assets/screenshots/equalizer-light.webp',
            $this->failure(),
        );

        file_put_contents($this->root . '/ui/site/assets/screenshots/equalizer-light.webp', 'not a picture');
        self::assertSame(
            self::EQUALIZER . ': hero: picture "equalizer" has a light variant that is not a picture: ui/site/assets/screenshots/equalizer-light.webp',
            $this->failure(),
        );
    }

    /** @return iterable<string, array{string, string, int, string}> */
    public static function unusablePairs(): iterable
    {
        yield 'unequal dimensions' => ['4x2', '6x2', 0, 'picture "equalizer" is 4 × 2 in its dark variant and 6 × 2 in its light variant; both must have one size'];
        yield 'odd width' => ['3x2', '3x2', 0, 'picture "equalizer" is 3 × 2; its width and height must be even'];
        yield 'file over the ceiling' => ['4x2', '4x2', 500_001, 'picture "equalizer": ui/site/assets/screenshots/equalizer-light.webp is 500039 bytes; a picture file has at most 500000'];
    }

    #[DataProvider('unusablePairs')]
    public function testPicturePairNeedsEqualEvenDimensionsAndFilesWithinTheSizeCeiling(string $dark, string $light, int $padding, string $problem): void
    {
        $this->site();
        $this->picture('equalizer', $dark, $light);
        file_put_contents($this->root . '/ui/site/assets/screenshots/equalizer-light.webp', str_repeat("\0", $padding), FILE_APPEND);
        self::assertSame(self::EQUALIZER . ': hero: ' . $problem, $this->failure());
    }

    public function testPictureFileAtTheSizeCeilingPasses(): void
    {
        $this->site();
        file_put_contents($this->root . '/ui/site/assets/screenshots/equalizer.webp', str_repeat("\0", 500_000 - 38), FILE_APPEND);
        self::assertSame(500_000, filesize($this->root . '/ui/site/assets/screenshots/equalizer.webp'));
        self::assertCount(3, FeaturePages::load($this->root));
    }

    public function testAlternativeTextMustBePresentAndUniqueWithinAPage(): void
    {
        $this->site();
        $page = self::equalizer();
        $page['hero']['alt'] = ' ';
        $this->data('equalizer', $page);
        self::assertSame(self::EQUALIZER . ': hero.alt: must be a non-empty string', $this->failure());

        $page['hero']['alt'] = 'One band dragged up.';
        $this->data('equalizer', $page);
        self::assertSame(self::EQUALIZER . ': tour[0].figure.alt: repeats the alternative text of another picture on the page', $this->failure());
    }

    public function testMissingPartsUnknownKeysAndTheNumberOfTourSectionsAreReported(): void
    {
        $this->site();
        $page = self::equalizer();
        unset($page['guide'], $page['tour'][0]['heading']);
        $page['tour'][1]['colour'] = 'violet';
        $page['audience'] = 'listeners';
        $this->data('equalizer', $page);
        self::assertSame(implode("\n", [
            self::EQUALIZER . ': tour[0].heading: missing',
            self::EQUALIZER . ': tour[1].colour: unknown key',
            self::EQUALIZER . ': guide: missing',
            self::EQUALIZER . ': audience: unknown key',
        ]), $this->failure());

        $section = static fn(int $number): array => ['id' => 'section-' . $number, 'heading' => 'Section ' . $number, 'text' => ['Text ' . $number . '.']];
        $this->data('equalizer', self::equalizer(['tour' => array_map($section, range(1, 5))]));
        self::assertSame(self::EQUALIZER . ': tour: 5 listed; it takes 2 to 4', $this->failure());
        $this->data('equalizer', self::equalizer(['tour' => [$section(1)]]));
        self::assertSame(self::EQUALIZER . ': tour: 1 listed; it takes 2 to 4', $this->failure());
        $this->data('equalizer', self::equalizer(['tour' => array_map($section, range(1, 4)), 'limits' => [], 'planned' => []]));
        self::assertSame(self::EQUALIZER . ': limits: 0 listed; it takes at least 1', $this->failure());
    }

    public function testFieldsOfTheWrongKindAreReported(): void
    {
        $this->site();
        $page = self::equalizer(['lead' => 'One paragraph', 'guide' => ['A running server'], 'manual' => [['label' => 'Media', 'href' => '/bac/']]]);
        $page['tour'][0]['id'] = 'Ten Bands';
        $page['tour'][0]['layout'] = 'full';
        $this->data('equalizer', $page);
        self::assertSame(implode("\n", [
            self::EQUALIZER . ': lead: must be a list',
            self::EQUALIZER . ': tour[0].id: must be kebab-case',
            self::EQUALIZER . ': tour[0].layout: must be "side" or "wide"',
            self::EQUALIZER . ': guide: must be an array with named keys',
            self::EQUALIZER . ': manual[0].href: must be a manual page URL that starts with /docs/',
        ]), $this->failure());

        file_put_contents($this->root . '/' . self::EQUALIZER, '<?php $page = [];');
        self::assertSame(self::EQUALIZER . ': must return an array', $this->failure());
    }

    public function testIndexAndDataFilesMustAgreeBothWays(): void
    {
        $this->site();
        $this->data('lyrics', self::equalizer(['name' => 'Lyrics', 'description' => 'Read synced lyrics while a song plays.']));
        self::assertSame('ui/site/features/lyrics.php: not listed in ' . self::INDEX, $this->failure());

        unlink($this->root . '/ui/site/features/lyrics.php');
        unlink($this->root . '/' . self::CONSOLE);
        self::assertSame(self::INDEX . ': lists "console", which has no data file ' . self::CONSOLE, $this->failure());

        $this->data('console', self::console());
        $index = self::index();
        $index['groups'][1]['pages'] = ['console', 'equalizer'];
        $this->data('index', $index);
        self::assertSame(self::INDEX . ': lists "equalizer" 2 times', $this->failure());

        unlink($this->root . '/' . self::INDEX);
        self::assertSame(implode("\n", [
            self::INDEX . ': does not exist',
            self::CONSOLE . ': not listed in ' . self::INDEX,
            self::EQUALIZER . ': not listed in ' . self::INDEX,
        ]), $this->failure());
    }

    public function testErrorsInTwoFilesAreReportedInOneException(): void
    {
        $this->site();
        $this->data('equalizer', self::equalizer(['colour' => 'violet']));
        $console = self::console();
        unset($console['limits']);
        $this->data('console', $console);
        self::assertSame(implode("\n", [
            self::CONSOLE . ': limits: missing',
            self::EQUALIZER . ': colour: unknown key',
        ]), $this->failure());
    }

    public function testDescriptionHasAtMost155Characters(): void
    {
        $this->site();
        $this->data('equalizer', self::equalizer(['description' => str_repeat('å', 155)]));
        self::assertCount(3, FeaturePages::load($this->root));
        $this->data('equalizer', self::equalizer(['description' => str_repeat('å', 156)]));
        self::assertSame(self::EQUALIZER . ': description: has 156 characters; a search description has at most 155', $this->failure());
        $this->data('index', self::index(['description' => str_repeat('a', 156)]));
        self::assertStringContainsString(self::INDEX . ': description: has 156 characters; a search description has at most 155', $this->failure());
    }

    public function testComposedTitleHasAtMost60Characters(): void
    {
        $this->site();
        $this->data('equalizer', self::equalizer(['name' => str_repeat('å', 40)]));
        self::assertSame(60, mb_strlen(str_repeat('å', 40) . ' — Features — Bånder'));
        self::assertCount(3, FeaturePages::load($this->root));
        $this->data('equalizer', self::equalizer(['name' => str_repeat('å', 41)]));
        self::assertSame(
            self::EQUALIZER . ': name: makes the title "' . str_repeat('å', 41) . ' — Features — Bånder", which has 61 characters; a title has at most 60',
            $this->failure(),
        );
    }

    public function testRepeatedTitleOrDescriptionFails(): void
    {
        $this->site();
        $this->data('equalizer', self::equalizer(['description' => self::console()['description']]));
        self::assertSame(self::EQUALIZER . ': description: repeats the description of ' . self::CONSOLE, $this->failure());
        $this->data('equalizer', self::equalizer(['description' => self::index()['description']]));
        self::assertSame(self::EQUALIZER . ': description: repeats the description of ' . self::INDEX, $this->failure());
        $this->data('equalizer', self::equalizer(['name' => 'Console']));
        self::assertSame(self::EQUALIZER . ': name: repeats the name of ' . self::CONSOLE . ', so both pages would have one title', $this->failure());
    }

    public function testProseMustBeWellFormedAndItsLinksStayOnTheSite(): void
    {
        $this->site();
        $this->data('equalizer', self::equalizer(['lead' => ['Open the <strong>equalizer from the player.']]));
        self::assertStringStartsWith(self::EQUALIZER . ': lead[0]: is not well-formed HTML', $this->failure());
        $this->data('equalizer', self::equalizer(['lead' => ['Read <a href="/docs/>the manual</a>.']]));
        self::assertStringStartsWith(self::EQUALIZER . ': lead[0]: is not well-formed HTML', $this->failure());
        $this->data('equalizer', self::equalizer(['lead' => ['Open the equalizer <!-- from the player.']]));
        self::assertSame(self::EQUALIZER . ': lead[0]: is not well-formed HTML (a tag, attribute or comment stays open)', $this->failure());

        $this->data('equalizer', self::equalizer(['lead' => [
            'Hear it on <a href="https://music.baander.app/">the demo</a>.',
            'Read <a href="//music.baander.app/docs/">the manual</a> or <a href="mailto:listeners@baander.app">write</a>.',
        ]]));
        self::assertSame(implode("\n", [
            self::EQUALIZER . ': lead[0]: the link to https://music.baander.app/ leaves the site',
            self::EQUALIZER . ': lead[1]: the link to //music.baander.app/docs/ leaves the site',
            self::EQUALIZER . ': lead[1]: the link to mailto:listeners@baander.app leaves the site',
        ]), $this->failure());

        $index = self::index();
        $index['groups'][0]['lead'] = 'Play your <em>collection.';
        $this->data('index', $index);
        $this->data('equalizer', self::equalizer(['headline' => 'Sound,<br>your way &amp; <a href="#bands">ten bands</a>.']));
        $message = $this->failure();
        self::assertStringStartsWith(self::INDEX . ': groups[0].lead: is not well-formed HTML', $message);
        self::assertStringNotContainsString(self::EQUALIZER, $message);
    }

    public function testBuiltPagesCarryTheirTitleTheBorrowedBlockAndTheNewestDateAmongTheirSources(): void
    {
        $this->site();
        $this->manual();
        $history = SourceHistory::fromGitLog(implode('', [
            "\x1e2026-11-12T10:00:00+00:00\n\ndocs-book/part-1-operator-guide/commands.md\n",
            "\x1e2026-11-10T10:00:00+00:00\n\nui/site/assets/screenshots/equalizer-bands-light.webp\n",
            "\x1e2026-11-03T10:00:00+00:00\n\n" . self::INDEX . "\n",
            "\x1e2026-11-02T10:00:00+00:00\n\n" . self::CONSOLE . "\nui/site/templates/feature.php\nui/site/templates/features.php\n",
            "\x1e2026-11-01T10:00:00+00:00\n\n" . self::EQUALIZER . "\nui/site/assets/screenshots/equalizer.webp\n",
        ]));
        $this->builder()->build(publicPages: FeaturePages::load($this->root), history: $history);

        $equalizer = $this->built('features/equalizer');
        self::assertSame('Equalizer — Features — Bånder', $equalizer['title']);
        self::assertSame('features.css', $equalizer['stylesheet']);
        self::assertSame(4, $equalizer['data']['feature']['hero']['width']);
        $block = $this->built('features/console')['data']['feature']['hero']['html'];
        self::assertStringContainsString('app:scan &lt;library&gt;', $block);
        self::assertStringContainsString($block, (string) file_get_contents($this->root . '/var/website/docs/operator-guide/commands/index.html'));
        self::assertSame('Features — Bånder', $this->built('features')['title']);

        $document = new \DOMDocument();
        self::assertTrue($document->load($this->root . '/var/website/sitemap.xml'));
        $dates = [];
        foreach ($document->getElementsByTagName('url') as $url) {
            $dates[$url->getElementsByTagName('loc')->item(0)?->textContent] = $url->getElementsByTagName('lastmod')->item(0)?->textContent;
        }
        self::assertSame([
            'https://baander.app/features/' => '2026-11-03T10:00:00+00:00',
            'https://baander.app/features/console/' => '2026-11-12T10:00:00+00:00',
            'https://baander.app/features/equalizer/' => '2026-11-10T10:00:00+00:00',
        ], array_filter($dates, static fn(string $location): bool => str_contains($location, '/features/'), ARRAY_FILTER_USE_KEY));
    }

    public function testManualEditThatRemovesWhatAPageTakesFailsTheBuildAndNamesFilePageAndText(): void
    {
        $this->site();
        $this->manual();
        $this->builder()->build(publicPages: FeaturePages::load($this->root));
        $published = file_get_contents($this->root . '/var/website/features/console/index.html');

        file_put_contents($this->root . '/docs-book/part-1-operator-guide/commands.md', "# Commands\n\n```text\nphp bin/console app:library:scan <library>\n```\n");
        file_put_contents($this->root . '/docs-book/part-1-operator-guide/media.md', "# Media\n\n## Sound\n");
        try {
            $this->builder()->build(publicPages: FeaturePages::load($this->root));
            self::fail('A reference the manual no longer satisfies must fail the build.');
        } catch (\RuntimeException $error) {
            self::assertSame(implode("\n", [
                self::EQUALIZER . ': manual link /docs/operator-guide/media/#equalizer names a heading its page does not have',
                self::CONSOLE . ': no block of part-1-operator-guide/commands.md contains "app:scan"',
            ]), $error->getMessage());
        }
        self::assertSame($published, file_get_contents($this->root . '/var/website/features/console/index.html'));
    }

    /**
     * Writes a valid index, the equalizer page with two pictures and the console page whose hero is
     * a manual block.
     */
    private function site(): void
    {
        $this->data('index', self::index());
        $this->data('equalizer', self::equalizer());
        $this->data('console', self::console());
        $this->picture('equalizer', '4x2', '4x2');
        $this->picture('equalizer-bands', '6x2', '6x2');
    }

    /** The manual pages the valid site links to and borrows from, and templates that print what they receive. */
    private function manual(): void
    {
        mkdir($this->root . '/docs-book/part-1-operator-guide', 0700, true);
        mkdir($this->root . '/docs-book/part-2-developer-guide', 0700, true);
        mkdir($this->root . '/ui/site/templates', 0700, true);
        file_put_contents($this->root . '/docs-book/part-1-operator-guide/README.md', "# Operator\n[Commands](commands.md)\n[Media](media.md)");
        file_put_contents($this->root . '/docs-book/part-1-operator-guide/commands.md', "# Commands\n\n```text\nphp bin/console app:scan <library>\n```\n");
        file_put_contents($this->root . '/docs-book/part-1-operator-guide/media.md', "# Media\n\n## Equalizer\n");
        file_put_contents($this->root . '/docs-book/part-2-developer-guide/README.md', '# Developer');
        foreach (['page', 'home', '404'] as $template) {
            file_put_contents($this->root . '/ui/site/templates/' . $template . '.php', '<?php echo $page["html"];');
        }
        foreach (['feature', 'features'] as $template) {
            file_put_contents(
                $this->root . '/ui/site/templates/' . $template . '.php',
                '<?php echo json_encode(["title" => $page["meta"]->title, "stylesheet" => $page["stylesheet"], "data" => $data], JSON_THROW_ON_ERROR | JSON_HEX_TAG);',
            );
        }
    }

    /** @return array<string, mixed> what the page's template received */
    private function built(string $path): array
    {
        return json_decode((string) file_get_contents($this->root . '/var/website/' . $path . '/index.html'), true, flags: JSON_THROW_ON_ERROR);
    }

    private function builder(): WebsiteBuilder
    {
        return new WebsiteBuilder($this->root, 'https://github.com/baander-app/baander/tree/abc123');
    }

    private function failure(): string
    {
        try {
            FeaturePages::load($this->root);
        } catch (\RuntimeException $error) {
            return $error->getMessage();
        }
        self::fail('Invalid feature data must fail.');
    }

    /** @param array<string, mixed> $value */
    private function data(string $name, array $value): void
    {
        file_put_contents($this->root . '/ui/site/features/' . $name . '.php', '<?php return ' . var_export($value, true) . ';');
    }

    /** Copies the fixtures of the given dimensions as the picture's dark and light files. */
    private function picture(string $name, string $dark, string $light): void
    {
        $fixtures = dirname(__DIR__) . '/Fixtures/picture-';
        copy($fixtures . $dark . '.webp', $this->root . '/ui/site/assets/screenshots/' . $name . '.webp');
        copy($fixtures . $light . '.webp', $this->root . '/ui/site/assets/screenshots/' . $name . '-light.webp');
    }

    /**
     * @param array<string, mixed> $changes
     * @return array<string, mixed>
     */
    private static function index(array $changes = []): array
    {
        return array_replace([
            'description' => 'What Bånder does for listeners, operators and developers.',
            'opening' => ['headline' => 'Everything Bånder does.', 'lead' => ['Pick a feature.']],
            'groups' => [
                ['id' => 'listeners', 'title' => 'For listeners', 'lead' => 'Play your collection.', 'pages' => ['equalizer']],
                ['id' => 'operators', 'title' => 'For operators', 'lead' => 'Run the server.', 'pages' => ['console']],
            ],
            'more' => [['name' => 'Movies', 'text' => 'Browse and play movies.', 'manual' => ['label' => 'Media', 'href' => '/docs/operator-guide/media/']]],
        ], $changes);
    }

    /**
     * @param array<string, mixed> $changes
     * @return array<string, mixed>
     */
    private static function equalizer(array $changes = []): array
    {
        return array_replace([
            'name' => 'Equalizer',
            'summary' => 'Shape the sound of every song.',
            'description' => 'Tune ten bands and save the result as a profile while the music plays.',
            'headline' => 'Sound, <em>your</em> way.',
            'lead' => ['Open the equalizer from the player.', 'Read <a href="/docs/operator-guide/media/#equalizer">how it processes audio</a>.'],
            'hero' => ['picture' => 'equalizer', 'alt' => 'The equalizer with ten bands.', 'caption' => 'The graphic equalizer'],
            'tour' => [
                [
                    'id' => 'bands', 'heading' => 'Ten bands', 'text' => ['Drag a band up or down.'], 'layout' => 'wide',
                    'figure' => ['picture' => 'equalizer-bands', 'alt' => 'One band dragged up.', 'caption' => 'One band raised'],
                ],
                ['id' => 'profiles', 'heading' => 'Profiles', 'text' => ['Save what you hear as a profile.']],
            ],
            'guide' => ['needs' => 'A running Bånder server.', 'steps' => [['title' => 'Open the player', 'text' => 'Press <kbd>E</kbd>.']]],
            'limits' => ['The equalizer works in signed-in browsers only.'],
        ], $changes);
    }

    /**
     * @param array<string, mixed> $changes
     * @return array<string, mixed>
     */
    private static function console(array $changes = []): array
    {
        return array_replace([
            'name' => 'Console',
            'summary' => 'Run Bånder from the command line.',
            'description' => 'Scan libraries and manage accounts with console commands.',
            'headline' => 'Every task has a command.',
            'lead' => ['The console reaches what the administration pages reach.'],
            'hero' => ['manual' => 'part-1-operator-guide/commands.md', 'contains' => 'app:scan'],
            'tour' => [
                ['id' => 'scan', 'heading' => 'Scan a library', 'text' => ['One command scans a library.']],
                ['id' => 'accounts', 'heading' => 'Manage accounts', 'text' => ['Create and remove accounts.']],
            ],
            'guide' => ['needs' => 'Shell access to the server.', 'steps' => [['title' => 'List the commands', 'text' => 'Run <code>php bin/console list app</code>.']]],
            'planned' => [['title' => 'Shell completion', 'text' => 'Completion scripts are <em>planned</em>.']],
            'limits' => ['Commands run on the server.'],
            'manual' => [['label' => 'Command reference', 'href' => '/docs/operator-guide/commands/']],
        ], $changes);
    }
}
