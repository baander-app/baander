<?php

declare(strict_types=1);

$binary = realpath($argv[1] ?? PHP_BINARY);
if ($binary === false || file_get_contents($binary, false, null, 0, 4) !== "\x7fELF") {
    throw new RuntimeException('Pass the actual qualification ELF executable.');
}

$temporary = sys_get_temp_dir().'/baander-bootstrap-'.bin2hex(random_bytes(6));
mkdir($temporary, 0700);
$prepend = $temporary.'/prepend.php';
$override = $temporary.'/override.php';
$failure = $temporary.'/failure.php';
$exitZero = $temporary.'/exit-zero.php';
$exitSeventeen = $temporary.'/exit-seventeen.php';
$configuration = $temporary.'/php.ini';
$script = $temporary.'/script.php';
file_put_contents($prepend, '<?php $GLOBALS["bootstrap_count"] = ($GLOBALS["bootstrap_count"] ?? 0) + 1; $GLOBALS["bootstrap_source"] = "configured";');
file_put_contents($override, '<?php $GLOBALS["bootstrap_count"] = ($GLOBALS["bootstrap_count"] ?? 0) + 1; $GLOBALS["bootstrap_source"] = "override";');
file_put_contents($failure, '<?php throw new RuntimeException("Bootstrap fixture failed");');
file_put_contents($exitZero, '<?php exit(0);');
file_put_contents($exitSeventeen, '<?php exit(17);');
file_put_contents($configuration, 'auto_prepend_file="'.$prepend.'"'.PHP_EOL);
$body = 'echo json_encode(["count" => $GLOBALS["bootstrap_count"] ?? 0, "source" => $GLOBALS["bootstrap_source"] ?? null, "argv" => $argv, "binary" => PHP_BINARY], JSON_THROW_ON_ERROR);';
file_put_contents($script, '<?php '.$body);
$environment = getenv();
unset($environment['BAANDER_TYPEPHP_QUALIFICATION']);
$environment['PHP_INI_SCAN_DIR'] = '';

$run = static function (array $arguments, array $environment) use ($binary): array {
    $process = proc_open([$binary, ...$arguments], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, null, $environment);
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start the qualification ELF.');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [proc_close($process), $stdout, $stderr];
};

$cases = [
    ['marker absent', null, ['-c', $configuration, '-r', $body, '--', 'argument value'], 0, null, ['Standard input code', 'argument value']],
    ['marker disabled', '0', ['-c', $configuration, '-r', $body, '--', 'argument value'], 0, null, ['Standard input code', 'argument value']],
    ['qualification prepend', '1', ['-c', $configuration, '-r', $body, '--', 'argument value'], 1, 'configured', ['Standard input code', 'argument value']],
    ['no configuration', '1', ['-n', '-r', $body, '--', 'argument value'], 0, null, ['Standard input code', 'argument value']],
    ['effective override', '1', ['-c', $configuration, '-d', 'auto_prepend_file='.$override, '-r', $body, '--', 'argument value'], 1, 'override', ['Standard input code', 'argument value']],
    ['explicit prepend without configuration', '1', ['-n', '-d', 'auto_prepend_file='.$override, '-r', $body, '--', 'argument value'], 1, 'override', ['Standard input code', 'argument value']],
    ['normal file prepends once', '1', ['-c', $configuration, $script, 'argument value'], 1, 'configured', [$script, 'argument value']],
];

try {
    foreach ($cases as [$label, $marker, $arguments, $count, $source, $expectedArgv]) {
        $caseEnvironment = $environment;
        if ($marker !== null) {
            $caseEnvironment['BAANDER_TYPEPHP_QUALIFICATION'] = $marker;
        }
        [$status, $stdout, $stderr] = $run($arguments, $caseEnvironment);
        if ($status !== 0) {
            throw new RuntimeException($label.' exited '.$status.': '.$stderr);
        }
        $actual = json_decode($stdout, true, flags: JSON_THROW_ON_ERROR);
        $expected = ['count' => $count, 'source' => $source, 'argv' => $expectedArgv, 'binary' => $binary];
        if ($actual !== $expected) {
            throw new RuntimeException($label.': expected '.json_encode($expected).', got '.$stdout);
        }
    }

    foreach ([[$failure, 255], [$temporary.'/missing.php', 255], [$exitZero, 0], [$exitSeventeen, 17]] as [$path, $expectedStatus]) {
        [$status, $stdout] = $run(
            ['-n', '-d', 'auto_prepend_file='.$path, '-r', 'echo "BODY-RAN";'],
            [...$environment, 'BAANDER_TYPEPHP_QUALIFICATION' => '1'],
        );
        if ($status !== $expectedStatus || str_contains($stdout, 'BODY-RAN')) {
            throw new RuntimeException(sprintf('Bootstrap must stop command-line evaluation with status %d, got %d.', $expectedStatus, $status));
        }
    }
    echo "Qualification bootstrap checks passed (11 cases).\n";
} finally {
    foreach ([$prepend, $override, $failure, $exitZero, $exitSeventeen, $configuration, $script] as $path) {
        unlink($path);
    }
    rmdir($temporary);
}
