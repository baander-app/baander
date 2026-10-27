<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Baander\Registry\StaticAnalysis;

use Baander\RegistryTests\Runtime;
use RuntimeException;

final class Diagnostics
{
    /** @return array<string,mixed> */
    public static function read(string $export): array
    {
        Runtime::requireThat(extension_loaded('yaml'), 'Registry static analysis requires ext-yaml.');
        $data = yaml_parse_file($export) ?? [];
        Runtime::requireThat(is_array($data), 'Invalid clang-tidy diagnostic export.');
        return $data;
    }

    public static function check(int $status, string $export, string $dependencies): void
    {
        Runtime::requireThat(in_array($status, [0, 1], true), 'clang-tidy failed without completing analysis: ' . $status);
        if (!is_file($export)) {
            Runtime::requireThat($status === 0, 'clang-tidy failed without exporting diagnostics.');
            return;
        }
        $data = self::read($export);
        $diagnostics = $data['Diagnostics'] ?? [];
        Runtime::requireThat(is_array($diagnostics), 'Invalid diagnostic list.');
        Runtime::requireThat(!$status || $diagnostics !== [], 'clang-tidy failed without structured diagnostics.');
        $root = realpath($dependencies);
        Runtime::requireThat($root !== false, 'Missing dependency source directory.');
        foreach ($diagnostics as $diagnostic) {
            Runtime::requireThat(is_array($diagnostic), 'Invalid diagnostic record.');
            $name = $diagnostic['DiagnosticName'] ?? '';
            $filename = $diagnostic['DiagnosticMessage']['FilePath'] ?? '';
            Runtime::requireThat(is_string($name) && $name !== '' && is_string($filename), 'Invalid diagnostic location.');
            $location = $filename === '' ? false : realpath($filename);
            if (
                $location === false
                || !str_starts_with($location, $root . '/')
                || !(str_starts_with($name, 'clang-analyzer-') || str_starts_with($name, 'bugprone-'))
            ) {
                throw new RuntimeException('Blocking diagnostic: ' . $name . ' at ' . $filename);
            }
            echo 'Outside production source scope: ' . $name . ' at ' . $filename . "\n";
        }
    }

    public static function mixCompilerControl(string $export, string $dependencies): void
    {
        $data = self::read($export);
        $diagnostics = $data['Diagnostics'] ?? [];
        Runtime::requireThat(is_array($diagnostics), 'Invalid compiler diagnostic list.');
        $names = $diagnostics |> (static fn(array $items): array => array_column($items, 'DiagnosticName'));
        Runtime::requireThat(in_array('clang-diagnostic-error', $names, true), 'Compiler control did not export a real compiler error.');
        array_unshift(
            $diagnostics,
            [
                'DiagnosticName' => 'clang-analyzer-core.CallAndMessage',
                'DiagnosticMessage' => ['FilePath' => $dependencies . '/boost_headers-src/boost/asio/impl/awaitable.hpp'],
            ],
        );
        $data['Diagnostics'] = $diagnostics;
        Runtime::requireThat(file_put_contents($export, yaml_emit($data)) !== false, 'Cannot write compiler control export.');
    }
}
