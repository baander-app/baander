<?php

declare(strict_types=1);

$mutate = in_array('--mutate-disposable', array_slice($argv, 1), true);
if (array_diff(array_slice($argv, 1), ['--mutate-disposable']) !== []) {
    throw new RuntimeException('Usage: source-streams.php [--mutate-disposable]');
}
if ($mutate && (getenv('BAANDER_TYPEPHP_DISPOSABLE') !== '1'
    || (!is_file('/.dockerenv') && !is_file('/run/.containerenv')))) {
    throw new RuntimeException('Physical source mutation requires a disposable container and BAANDER_TYPEPHP_DISPOSABLE=1.');
}

$checks = 0;
$failures = [];
$assert = static function (bool $condition, string $message) use (&$checks, &$failures): void {
    ++$checks;
    if (!$condition) {
        $failures[] = $message;
    }
};
$tokens = static function (string $source): array {
    $result = [];
    foreach (token_get_all($source) as $token) {
        if (is_array($token)) {
            if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $result[] = [$token[0], in_array($token[0], [T_OPEN_TAG, T_CLOSE_TAG], true) ? rtrim($token[1]) : $token[1]];
        } else {
            $result[] = $token;
        }
    }

    return $result;
};
$scan = static function (string $path, string $source, string $phase) use ($assert, $tokens): void {
    // File scanning skips the leading shebang; string token/highlight APIs retain it.
    $fileSource = $source;
    if (str_starts_with($fileSource, '#!')) {
        $lineEnd = strpos($fileSource, "\n");
        $fileSource = $lineEnd === false ? '' : substr($fileSource, $lineEnd + 1);
    }
    for ($attempt = 1; $attempt <= 3; ++$attempt) {
        $prefix = $phase.' scan '.$attempt.' '.$path;
        $stripped = php_strip_whitespace($path);
        $assert($stripped !== '', $prefix.' returned empty source.');
        $assert($tokens($stripped) === $tokens($fileSource), $prefix.' changed PHP tokens.');
        $assert(highlight_file($path, true) === highlight_string($fileSource, true), $prefix.' changed highlighting.');
    }
};

$paths = [
    '/var/www/html/vendor/symfony/polyfill-php86/Resources/stubs/SortDirection.php',
    '/var/www/html/bin/console',
];
$sources = [];
foreach ($paths as $path) {
    $source = file_get_contents($path);
    if ($source === false || $source === '') {
        throw new RuntimeException('Required archived source is unavailable: '.$path);
    }
    $sources[$path] = $source;
    $scan($path, $source, 'original');
}

$temporary = tempnam(sys_get_temp_dir(), 'baander-source-stream-');
if ($temporary === false) {
    throw new RuntimeException('Cannot create physical source fixture.');
}
try {
    $physical = "<?php /* physical source */ return 'physical';\n";
    file_put_contents($temporary, $physical);
    $scan($temporary, $physical, 'physical delegation');
    $assert((require $temporary) === 'physical', 'Physical include changed after scanning.');
} finally {
    unlink($temporary);
}

$wrapper = new class {
    public mixed $context;
    public static string $source = "<?php /* custom stream */ return 'custom';\n";
    private int $offset = 0;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        $this->offset = 0;
        return true;
    }

    public function stream_read(int $count): string
    {
        $result = substr(self::$source, $this->offset, $count);
        $this->offset += strlen($result);
        return $result;
    }

    public function stream_eof(): bool
    {
        return $this->offset >= strlen(self::$source);
    }

    public function stream_tell(): int
    {
        return $this->offset;
    }

    public function stream_seek(int $offset, int $whence = SEEK_SET): bool
    {
        $position = match ($whence) {
            SEEK_SET => $offset,
            SEEK_CUR => $this->offset + $offset,
            SEEK_END => strlen(self::$source) + $offset,
            default => -1,
        };
        if ($position < 0 || $position > strlen(self::$source)) {
            return false;
        }
        $this->offset = $position;
        return true;
    }

    public function stream_stat(): array
    {
        return ['mode' => 0100444, 'size' => strlen(self::$source)];
    }

    public function url_stat(string $path, int $flags): array
    {
        return $this->stream_stat();
    }

    public function stream_set_option(int $option, int $first, int $second): bool
    {
        return false;
    }
};
stream_wrapper_register('baandersource', $wrapper::class);
try {
    $scan('baandersource://fixture', $wrapper::$source, 'custom delegation');
    $assert((require 'baandersource://fixture') === 'custom', 'Custom stream include changed after scanning.');
} finally {
    stream_wrapper_unregister('baandersource');
}

$includeArchived = static function () use ($assert, $paths): void {
    require_once $paths[0];
    $assert(enum_exists('SortDirection', false), 'Archived enum did not load after scanning.');
    $previous = $_SERVER['SCRIPT_FILENAME'] ?? null;
    // Read the real console factory without starting its kernel or a service.
    $_SERVER['SCRIPT_FILENAME'] = '';
    try {
        $factory = require $paths[1];
        $assert($factory instanceof Closure, 'Archived console did not return its factory after scanning.');
    } finally {
        if ($previous === null) {
            unset($_SERVER['SCRIPT_FILENAME']);
        } else {
            $_SERVER['SCRIPT_FILENAME'] = $previous;
        }
    }
};

/** @param list<string> $arguments */
function physicalSourceCommand(array $arguments, string $input = ''): string
{
    $process = proc_open(['/usr/bin/timeout', '5s', ...$arguments],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Cannot start physical source command.');
    }
    $written = fwrite($pipes[0], $input);
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);
    if ($written !== strlen($input) || $output === false || $status !== 0) {
        throw new RuntimeException('Physical source command failed with status '.$status.': '.trim((string) $error));
    }

    return $output;
}

if ($mutate) {
    $originals = [];
    try {
        foreach ($paths as $path) {
            $originals[$path] = physicalSourceCommand(['/bin/cat', '--', $path]);
            $poison = '<?php throw new RuntimeException("Physical source poison executed");';
            physicalSourceCommand(['/usr/bin/tee', '--', $path], $poison);
            clearstatcache(true, $path);
            $scan($path, $sources[$path], 'poisoned physical source');
            physicalSourceCommand(['/bin/rm', '--', $path]);
            clearstatcache(true, $path);
            $scan($path, $sources[$path], 'removed physical source');
        }
        $includeArchived();
    } finally {
        $restoreErrors = [];
        foreach ($originals as $path => $source) {
            try {
                physicalSourceCommand(['/usr/bin/tee', '--', $path], $source);
            } catch (Throwable $error) {
                $restoreErrors[] = $path.': '.$error->getMessage();
            }
        }
        if ($restoreErrors !== []) {
            throw new RuntimeException('Cannot restore physical sources: '.implode('; ', $restoreErrors));
        }
    }
} else {
    $includeArchived();
}

if ($failures !== []) {
    throw new RuntimeException(implode("\n", $failures));
}
echo 'Source stream qualification passed ('.$checks.' checks).'.PHP_EOL;
