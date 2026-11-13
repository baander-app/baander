<?php

declare(strict_types=1);

namespace Baander\Tooling\Tests\Website;

use Baander\Tooling\Website\AacCharts;
use Baander\Tooling\Website\AacEvidence;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AacEvidenceTest extends TestCase
{
    /** A made-up run in the shape the benchmark harness's lossless stage writes, generated from its own helpers. */
    private const string LOSSLESS_FIXTURE = __DIR__ . '/../Fixtures/aac-lossless-result.json';
    private const array LOSSLESS_ORDER = [
        'bac-0', 'bac-1', 'bac-2', 'bac-3', 'bac-4', 'bac-5', 'bac-6', 'bac-7', 'bac-8',
        'flac-0', 'flac-1', 'flac-2', 'flac-3', 'flac-4', 'flac-5', 'flac-6', 'flac-7', 'flac-8',
        'flac-ffmpeg-5', 'flac-ffmpeg-8', 'alac-ffmpeg', 'alac-apple-ref',
    ];

    private string $root;
    private string $directory;
    /** @var list<array<string, mixed>> */
    private array $summary = [];
    /** @var list<array<string, mixed>> */
    private array $raw = [];
    /** @var list<array<string, mixed>> */
    private array $speed = [];
    /** @var array<array-key, mixed> */
    private array $lossless = [];

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/baander-audio-core-evidence-' . bin2hex(random_bytes(6));
        $this->directory = $this->root . '/packages/baander-audio-core/bench/results/';
        mkdir($this->directory, 0700, true);
        file_put_contents($this->root . '/packages/baander-audio-core/package.json', json_encode(['name' => '@baander/audio-core', 'version' => '0.2.0'], JSON_THROW_ON_ERROR));
        $profiles = ['lc' => [[64, 96, 128, 192, 256], ['ours', 'ffmpeg', 'fdk', 'libxaac', 'apple']], 'he' => [[32, 48, 64], ['ours', 'fdk', 'libxaac', 'apple']], 'hev2' => [[24, 32], ['ours', 'fdk', 'libxaac']], 'xhe' => [[24, 32, 48, 64, 96], ['ours', 'fdk', 'libxaac']]];
        foreach ($profiles as $family => [$rates, $encoders]) {
            foreach ($encoders as $encoder) {
                foreach ($rates as $rate) {
                    if ($family === 'xhe' && $encoder === 'libxaac' && $rate < 64) {
                        continue;
                    }
                    $record = ['config' => "$family-$encoder-cbr$rate", 'family' => $family, 'encoder' => $encoder, 'mode' => 'cbr', 'nominal' => $rate, 'items' => 10, 'kbps' => $rate + 0.75, 'peaq_basic' => -2.3, 'visqol' => 3.5, 'enc_x_realtime' => 9999, 'd_peaq_ci' => ''];
                    $this->summary[] = $record;
                    for ($i = 0; $i < 10; ++$i) {
                        $this->raw[] = $record + ['item' => "music-$i", 'seconds' => 13, 'bytes' => 16000];
                    }
                }
            }
            $rate = ['lc' => 128, 'he' => 48, 'hev2' => 32, 'xhe' => 64][$family];
            foreach (['encode', 'decode'] as $kind) {
                $codecs = $kind === 'encode' || $family === 'xhe' ? $encoders : $profiles['lc'][1];
                foreach ($codecs as $codec) {
                    $config = $kind === 'encode' ? "$family-$codec-cbr$rate" : "$family-fdk-cbr$rate" . ($family === 'xhe' ? '.loas' : '.aac');
                    $this->speed[] = ['kind' => $kind, 'family' => $family, 'codec' => $codec, 'config' => $config, 'seconds' => 130.0, 'cpu' => 2.0, 'wall' => 4.0, 'kbps' => $rate + 0.5];
                }
            }
        }
        // These unsupported configurations must not become chart observations.
        $extra = $this->raw[0];
        $extra['config'] = 'xhe-fdk-cbr16';
        $this->raw[] = $extra;
        $extra['config'] = 'xhe-fdk-cbr128';
        $this->raw[] = $extra;
        $this->speed[] = ['kind' => 'baseline', 'family' => '', 'codec' => 'apple', 'seconds' => 0, 'cpu' => 0.1];
        $this->speed[] = ['kind' => 'encode', 'family' => 'lc', 'codec' => 'oursnoab', 'seconds' => 130, 'cpu' => 1];
        $this->lossless = json_decode((string) file_get_contents(self::LOSSLESS_FIXTURE), true, 512, JSON_THROW_ON_ERROR);
        $this->write();
    }

    protected function tearDown(): void
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->root);
    }

    public function testSelectsCompleteCoverageAndComputesCpuThroughputIndependentlyOfQualityTiming(): void
    {
        $evidence = new AacEvidence()->load($this->root);
        self::assertSame(['lc' => 25, 'he' => 12, 'hev2' => 6, 'xhe' => 12], array_map('count', $evidence['quality']));
        self::assertSame('0.2.0', $evidence['version']);
        self::assertSame(['lc' => [5, 5], 'he' => [4, 5], 'hev2' => [3, 5], 'xhe' => [3, 3]], array_map(static fn(array $rows): array => [count($rows['encode']), count($rows['decode'])], $evidence['speed']));
        $row = $evidence['quality']['lc'][0];
        self::assertSame('Bånder Audio Core', $row['label']);
        self::assertSame(64.0, $row['nominal']);
        self::assertSame(64.75, $row['kbps']);
        self::assertSame(65.0, $evidence['speed']['lc']['encode'][0]['throughput']);
        self::assertArrayNotHasKey('enc_x_realtime', $row);
        self::assertArrayNotHasKey('d_peaq_ci', $row);
        self::assertSame('he-fdk-cbr48.aac', $evidence['speed']['he']['decode'][0]['config']);
    }

    public function testMeasuredZeroAndPositivePeaqScoresRemainObservations(): void
    {
        $this->summary[0]['peaq_basic'] = 0;
        $this->summary[1]['peaq_basic'] = 0.4;
        for ($i = 0; $i < 20; ++$i) {
            $this->raw[$i]['peaq_basic'] = $i < 10 ? 0 : 0.4;
        }
        $this->write();
        $evidence = new AacEvidence()->load($this->root);
        self::assertSame(0.0, $evidence['quality']['lc'][0]['peaq_basic']);
        self::assertSame(0.4, $evidence['quality']['lc'][1]['peaq_basic']);
        $chart = new AacCharts()->render($evidence)['quality']['lc']['charts'][0];
        $document = $this->svg($chart['svg']);
        $xpath = new \DOMXPath($document);
        $xpath->registerNamespace('s', 'http://www.w3.org/2000/svg');
        $point = $xpath->query('//s:g[@data-value="0.4"]/s:circle')->item(0);
        self::assertInstanceOf(\DOMElement::class, $point);
        self::assertGreaterThan(35, (float) $point->getAttribute('cy'));
        self::assertLessThan(298, (float) $point->getAttribute('cy'));
    }

    #[DataProvider('invalidEvidence')]
    public function testRejectsIncompleteInconsistentAndNonFiniteEvidence(string $case): void
    {
        match ($case) {
            'blank' => $this->summary[0]['visqol'] = '',
            'nan' => $this->summary[0]['peaq_basic'] = 'NaN',
            'infinity' => $this->summary[0]['kbps'] = '1e999',
            'identity' => $this->summary[0]['encoder'] = 'ffmpeg',
            'nominal' => $this->summary[0]['nominal'] = 96,
            'duplicate-summary' => $this->summary[] = $this->summary[0],
            'missing-summary' => array_pop($this->summary),
            'duplicate-raw' => $this->raw[] = $this->raw[0],
            'missing-raw' => array_shift($this->raw),
            'raw-corpus' => $this->raw[0]['item'] = 'different-music',
            'raw-mean' => $this->raw[0]['visqol'] = 2,
            'speed-zero-cpu' => $this->speed[0]['cpu'] = 0,
            'speed-zero-audio' => $this->speed[0]['seconds'] = 0,
            'speed-infinite' => $this->speed[0]['wall'] = '1e999',
            'speed-identity' => $this->speed[0]['config'] = 'lc-ours-cbr64',
            'speed-duplicate' => $this->speed[] = $this->speed[0],
            'speed-missing' => array_shift($this->speed),
            'lossless-schema' => $this->lossless['schema'] = 3,
            'lossless-rows' => $this->lossless['rows'] = ['bac-5'],
            'lossless-date' => $this->lossless['date'] = '2026-11-31T23:40:12+01:00',
            'lossless-tools' => $this->lossless['tools']['ffmpeg'] = 'avconv 12',
            'version' => file_put_contents($this->root . '/packages/baander-audio-core/package.json', '{"version":"next"}'),
            default => throw new \InvalidArgumentException($case),
        };
        $this->write();
        $this->expectException(\RuntimeException::class);
        new AacEvidence()->load($this->root);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidEvidence(): iterable
    {
        foreach (['blank', 'nan', 'infinity', 'identity', 'nominal', 'duplicate-summary', 'missing-summary', 'duplicate-raw', 'missing-raw', 'raw-corpus', 'raw-mean', 'speed-zero-cpu', 'speed-zero-audio', 'speed-infinite', 'speed-identity', 'speed-duplicate', 'speed-missing', 'lossless-schema', 'lossless-rows', 'lossless-date', 'lossless-tools', 'version'] as $case) {
            yield $case => [$case];
        }
    }

    public function testReadsEveryRowOfTheLosslessRunInOneOrderWhateverTheFileOrder(): void
    {
        $lossless = new AacEvidence()->load($this->root)['lossless'];
        self::assertSame(self::LOSSLESS_ORDER, array_column($lossless['rows'], 'codec'));
        $this->lossless['rows'] = array_reverse($this->lossless['rows']);
        $this->write();
        self::assertSame($lossless, new AacEvidence()->load($this->root)['lossless']);

        $rows = array_column($lossless['rows'], null, 'codec');
        self::assertSame(['Bånder Audio Core level 5 (default)', 'Bånder Audio Core level 8', 'flac level 5', 'FFmpeg FLAC level 8', 'FFmpeg ALAC'], array_map(static fn(string $codec): string => $rows[$codec]['label'], ['bac-5', 'bac-8', 'flac-5', 'flac-ffmpeg-8', 'alac-ffmpeg']));
        self::assertSame(10232449, $rows['bac-5']['frame_bytes']);
        self::assertSame(10248681, $rows['flac-5']['frame_bytes']);
        self::assertSame(629.6765212987337, $rows['flac-5']['kbps']);
        self::assertEqualsWithDelta(560.0, $rows['bac-5']['encode'], 1e-9);
        self::assertEqualsWithDelta(436.0, $rows['bac-5']['decode'], 1e-9);
        self::assertEqualsWithDelta(195.0, $rows['flac-8']['encode'], 1e-9);
        self::assertNull($rows['alac-ffmpeg']['frame_bytes']);
        self::assertTrue($rows['bac-8']['bit_exact']);
        self::assertSame([
            'date' => '11 November 2026',
            'processor' => 'Fixture processor, not a measurement',
            'compiler' => 'g++ (GCC) 16.2.1 (fixture)',
            'flac' => 'flac 1.4.3',
            'ffmpeg' => 'FFmpeg 8.1.3',
            'repetitions' => 24,
            'heldOutFirstRun' => true,
        ], $lossless['run']);
    }

    public function testAcceptsTheCarriedReferenceRowWithoutSpeedFigures(): void
    {
        $rows = array_column(new AacEvidence()->load($this->root)['lossless']['rows'], null, 'codec');
        self::assertSame([
            'codec' => 'alac-apple-ref',
            'label' => 'Apple ALAC (refalac)',
            'kbps' => 690.2499619918112,
            'frame_bytes' => null,
            'encode' => null,
            'decode' => null,
            'bit_exact' => true,
            'carried' => '30 October 2026',
        ], $rows['alac-apple-ref']);
    }

    #[DataProvider('invalidLosslessRows')]
    public function testRejectsALosslessRowWithAMessageNamingIt(string $case, string $codec): void
    {
        $index = array_search($codec, array_column($this->lossless['rows'], 'codec'), true);
        match ($case) {
            'unknown-codec' => $this->lossless['rows'][] = ['codec' => $codec] + $this->lossless['rows'][array_search('flac-ffmpeg-5', array_column($this->lossless['rows'], 'codec'), true)],
            'duplicate' => $this->lossless['rows'][] = $this->lossless['rows'][$index],
            'missing-level' => array_splice($this->lossless['rows'], $index, 1),
            'zero-rate' => $this->lossless['rows'][$index]['kbps'] = 0,
            'negative-frame-bytes' => $this->lossless['rows'][$index]['frame_bytes'] = -1,
            'zero-encode-time' => $this->lossless['rows'][$index]['enc_cpu'] = 0,
            'missing-decode-time' => $this->lossless['rows'][$index]['dec_cpu'] = null,
            'bit-exact-string' => $this->lossless['rows'][$index]['bit_exact'] = 'true',
            'carried-date' => $this->lossless['rows'][$index]['carried'] = 'last October',
            default => throw new \InvalidArgumentException($case),
        };
        $this->write();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/(?<![\w-])' . preg_quote($codec, '/') . '(?![\w-])/');
        new AacEvidence()->load($this->root);
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidLosslessRows(): iterable
    {
        yield 'unknown codec id' => ['unknown-codec', 'flac-ffmpeg-6'];
        yield 'codec id from before the rename' => ['unknown-codec', 'baac-5'];
        yield 'duplicate row' => ['duplicate', 'bac-5'];
        yield 'missing Bånder Audio Core level' => ['missing-level', 'bac-3'];
        yield 'zero bitrate' => ['zero-rate', 'flac-8'];
        yield 'negative frame bytes' => ['negative-frame-bytes', 'bac-8'];
        yield 'zero encode time' => ['zero-encode-time', 'alac-ffmpeg'];
        yield 'missing decode time' => ['missing-decode-time', 'flac-ffmpeg-8'];
        yield 'bit-exact verdict as text' => ['bit-exact-string', 'bac-0'];
        yield 'carried date not a date' => ['carried-date', 'alac-apple-ref'];
    }

    public function testReadsTheEarlierListOfFourLosslessReferences(): void
    {
        $this->lossless = [];
        foreach (['alac-ffmpeg', 'flac-5', 'flac-8', 'alac-apple-ref'] as $codec) {
            $this->lossless[] = ['codec' => $codec, 'seconds' => 130, 'kbps' => 650, 'enc_cpu' => 1, 'dec_cpu' => 0.5, 'bit_exact' => true];
        }
        $this->write();
        $lossless = new AacEvidence()->load($this->root)['lossless'];
        self::assertNull($lossless['run']);
        self::assertSame(['flac-5', 'flac-8', 'alac-ffmpeg', 'alac-apple-ref'], array_column($lossless['rows'], 'codec'));
        self::assertSame(['codec' => 'flac-5', 'label' => 'flac level 5', 'kbps' => 650.0, 'frame_bytes' => null, 'encode' => 130.0, 'decode' => 260.0, 'bit_exact' => true, 'carried' => null], $lossless['rows'][0]);

        $this->lossless[0]['bit_exact'] = 'true';
        $this->write();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/alac-ffmpeg/');
        new AacEvidence()->load($this->root);
    }

    public function testChartsExposeMatchingValuesAccessibleLabelsAndZeroBasedBars(): void
    {
        $evidence = new AacEvidence()->load($this->root);
        $charts = new AacCharts()->render($evidence);
        foreach (['quality', 'speed'] as $kind) {
            foreach ($charts[$kind] as $profile) {
                foreach ($profile['charts'] as $chart) {
                    $document = $this->svg($chart['svg']);
                    self::assertSame('img', $document->documentElement->getAttribute('role'));
                    self::assertStringContainsString($chart['id'] . '-title', $document->documentElement->getAttribute('aria-labelledby'));
                    self::assertCount(1, $document->getElementsByTagName('desc'));
                    $xpath = new \DOMXPath($document);
                    $nodes = $xpath->query('//*[@data-value]');
                    self::assertCount(count($chart['rows']), $nodes);
                    foreach ($chart['rows'] as $index => $row) {
                        self::assertInstanceOf(\DOMElement::class, $nodes->item($index));
                        self::assertSame((string) $row[$chart['metric']], $nodes->item($index)->getAttribute('data-value'));
                        self::assertSame($row['config'], $nodes->item($index)->getAttribute('data-config'));
                        if ($kind === 'speed') {
                            self::assertSame('155', $nodes->item($index)->getAttribute('x'));
                        }
                    }
                }
            }
        }
        $svg = $charts['quality']['lc']['charts'][0]['svg'];
        foreach (['circle', 'rect', 'stroke-dasharray="8 4"', 'stroke-dasharray="3 3"'] as $marker) {
            self::assertStringContainsString($marker, $svg);
        }
        self::assertSame($charts, new AacCharts()->render($evidence));
    }

    public function testVendorColorsShapesAndDashesStayConsistentAcrossProfilesAndWorkloads(): void
    {
        $charts = new AacCharts()->render(new AacEvidence()->load($this->root));
        $vendors = ['ours' => ['', 'circle'], 'ffmpeg' => ['8 4', 'rect'], 'fdk' => ['3 3', 'path'], 'libxaac' => ['10 3 2 3', 'path'], 'apple' => ['2 5', 'path']];
        foreach (['quality', 'speed'] as $kind) {
            foreach ($charts[$kind] as $profile) {
                foreach ($profile['charts'] as $chart) {
                    $document = $this->svg($chart['svg']);
                    $xpath = new \DOMXPath($document);
                    foreach (array_unique(array_column($chart['rows'], $kind === 'quality' ? 'encoder' : 'codec')) as $vendor) {
                        [$dash, $shape] = $vendors[$vendor];
                        $nodes = $xpath->query('//*[@data-codec="' . $vendor . '" and contains(@class,"baac-series-")]');
                        self::assertCount(1, $nodes, $chart['id'] . ' / ' . $vendor);
                        $node = $nodes->item(0);
                        self::assertInstanceOf(\DOMElement::class, $node);
                        $variable = 'var(--baac-series-' . $vendor . ',currentColor)';
                        if ($kind === 'quality') {
                            self::assertSame('color:' . $variable, $node->getAttribute('style'));
                            $lines = $node->getElementsByTagName('polyline');
                            self::assertInstanceOf(\DOMElement::class, $lines->item(0));
                            self::assertSame($dash, $lines->item(0)->getAttribute('stroke-dasharray'));
                            $markers = $xpath->query('./*[contains(@class,"baac-point")]/*[local-name()="' . $shape . '"]', $node);
                            self::assertGreaterThan(0, $markers->count());
                        } else {
                            self::assertSame($variable, $node->getAttribute('fill'));
                        }
                    }
                }
            }
        }
    }

    public function testFixtureChangesUpdateChartGeometryAndMatchingTableRows(): void
    {
        $initial = new AacCharts()->render(new AacEvidence()->load($this->root));
        $this->summary[0]['kbps'] = 80;
        for ($i = 0; $i < 10; ++$i) {
            $this->raw[$i]['kbps'] = 80;
        }
        $this->write();
        $updated = new AacCharts()->render(new AacEvidence()->load($this->root));
        self::assertSame(80.0, $updated['quality']['lc']['charts'][0]['rows'][0]['kbps']);
        self::assertNotSame($initial['quality']['lc']['charts'][0]['svg'], $updated['quality']['lc']['charts'][0]['svg']);
    }

    public function testSvgEscapesXmlShapedLabelsAndIdentities(): void
    {
        $evidence = new AacEvidence()->load($this->root);
        $evidence['quality']['lc'][0]['label'] = '<script>" & example</script>';
        $evidence['quality']['lc'][0]['config'] = '"><script>example</script>';
        $evidence['speed']['lc']['encode'][0]['label'] = '<foreignObject>example</foreignObject>';
        $charts = new AacCharts()->render($evidence);
        foreach ([$charts['quality']['lc']['charts'][0], $charts['speed']['lc']['charts'][0]] as $chart) {
            $document = $this->svg($chart['svg']);
            self::assertCount(0, $document->getElementsByTagName('script'));
            self::assertCount(0, $document->getElementsByTagName('foreignObject'));
            self::assertStringContainsString('example', $document->textContent);
        }
    }

    private function write(): void
    {
        foreach (['quality_summary.csv' => $this->summary, 'quality.csv' => $this->raw] as $name => $rows) {
            $handle = fopen($this->directory . $name, 'wb');
            $header = array_keys($rows[0]);
            fputcsv($handle, $header, ',', '"', '');
            foreach ($rows as $row) {
                fputcsv($handle, array_map(static fn(string $field): mixed => $row[$field], $header), ',', '"', '');
            }
            fclose($handle);
        }
        foreach (['speed.json' => $this->speed, 'lossless.json' => $this->lossless] as $name => $rows) {
            file_put_contents($this->directory . $name, json_encode($rows, JSON_THROW_ON_ERROR));
        }
    }

    private function svg(string $svg): \DOMDocument
    {
        $document = new \DOMDocument();
        self::assertTrue($document->loadXML($svg, LIBXML_NONET));
        return $document;
    }
}
