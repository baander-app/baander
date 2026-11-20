<?php

declare(strict_types=1);

if (!extension_loaded('pcntl') || !extension_loaded('posix') || posix_geteuid() === 0) {
    throw new RuntimeException('Run filesystem qualification as a non-root user with pcntl and posix.');
}

$binary = realpath($argv[1] ?? PHP_BINARY);
if ($binary === false || file_get_contents($binary, false, null, 0, 4) !== "\x7fELF") {
    throw new RuntimeException('Pass the actual PHP or packaged ELF executable.');
}
if (realpath(PHP_BINARY) !== $binary) {
    throw new RuntimeException('Execute this fixture through the ELF being qualified.');
}

$temporary = sys_get_temp_dir().'/baander-filesystem-'.bin2hex(random_bytes(6));
mkdir($temporary, 0700);
$originalDirectory = getcwd();
$failures = [];
$checks = 0;
$accessFunctions = ['file_exists', 'is_readable', 'is_writable', 'is_writeable', 'is_executable'];

final class FilesystemQualificationPath implements Stringable
{
    public int $conversions = 0;

    public function __construct(private readonly string $path) {}

    public function __toString(): string
    {
        ++$this->conversions;

        return $this->path;
    }
}

/** The child waits until after the parent has populated its stat cache. */
function observeExternalChange(string $binary, string $path, string $operation, callable $observe): array
{
    $code = <<<'PHP'
pcntl_sigprocmask(SIG_BLOCK, [SIGUSR1]);
echo "ready\n";
fflush(STDOUT);
if (pcntl_sigwaitinfo([SIGUSR1], $information) !== SIGUSR1) { exit(20); }
$result = match ($argv[2]) {
    'unlink' => unlink($argv[1]),
    'create' => file_put_contents($argv[1], 'created') !== false,
    'chmod' => chmod($argv[1], 0000),
};
exit($result ? 0 : 21);
PHP;
    $process = proc_open([$binary, '-r', $code, '--', $path, $operation],
        [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => STDERR], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start filesystem mutation child.');
    }
    try {
        stream_set_timeout($pipes[1], 5);
        if (fgets($pipes[1]) !== "ready\n") {
            throw new RuntimeException('Filesystem mutation child did not become ready.');
        }
        fclose($pipes[1]);
        $pid = proc_get_status($process)['pid'];
        $before = $observe();
        // No parent output or filesystem mutation between these observations:
        // writing to a PHP stream can clear the very cache being tested.
        if (!posix_kill($pid, SIGUSR1)) {
            throw new RuntimeException('Could not release filesystem mutation child.');
        }
        $status = proc_close($process);
        $after = $observe();
        if ($status !== 0) {
            throw new RuntimeException('Filesystem mutation child exited '.$status.'.');
        }

        return [$before, $after];
    } finally {
        if (is_resource($process)) {
            proc_terminate($process, SIGKILL);
            proc_close($process);
        }
        if (is_resource($pipes[1])) {
            fclose($pipes[1]);
        }
    }
}

$expect = static function (string $label, mixed $actual, mixed $expected) use (&$failures, &$checks): void {
    ++$checks;
    if ($actual !== $expected) {
        $failures[] = $label.': expected '.json_encode($expected).', got '.json_encode($actual);
    }
};

try {
    chdir($temporary);
    foreach (['absolute', 'relative', 'file URI'] as $form) {
        $path = $temporary.'/probe';
        $argument = match ($form) {
            'absolute' => $path,
            'relative' => 'probe',
            'file URI' => 'file://'.$path,
        };
        file_put_contents($path, 'present');
        $expect($form.' external unlink', observeExternalChange($binary, $path, 'unlink',
            static fn (): bool => file_exists($argument)), [true, false]);
        clearstatcache();
        $expect($form.' external create', observeExternalChange($binary, $path, 'create',
            static fn (): bool => file_exists($argument)), [false, true]);
        unlink($path);

        foreach (['is_readable' => 0400, 'is_writable' => 0200, 'is_writeable' => 0200, 'is_executable' => 0100] as $function => $mode) {
            file_put_contents($path, 'permissions');
            chmod($path, $mode);
            $expect($form.' external chmod '.$function, observeExternalChange($binary, $path, 'chmod',
                static fn (): bool => $function($argument)), [true, false]);
            unlink($path);
        }
        foreach (['file_exists', 'is_readable', 'is_writable', 'is_writeable', 'is_executable'] as $function) {
            $expect($form.' missing '.$function, $function($argument), false);
        }
    }

    mkdir($temporary.'/other', 0700);
    file_put_contents($temporary.'/probe', 'cwd target');
    $before = file_exists('probe');
    chdir($temporary.'/other');
    $after = file_exists('probe');
    $expect('relative path follows changed cwd', [$before, $after], [true, false]);
    chdir($temporary);
    unlink($temporary.'/probe');

    foreach (['absolute', 'relative', 'file URI'] as $form) {
        $target = $temporary.'/target';
        $link = $temporary.'/link';
        file_put_contents($target, 'symlink target');
        symlink($target, $link);
        $argument = match ($form) {
            'absolute' => $link,
            'relative' => 'link',
            'file URI' => 'file://'.$link,
        };
        $expect($form.' symlink target externally removed', observeExternalChange($binary, $target, 'unlink',
            static fn (): bool => file_exists($argument)), [true, false]);
        clearstatcache();
        foreach ($accessFunctions as $function) {
            $expect($form.' dangling symlink '.$function, $function($argument), false);
        }
        unlink($link);
    }

    $coerciveCall = eval('declare(strict_types=0); return static fn (string $function, mixed $argument): bool => $function($argument);');
    $wrapper = new class {
        public mixed $context;
        public static int $calls = 0;

        public function url_stat(string $path, int $flags): array
        {
            ++self::$calls;

            return ['mode' => 0100777, 'uid' => posix_getuid(), 'gid' => posix_getgid(), 'size' => 7];
        }
    };
    if (!stream_wrapper_register('baanderqualification', $wrapper::class)) {
        throw new RuntimeException('Could not register disposable qualification stream wrapper.');
    }
    try {
        foreach ($accessFunctions as $function) {
            $wrapper::$calls = 0;
            $expect('custom wrapper '.$function, $function('baanderqualification://fixture/'.$function), true);
            $expect('custom wrapper delegated once '.$function, $wrapper::$calls, 1);
            $path = new FilesystemQualificationPath('baanderqualification://fixture/stringable-'.$function);
            $wrapper::$calls = 0;
            $expect('Stringable custom wrapper '.$function, $coerciveCall($function, $path), true);
            $expect('Stringable custom path converted once '.$function, $path->conversions, 1);
            $expect('Stringable custom wrapper delegated once '.$function, $wrapper::$calls, 1);
        }
    } finally {
        stream_wrapper_unregister('baanderqualification');
    }

    file_put_contents($temporary.'/42', 'coercible path');
    chmod($temporary.'/42', 0700);
    foreach ($accessFunctions as $function) {
        $expect('empty path '.$function, $function(''), false);
        $expect('NUL path '.$function, $function("invalid\0path"), false);
        $warnings = [];
        set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
            $warnings[] = [$severity, $message];

            return true;
        });
        try {
            $remoteResult = $function('file://baander.app/nonexistent');
        } finally {
            restore_error_handler();
        }
        $expect('remote file URL result '.$function, $remoteResult, false);
        $expect('remote file URL warning count '.$function, count($warnings), 0);
        $expect('remote file URL warnings '.$function, $warnings, []);
        $expect('integer path coercion '.$function, $coerciveCall($function, 42), true);
        $path = new FilesystemQualificationPath($temporary.'/42');
        $expect('Stringable path '.$function, $coerciveCall($function, $path), true);
        $expect('Stringable converted once '.$function, $path->conversions, 1);
        try {
            $function([]);
            $expect('array path rejected '.$function, false, true);
        } catch (TypeError) {
            $expect('array path rejected '.$function, true, true);
        }
    }
    unlink($temporary.'/42');

    if (extension_loaded('swoole') && defined('SWOOLE_HOOK_FILE')) {
        $originalFlags = Swoole\Runtime::getHookFlags();
        try {
            foreach (['file hooks enabled' => SWOOLE_HOOK_FILE, 'file hooks disabled' => 0] as $label => $flags) {
                Swoole\Runtime::enableCoroutine($flags);
                Swoole\Coroutine\run(static function () use ($temporary, $binary, $expect, $label): void {
                    $path = $temporary.'/hooked';
                    file_put_contents($path, 'hook target');
                    $expect($label.' external unlink', observeExternalChange($binary, $path, 'unlink',
                        static fn (): bool => file_exists($path)), [true, false]);
                    foreach (['is_readable' => 0400, 'is_writable' => 0200, 'is_writeable' => 0200, 'is_executable' => 0100] as $function => $mode) {
                        file_put_contents($path, 'hook permissions');
                        chmod($path, $mode);
                        $expect($label.' external chmod '.$function, observeExternalChange($binary, $path, 'chmod',
                            static fn (): bool => $function($path)), [true, false]);
                        unlink($path);
                    }
                });
            }
        } finally {
            Swoole\Runtime::enableCoroutine($originalFlags);
        }
    }

    $applicationFile = $argv[2] ?? '/var/www/html/src/Kernel.php';
    $expect('application file exists', file_exists($applicationFile), true);
    $expect('application file readable', is_readable($applicationFile), true);
    $expect('application directory exists', file_exists(dirname($applicationFile)), true);
    $expect('application directory type', is_dir(dirname($applicationFile)), true);
    foreach (['file_exists', 'is_readable'] as $function) {
        $path = new FilesystemQualificationPath($applicationFile);
        $expect('Stringable application path '.$function, $coerciveCall($function, $path), true);
        $expect('Stringable application path converted once '.$function, $path->conversions, 1);
    }

    mkdir($temporary.'/allowed', 0700);
    $denied = $temporary.'/denied';
    file_put_contents($denied, 'outside allowed directory');
    $code = 'echo json_encode(array_map(static fn ($f) => @$f($argv[1]), '
        .'["file_exists", "is_readable", "is_writable", "is_writeable", "is_executable"]));';
    $process = proc_open([$binary, '-d', 'auto_prepend_file=', '-d', 'open_basedir='.$temporary.'/allowed', '-r', $code, '--', $denied],
        [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => STDERR], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start open_basedir probe.');
    }
    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $expect('open_basedir child status', proc_close($process), 0);
    $expect('open_basedir access denied', json_decode($output, true, flags: JSON_THROW_ON_ERROR), array_fill(0, 5, false));

    if ($failures !== []) {
        throw new RuntimeException(implode("\n", $failures));
    }
    echo 'Filesystem qualification passed ('.$checks.' checks).'.PHP_EOL;
} finally {
    chdir($originalDirectory);
    foreach (glob($temporary.'/*') ?: [] as $path) {
        if (is_dir($path)) {
            rmdir($path);
        } else {
            unlink($path);
        }
    }
    rmdir($temporary);
}
