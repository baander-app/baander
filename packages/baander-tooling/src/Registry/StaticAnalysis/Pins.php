<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Baander\Tooling\Registry\StaticAnalysis;

use Baander\Tooling\Registry\Support;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class Pins
{
    public static function read(string $path): string
    {
        $value = file_get_contents($path);
        Support::requireThat($value !== false, 'Cannot read ' . $path);
        return $value;
    }

    public static function check(string $root): void
    {
        $cmake = self::read($root . '/relay/CMakeLists.txt');
        $expected = [
            'boost_headers' => [
                'https://archives.boost.io/release/1.88.0/source/boost_1_88_0.tar.bz2',
                '46d9d2c06637b219270877c9e16155cbd015b6dc84349af064c088e9b5b12f7b',
            ],
            'json' => [
                'https://github.com/nlohmann/json/releases/download/v3.12.0/json.tar.xz',
                '42f6e95cad6ec532fd372391373363b62a14af6d771056dbfc86160e6dfff7aa',
            ],
            'googletest' => [
                'https://codeload.github.com/google/googletest/tar.gz/refs/tags/v1.17.0',
                '65fab701d9829d38cb77c14acdc431d2108bfdbf8979e40eb8ae567edf10b27c',
            ],
        ];
        preg_match_all('/FetchContent_Declare\s*\((.*?)\)/si', $cmake, $matches);
        $actual = [];
        foreach ($matches[1] as $declaration) {
            $name = $declaration
                |> trim(...)
                |> (static fn(string $value): array => preg_split('/\s+/', $value))
                |> (static fn(array $words): string => $words[0]);
            $hasUrl = preg_match('/\bURL\s+(\S+)/', $declaration, $url) === 1;
            $hasDigest = preg_match('/\bURL_HASH\s+SHA256=([a-f0-9]{64})/', $declaration, $digest) === 1;
            Support::requireThat(!isset($actual[$name]) && $hasUrl && $hasDigest, 'Unreviewed dependency declaration: ' . $declaration);
            $actual[$name] = [$url[1], $digest[1]];
        }
        Support::requireThat($actual == $expected, 'Registry dependency inventory changed; review versions, hashes and licenses.');
        preg_match_all('/find_package\s*\(\s*(\w+)/i', $cmake, $packages);
        $packages = array_unique($packages[1]);
        sort($packages);
        Support::requireThat(
            $packages === ['OpenSSL', 'Threads'],
            'Registry environment dependency inventory changed; review its licenses.',
        );
        Support::requireThat(
            str_contains($cmake, 'find_package(OpenSSL 3.5.3 EXACT REQUIRED)'),
            'Registry OpenSSL pin changed; review its source and license.',
        );
        foreach ([
            [
                'relay/docker/Dockerfile',
                'openssl-3.5.3',
                'c9489d2abcf943cdc8329a57092331c598a402938054dc3a22218aea8a8ec3bf',
                false,
            ],
            [
                'relay/docker/rqlite.Dockerfile',
                '8b6cc4cb14a78b81ac725ec3c7cd281e1c646422',
                '88a51c52c9398e43ad179af0835c62d7ff2d9ffc4ff9cfe29630e51ecf617398',
                true,
            ],
            [
                'relay/docker/backup.Dockerfile',
                'https://github.com/FiloSottile/age/releases/download/v1.3.2/age-v1.3.2-linux-amd64.tar.gz',
                'cbe24006683f8eb669266162894b9a522a1af52f2665fbc63a4bb032ed26ac10',
                false,
            ],
            [
                'relay/docker/backup.Dockerfile',
                '84a85367111c84061c959e01e0c320618e492dc6',
                '9f2bdefc2e15049e5ecea6b5f5c477460461281256a512c8b151a2753d6e2e9b',
                true,
            ],
            [
                'relay/docker/rqlite.Dockerfile',
                'go1.27.1.linux-amd64.tar.gz',
                '63d339f0da5ab53635a56f2490a7984dfe12dfcff22ad749f63edaf590168445',
                false,
            ],
            [
                'relay/docker/backup.Dockerfile',
                'go1.25.3.linux-amd64.tar.gz',
                '0335f314b6e7bfe08c3d0cfaa7c19db961b7b99fb20be62b0a826c992ad14e0f',
                false,
            ],
        ] as [$filename, $version, $digest, $archive]) {
            $dockerfile = self::read($root . '/' . $filename);
            $evidence = $dockerfile . ($archive ? self::read($root . '/relay/third_party/inventory/go/components.json') : '');
            Support::requireThat(
                str_contains($dockerfile, $version) && str_contains($evidence, $digest),
                'Registry packaged dependency pin changed: ' . $filename,
            );
        }
        $notices = glob($root . '/relay/third_party/*-LICENSE.txt') ?: [];
        $names = $notices
            |> (static fn(array $files): array => array_map(
                static fn(string $file): string => $file
                    |> basename(...)
                    |> (static fn(string $name): string => substr($name, 0, -12)),
                $files,
            ));
        sort($names);
        Support::requireThat(
            $names === ['age', 'boost', 'googletest', 'json', 'openssl', 'rclone', 'rqlite'],
            'Registry retained-notice inventory changed; review its coverage.',
        );
        foreach ([
            'age' => 'c5d65279d02955c0fc2294ae417c3add650d228f4f5c82bd6d531fc26c89cd96',
            'rclone' => '8cd2e9e750b90a04b7d82dbbca3930c696ae0309d7c10464f90a44f45754cd04',
        ] as $name => $digest) {
            Support::requireThat(
                hash_file('sha256', $root . '/relay/third_party/' . $name . '-LICENSE.txt') === $digest,
                'Registry packaged dependency notice changed: ' . $name,
            );
        }
        foreach (['cpp' => 'cpp', 'include' => 'hpp', 'tests' => 'cpp'] as $directory => $extension) {
            $sources = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
                $root . '/relay/' . $directory,
                FilesystemIterator::SKIP_DOTS,
            ));
            foreach ($sources as $source) {
                if ($source->isFile() && $source->getExtension() === $extension) {
                    $header = $source->getPathname()
                        |> self::read(...)
                        |> (static fn(string $content): array => explode("\n", $content))
                        |> (static fn(array $lines): array => array_slice($lines, 0, 5))
                        |> (static fn(array $lines): string => implode("\n", $lines));
                    Support::requireThat(
                        str_contains($header, 'SPDX-License-Identifier: Apache-2.0'),
                        'Missing first-party Apache-2.0 declaration: ' . $source->getPathname(),
                    );
                }
            }
        }
        echo "Reviewed direct dependency pins and first-party license declarations passed.\n";
    }
}
