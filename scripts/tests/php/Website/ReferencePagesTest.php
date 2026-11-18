<?php

declare(strict_types=1);

namespace Baander\Tooling\Tests\Website;

use Baander\Tooling\Website\ReferencePages;
use Baander\Tooling\Website\SourceHistory;
use Baander\Tooling\Website\WebsiteBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReferencePagesTest extends TestCase
{
    private const string CONCEPTS = 'ui/site/reference/concepts.php';
    private const string DEPLOY = 'ui/site/reference/deploy.php';
    private const string PRESETS = 'deploy/preset/';
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/baander-reference-pages-' . bin2hex(random_bytes(6));
        foreach (['ui/site/reference', 'ui/site/templates', 'deploy/preset', 'third_party/inventory', 'docs-book/part-1-operator-guide', 'docs-book/part-2-developer-guide'] as $directory) {
            mkdir($this->root . '/' . $directory, 0700, true);
        }
        file_put_contents($this->root . '/docs-book/part-1-operator-guide/README.md', "# Operator\n## Presets");
        file_put_contents($this->root . '/docs-book/part-2-developer-guide/README.md', '# Developer');
        foreach (['page', 'home', '404'] as $template) {
            file_put_contents($this->root . '/ui/site/templates/' . $template . '.php', '<?php echo $page["html"];');
        }
        // The real page template between a head that only defines the escaper and an empty header and footer.
        copy(dirname(__DIR__, 4) . '/ui/site/templates/reference.php', $this->root . '/ui/site/templates/reference.php');
        file_put_contents(
            $this->root . '/ui/site/templates/head.php',
            '<?php $escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8"); ?><!doctype html><html lang="en"><head><title>Test</title></head><body>',
        );
        file_put_contents($this->root . '/ui/site/templates/header.php', '');
        file_put_contents($this->root . '/ui/site/templates/footer.php', '</body></html>');
    }

    protected function tearDown(): void
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->root);
    }

    public function testEachDataFileYieldsAPageAtItsNameBuiltFromItsSource(): void
    {
        $this->concepts();
        [$concepts] = ReferencePages::load($this->root);

        self::assertSame([
            'template' => 'reference',
            'url' => '/concepts/',
            'title' => 'Concepts',
            'description' => 'The terms Bånder’s code and documentation use.',
            'markdown' => 'CONCEPTS.md',
            'sources' => [self::CONCEPTS, 'ui/site/templates/reference.php', 'CONCEPTS.md'],
            'references' => ['file' => self::CONCEPTS, 'links' => ['/docs/operator-guide/'], 'blocks' => []],
            'data' => ['reference' => [
                'label' => 'Concepts',
                'opening' => 'The shared vocabulary of the <a href="/docs/operator-guide/">manual</a>.',
                'presets' => null,
            ]],
        ], $concepts);
    }

    public function testPageShowsTheSourceTitleThenTheOpeningAndJumpsToEverySection(): void
    {
        $this->concepts();
        $page = $this->build('concepts');

        $titles = $page->getElementsByTagName('h1');
        self::assertCount(1, $titles);
        self::assertSame('concepts', $titles->item(0)?->getAttribute('id'));
        $opening = self::nextElement($titles->item(0));
        self::assertSame('reference-opening', $opening?->getAttribute('class'));
        self::assertStringContainsString('The shared vocabulary of the manual.', (string) $opening?->textContent);
        self::assertSame(
            ['#identity', '#playback'],
            array_map(static fn(\DOMElement $link): string => $link->getAttribute('href'), iterator_to_array(self::query($page, '//nav[@aria-label="On this page"]//a'))),
        );
        self::assertSame(['public-id', 'selected-video-endpoint'], array_map(
            static fn(\DOMElement $heading): string => $heading->getAttribute('id'),
            iterator_to_array($page->getElementsByTagName('h3')),
        ));
        self::assertSame('Concepts', self::query($page, '//*[@data-pagefind-body]/*[@data-pagefind-meta="label"]')->item(0)?->textContent);
    }

    public function testEditingTheSourceChangesThePageWithoutTouchingTheDataFile(): void
    {
        $this->concepts();
        $data = (string) file_get_contents($this->root . '/' . self::CONCEPTS);
        self::assertStringContainsString('A short identifier.', (string) $this->build('concepts')->saveHTML());

        file_put_contents($this->root . '/CONCEPTS.md', str_replace('A short identifier.', 'A 21-character identifier.', (string) file_get_contents($this->root . '/CONCEPTS.md')));
        $html = (string) $this->build('concepts')->saveHTML();

        self::assertStringContainsString('A 21-character identifier.', $html);
        self::assertStringNotContainsString('A short identifier.', $html);
        self::assertSame($data, file_get_contents($this->root . '/' . self::CONCEPTS));
    }

    public function testLicensingLinkToTheReviewRecordOpensTheSourceRepository(): void
    {
        file_put_contents($this->root . '/third_party/inventory/application-review.md', '# Review');
        file_put_contents($this->root . '/THIRD_PARTY.md', "# Third-party licensing\nSee the [application review record](third_party/inventory/application-review.md).");
        $this->data('licensing', ['source' => 'THIRD_PARTY.md', 'title' => 'Third-party licensing', 'description' => 'Licences of what Bånder ships.', 'opening' => 'Licences.']);

        $link = self::query($this->build('licensing'), '//a[text()="application review record"]')->item(0);

        self::assertSame('https://github.com/baander-app/baander/blob/abc123/third_party/inventory/application-review.md', $link?->getAttribute('href'));
    }

    public function testDeployPageComparesTheSixPresetsBySize(): void
    {
        $this->deploy();
        [$deploy] = ReferencePages::load($this->root);
        self::assertSame([self::DEPLOY, 'ui/site/templates/reference.php', 'deploy/preset'], $deploy['sources']);

        $page = $this->build('deploy');
        $region = self::query($page, '//div[@role="region"][@tabindex="0"]')->item(0);
        self::assertSame('Size settings of the example presets', $region?->getAttribute('aria-label'));
        self::assertSame(
            ['Setting', '4 GB remux', '8 GB remux', '16 GB remux', '4 GB transcode', '8 GB transcode', '16 GB transcode'],
            array_map(static fn(\DOMElement $cell): string => trim($cell->textContent), iterator_to_array(self::query($page, '//table//thead//th'))),
        );
        $rows = [];
        foreach (self::query($page, '//table/tbody/tr') as $row) {
            $rows[trim((string) self::query($page, './th', $row)->item(0)?->textContent)] = array_map(
                static fn(\DOMElement $cell): string => trim($cell->textContent),
                iterator_to_array(self::query($page, './td', $row)),
            );
        }
        self::assertSame(['webWorkers', 'databaseSlots', 'ordinaryConsumers', 'sharedBuffersMiB', 'workMemMiB', 'copySlots', 'videoSlots', 'audioSlots'], array_keys($rows));
        self::assertSame(['2', '3', '4', '2', '3', '4'], $rows['webWorkers']);
        self::assertSame(['2', '4', '8', '—not used', '—not used', '—not used'], $rows['copySlots']);
        self::assertSame(['—not used', '—not used', '—not used', '4', '6', '8'], $rows['videoSlots']);
        self::assertSame('not used', self::query($page, '//table/tbody/tr[6]/td[4]/span[@class="visually-hidden"]')->item(0)?->textContent);
    }

    public function testSitemapListsReferencePagesDatedByTheirSources(): void
    {
        $this->concepts();
        $this->deploy();
        $history = SourceHistory::fromGitLog(implode('', [
            "\x1e2026-11-14T10:00:00+00:00\n\ndeploy/preset/example-8gb-remux.json\n",
            "\x1e2026-11-12T10:00:00+00:00\n\nCONCEPTS.md\n",
            "\x1e2026-11-01T10:00:00+00:00\n\n" . self::CONCEPTS . "\n" . self::DEPLOY . "\nui/site/templates/reference.php\n",
        ]));
        $this->builder()->build(publicPages: ReferencePages::load($this->root), history: $history);

        $sitemap = new \DOMDocument();
        self::assertTrue($sitemap->load($this->root . '/var/website/sitemap.xml'));
        $dates = [];
        foreach ($sitemap->getElementsByTagName('url') as $url) {
            $dates[$url->getElementsByTagName('loc')->item(0)?->textContent] = $url->getElementsByTagName('lastmod')->item(0)?->textContent;
        }
        self::assertSame('2026-11-12T10:00:00+00:00', $dates['https://baander.app/concepts/']);
        self::assertSame('2026-11-14T10:00:00+00:00', $dates['https://baander.app/deploy/']);
    }

    /** @return iterable<string, array{\Closure(self): void, string}> */
    public static function invalidData(): iterable
    {
        yield 'unknown key in a data file' => [
            static fn(self $test) => $test->data('concepts', [...self::conceptsData(), 'url' => '/terms/']),
            self::CONCEPTS . ': url: unknown key',
        ];
        yield 'seventh preset file' => [
            static fn(self $test) => $test->preset('32', 'remux', ['copySlots' => 9]),
            self::PRESETS . 'example-32gb-remux.json: not one of the six example presets',
        ];
        yield 'missing preset file' => [
            static fn(self $test) => $test->remove(self::PRESETS . 'example-16gb-transcode.json'),
            self::PRESETS . 'example-16gb-transcode.json: does not exist',
        ];
        yield 'unknown size key' => [
            static fn(self $test) => $test->preset('8', 'remux', ['copySlots' => 4, 'gpuSlots' => 1]),
            self::PRESETS . 'example-8gb-remux.json: size.gpuSlots: unknown key',
        ];
        yield 'missing size key' => [
            static fn(self $test) => $test->preset('8', 'transcode', ['videoSlots' => 6]),
            self::PRESETS . 'example-8gb-transcode.json: size.audioSlots: missing',
        ];
        yield 'capability that disagrees with the file name' => [
            static fn(self $test) => $test->preset('4', 'transcode', ['copySlots' => 2], capability: 'remux'),
            self::PRESETS . 'example-4gb-transcode.json: capability: "remux" disagrees with the file name, which names "transcode"',
        ];
    }

    /** @param \Closure(self): void $break */
    #[DataProvider('invalidData')]
    public function testInvalidDataOrPresetFailsNamingTheFile(\Closure $break, string $problem): void
    {
        $this->concepts();
        $this->deploy();
        $break($this);
        try {
            ReferencePages::load($this->root);
            self::fail('Invalid reference data must fail.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString($problem, $error->getMessage());
        }
    }

    /** @param array<string, mixed> $value */
    public function data(string $name, array $value): void
    {
        file_put_contents($this->root . '/ui/site/reference/' . $name . '.php', '<?php return ' . var_export($value, true) . ';');
    }

    public function remove(string $path): void
    {
        unlink($this->root . '/' . $path);
    }

    /**
     * Writes one example preset; the shared size settings grow with the memory size.
     *
     * @param array<string, int> $slots
     */
    public function preset(string $gigabytes, string $name, array $slots, ?string $capability = null): void
    {
        $step = ['4' => 2, '8' => 3, '16' => 4][$gigabytes] ?? 5;
        $preset = [
            'version' => 2,
            'name' => 'baander-' . $gigabytes . 'gb-' . $name,
            'capability' => $capability ?? $name,
            'size' => ['webWorkers' => $step, 'databaseSlots' => $step * 2, 'ordinaryConsumers' => $step - 1, 'sharedBuffersMiB' => 128 * $step, 'workMemMiB' => 4 * $step, ...$slots],
            'origin' => 'http://baander.app:8080',
        ];
        file_put_contents($this->root . '/' . self::PRESETS . 'example-' . $gigabytes . 'gb-' . $name . '.json', json_encode($preset, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
    }

    /** @return array<string, string> */
    private static function conceptsData(): array
    {
        return [
            'source' => 'CONCEPTS.md',
            'title' => 'Concepts',
            'description' => 'The terms Bånder’s code and documentation use.',
            'opening' => 'The shared vocabulary of the <a href="/docs/operator-guide/">manual</a>.',
        ];
    }

    private function concepts(): void
    {
        file_put_contents($this->root . '/CONCEPTS.md', "# Concepts\nTerms used across Bånder.\n## Identity\n### Public ID\nA short identifier.\n## Playback\n### Selected video endpoint\nThe endpoint that plays video.");
        $this->data('concepts', self::conceptsData());
    }

    private function deploy(): void
    {
        file_put_contents($this->root . '/deploy/preset/README.md', "# Deployment preset\nRuns the stack.\n## Prepare\nSee [presets](../../docs-book/part-1-operator-guide/README.md#presets).");
        $this->data('deploy', ['source' => 'deploy/preset/README.md', 'title' => 'Deploy preset', 'description' => 'How to start Bånder from a preset.', 'opening' => 'A preset.']);
        foreach (['4' => [2, 4, 6], '8' => [4, 6, 9], '16' => [8, 8, 12]] as $gigabytes => [$copy, $video, $audio]) {
            $this->preset((string) $gigabytes, 'remux', ['copySlots' => $copy]);
            $this->preset((string) $gigabytes, 'transcode', ['videoSlots' => $video, 'audioSlots' => $audio]);
        }
    }

    private function build(string $slug): \DOMDocument
    {
        $this->builder()->build(publicPages: ReferencePages::load($this->root));
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">' . file_get_contents($this->root . '/var/website/' . $slug . '/index.html'));
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $document;
    }

    /** @return \DOMNodeList<\DOMElement> */
    private static function query(\DOMDocument $document, string $expression, ?\DOMNode $context = null): \DOMNodeList
    {
        /** @var \DOMNodeList<\DOMElement> */
        return new \DOMXPath($document)->query($expression, $context);
    }

    private static function nextElement(?\DOMNode $node): ?\DOMElement
    {
        do {
            $node = $node?->nextSibling;
        } while ($node !== null && !$node instanceof \DOMElement);

        return $node;
    }

    private function builder(): WebsiteBuilder
    {
        return new WebsiteBuilder($this->root, 'https://github.com/baander-app/baander/tree/abc123');
    }
}
