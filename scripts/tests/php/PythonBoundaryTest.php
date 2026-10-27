<?php

declare(strict_types=1);

namespace Baander\Tooling\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class PythonBoundaryTest extends TestCase
{
    private const array SUBJECTS = [
        'relay/backup/registry_backup.py',
        'relay/tools/issue_enrollment.py',
    ];

    private const array SUBJECT_CALLERS = [
        'relay/CMakeLists.txt',
        'relay/docker/backup.Dockerfile',
        'relay/docker/backup.compose.yml',
        'relay/docker/qualification.Dockerfile',
        'relay/tests/run_backup_cadence_contract.php',
        'relay/tests/run_backup_contract.php',
        'relay/tests/run_backup_fault_contract.php',
        'relay/tests/run_backup_qualification.php',
        'relay/tests/test_backup.php',
        'relay/tests/test_issue_enrollment.php',
        'packages/baander-tooling/src/Registry/ContainerQualification.php',
        'packages/baander-tooling/src/Registry/QualificationRunner.php',
        'scripts/tests/php/BackupContractTest.php',
        'scripts/tests/php/EnrollmentIssuerTest.php',
        'scripts/tests/php/RegistryContainerWorkflowTest.php',
        '.forgejo/workflows/ci.yaml',
        '.forgejo/workflows/registry-nightly.yaml',
    ];

    private const array RUNTIME_ASSERTIONS = [
        'docker/runner/tooling-test.Dockerfile',
    ];

    private const array UPSTREAM_RUNTIMES = [
        'Dockerfile' => 'Distribution Supervisor and upstream TSDuck build tools',
        'docker/runner/Dockerfile' => 'Upstream Emscripten SDK',
        'docker/ffmpeg/Dockerfile' => 'Upstream Meson and FFmpeg build tooling',
        'docker/postgres/Dockerfile' => 'Application PL/Python extension and its dependencies',
    ];

    public function testWorkflowSourcesContainNoPythonHarnesses(): void
    {
        $root = dirname(__DIR__, 3);
        $violations = [];
        foreach (self::workflowFiles($root) as $file) {
            if (!self::isWorkflow($file) || !is_file($root . '/' . $file)) {
                continue;
            }
            if ($file === 'scripts/tests/php/PythonBoundaryTest.php' || in_array($file, self::SUBJECTS, true)) {
                continue;
            }
            $reason = self::violation(
                $file,
                (string) file_get_contents($root . '/' . $file),
                in_array($file, self::SUBJECT_CALLERS, true)
                    || in_array($file, self::RUNTIME_ASSERTIONS, true)
                    || isset(self::UPSTREAM_RUNTIMES[$file]),
            );
            if ($reason !== null) {
                $violations[] = $file . ': ' . $reason;
            }
        }
        self::assertSame([], $violations, implode("\n", $violations));
    }

    /** @return iterable<string> */
    private static function workflowFiles(string $root): iterable
    {
        yield from ['Makefile', 'Dockerfile', 'relay/CMakeLists.txt'];
        foreach ([
            'scripts',
            'tests/Fixtures',
            'packages/baander-tooling',
            'packages/dsp',
            'relay/tests',
            'relay/tools',
            'relay/docker',
            'docker',
            '.forgejo/workflows',
            '.agents/skills/prose-fix/scripts',
        ] as $directory) {
            $files = new RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator($root . '/' . $directory, RecursiveDirectoryIterator::SKIP_DOTS),
                static fn(SplFileInfo $file): bool => !$file->isLink()
                    && !in_array($file->getFilename(), ['vendor', 'node_modules', '.git', '__pycache__'], true),
            );
            foreach (new RecursiveIteratorIterator($files) as $file) {
                if ($file->isFile()) {
                    yield substr($file->getPathname(), strlen($root) + 1);
                }
            }
        }
    }

    /** @return iterable<string, array{string, string, bool}> */
    public static function forbiddenHarnesses(): iterable
    {
        yield 'Python module' => ['scripts/check.py', 'print("ready")', false];
        yield 'Renamed launcher' => ['scripts/check.sh', "#!/usr/bin/env python3\nprint('ready')", false];
        yield 'Shell heredoc' => ['scripts/check.sh', "python3 - <<'PY'\nimport json\nPY\n", false];
        yield 'PHP-generated launcher' => ['scripts/check.php', '<?php $body = "#!/usr/bin/env python3\\n";', false];
        yield 'Inline bridge inside subject caller' => ['relay/tests/test_backup.php', '<?php $argv = ["python3", "-c", "import backup"];', true];
        yield 'Old unittest inside subject CI job' => ['.forgejo/workflows/ci.yaml', 'run: python3 -m unittest discover', true];
    }

    #[DataProvider('forbiddenHarnesses')]
    public function testBoundaryRejectsInlineGeneratedAndRenamedHarnesses(string $path, string $source, bool $subjectCaller): void
    {
        self::assertNotNull(self::violation($path, $source, $subjectCaller));
    }

    public function testExplicitProductRuntimeRemainsAllowed(): void
    {
        self::assertNull(self::violation(
            'relay/tests/test_backup.php',
            '<?php $argv = ["python3", "relay/backup/registry_backup.py", "once"];',
            true,
        ));
    }

    private static function isWorkflow(string $path): bool
    {
        if (in_array($path, ['Makefile', 'Dockerfile', 'relay/CMakeLists.txt'], true)) {
            return true;
        }
        $prefixes = [
            'scripts/',
            'tests/Fixtures/',
            'packages/baander-tooling/',
            'relay/tests/',
            'relay/tools/',
            'relay/docker/',
            'docker/',
            '.forgejo/workflows/',
            '.agents/skills/prose-fix/scripts/',
        ];
        if (str_starts_with($path, 'packages/dsp/') && basename($path) === 'Makefile') {
            return true;
        }
        return array_any($prefixes, static fn(string $prefix): bool => str_starts_with($path, $prefix))
            && (preg_match('/\.(?:php|py|sh|ya?ml)$/D', $path) === 1
                || str_contains(basename($path), 'Dockerfile'));
    }

    private static function violation(string $path, string $source, bool $subjectCaller): ?string
    {
        if (str_ends_with($path, '.py')) {
            return 'Repository-owned Python workflow file';
        }
        if (preg_match('/\bpython(?:[23](?:\.\d+)?)?\b[^\r\n]{0,80}?[\s\x22\x27]-[cm](?:[\s\x22\x27]|$)/i', $source)) {
            return 'Inline Python or Python module harness';
        }
        if ($subjectCaller) {
            return null;
        }
        if (str_ends_with($path, '.php')) {
            $source = token_get_all($source)
                |> (static fn(array $tokens): array => array_filter(
                    $tokens,
                    static fn(mixed $token): bool => is_array($token)
                        && in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE, T_INLINE_HTML], true),
                ))
                |> (static fn(array $tokens): array => array_column($tokens, 1))
                |> (static fn(array $strings): string => implode("\n", $strings));
        }
        return preg_match('/\bpython(?:[23](?:\.\d+)?)?\b/i', $source)
            ? 'Unclassified Python workflow invocation or declaration'
            : null;
    }
}
