<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;
use TypePhp\Build\SapiExtensionRequirements;

require '/opt/typephp/vendor/autoload.php';

$jobs = $_SERVER['argv'][1] ?? '2';
if (!is_string($jobs) || preg_match('/^[1-9][0-9]*$/D', $jobs) !== 1 || filter_var($jobs, FILTER_VALIDATE_INT) === false) {
    fwrite(STDERR, "Usage: php tools/typephp/tests/process-cache/build.php [positive-jobs]\n");
    exit(2);
}

$root = dirname(__DIR__, 4);
$artifacts = $root.'/var/typephp/process-cache-fixture';
$project = $artifacts.'/project';
if (!is_dir($project) && !mkdir($project, 0777, true) && !is_dir($project)) {
    throw new RuntimeException('Cannot create the process-cache fixture project.');
}
foreach (['entry.php', 'fixtures.php'] as $file) {
    if (!copy(__DIR__.'/'.$file, $project.'/'.$file)) {
        throw new RuntimeException('Cannot stage '.$file);
    }
}
if (!copy($root.'/composer.json', $project.'/composer.json')) {
    throw new RuntimeException('Cannot stage the application Composer manifest.');
}
$config = Yaml::parseFile($root.'/tools/typephp/application.yml');
$manifest = json_decode((string) file_get_contents($project.'/composer.json'), true, flags: JSON_THROW_ON_ERROR);
$extensions = [];
foreach (array_keys($manifest['require'] ?? []) as $dependency) {
    if (str_starts_with($dependency, 'ext-')) {
        $extensions[] = substr($dependency, 4);
    }
}
// This project has no embedded vendor directory to trigger Composer extension inference.
$config['php-builder']['extensions'] = SapiExtensionRequirements::merge($config['php-builder']['extensions'], $extensions);
$config['name'] = 'process-cache-fixture';
$config['entry'] = 'entry.php';
$config['sources'] = ['entry.php'];
$config['embedded-files'] = ['fixtures.php'];
$config['build-dir'] = $artifacts.'/build';
$config['output'] = $artifacts.'/process-cache-fixture';
if (file_put_contents($project.'/application.yml', Yaml::dump($config, 5, 2)) === false) {
    throw new RuntimeException('Cannot write the process-cache fixture configuration.');
}

$run = static function (array $arguments) use ($project): void {
    $process = proc_open($arguments, [STDIN, STDOUT, STDERR], $pipes, $project);
    if (!is_resource($process)) {
        throw new RuntimeException('Cannot start the process-cache fixture command.');
    }
    $status = proc_close($process);
    if ($status !== 0) {
        exit($status > 0 ? $status : 1);
    }
};

if (is_file($config['output']) && !unlink($config['output'])) {
    throw new RuntimeException('Cannot remove the previous process-cache fixture executable.');
}
$run([PHP_BINARY, '/opt/typephp/vendor/bin/tpc.php', $project.'/application.yml', '--no-progress', '--no-color', '--job', $jobs]);
if (!is_file($config['output']) || file_get_contents($config['output'], false, null, 0, 4) !== "\x7fELF") {
    throw new RuntimeException('The process-cache fixture build did not produce an ELF executable.');
}
if (!unlink($project.'/fixtures.php')) {
    throw new RuntimeException('Cannot remove the physical fixture source before verification.');
}
foreach ([0, 1] as $enabled) {
    echo 'Process-cache fixture: opcache.enable_cli='.$enabled."\n";
    $run(['/usr/bin/timeout', '20s', $config['output'], '-n', '-d', 'opcache.enable_cli='.$enabled, '-d', 'opcache.enable=1', '-f', $project.'/entry.php']);
}
