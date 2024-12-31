<?php

namespace App\Console\Commands\Octane;

use Laravel\Octane\Commands\StartSwooleCommand;
use Laravel\Octane\Swoole\ServerProcessInspector;
use Laravel\Octane\Swoole\ServerStateFile;
use Laravel\Octane\Swoole\SwooleExtension;

class ProdStartSwooleCommand extends StartSwooleCommand
{
    public $signature = 'octane:dump-server-state
                    {--host=0.0.0.0 : The IP address the server should bind to}
                    {--port=8000 : The port the server should be available on}
                    {--workers=5 : The number of workers that should be available to handle requests}
                    {--task-workers=2 : The number of task workers that should be available to handle tasks}
                    {--max-requests=500 : The number of requests to process before reloading the server}';

    public $description = 'Dump octane server state file. The start-swoole-server script must always be running.';

    public function handle(
        ServerProcessInspector $inspector,
        ServerStateFile        $serverStateFile,
        SwooleExtension        $extension,
    ): int
    {
        if (!$extension->isInstalled()) {
            $this->error('The Swoole extension is missing.');

            return self::FAILURE;
        }

        if ($inspector->serverIsRunning()) {
            $this->error('Server is already running.');

            return self::FAILURE;
        }

        if (config('octane.swoole.ssl', false) === true && !defined('SWOOLE_SSL')) {
            $this->error('You must configure Swoole with `--enable-openssl` to support ssl.');

            return self::FAILURE;
        }

        $this->writeServerStateFile($serverStateFile, $extension);

        $savedFilePath = $serverStateFile->path();

        $this->info("Server state file saved at: {$savedFilePath}");

        return self::SUCCESS;
    }
}
