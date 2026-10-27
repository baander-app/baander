<?php

declare(strict_types=1);

namespace Baander\Tooling\Fixtures\Web;

/**
 * Checks malformed HTTP input and WebSocket authentication across Redis outages.
 */
final class SecurityWorkflow
{
    public static function main(string $root): int
    {
        $workflow = new RuntimeWorkflow($root, security: true);
        return $workflow->execute(static function (RuntimeWorkflow $workflow): void {
            $workflow->fixture(['php', 'tests/Fixtures/security-runtime.php']);
            $fixture = ['php', 'tests/Fixtures/websocket-runtime.php'];
            $workflow->fixture([...$fixture, 'normal']);
            $workflow->fixture([...$fixture, 'prepare-outage']);
            $workflow->docker->run(['pause', $workflow->id . '-redis']);
            $workflow->fixture([...$fixture, 'outage']);
            $workflow->docker->run(['unpause', $workflow->id . '-redis']);
            $workflow->fixture([...$fixture, 'recovered']);
            $workflow->fixture([...$fixture, 'prepare-outage']);
            $workflow->docker->run(['stop', '-t', '1', $workflow->id . '-redis']);
            $workflow->fixture([...$fixture, 'outage']);
            $workflow->docker->run(['start', $workflow->id . '-redis']);
            $workflow->attempts($workflow->redisReady(...), 20);
            $workflow->fixture([...$fixture, 'recovered']);
            $workflow->fixture([...$fixture, 'logs']);
            $canary = $workflow->app(
                ['grep', '-R', '-F', 'security-runtime-secret-canary', 'var/log', '/tmp/security-runtime-serve.log'],
                check: false,
            );
            if ($canary->exitCode === 0) {
                fwrite(STDOUT, $canary->stdout);
                throw new \RuntimeException('A malformed request credential reached runtime logs.');
            }
        });
    }
}
