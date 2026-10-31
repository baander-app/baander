<?php

declare(strict_types=1);

namespace Baander\Tooling\Tests\Website;

use Baander\Tooling\Website\AacCharts;
use Baander\Tooling\Website\AacEvidence;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AacEvidenceTest extends TestCase
{
    private string $root;
    private string $directory;
    /** @var list<array<string, mixed>> */
    private array $summary = [];
    /** @var list<array<string, mixed>> */
    private array $raw = [];
    /** @var list<array<string, mixed>> */
    private array $speed = [];
    /** @var list<array<string, mixed>> */
    private array $lossless = [];

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/baander-aac-evidence-' . bin2hex(random_bytes(6));
        $this->directory = $this->root . '/packages/baander-aac/bench/results/';
        mkdir($this->directory, 0700, true);
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
        foreach (['alac-ffmpeg', 'flac-5', 'flac-8', 'alac-apple-ref'] as $codec) {
            $this->lossless[] = ['codec' => $codec, 'seconds' => 130, 'kbps' => 650, 'enc_cpu' => 1, 'dec_cpu' => 0.5, 'bit_exact' => true];
        }
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
        self::assertCount(4, $evidence['lossless']);
        self::assertSame(['lc' => [5, 5], 'he' => [4, 5], 'hev2' => [3, 5], 'xhe' => [3, 3]], array_map(static fn(array $rows): array => [count($rows['encode']), count($rows['decode'])], $evidence['speed']));
        $row = $evidence['quality']['lc'][0];
        self::assertSame('bAAC', $row['label']);
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
            'lossless-flag' => $this->lossless[0]['bit_exact'] = 'true',
            'lossless-missing' => array_pop($this->lossless),
            default => throw new \InvalidArgumentException($case),
        };
        $this->write();
        $this->expectException(\RuntimeException::class);
        new AacEvidence()->load($this->root);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidEvidence(): iterable
    {
        foreach (['blank', 'nan', 'infinity', 'identity', 'nominal', 'duplicate-summary', 'missing-summary', 'duplicate-raw', 'missing-raw', 'raw-corpus', 'raw-mean', 'speed-zero-cpu', 'speed-zero-audio', 'speed-infinite', 'speed-identity', 'speed-duplicate', 'speed-missing', 'lossless-flag', 'lossless-missing'] as $case) {
            yield $case => [$case];
        }
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
