<?php

declare(strict_types=1);

namespace Baander\Tooling\Architecture;

final class SourceExceptions
{
    /** @var list<array{path:string,rule:string,anchor:string,count:int,reason:string}> */
    private const array CALLSITES = [
        [
            'path' => 'scripts/tests/php/DevelopmentRetainedStateTest.php',
            'rule' => 'application-root',
            'anchor' => '        yield \'literal environment\' => ["ENV APP_HOME=/var/www/html \\\\\\n    APP_ENV=prod\\nWORKDIR \\${APP_HOME}\\n", true];',
            'count' => 1,
            'reason' => 'Independent historical layout fixture proves literal source resolution and rejects ambiguous declarations.',
        ],
        [
            'path' => 'scripts/tests/php/DevelopmentRetainedStateTest.php',
            'rule' => 'application-root',
            'anchor' => '        yield \'overridden environment\' => ["ENV APP_HOME=/var/www/html\\nENV APP_HOME=/srv/baander\\nWORKDIR \\${APP_HOME}\\n", false];',
            'count' => 1,
            'reason' => 'Independent historical layout fixture proves literal source resolution and rejects ambiguous declarations.',
        ],
        [
            'path' => 'scripts/tests/php/DevelopmentRetainedStateTest.php',
            'rule' => 'application-root',
            'anchor' => '        yield \'legacy syntax override\' => ["ENV APP_HOME=/var/www/html\\nENV APP_HOME /srv/baander\\nWORKDIR \\${APP_HOME}\\n", false];',
            'count' => 1,
            'reason' => 'Independent historical layout fixture proves literal source resolution and rejects ambiguous declarations.',
        ],
        [
            'path' => 'scripts/tests/php/DevelopmentRetainedStateTest.php',
            'rule' => 'application-root',
            'anchor' => '        yield \'environment declared too late\' => ["WORKDIR \\${APP_HOME}\\nENV APP_HOME=/var/www/html\\n", false];',
            'count' => 1,
            'reason' => 'Independent historical layout fixture proves literal source resolution and rejects ambiguous declarations.',
        ],
        [
            'path' => 'scripts/tests/php/DevelopmentRetainedStateTest.php',
            'rule' => 'application-root',
            'anchor' => '        yield \'environment from unrelated stage\' => ["ENV APP_HOME=/var/www/html\\nFROM php:8.5\\nWORKDIR \\${APP_HOME}\\n", false];',
            'count' => 1,
            'reason' => 'Independent historical layout fixture proves literal source resolution and rejects ambiguous declarations.',
        ],
        [
            'path' => 'scripts/tests/php/DevelopmentRetainedStateTest.php',
            'rule' => 'application-root',
            'anchor' => '        self::assertSame(\'/var/www/html\', $this->store->load($record[\'id\'])[\'prepared_release\'][\'layout\'][\'root\']);',
            'count' => 1,
            'reason' => 'Independent historical layout fixture proves literal source resolution and rejects ambiguous declarations.',
        ],
        [
            'path' => 'scripts/tests/php/ToolingArchitectureTest.php',
            'rule' => 'obsolete-helper',
            'anchor' => "                'packages/baander-tooling/src/Fixtures/Web/Bad.php' => '<?php namespace Baander\\\\Tooling\\\\Fixtures\\\\Web; final class Bad { public function value(): object { return new \\\\Baander\\\\Tooling\\\\Development\\\\Docker; } }',",
            'count' => 1,
            'reason' => 'Independent dependency-gate fixture must prove that the removed helper remains a forbidden edge.',
        ],
        [
            'path' => 'scripts/tests/php/HttpFixtureTest.php',
            'rule' => 'manual-loader',
            'anchor' => '        $autoload = var_export(dirname(__DIR__, 3) . \'/vendor/autoload.php\', true);
        file_put_contents(
            $harness,
            \'<?php require \' . $autoload . \';\' . <<<\'PHP_HARNESS\'',
            'count' => 1,
            'reason' => 'The generated HTTP qualification child loads the root Composer loader exported immediately above this callsite.',
        ],
        [
            'path' => 'scripts/tests/php/StaticCheckStagingStatusTest.php',
            'rule' => 'manual-loader',
            'anchor' => '            $loader = dirname(__DIR__, 3) . \'/vendor/autoload.php\';
            file_put_contents($directory . \'/invoke.php\', \'<?php require \' . var_export($loader, true) . \';\' . <<<\'CHILD\'',
            'count' => 1,
            'reason' => 'The generated staging failure child loads the root Composer loader assigned immediately above this callsite.',
        ],
        [
            'path' => 'scripts/tests/php/Fixtures/controller-publication-gate.php',
            'rule' => 'manual-loader',
            'anchor' => '$argc = count($argv);
require $launcher;',
            'count' => 1,
            'reason' => 'The independent publication probe waits before loading the actual installed launcher to verify publication and lease behavior.',
        ],
        [
            'path' => 'scripts/tests/php/ApplicationBuildContractTest.php',
            'rule' => 'process-runner',
            'anchor' => '                $script = dirname(__DIR__, 3) . \'/docker/general/start-\' . $role . \'.sh\';
                $result = new \\Baander\\Tooling\\Process\\ProcessRunner()->run(new ProcessSpec(',
            'count' => 1,
            'reason' => 'Engine contract test or independent test observer exercises raw process semantics.',
        ],
        [
            'path' => 'scripts/tests/php/ApplicationBuildContractTest.php',
            'rule' => 'process-runner',
            'anchor' => '        $environmentFile = $this->source . \'/compose.fixture\';
        $result = new \\Baander\\Tooling\\Process\\ProcessRunner()->run(new ProcessSpec(',
            'count' => 1,
            'reason' => 'Engine contract test or independent test observer exercises raw process semantics.',
        ],
        [
            'path' => 'scripts/tests/php/ApplicationBuildContractTest.php',
            'rule' => 'process-runner',
            'anchor' => '        chmod($docker, 0700);
        $result = new \\Baander\\Tooling\\Process\\ProcessRunner()->run(new ProcessSpec(',
            'count' => 1,
            'reason' => 'Engine contract test or independent test observer exercises raw process semantics.',
        ],
        [
            'path' => 'scripts/tests/php/ApplicationInventoryTest.php',
            'rule' => 'application-root',
            'anchor' => '        \'revision\' => \'abc\',
        \'application_layout\' => [\'version\' => 1, \'root\' => \'/var/www/html\'],',
            'count' => 1,
            'reason' => 'Explicit canonical-layout assertion or fixture response tests the configured layout contract.',
        ],
        [
            'path' => 'scripts/tests/php/ApplicationInventoryTest.php',
            'rule' => 'application-root',
            'anchor' => '        ]);
        $this->put(\'/var/www/html/vendor/acme/runtime/LICENSE\', \'composer notice\');',
            'count' => 1,
            'reason' => 'Explicit canonical-layout assertion or fixture response tests the configured layout contract.',
        ],
        [
            'path' => 'scripts/tests/php/ApplicationInventoryTest.php',
            'rule' => 'application-root',
            'anchor' => '        $this->put(\'/var/www/html/vendor/acme/runtime/LICENSE\', \'composer notice\');
        $this->put(\'/var/www/html/composer.lock\', \'{"packages-dev":[{"name":"acme/dev"}]}\');',
            'count' => 1,
            'reason' => 'Explicit canonical-layout assertion or fixture response tests the configured layout contract.',
        ],
        [
            'path' => 'scripts/tests/php/ApplicationInventoryTest.php',
            'rule' => 'application-root',
            'anchor' => '    {
        $this->put(\'/var/www/html/vendor/composer/installed.json\', Json::canonical([\'packages\' => [$package]]));',
            'count' => 1,
            'reason' => 'Explicit canonical-layout assertion or fixture response tests the configured layout contract.',
        ],
        [
            'path' => 'scripts/tests/php/ApplicationInventoryTest.php',
            'rule' => 'application-root',
            'anchor' => '        self::assertSame([\'acme/runtime\'], array_column($inventory[\'composer\'], \'name\'));
        self::assertSame(\'installed-composer\', $this->entry($inventory, \'/var/www/html/vendor/acme/runtime/LICENSE\')[\'group\']);',
            'count' => 1,
            'reason' => 'Explicit canonical-layout assertion or fixture response tests the configured layout contract.',
        ],
        [
            'path' => 'scripts/tests/php/ApplicationInventoryTest.php',
            'rule' => 'application-root',
            'anchor' => '        ]);
        $this->put(\'/var/www/html/packages/swoole-bundle/LICENSE\', \'local package notice\');',
            'count' => 1,
            'reason' => 'Explicit canonical-layout assertion or fixture response tests the configured layout contract.',
        ],
        [
            'path' => 'scripts/tests/php/ApplicationInventoryTest.php',
            'rule' => 'application-root',
            'anchor' => '        $this->put(\'/var/www/html/packages/swoole-bundle/LICENSE\', \'local package notice\');
        $this->put(\'/var/www/html/packages/swoole-bundle/src/Bundle.php\', \'<?php // source\');',
            'count' => 1,
            'reason' => 'Explicit canonical-layout assertion or fixture response tests the configured layout contract.',
        ],
        [
            'path' => 'scripts/tests/php/ApplicationInventoryTest.php',
            'rule' => 'application-root',
            'anchor' => '        $this->put(\'/var/www/html/packages/swoole-bundle/src/Bundle.php\', \'<?php // source\');
        Inventory::directory($this->root . \'/var/www/html/vendor/swoole-bundle\');',
            'count' => 1,
            'reason' => 'Explicit canonical-layout assertion or fixture response tests the configured layout contract.',
        ],
        [
            'path' => 'scripts/tests/php/ApplicationInventoryTest.php',
            'rule' => 'application-root',
            'anchor' => '        Inventory::directory($this->root . \'/var/www/html/vendor/swoole-bundle\');
        symlink(\'../../packages/swoole-bundle/\', $this->root . \'/var/www/html/vendor/swoole-bundle/swoole-bundle\');',
            'count' => 1,
            'reason' => 'Explicit canonical-layout assertion or fixture response tests the configured layout contract.',
        ],
        [
            'path' => 'scripts/tests/php/ApplicationInventoryTest.php',
            'rule' => 'application-root',
            'anchor' => '        foreach ([
            \'/var/www/html/packages/swoole-bundle/src/Bundle.php\',',
            'count' => 1,
            'reason' => 'Explicit canonical-layout assertion or fixture response tests the configured layout contract.',
        ],
        [
            'path' => 'scripts/tests/php/ApplicationInventoryTest.php',
            'rule' => 'application-root',
            'anchor' => '            \'/var/www/html/packages/swoole-bundle/src/Bundle.php\',
            \'/var/www/html/vendor/swoole-bundle/swoole-bundle\',',
            'count' => 1,
            'reason' => 'Explicit canonical-layout assertion or fixture response tests the configured layout contract.',
        ],
        [
            'path' => 'scripts/tests/php/ApplicationInventoryTest.php',
            'rule' => 'application-root',
            'anchor' => '        }
        self::assertContains(\'/var/www/html/packages/swoole-bundle/LICENSE\', array_column($inventory[\'evidence\'], \'path\'));',
            'count' => 1,
            'reason' => 'Explicit canonical-layout assertion or fixture response tests the configured layout contract.',
        ],
        [
            'path' => 'scripts/tests/php/ApplicationInventoryTest.php',
            'rule' => 'application-root',
            'anchor' => '    {
        $this->put(\'/var/www/html/ui/package.json\', \'{"dependencies":{"fixture":"1"}}\');',
            'count' => 1,
            'reason' => 'Explicit canonical-layout assertion or fixture response tests the configured layout contract.',
        ],
        [
            'path' => 'scripts/tests/php/ApplicationInventoryTest.php',
            'rule' => 'application-root',
            'anchor' => '        $this->put(\'/var/www/html/ui/package.json\', \'{"dependencies":{"fixture":"1"}}\');
        $this->put(\'/var/www/html/public/code.wasm\', "\\0asmcode");',
            'count' => 1,
            'reason' => 'Explicit canonical-layout assertion or fixture response tests the configured layout contract.',
        ],
        [
            'path' => 'scripts/tests/php/ApplicationInventoryTest.php',
            'rule' => 'application-root',
            'anchor' => '        $inventory = $this->generate();
        self::assertSame(\'copied-repository\', $this->entry($inventory, \'/var/www/html/ui/package.json\')[\'group\']);',
            'count' => 1,
            'reason' => 'Explicit canonical-layout assertion or fixture response tests the configured layout contract.',
        ],
        [
            'path' => 'scripts/tests/php/ApplicationInventoryTest.php',
            'rule' => 'application-root',
            'anchor' => '        self::assertSame(\'copied-repository\', $this->entry($inventory, \'/var/www/html/ui/package.json\')[\'group\']);
        self::assertContains(\'/var/www/html/public/code.wasm\', array_column($inventory[\'review_gaps\'], \'path\'));',
            'count' => 1,
            'reason' => 'Explicit canonical-layout assertion or fixture response tests the configured layout contract.',
        ],
        [
            'path' => 'scripts/tests/php/ApplicationInventoryTest.php',
            'rule' => 'application-root',
            'anchor' => '    {
        $this->put(\'/var/www/html/LICENSE\', \'composer notice\');',
            'count' => 1,
            'reason' => 'Explicit canonical-layout assertion or fixture response tests the configured layout contract.',
        ],
        [
            'path' => 'scripts/tests/php/ApplicationInventoryTest.php',
            'rule' => 'application-root',
            'anchor' => '        Inventory::directory($this->root . \'/srv\');
        rename($this->root . \'/var/www/html\', $this->root . \'/srv/baander\');',
            'count' => 1,
            'reason' => 'Explicit canonical-layout assertion or fixture response tests the configured layout contract.',
        ],
        [
            'path' => 'scripts/tests/php/ApplicationInventoryTest.php',
            'rule' => 'process-engine',
            'anchor' => '        $output = $this->temporary . \'/must-not-exist\';
        $child = proc_open(',
            'count' => 1,
            'reason' => 'Independent test child observes lifecycle or invokes the subject process.',
        ],
        [
            'path' => 'scripts/tests/php/ApplicationInventoryTest.php',
            'rule' => 'process-engine',
            'anchor' => '        );
        $child = proc_open(',
            'count' => 1,
            'reason' => 'Independent test child observes lifecycle or invokes the subject process.',
        ],
        [
            'path' => 'scripts/tests/php/ApplicationInventoryTest.php',
            'rule' => 'process-engine',
            'anchor' => '        $environment[\'TMPDIR\'] = $this->temporary;
        $child = proc_open(',
            'count' => 1,
            'reason' => 'Independent test child observes lifecycle or invokes the subject process.',
        ],
        [
            'path' => 'scripts/tests/php/ApplicationLayoutTest.php',
            'rule' => 'application-root',
            'anchor' => '        self::assertSame(1, $layout->version);
        self::assertSame(\'/var/www/html\', $layout->root);',
            'count' => 1,
            'reason' => 'Explicit canonical-layout assertion or fixture response tests the configured layout contract.',
        ],
        [
            'path' => 'scripts/tests/php/ApplicationLayoutTest.php',
            'rule' => 'application-root',
            'anchor' => '        self::assertSame(\'/var/www/html\', $layout->root);
        self::assertSame(\'/var/www/html/ui/web\', $layout->path(\'ui/web\'));',
            'count' => 1,
            'reason' => 'Explicit canonical-layout assertion or fixture response tests the configured layout contract.',
        ],
        [
            'path' => 'scripts/tests/php/AtomicFileTest.php',
            'rule' => 'process-engine',
            'anchor' => '    {
        $child = proc_open(',
            'count' => 1,
            'reason' => 'Independent test child observes lifecycle or invokes the subject process.',
        ],
        [
            'path' => 'scripts/tests/php/BackendComparisonTest.php',
            'rule' => 'application-root',
            'anchor' => '{
    private const string SOURCE = \'/var/www/html/src/Probe.php\';',
            'count' => 1,
            'reason' => 'Explicit canonical-layout assertion or fixture response tests the configured layout contract.',
        ],
        [
            'path' => 'scripts/tests/php/BackendCoordinatorTest.php',
            'rule' => 'application-root',
            'anchor' => '        $lanes = Coordinator::partition(
            [\'/var/www/html/tests/Unit/NativeTest.php\', \'tests/Functional/OnlyTest.php\'],',
            'count' => 1,
            'reason' => 'Explicit canonical-layout assertion or fixture response tests the configured layout contract.',
        ],
        [
            'path' => 'scripts/tests/php/BackendCoordinatorTest.php',
            'rule' => 'application-root',
            'anchor' => '            self::assertStringContainsString("tar -xf /tmp/checkout.tar -C \'/srv/baander\'", $provision[array_key_last($provision)]);
            self::assertStringNotContainsString(\'/var/www/html\', $provision[array_key_last($provision)]);',
            'count' => 1,
            'reason' => 'Explicit canonical-layout assertion or fixture response tests the configured layout contract.',
        ],
        [
            'path' => 'scripts/tests/php/BackendCoverageTest.php',
            'rule' => 'application-root',
            'anchor' => '    private const string XML = <<<\'XML\'
        <coverage><project><file name="/var/www/html/src/Covered.php">',
            'count' => 1,
            'reason' => 'Explicit canonical-layout assertion or fixture response tests the configured layout contract.',
        ],
        [
            'path' => 'scripts/tests/php/CommandExecutorTest.php',
            'rule' => 'process-runner',
            'anchor' => '            PHP;
        $result = new ProcessRunner()->run(new ProcessSpec(',
            'count' => 1,
            'reason' => 'Engine contract test or independent test observer exercises raw process semantics.',
        ],
        [
            'path' => 'scripts/tests/php/ControllerPayloadTest.php',
            'rule' => 'process-engine',
            'anchor' => '        $old = $this->payload->publish($this->root . \'/source\');
        $child = proc_open([PHP_BINARY, $this->payload->launcher(), \'wait\'], [0 => [\'pipe\', \'r\'], 1 => [\'pipe\', \'w\'], 2 => [\'pipe\', \'w\']], $pipes);',
            'count' => 2,
            'reason' => 'Independent test child observes lifecycle or invokes the subject process.',
        ],
        [
            'path' => 'scripts/tests/php/ControllerPayloadTest.php',
            'rule' => 'process-engine',
            'anchor' => '        $old = $this->payload->publish($this->root . \'/source\');
        $child = proc_open([PHP_BINARY, $this->payload->launcher(), \'shutdown\'], [0 => [\'pipe\', \'r\'], 1 => [\'pipe\', \'w\'], 2 => [\'pipe\', \'w\']], $pipes);',
            'count' => 1,
            'reason' => 'Independent test child observes lifecycle or invokes the subject process.',
        ],
        [
            'path' => 'scripts/tests/php/ControllerPayloadTest.php',
            'rule' => 'process-engine',
            'anchor' => '        $id = $this->payload->publish($this->root . \'/source\');
        $child = proc_open([PHP_BINARY, __DIR__ . \'/Fixtures/controller-publication-gate.php\', $this->payload->launcher()], [0 => [\'pipe\', \'r\'], 1 => [\'pipe\', \'w\'], 2 => [\'pipe\', \'w\']], $pipes);',
            'count' => 1,
            'reason' => 'Independent test child observes lifecycle or invokes the subject process.',
        ],
        [
            'path' => 'scripts/tests/php/ControllerPayloadTest.php',
            'rule' => 'process-engine',
            'anchor' => '        $this->payload->publish($this->root . \'/source\');
        $reader = proc_open([PHP_BINARY, $this->payload->launcher(), \'wait\'], [0 => [\'pipe\', \'r\'], 1 => [\'pipe\', \'w\'], 2 => [\'pipe\', \'w\']], $readerPipes);',
            'count' => 1,
            'reason' => 'Independent test child observes lifecycle or invokes the subject process.',
        ],
        [
            'path' => 'scripts/tests/php/ControllerPayloadTest.php',
            'rule' => 'process-engine',
            'anchor' => '        $selected = $this->payload->publish($this->root . \'/source\');
        $drainer = proc_open([PHP_BINARY, __DIR__ . \'/Fixtures/controller-drain-readers.php\', $this->root . \'/state\'], [0 => [\'pipe\', \'r\'], 1 => [\'pipe\', \'w\'], 2 => [\'pipe\', \'w\']], $drainPipes);',
            'count' => 1,
            'reason' => 'Independent test child observes lifecycle or invokes the subject process.',
        ],
        [
            'path' => 'scripts/tests/php/DeveloperHelpersTest.php',
            'rule' => 'process-runner',
            'anchor' => '        file_put_contents($this->work . \'/new.md\', $new . "\\n");
        $result = new ProcessRunner()->run(new ProcessSpec(argv: [PHP_BINARY, $this->audit(), $this->work . \'/old.md\', $this->work . \'/new.md\']));',
            'count' => 1,
            'reason' => 'Engine contract test or independent test observer exercises raw process semantics.',
        ],
        [
            'path' => 'scripts/tests/php/DeveloperHelpersTest.php',
            'rule' => 'process-runner',
            'anchor' => '        self::assertSame($report . "\\n", $result->stdout);
        $same = new ProcessRunner()->run(new ProcessSpec(argv: [PHP_BINARY, $this->audit(), $this->work . \'/old.md\', $this->work . \'/old.md\']));',
            'count' => 1,
            'reason' => 'Engine contract test or independent test observer exercises raw process semantics.',
        ],
        [
            'path' => 'scripts/tests/php/DeveloperHelpersTest.php',
            'rule' => 'process-runner',
            'anchor' => '        file_put_contents($this->work . \'/new.md\', $new);
        $result = new ProcessRunner()->run(new ProcessSpec(argv: [PHP_BINARY, \'-n\', $this->audit(), $this->work . \'/old.md\', $this->work . \'/new.md\']));',
            'count' => 1,
            'reason' => 'Engine contract test or independent test observer exercises raw process semantics.',
        ],
        [
            'path' => 'scripts/tests/php/DeveloperHelpersTest.php',
            'rule' => 'process-runner',
            'anchor' => '    {
        $runner = new ProcessRunner();',
            'count' => 1,
            'reason' => 'Engine contract test or independent test observer exercises raw process semantics.',
        ],
        [
            'path' => 'scripts/tests/php/DevelopmentAcceptanceTest.php',
            'rule' => 'process-runner',
            'anchor' => '        openssl_pkey_export_to_file($key, $this->root . \'/key.pem\');
        $runner = new ProcessRunner();',
            'count' => 1,
            'reason' => 'Engine contract test or independent test observer exercises raw process semantics.',
        ],
        [
            'path' => 'scripts/tests/php/DevelopmentBootstrapTest.php',
            'rule' => 'application-root',
            'anchor' => '     */
    private function inventoryRunner(?array $appEnvironment = null, ?array $observation = null, array $extraMounts = [], string $applicationRoot = \'/var/www/html\', bool $resources = false, bool $attachedFailure = false): DevDataRunner',
            'count' => 1,
            'reason' => 'Explicit canonical-layout assertion or fixture response tests the configured layout contract.',
        ],
        [
            'path' => 'scripts/tests/php/DevelopmentBootstrapTest.php',
            'rule' => 'application-root',
            'anchor' => '            self::assertContains(\'/srv/baander\', $probe[\'arguments\']);
            self::assertStringNotContainsString(\'/var/www/html\', implode(\' \', $probe[\'arguments\']));',
            'count' => 1,
            'reason' => 'Explicit canonical-layout assertion or fixture response tests the configured layout contract.',
        ],
        [
            'path' => 'scripts/tests/php/DevelopmentBootstrapTest.php',
            'rule' => 'application-root',
            'anchor' => '            $this->instance,
            array_replace($this->environment(), [$key => \'/var/www/html/storage/nested\']),',
            'count' => 1,
            'reason' => 'Explicit canonical-layout assertion or fixture response tests the configured layout contract.',
        ],
        [
            'path' => 'scripts/tests/php/DevelopmentBootstrapTest.php',
            'rule' => 'application-root',
            'anchor' => '            \'Type\' => $kind,
            \'Destination\' => \'/var/www/html/var/transcode_state\',',
            'count' => 1,
            'reason' => 'Explicit canonical-layout assertion or fixture response tests the configured layout contract.',
        ],
        [
            'path' => 'scripts/tests/php/DevelopmentCliBoundaryTest.php',
            'rule' => 'process-runner',
            'anchor' => '    {
        return new ProcessRunner()->run(new ProcessSpec(',
            'count' => 1,
            'reason' => 'Engine contract test or independent test observer exercises raw process semantics.',
        ],
        [
            'path' => 'scripts/tests/php/DevelopmentCliBoundaryTest.php',
            'rule' => 'process-runner',
            'anchor' => '        unset($environment[\'BAANDER_TEST_TIMEOUT\']);
        $result = new ProcessRunner()->run(new ProcessSpec(',
            'count' => 1,
            'reason' => 'Engine contract test or independent test observer exercises raw process semantics.',
        ],
        [
            'path' => 'scripts/tests/php/DevelopmentCliBoundaryTest.php',
            'rule' => 'process-engine',
            'anchor' => '        $this->executable(\'git\', [\'repository\' => $this->repository, \'hang\' => true, \'marker\' => $marker]);
        $process = proc_open(',
            'count' => 1,
            'reason' => 'Independent test child observes lifecycle or invokes the subject process.',
        ],
        [
            'path' => 'scripts/tests/php/DevelopmentCliTest.php',
            'rule' => 'application-root',
            'anchor' => '        mkdir($root . \'/packages/baander-tooling/resources\', 0700, true);
        file_put_contents($root . \'/packages/baander-tooling/resources/application-layout.json\', \'{"version":1,"root":"/var/www/html"}\');',
            'count' => 1,
            'reason' => 'Explicit canonical-layout assertion or fixture response tests the configured layout contract.',
        ],
        [
            'path' => 'scripts/tests/php/DevelopmentCliTest.php',
            'rule' => 'process-runner',
            'anchor' => '        ] as $target => $expected) {
            $result = new ProcessRunner()->run(new ProcessSpec(',
            'count' => 1,
            'reason' => 'Engine contract test or independent test observer exercises raw process semantics.',
        ],
        [
            'path' => 'scripts/tests/php/DevelopmentCliTest.php',
            'rule' => 'process-runner',
            'anchor' => '        $payload = escapeshellarg(PHP_BINARY) . \' \' . escapeshellarg($capture) . " \'quoted value\' \';\' touch " . escapeshellarg($marker);
        $result = new ProcessRunner()->run(new ProcessSpec(',
            'count' => 1,
            'reason' => 'Engine contract test or independent test observer exercises raw process semantics.',
        ],
        [
            'path' => 'scripts/tests/php/DevelopmentCliTest.php',
            'rule' => 'process-runner',
            'anchor' => '        $runner->execute([\'git\', \'init\', \'-q\', $this->directory . \'/checkout\']);
        $result = new ProcessRunner()->run(new ProcessSpec(',
            'count' => 1,
            'reason' => 'Engine contract test or independent test observer exercises raw process semantics.',
        ],
        [
            'path' => 'scripts/tests/php/DevelopmentCliTest.php',
            'rule' => 'process-runner',
            'anchor' => '    {
        $result = new ProcessRunner()->run(new ProcessSpec(',
            'count' => 2,
            'reason' => 'Engine contract test or independent test observer exercises raw process semantics.',
        ],
        [
            'path' => 'scripts/tests/php/DevelopmentImagePinsTest.php',
            'rule' => 'default-image',
            'anchor' => '        self::assertNotContains([$this->docker, \'--host\', \'unix:///var/run/docker.sock\', \'pull\', \'baander-database:latest\'], $this->runner->calls);',
            'count' => 1,
            'reason' => 'Independent catalog or image-selection assertion checks the documented default.',
        ],
        [
            'path' => 'scripts/tests/php/DevelopmentRetainedStateTest.php',
            'rule' => 'application-root',
            'anchor' => '        PrivateDirectory::prepare($source . \'/bin\');
        file_put_contents($source . \'/Dockerfile\', "FROM php:8.5\\nWORKDIR /var/www/html\\n");',
            'count' => 1,
            'reason' => 'Explicit canonical-layout assertion or fixture response tests the configured layout contract.',
        ],
        [
            'path' => 'scripts/tests/php/DevelopmentRetainedStateTest.php',
            'rule' => 'application-root',
            'anchor' => '            $release = ReleaseContract::fromArray($saved[$field])->toArray();
            self::assertEquals([\'version\' => 1, \'root\' => \'/var/www/html\'], $release[\'layout\']);',
            'count' => 1,
            'reason' => 'Explicit canonical-layout assertion or fixture response tests the configured layout contract.',
        ],
        [
            'path' => 'scripts/tests/php/DevelopmentRuntimeTest.php',
            'rule' => 'application-root',
            'anchor' => '        copy(dirname(__DIR__, 3) . \'/docker/dev/frontend.conf.template\', $this->root . \'/source/docker/dev/frontend.conf.template\');
        $this->release = [\'revision\' => \'revision\', \'source\' => $this->root . \'/source\', \'layout\' => [\'version\' => 1, \'root\' => \'/var/www/html\']];',
            'count' => 1,
            'reason' => 'Explicit canonical-layout assertion or fixture response tests the configured layout contract.',
        ],
        [
            'path' => 'scripts/tests/php/DevelopmentRuntimeTest.php',
            'rule' => 'application-root',
            'anchor' => '        self::assertCount(1, $installs);
        self::assertContains(\'type=bind,src=\' . $instance[\'worktree\'] . \',dst=/var/www/html,readonly\', array_values($installs)[0]);',
            'count' => 1,
            'reason' => 'Explicit canonical-layout assertion or fixture response tests the configured layout contract.',
        ],
        [
            'path' => 'scripts/tests/php/DevelopmentRuntimeTest.php',
            'rule' => 'application-root',
            'anchor' => '            $this->store->directory(\'persistent\') . \'/env.json\',
            \'{"CONVERT_STORAGE_PATH":"/var/www/html/../outside-preview"}\',',
            'count' => 1,
            'reason' => 'Explicit canonical-layout assertion or fixture response tests the configured layout contract.',
        ],
        [
            'path' => 'scripts/tests/php/DevelopmentRuntimeTest.php',
            'rule' => 'application-root',
            'anchor' => '        $script = str_replace(
            [\'/var/www/html/public\', \'/seed\'],',
            'count' => 1,
            'reason' => 'Explicit canonical-layout assertion or fixture response tests the configured layout contract.',
        ],
        [
            'path' => 'scripts/tests/php/DevelopmentRuntimeTest.php',
            'rule' => 'application-root',
            'anchor' => '        $script = str_replace(
            [\'/var/www/html/config/secrets\', \'/seed\'],',
            'count' => 1,
            'reason' => 'Explicit canonical-layout assertion or fixture response tests the configured layout contract.',
        ],
        [
            'path' => 'scripts/tests/php/DevelopmentSupervisorTest.php',
            'rule' => 'process-runner',
            'anchor' => 'if (($argv[1] ?? \'\') === \'--probe-package\') {
    $runner = new Baander\\Tooling\\Process\\ProcessRunner();',
            'count' => 1,
            'reason' => 'Engine contract test or independent test observer exercises raw process semantics.',
        ],
        [
            'path' => 'scripts/tests/php/DevelopmentSupervisorTest.php',
            'rule' => 'process-runner',
            'anchor' => '        self::assertFileExists($generation . \'/scripts/vendor/autoload.php\');
        self::assertSame(\'controller\', new ProcessRunner()->run(new ProcessSpec([PHP_BINARY, \'-n\', $controller]))->stdout);',
            'count' => 1,
            'reason' => 'Engine contract test or independent test observer exercises raw process semantics.',
        ],
        [
            'path' => 'scripts/tests/php/DevelopmentSupervisorTest.php',
            'rule' => 'process-runner',
            'anchor' => '        $probe = json_decode(
            new ProcessRunner()->run(new ProcessSpec([PHP_BINARY, $controller, \'--probe-package\']))->stdout,',
            'count' => 1,
            'reason' => 'Engine contract test or independent test observer exercises raw process semantics.',
        ],
        [
            'path' => 'scripts/tests/php/DevelopmentWorkersTest.php',
            'rule' => 'application-root',
            'anchor' => '                \'boot_id\' => \'saved-boot\',
                \'layout\' => [\'version\' => 1, \'root\' => \'/var/www/html\'],',
            'count' => 1,
            'reason' => 'Explicit canonical-layout assertion or fixture response tests the configured layout contract.',
        ],
        [
            'path' => 'scripts/tests/php/DevelopmentWorkersTest.php',
            'rule' => 'application-root',
            'anchor' => '        ]];
        self::assertSame([\'--tmpfs\', $source . \'/movies:ro,noexec,nosuid,nodev,size=1m,mode=0555\'], Workers::retirementMountArguments($manifest, \'/var/www/html\'));',
            'count' => 1,
            'reason' => 'Explicit canonical-layout assertion or fixture response tests the configured layout contract.',
        ],
        [
            'path' => 'scripts/tests/php/DevelopmentWorkersTest.php',
            'rule' => 'application-root',
            'anchor' => '        self::assertSame([\'--tmpfs\', \'/run/media/unavailable:ro,noexec,nosuid,nodev,size=1m,mode=0555\'], Workers::retirementMountArguments([\'mediaMounts\' => [[\'source\' => \'/run/media/unavailable\', \'target\' => \'/media\', \'role\' => \'source\', \'readOnly\' => true]]], \'/var/www/html\'));',
            'count' => 1,
            'reason' => 'Explicit canonical-layout assertion or fixture response tests the configured layout contract.',
        ],
        [
            'path' => 'scripts/tests/php/DevelopmentWorkersTest.php',
            'rule' => 'application-root',
            'anchor' => '    {
        foreach ([\'/\', \'/operator/manifest.json\', \'/var/run/docker.sock\', \'/usr/local/bin/php\', \'/var/www/html/vendor\', \'/media/../operator\'] as $source) {',
            'count' => 1,
            'reason' => 'Explicit canonical-layout assertion or fixture response tests the configured layout contract.',
        ],
        [
            'path' => 'scripts/tests/php/DevelopmentWorkersTest.php',
            'rule' => 'application-root',
            'anchor' => '            try {
                Workers::retirementMountArguments([\'mediaMounts\' => [[\'source\' => $source, \'target\' => \'/media\', \'role\' => \'source\', \'readOnly\' => true]]], \'/var/www/html\');',
            'count' => 1,
            'reason' => 'Explicit canonical-layout assertion or fixture response tests the configured layout contract.',
        ],
        [
            'path' => 'scripts/tests/php/DevelopmentWorkersTest.php',
            'rule' => 'application-root',
            'anchor' => '            \'sha256:\' . str_repeat(\'b\', 64),
            [\'/usr/local/bin/php\', \'/var/www/html/bin/console\', \'app:worker\'],',
            'count' => 1,
            'reason' => 'Explicit canonical-layout assertion or fixture response tests the configured layout contract.',
        ],
        [
            'path' => 'scripts/tests/php/DevelopmentWorkersTest.php',
            'rule' => 'application-root',
            'anchor' => '        AtomicFile::writeJson($this->store->directory(\'persistent\') . \'/env.json\', $this->environment());
        $this->instance[\'state_mounts\'][\'storage\'] = [\'type\' => \'bind\', \'source\' => $this->root, \'target\' => \'/var/www/html/storage\'];',
            'count' => 1,
            'reason' => 'Explicit canonical-layout assertion or fixture response tests the configured layout contract.',
        ],
        [
            'path' => 'scripts/tests/php/Fixtures/backend-docker.php',
            'rule' => 'application-root',
            'anchor' => '    }
    $layout = [\'version\' => 1, \'root\' => getenv(\'BACKEND_APP_HOME\') ?: \'/var/www/html\'];',
            'count' => 1,
            'reason' => 'Explicit canonical-layout assertion or fixture response tests the configured layout contract.',
        ],
        [
            'path' => 'scripts/tests/php/Fixtures/development-command-executor.php',
            'rule' => 'process-runner',
            'anchor' => '        $result = new ProcessRunner()->run($command);',
            'count' => 1,
            'reason' => 'Engine contract test or independent test observer exercises raw process semantics.',
        ],
        [
            'path' => 'scripts/tests/php/Fixtures/development-command-executor.php',
            'rule' => 'process-runner',
            'anchor' => '    {
        return ($this->processRunner ??= new ProcessRunner())->start($command);',
            'count' => 1,
            'reason' => 'Engine contract test or independent test observer exercises raw process semantics.',
        ],
        [
            'path' => 'scripts/tests/php/Fixtures/development-command-executor.php',
            'rule' => 'process-runner',
            'anchor' => '    {
        return $policy->complete(($this->processRunner ??= new ProcessRunner())->wait($process));',
            'count' => 1,
            'reason' => 'Engine contract test or independent test observer exercises raw process semantics.',
        ],
        [
            'path' => 'scripts/tests/php/Fixtures/development-command-executor.php',
            'rule' => 'process-runner',
            'anchor' => '        $process->cancel($signal);
        return ($this->processRunner ??= new ProcessRunner())->wait($process);',
            'count' => 1,
            'reason' => 'Engine contract test or independent test observer exercises raw process semantics.',
        ],
        [
            'path' => 'scripts/tests/php/Fixtures/development-command-executor.php',
            'rule' => 'application-root',
            'anchor' => '            \'revision\' => \'fixture-revision\',
            \'layout\' => [\'version\' => 1, \'root\' => \'/var/www/html\'],',
            'count' => 1,
            'reason' => 'Explicit canonical-layout assertion or fixture response tests the configured layout contract.',
        ],
        [
            'path' => 'scripts/tests/php/Fixtures/development-database-docker.php',
            'rule' => 'application-root',
            'anchor' => '} elseif ($command === \'image\' && ($arguments[1] ?? null) === \'inspect\') {
    $applicationRoot = $configuration[\'applicationRoot\'] ?? \'/var/www/html\';',
            'count' => 1,
            'reason' => 'Explicit canonical-layout assertion or fixture response tests the configured layout contract.',
        ],
        [
            'path' => 'scripts/tests/php/Fixtures/development-database-docker.php',
            'rule' => 'application-root',
            'anchor' => '            }
            $mounts = [[\'Type\' => \'bind\', \'Source\' => \'/original/checkout\', \'Destination\' => $configuration[\'applicationRoot\'] ?? \'/var/www/html\'],',
            'count' => 1,
            'reason' => 'Explicit canonical-layout assertion or fixture response tests the configured layout contract.',
        ],
        [
            'path' => 'scripts/tests/php/Fixtures/historical-worker-recipe.php',
            'rule' => 'application-root',
            'anchor' => '            }
            if (($target === \'/var\' || $target === \'/var/www\' || $target === \'/var/www/html\'',
            'count' => 1,
            'reason' => 'Explicit canonical-layout assertion or fixture response tests the configured layout contract.',
        ],
        [
            'path' => 'scripts/tests/php/Fixtures/historical-worker-recipe.php',
            'rule' => 'application-root',
            'anchor' => '            if (($target === \'/var\' || $target === \'/var/www\' || $target === \'/var/www/html\'
                || str_starts_with($target, \'/var/www/html/\'))',
            'count' => 1,
            'reason' => 'Explicit canonical-layout assertion or fixture response tests the configured layout contract.',
        ],
        [
            'path' => 'scripts/tests/php/Fixtures/historical-worker-recipe.php',
            'rule' => 'application-root',
            'anchor' => '                || str_starts_with($target, \'/var/www/html/\'))
                && $target !== \'/var/www/html/var/transcode_state\'',
            'count' => 1,
            'reason' => 'Explicit canonical-layout assertion or fixture response tests the configured layout contract.',
        ],
        [
            'path' => 'scripts/tests/php/Fixtures/historical-worker-recipe.php',
            'rule' => 'application-root',
            'anchor' => '                && $target !== \'/var/www/html/var/transcode_state\'
                && $target !== \'/var/www/html/storage\'',
            'count' => 1,
            'reason' => 'Explicit canonical-layout assertion or fixture response tests the configured layout contract.',
        ],
        [
            'path' => 'scripts/tests/php/Fixtures/historical-worker-recipe.php',
            'rule' => 'application-root',
            'anchor' => '                && $target !== \'/var/www/html/storage\'
                && !str_starts_with($target, \'/var/www/html/storage/\')) {',
            'count' => 1,
            'reason' => 'Explicit canonical-layout assertion or fixture response tests the configured layout contract.',
        ],
        [
            'path' => 'scripts/tests/php/Fixtures/messaging-docker.php',
            'rule' => 'application-root',
            'anchor' => '} elseif ($kind === \'image\' && $verb === \'inspect\') {
    $home = getenv(\'PROBE_APP_HOME\') ?: \'/var/www/html\';',
            'count' => 1,
            'reason' => 'Explicit canonical-layout assertion or fixture response tests the configured layout contract.',
        ],
        [
            'path' => 'scripts/tests/php/Fixtures/process-child.php',
            'rule' => 'process-engine',
            'anchor' => '    case \'descendant\':
        $child = proc_open([PHP_BINARY, __FILE__, \'sleep\'], [0 => [\'file\', \'/dev/null\', \'r\'], 1 => STDOUT, 2 => STDERR], $pipes);',
            'count' => 1,
            'reason' => 'Independent test child observes lifecycle or invokes the subject process.',
        ],
        [
            'path' => 'scripts/tests/php/Fixtures/process-runner.php',
            'rule' => 'process-runner',
            'anchor' => '
$runner = new ProcessRunner();',
            'count' => 1,
            'reason' => 'Engine contract test or independent test observer exercises raw process semantics.',
        ],
        [
            'path' => 'scripts/tests/php/Fixtures/retained-state-executor.php',
            'rule' => 'application-root',
            'anchor' => '            $output = json_encode([[\'Id\' => $arguments[array_key_last($arguments)],
                \'Config\' => [\'WorkingDir\' => \'/var/www/html\', \'Env\' => [\'APP_HOME=/var/www/html\'], \'Labels\' => []]]], JSON_THROW_ON_ERROR);',
            'count' => 1,
            'reason' => 'Explicit canonical-layout assertion or fixture response tests the configured layout contract.',
        ],
        [
            'path' => 'scripts/tests/php/Fixtures/staging-status-docker.php',
            'rule' => 'application-root',
            'anchor' => '} elseif ($command === \'image\') {
    echo json_encode([[\'Config\' => [\'Labels\' => [\'org.baander.application-layout\' => \'{"version":1,"root":"/var/www/html"}\'], \'Env\' => [\'APP_HOME=/var/www/html\'], \'WorkingDir\' => \'/var/www/html\']]]);',
            'count' => 1,
            'reason' => 'Explicit canonical-layout assertion or fixture response tests the configured layout contract.',
        ],
        [
            'path' => 'scripts/tests/php/Fixtures/static-check-resource-docker.php',
            'rule' => 'application-root',
            'anchor' => 'if ($command === \'image\' && ($arguments[1] ?? \'\') === \'inspect\') {
    $root = is_file($state . \'/wrong-layout\') ? \'/srv/baander\' : \'/var/www/html\';',
            'count' => 1,
            'reason' => 'Explicit canonical-layout assertion or fixture response tests the configured layout contract.',
        ],
        [
            'path' => 'scripts/tests/php/HttpFixtureTest.php',
            'rule' => 'process-runner',
            'anchor' => '        mkdir($this->directory, 0700);
        $this->runner = new ProcessRunner();',
            'count' => 1,
            'reason' => 'Engine contract test or independent test observer exercises raw process semantics.',
        ],
        [
            'path' => 'scripts/tests/php/ImageCatalogTest.php',
            'rule' => 'default-image',
            'anchor' => '        $images = ToolingConfiguration::resolve()->images;
        self::assertSame(\'redis:7-alpine\', $images->role(\'developmentRedis\')->reference);',
            'count' => 1,
            'reason' => 'Independent catalog or image-selection assertion checks the documented default.',
        ],
        [
            'path' => 'scripts/tests/php/ImageCatalogTest.php',
            'rule' => 'default-image',
            'anchor' => '        self::assertSame(\'redis:7-alpine\', $images->role(\'developmentRedis\')->reference);
        self::assertSame(\'redis/redis-stack-server:edge\', $images->role(\'fixtureRedis\')->reference);',
            'count' => 1,
            'reason' => 'Independent catalog or image-selection assertion checks the documented default.',
        ],
        [
            'path' => 'scripts/tests/php/ImageCatalogTest.php',
            'rule' => 'default-image',
            'anchor' => '        self::assertSame(\'redis/redis-stack-server:edge\', $images->role(\'fixtureRedis\')->reference);
        self::assertSame(\'mcr.microsoft.com/playwright:v1.60.0-noble\', $images->role(\'browser\')->reference);',
            'count' => 1,
            'reason' => 'Independent catalog or image-selection assertion checks the documented default.',
        ],
        [
            'path' => 'scripts/tests/php/MessagingWorkflowTest.php',
            'rule' => 'process-runner',
            'anchor' => '        PHP_CODE;
        return new \\Baander\\Tooling\\Process\\ProcessRunner()->run(new \\Baander\\Tooling\\Process\\ProcessSpec(',
            'count' => 1,
            'reason' => 'Engine contract test or independent test observer exercises raw process semantics.',
        ],
        [
            'path' => 'scripts/tests/php/ProcessRunnerTest.php',
            'rule' => 'process-runner',
            'anchor' => '        $arguments = [\'\', \'literal space\', \'"quote"\', \'$dollar;command\', "new\\nline"];
        $result = new ProcessRunner()->run(new ProcessSpec(',
            'count' => 1,
            'reason' => 'Engine contract test or independent test observer exercises raw process semantics.',
        ],
        [
            'path' => 'scripts/tests/php/ProcessRunnerTest.php',
            'rule' => 'process-runner',
            'anchor' => '    {
        $runner = new ProcessRunner();',
            'count' => 4,
            'reason' => 'Engine contract test or independent test observer exercises raw process semantics.',
        ],
        [
            'path' => 'scripts/tests/php/ProcessRunnerTest.php',
            'rule' => 'process-runner',
            'anchor' => '    {
        $result = new ProcessRunner()->run(new ProcessSpec(argv: $this->child(\'sleep\'), timeout: 0.08));',
            'count' => 1,
            'reason' => 'Engine contract test or independent test observer exercises raw process semantics.',
        ],
        [
            'path' => 'scripts/tests/php/ProcessRunnerTest.php',
            'rule' => 'process-runner',
            'anchor' => '    {
        $result = new ProcessRunner()->run(new ProcessSpec(argv: $this->child(\'descendant\'), processGroup: true, timeout: 3));',
            'count' => 1,
            'reason' => 'Engine contract test or independent test observer exercises raw process semantics.',
        ],
        [
            'path' => 'scripts/tests/php/ProcessRunnerTest.php',
            'rule' => 'process-runner',
            'anchor' => '        try {
            $result = new ProcessRunner()->run(new ProcessSpec(argv: $this->child(\'echo\'), logPath: $path));',
            'count' => 1,
            'reason' => 'Engine contract test or independent test observer exercises raw process semantics.',
        ],
        [
            'path' => 'scripts/tests/php/ProcessRunnerTest.php',
            'rule' => 'process-runner',
            'anchor' => '        try {
            $runner = new ProcessRunner();',
            'count' => 3,
            'reason' => 'Engine contract test or independent test observer exercises raw process semantics.',
        ],
        [
            'path' => 'scripts/tests/php/ProcessRunnerTest.php',
            'rule' => 'process-runner',
            'anchor' => '        foreach ([2, 15] as $signal) {
            $result = new ProcessRunner()->run(new ProcessSpec(',
            'count' => 1,
            'reason' => 'Engine contract test or independent test observer exercises raw process semantics.',
        ],
        [
            'path' => 'scripts/tests/php/ProcessRunnerTest.php',
            'rule' => 'process-runner',
            'anchor' => '    {
        $result = new ProcessRunner()->run(new ProcessSpec(',
            'count' => 9,
            'reason' => 'Engine contract test or independent test observer exercises raw process semantics.',
        ],
        [
            'path' => 'scripts/tests/php/ProcessRunnerTest.php',
            'rule' => 'process-runner',
            'anchor' => '    {
        $result = new ProcessRunner()->run(new ProcessSpec(argv: $this->child(\'descendant\')));',
            'count' => 1,
            'reason' => 'Engine contract test or independent test observer exercises raw process semantics.',
        ],
        [
            'path' => 'scripts/tests/php/ProcessRunnerTest.php',
            'rule' => 'process-runner',
            'anchor' => '            try {
                new ProcessRunner()->run(new ProcessSpec(argv: $this->child(\'echo\'), logPath: $directory . \'/linked\'));',
            'count' => 1,
            'reason' => 'Engine contract test or independent test observer exercises raw process semantics.',
        ],
        [
            'path' => 'scripts/tests/php/ProcessRunnerTest.php',
            'rule' => 'process-runner',
            'anchor' => '        try {
            $result = new ProcessRunner()->run(new ProcessSpec(',
            'count' => 3,
            'reason' => 'Engine contract test or independent test observer exercises raw process semantics.',
        ],
        [
            'path' => 'scripts/tests/php/ProcessRunnerTest.php',
            'rule' => 'process-runner',
            'anchor' => '    {
        $result = new ProcessRunner()->run(new ProcessSpec(argv: [PHP_BINARY, __DIR__ . \'/Fixtures/process-runner.php\', \'scope-between\'], timeout: 3));',
            'count' => 1,
            'reason' => 'Engine contract test or independent test observer exercises raw process semantics.',
        ],
        [
            'path' => 'scripts/tests/php/ProcessRunnerTest.php',
            'rule' => 'process-runner',
            'anchor' => '    {
        $result = new ProcessRunner()->run(new ProcessSpec(argv: [PHP_BINARY, __DIR__ . \'/Fixtures/process-runner.php\', \'scope-cleanup\'], timeout: 3));',
            'count' => 1,
            'reason' => 'Engine contract test or independent test observer exercises raw process semantics.',
        ],
        [
            'path' => 'scripts/tests/php/ProcessRunnerTest.php',
            'rule' => 'process-runner',
            'anchor' => '    {
        $result = new ProcessRunner()->run(new ProcessSpec(argv: [PHP_BINARY, __DIR__ . \'/Fixtures/process-runner.php\', \'scope-close\'], timeout: 3));',
            'count' => 1,
            'reason' => 'Engine contract test or independent test observer exercises raw process semantics.',
        ],
        [
            'path' => 'scripts/tests/php/ProcessRunnerTest.php',
            'rule' => 'process-runner',
            'anchor' => '                try {
                    new ProcessRunner()->run(new ProcessSpec(argv: $this->child(\'echo\'), logPath: $path, stderrLogPath: $stderr));',
            'count' => 1,
            'reason' => 'Engine contract test or independent test observer exercises raw process semantics.',
        ],
        [
            'path' => 'scripts/tests/php/ProcessRunnerTest.php',
            'rule' => 'process-runner',
            'anchor' => '        foreach ([true, false] as $timeout) {
            $runner = new ProcessRunner();',
            'count' => 1,
            'reason' => 'Engine contract test or independent test observer exercises raw process semantics.',
        ],
        [
            'path' => 'scripts/tests/php/RegistryCleanupTest.php',
            'rule' => 'process-runner',
            'anchor' => 'PHP;
            $result = new ProcessRunner()->run(new ProcessSpec([PHP_BINARY, \'-r\', $source, $root], environment: getenv(), timeout: 10, processGroup: true));',
            'count' => 1,
            'reason' => 'Engine contract test or independent test observer exercises raw process semantics.',
        ],
        [
            'path' => 'scripts/tests/php/RegistryCleanupTest.php',
            'rule' => 'process-runner',
            'anchor' => '        try {
            $result = new ProcessRunner()->run(new ProcessSpec(',
            'count' => 1,
            'reason' => 'Engine contract test or independent test observer exercises raw process semantics.',
        ],
        [
            'path' => 'scripts/tests/php/RegistryCleanupTest.php',
            'rule' => 'process-runner',
            'anchor' => '            self::assertStringContainsString(\'network rm\', $calls);
            $retry = new ProcessRunner()->run(new ProcessSpec(',
            'count' => 1,
            'reason' => 'Engine contract test or independent test observer exercises raw process semantics.',
        ],
        [
            'path' => 'scripts/tests/php/RegistryCleanupTest.php',
            'rule' => 'process-runner',
            'anchor' => '    {
        $result = new ProcessRunner()->run(new ProcessSpec(',
            'count' => 1,
            'reason' => 'Engine contract test or independent test observer exercises raw process semantics.',
        ],
        [
            'path' => 'scripts/tests/php/RegistryContainerWorkflowTest.php',
            'rule' => 'process-engine',
            'anchor' => '}
$child = proc_open([\'docker\', \'info\', \'--format\', \'{{json .}}\'], [0 => [\'file\', \'/dev/null\', \'r\'], 1 => [\'pipe\', \'w\'], 2 => [\'pipe\', \'w\']], $pipes);',
            'count' => 1,
            'reason' => 'Independent test child observes lifecycle or invokes the subject process.',
        ],
        [
            'path' => 'scripts/tests/php/RegistryContainerWorkflowTest.php',
            'rule' => 'process-engine',
            'anchor' => '        );
        $process = proc_open(',
            'count' => 1,
            'reason' => 'Independent test child observes lifecycle or invokes the subject process.',
        ],
        [
            'path' => 'scripts/tests/php/RegistryQualificationTest.php',
            'rule' => 'process-runner',
            'anchor' => '    {
        $result = new \\Baander\\Tooling\\Process\\ProcessRunner()->run(new \\Baander\\Tooling\\Process\\ProcessSpec(',
            'count' => 1,
            'reason' => 'Engine contract test or independent test observer exercises raw process semantics.',
        ],
        [
            'path' => 'scripts/tests/php/RegistryQualificationTest.php',
            'rule' => 'process-engine',
            'anchor' => '    {
        $process = proc_open(',
            'count' => 1,
            'reason' => 'Independent test child observes lifecycle or invokes the subject process.',
        ],
        [
            'path' => 'scripts/tests/php/RegistryQualificationTest.php',
            'rule' => 'process-engine',
            'anchor' => '        chmod($probe, 0755);
        $process = proc_open(',
            'count' => 1,
            'reason' => 'Independent test child observes lifecycle or invokes the subject process.',
        ],
        [
            'path' => 'scripts/tests/php/RegistryStaticTest.php',
            'rule' => 'process-engine',
            'anchor' => 'if (basename($argv[0]) === \'curl\') {
    $child = proc_open([PHP_BINARY, \'-r\', \'sleep(30);\'], [], $pipes);',
            'count' => 1,
            'reason' => 'Independent test child observes lifecycle or invokes the subject process.',
        ],
        [
            'path' => 'scripts/tests/php/RetainBuildEvidenceTest.php',
            'rule' => 'process-engine',
            'anchor' => '    {
        $process = proc_open($arguments, [0 => [\'pipe\', \'r\'], 1 => [\'pipe\', \'w\'], 2 => [\'pipe\', \'w\']], $pipes);',
            'count' => 1,
            'reason' => 'Independent test child observes lifecycle or invokes the subject process.',
        ],
        [
            'path' => 'scripts/tests/php/RuntimeTest.php',
            'rule' => 'process-engine',
            'anchor' => '            . $body;
        $process = proc_open(',
            'count' => 1,
            'reason' => 'Independent test child observes lifecycle or invokes the subject process.',
        ],
        [
            'path' => 'scripts/tests/php/StartupWorkflowTest.php',
            'rule' => 'application-root',
            'anchor' => '        mkdir($directory . \'/temporary\', 0700);
        file_put_contents($directory . \'/docker/general/start-web.sh\', "#!/bin/sh\\nrm -rf /var/www/html/var/cache/prod\\n");',
            'count' => 1,
            'reason' => 'Explicit canonical-layout assertion or fixture response tests the configured layout contract.',
        ],
        [
            'path' => 'scripts/tests/php/StartupWorkflowTest.php',
            'rule' => 'process-runner',
            'anchor' => '        try {
            $result = new ProcessRunner()->run(new ProcessSpec(',
            'count' => 2,
            'reason' => 'Engine contract test or independent test observer exercises raw process semantics.',
        ],
        [
            'path' => 'scripts/tests/php/WebRuntimeWorkflowTest.php',
            'rule' => 'process-runner',
            'anchor' => '        unset($environment[\'DOCKER_HOST\'], $environment[\'DOCKER_CONTEXT\'], $environment[\'BAANDER_TEST_DOCKER_ENDPOINT\']);
        $result = (new ProcessRunner())->run(new ProcessSpec(',
            'count' => 1,
            'reason' => 'Engine contract test or independent test observer exercises raw process semantics.',
        ],
        [
            'path' => 'scripts/tests/php/WebWorkflowTest.php',
            'rule' => 'process-runner',
            'anchor' => '        $trace = $directory . \'/trace\';
        $runner = new ProcessRunner();',
            'count' => 1,
            'reason' => 'Engine contract test or independent test observer exercises raw process semantics.',
        ],
        [
            'path' => 'scripts/tests/php/WebWorkflowTest.php',
            'rule' => 'process-runner',
            'anchor' => '        $ready = $directory . \'/ready\';
        $runner = new ProcessRunner();',
            'count' => 1,
            'reason' => 'Engine contract test or independent test observer exercises raw process semantics.',
        ],
        [
            'path' => 'scripts/tests/php/WebWorkflowTest.php',
            'rule' => 'process-runner',
            'anchor' => '        $tarTrace = $directory . \'/tar-trace\';
        $runner = new ProcessRunner();',
            'count' => 1,
            'reason' => 'Engine contract test or independent test observer exercises raw process semantics.',
        ],
        [
            'path' => 'packages/baander-tooling/src/Architecture/SourcePolicy.php',
            'rule' => 'application-root',
            'anchor' => 'if (str_contains($value, \'/var/www/html\'))',
            'count' => 1,
            'reason' => 'The checker names the forbidden root as policy syntax, never as a runtime default.',
        ],
        [
            'path' => 'packages/baander-tooling/src/Process/RunningProcess.php',
            'rule' => 'process-engine',
            'anchor' => '$process = proc_open($argv, $descriptors, $pipes, $spec->cwd, $spec->environment, [\'bypass_shell\' => true]);',
            'count' => 1,
            'reason' => 'The shared process engine owns native child creation.',
        ],
        [
            'path' => 'packages/baander-tooling/src/Process/EngineExecutor.php',
            'rule' => 'process-runner',
            'anchor' => '$this->runner = $runner ?? new ProcessRunner();',
            'count' => 1,
            'reason' => 'The shared executor composes the underlying engine.',
        ],
        [
            'path' => 'packages/baander-tooling/src/Fixtures/Http/OrphanSupervisor.php',
            'rule' => 'process-runner',
            'anchor' => '$parent = new ProcessRunner()->run(new ProcessSpec(',
            'count' => 1,
            'reason' => 'Independent SIGKILL and adopted-child observation needs unmodified raw engine results.',
        ],
        [
            'path' => 'packages/baander-tooling/src/Backend/StackIsolation.php',
            'rule' => 'process-engine',
            'anchor' => '$p = proc_open(',
            'count' => 1,
            'reason' => 'The generated subject proves environment inheritance into a nested application process.',
        ],
        [
            'path' => 'scripts/tests/php/RegistryContainerWorkflowTest.php',
            'rule' => 'raw-docker',
            'anchor' => '$child = proc_open([\'docker\', \'info\', \'--format\', \'{{json .}}\'], [0 => [\'file\', \'/dev/null\', \'r\'], 1 => [\'pipe\', \'w\'], 2 => [\'pipe\', \'w\']], $pipes);',
            'count' => 1,
            'reason' => 'Independent nested subject verifies exported Docker target inheritance.',
        ],
        [
            'path' => 'relay/tests/Support/Process.php',
            'rule' => 'process-engine',
            'anchor' => '        $this->process = proc_open($command, $descriptors, $this->pipes, $cwd, $env, [\'bypass_shell\' => true]);',
            'count' => 1,
            'reason' => 'Independent relay qualification engine owns child observation inside the self-contained relay build context.',
        ],
        [
            'path' => 'relay/tools/inventory/Inventory.php',
            'rule' => 'process-engine',
            'anchor' => '        $process = proc_open($argv, [0 => [\'file\', \'/dev/null\', \'r\'], 1 => [\'pipe\', \'w\'], 2 => [\'file\', \'php://stderr\', \'w\']], $pipes);',
            'count' => 1,
            'reason' => 'Self-contained registry build inventory runs inspected build commands without a root tooling package dependency.',
        ],
        [
            'path' => 'relay/tools/qualification/Runtime.php',
            'rule' => 'process-engine',
            'anchor' => '        $process = proc_open($arguments, [0 => [\'file\', \'/dev/null\', \'r\'], 1 => [\'pipe\', \'w\'], 2 => [\'pipe\', \'w\']], $pipes);',
            'count' => 1,
            'reason' => 'Self-contained registry filesystem qualification owns its bounded inspection child.',
        ],
        [
            'path' => 'scripts/backend-test-runner.php',
            'rule' => 'process-engine',
            'anchor' => '        $process = proc_open($command, [STDIN, STDOUT, STDERR], $pipes);',
            'count' => 1,
            'reason' => 'The application-side PHPUnit partition subject launches its test command.',
        ],
        [
            'path' => 'scripts/tests/php/Fixtures/registry-command.php',
            'rule' => 'raw-docker',
            'anchor' => '    $name = \'docker\';',
            'count' => 1,
            'reason' => 'The fake executable chooses its Docker protocol response after being launched as a disposable test binary.',
        ],
        [
            'path' => 'scripts/tests/php/WorkerSamplerTest.php',
            'rule' => 'raw-docker',
            'anchor' => '                \'/usr/bin/docker\',',
            'count' => 1,
            'reason' => 'The assertion checks the configured Docker executable in sampled command evidence.',
        ],
        [
            'path' => 'packages/baander-tooling/src/Architecture/SourcePolicy.php',
            'rule' => 'raw-docker',
            'anchor' => '            if (!in_array($string[\'value\'], [\'docker\', \'/usr/bin/docker\', \'/usr/local/bin/docker\'], true)) {',
            'count' => 1,
            'reason' => 'The checker recognizes executable syntax rather than dispatching Docker.',
        ],
    ];

    /**
     * @param list<string> $paths
     * @return list<array{path:string,line:int,rule:string,message:string}>
     */
    public static function missing(array $paths): array
    {
        $result = [];
        foreach (self::CALLSITES as $exception) {
            if (!in_array($exception['path'], $paths, true) && !isset($result[$exception['path']])) {
                $result[$exception['path']] = [
                    'path' => $exception['path'],
                    'line' => 1,
                    'rule' => 'stale-exception',
                    'message' => 'Remove or review exceptions for the missing source file.',
                ];
            }
        }
        return array_values($result);
    }

    /**
     * @param list<array{path:string,line:int,rule:string,message:string,offset:int}> $diagnostics
     * @return list<array{path:string,line:int,rule:string,message:string}>
     */
    public static function apply(string $path, string $source, array $diagnostics): array
    {
        $stale = [];
        $approved = [];
        foreach (self::CALLSITES as $exception) {
            if ($exception['path'] !== $path) {
                continue;
            }
            $ranges = [];
            $offset = 0;
            while (($start = strpos($source, $exception['anchor'], $offset)) !== false) {
                $lastLine = strrpos($exception['anchor'], "\n");
                $ranges[] = [$start + ($lastLine === false ? 0 : $lastLine + 1), $start + strlen($exception['anchor'])];
                $offset = $start + strlen($exception['anchor']);
            }
            $matching = array_filter($diagnostics, static fn(array $diagnostic): bool => $diagnostic['rule'] === $exception['rule']
                && array_any($ranges, static fn(array $range): bool => $diagnostic['offset'] >= $range[0] && $diagnostic['offset'] < $range[1]));
            $matchedLines = array_unique(array_column($matching, 'line'));
            if (count($ranges) !== $exception['count'] || count($matchedLines) !== $exception['count']) {
                $stale[] = ['path' => $path, 'line' => 1, 'rule' => 'stale-exception', 'message' => 'Review the ' . $exception['rule'] . ' exception: ' . $exception['reason']];
                continue;
            }
            array_push($approved, ...$matching);
        }
        $result = [];
        foreach ($diagnostics as $diagnostic) {
            if (in_array($diagnostic, $approved, true)) {
                continue;
            }
            unset($diagnostic['offset']);
            if (!in_array($diagnostic, $result, true)) {
                $result[] = $diagnostic;
            }
        }
        return [...$result, ...$stale];
    }
}
