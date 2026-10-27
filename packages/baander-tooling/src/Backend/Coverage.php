<?php

declare(strict_types=1);

namespace Baander\Tooling\Backend;

use DOMDocument;
use DOMElement;
use DOMXPath;
use RuntimeException;

final class Coverage
{
    /** @return array<string,array{string,?array{int,int}}> */
    public static function textMetrics(string $path): array
    {
        $text = @file_get_contents($path);
        if ($text === false) {
            throw new RuntimeException('Cannot read coverage text: ' . $path);
        }
        $result = [];
        foreach (explode("\n", $text) as $line) {
            if (!preg_match('/^\s*(Lines|Methods|Classes):/', $line, $candidate)) {
                continue;
            }
            if ($candidate[1] === 'Methods' && str_contains($line, 'Lines:')) {
                continue;
            }
            if (!preg_match('/^\s*(Lines|Methods|Classes):\s*(\S+)%\s*(?:\((\d+)\/(\d+)\))?\s*$/D', $line, $match)) {
                throw new RuntimeException('Malformed coverage summary: ' . trim($line));
            }
            $name = strtolower($match[1]);
            if (isset($result[$name])) {
                throw new RuntimeException('Duplicate ' . $match[1] . ' coverage summary');
            }
            $percentage = $match[2];
            if (!is_numeric($percentage) || !is_finite((float) $percentage) || (float) $percentage < 0 || (float) $percentage > 100) {
                throw new RuntimeException('Invalid ' . $match[1] . ' percentage');
            }
            $result[$name] = [$percentage, isset($match[3]) ? [(int) $match[3], (int) $match[4]] : null];
        }
        if (count($result) !== 3) {
            throw new RuntimeException('Coverage summary must contain Lines, Methods and Classes exactly once');
        }
        return $result;
    }

    public static function document(string $path): DOMDocument
    {
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            if (!$document->load($path, LIBXML_NONET)) {
                throw new RuntimeException('Invalid Clover XML: ' . $path);
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        return $document;
    }

    /** @return list<DOMElement> */
    public static function nodes(DOMElement|DOMDocument $node, string $expression): array
    {
        $document = $node instanceof DOMDocument ? $node : $node->ownerDocument;
        if ($document === null) {
            throw new RuntimeException('Missing Clover document');
        }
        $nodes = new DOMXPath($document)->query($expression, $node);
        if ($nodes === false) {
            throw new RuntimeException('Invalid Clover query');
        }
        $elements = [];
        foreach ($nodes as $element) {
            if ($element instanceof DOMElement) {
                $elements[] = $element;
            }
        }
        return $elements;
    }

    public static function count(DOMElement $metrics, string $name): int
    {
        $value = $metrics->getAttribute($name);
        if (!preg_match('/^[0-9]+$/D', $value) || self::integerCompare($value, (string) PHP_INT_MAX) > 0) {
            throw new RuntimeException('Clover ' . $name . ' must be a nonnegative integer');
        }
        return (int) $value;
    }

    /** @return array{int,int} */
    private static function pair(DOMElement $metrics, string $name): array
    {
        $total = self::count($metrics, $name);
        $covered = self::count($metrics, 'covered' . $name);
        if ($covered > $total) {
            throw new RuntimeException('Clover covered' . $name . ' exceeds ' . $name);
        }
        return [$covered, $total];
    }

    private static function metrics(DOMElement $node): DOMElement
    {
        $metrics = self::nodes($node, './metrics');
        if (count($metrics) !== 1) {
            throw new RuntimeException('Each Clover project, file and class must have exactly one metrics element');
        }
        $result = $metrics[0];
        foreach (['files', 'classes', 'loc', 'ncloc'] as $name) {
            if ($result->hasAttribute($name)) {
                self::count($result, $name);
            }
        }
        foreach (['statements', 'methods', 'conditionals', 'elements', 'classes'] as $name) {
            if ($result->hasAttribute('covered' . $name)) {
                self::pair($result, $name);
            }
        }
        return $result;
    }

    /** @return array<string,array{int,int}> */
    public static function cloverMetrics(string $path): array
    {
        $document = self::document($path);
        $projects = self::nodes($document, '/coverage/project');
        if (count($projects) !== 1) {
            throw new RuntimeException('Clover must contain one coverage project');
        }
        $project = $projects[0];
        $metrics = self::metrics($project);
        $result = ['lines' => self::pair($metrics, 'statements'), 'methods' => self::pair($metrics, 'methods')];
        $files = self::nodes($project, './/file');
        if ($files === [] || self::count($metrics, 'files') !== count($files)) {
            throw new RuntimeException('Clover file count is empty or inconsistent');
        }
        $totals = ['statements' => [0, 0], 'methods' => [0, 0]];
        $classes = $coveredClasses = 0;
        foreach ($files as $file) {
            $fileMetrics = self::metrics($file);
            foreach (array_keys($totals) as $name) {
                [$covered, $total] = self::pair($fileMetrics, $name);
                $totals[$name][0] += $covered;
                $totals[$name][1] += $total;
            }
            $fileClasses = 0;
            foreach (self::nodes($file, './class') as $class) {
                $classMetrics = self::metrics($class);
                $statements = self::pair($classMetrics, 'statements');
                $methods = self::pair($classMetrics, 'methods');
                $conditionals = self::pair($classMetrics, 'conditionals');
                if ($methods[1] === 0) {
                    continue;
                }
                ++$fileClasses;
                ++$classes;
                // Match php-code-coverage's executable-class branch/line rule.
                $coverage = $conditionals[0] > 0 ? $conditionals : $statements;
                $coveredClasses += (int) ($coverage[1] > 0 && $coverage[0] === $coverage[1]);
            }
            if ($fileClasses !== self::count($fileMetrics, 'classes')) {
                throw new RuntimeException('Clover file class denominator is inconsistent');
            }
        }
        foreach (['statements' => 'lines', 'methods' => 'methods'] as $name => $aggregate) {
            if ($totals[$name] !== $result[$aggregate]) {
                throw new RuntimeException('Clover project ' . $name . ' counts disagree with its files');
            }
        }
        if ($classes !== self::count($metrics, 'classes')) {
            throw new RuntimeException('Clover project class denominator is inconsistent');
        }
        $result['classes'] = [$coveredClasses, $classes];
        if ($metrics->hasAttribute('coveredclasses') && self::count($metrics, 'coveredclasses') !== $coveredClasses) {
            throw new RuntimeException('Clover project covered class count is inconsistent');
        }
        foreach ($result as [, $total]) {
            if ($total <= 0) {
                throw new RuntimeException('Coverage denominators must be positive');
            }
        }
        return $result;
    }

    /** @return array<string,float> */
    public static function validate(string $directory): array
    {
        $summary = self::textMetrics($directory . '/report.txt');
        $clover = self::cloverMetrics($directory . '/clover.xml');
        $output = [];
        foreach ($summary as $name => [$percentage, $textCounts]) {
            [$covered, $total] = $clover[$name];
            if ($textCounts !== null && $textCounts !== [$covered, $total]) {
                throw new RuntimeException($name . ' text counts disagree with Clover');
            }
            if (!self::withinTolerance($percentage, $covered, $total)) {
                throw new RuntimeException($name . ' text percentage disagrees with Clover');
            }
            $output[$name] = (float) $percentage;
        }
        self::publish($directory, json_encode($output, JSON_THROW_ON_ERROR) . "\n");
        return $output;
    }

    private static function publish(string $directory, string $content): void
    {
        $temporary = $directory . '/.metrics-' . bin2hex(random_bytes(12)) . '.json';
        $mask = umask(0077);
        try {
            $stream = @fopen($temporary, 'x+b');
        } finally {
            umask($mask);
        }
        if ($stream === false) {
            throw new RuntimeException('Cannot create coverage metrics staging file');
        }
        try {
            $remaining = $content;
            while ($remaining !== '') {
                $written = fwrite($stream, $remaining);
                if ($written === false || $written === 0) {
                    throw new RuntimeException('Cannot write coverage metrics');
                }
                $remaining = substr($remaining, $written);
            }
            if (!fflush($stream) || !fsync($stream)) {
                throw new RuntimeException('Cannot synchronize coverage metrics');
            }
            fclose($stream);
            $stream = null;
            if (!@rename($temporary, $directory . '/metrics.json')) {
                throw new RuntimeException('Cannot replace coverage metrics');
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
            if (file_exists($temporary)) {
                unlink($temporary);
            }
        }
    }

    private static function withinTolerance(string $percentage, int $covered, int $total): bool
    {
        preg_match('/^([+-]?)(\d*\.?\d*)(?:[eE]([+-]?\d+))?$/D', $percentage, $parts);
        $mantissa = $parts[2];
        $point = strpos($mantissa, '.');
        $scale = ($point === false ? 0 : strlen($mantissa) - $point - 1) - (int) ($parts[3] ?? 0);
        $digits = str_replace('.', '', $mantissa)
            |> (static fn(string $value): string => ltrim($value, '0'));
        if ($digits === '') {
            return self::integerCompare(self::product((string) $covered, '10000'), (string) $total) <= 0;
        }
        if ($parts[1] === '-') {
            return false;
        }
        // Below this magnitude a positive decimal cannot bridge the smallest
        // nonzero distance between an integer-count ratio and the tolerance.
        if ($scale > strlen($digits) + 40) {
            return self::integerCompare(self::product((string) $covered, '10000'), (string) $total) <= 0;
        }
        if ($scale < 2) {
            $digits .= str_repeat('0', 2 - $scale);
            $scale = 2;
        }
        if (self::integerCompare($digits, '100' . str_repeat('0', $scale)) > 0) {
            return false;
        }
        $actual = self::product($digits, (string) $total);
        $expected = (string) $covered . str_repeat('0', $scale + 2);
        $tolerance = (string) $total . str_repeat('0', $scale - 2);
        if (self::integerCompare($actual, $expected) < 0) {
            [$actual, $expected] = [$expected, $actual];
        }
        $difference = '';
        $borrow = 0;
        $expected = str_pad($expected, strlen($actual), '0', STR_PAD_LEFT);
        for ($index = strlen($actual) - 1; $index >= 0; --$index) {
            $digit = (int) $actual[$index] - (int) $expected[$index] - $borrow;
            $borrow = (int) ($digit < 0);
            $difference = (string) ($digit + $borrow * 10) . $difference;
        }
        return self::integerCompare($difference, $tolerance) <= 0;
    }

    private static function integerCompare(string $left, string $right): int
    {
        $left = ltrim($left, '0');
        $right = ltrim($right, '0');
        return strlen($left) <=> strlen($right) ?: strcmp($left, $right);
    }

    private static function product(string $left, string $right): string
    {
        $digits = array_fill(0, strlen($left) + strlen($right), 0);
        for ($leftIndex = strlen($left) - 1; $leftIndex >= 0; --$leftIndex) {
            for ($rightIndex = strlen($right) - 1; $rightIndex >= 0; --$rightIndex) {
                $position = $leftIndex + $rightIndex + 1;
                $value = $digits[$position] + (int) $left[$leftIndex] * (int) $right[$rightIndex];
                $digits[$position] = $value % 10;
                $digits[$position - 1] += intdiv($value, 10);
            }
        }
        return ltrim(implode('', $digits), '0') ?: '0';
    }

    /** @param list<string> $arguments */
    public static function main(array $arguments): int
    {
        if (count($arguments) > 1) {
            throw new \InvalidArgumentException('Usage: validate-backend-coverage.php [DIRECTORY]');
        }
        try {
            $metrics = self::validate($arguments[0] ?? 'coverage');
        } catch (\Throwable $error) {
            fwrite(STDERR, 'Coverage validation failed: ' . $error->getMessage() . "\n");
            return 1;
        }
        $formatted = [];
        foreach ($metrics as $name => $value) {
            $formatted[] = sprintf('%s %.2f%%', $name, $value);
        }
        fwrite(STDOUT, 'Coverage validated: ' . implode(', ', $formatted) . "\n");
        return 0;
    }
}
