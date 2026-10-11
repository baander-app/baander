<?php

declare(strict_types=1);

namespace SwooleBundle\SwooleBundle\Bridge\Symfony\Bundle\Command;

use SwooleBundle\SwooleBundle\Coroutine\CoroutinePool;
use SwooleBundle\SwooleBundle\Server\Api\ApiServerClientFactory;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * Stops the running server through its API server (DELETE /api/server), the same stop as SIGTERM,
 * and waits for the master to exit: the configured shutdown timeout plus a margin.
 */
final class ServerStopCommand extends Command
{
    private const int MARGIN_SECONDS = 5;

    public function __construct(
        private readonly ApiServerClientFactory $apiServerClientFactory,
        private readonly int $shutdownTimeout,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setDescription('Stop the running Swoole HTTP server through its API server and wait for it to exit.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            [$masterPid] = CoroutinePool::fromCoroutines(function (): int {
                $client = $this->apiServerClientFactory->newClient();
                $masterPid = $client->status()['server']['processes']['master']['pid'];
                $client->shutdown();

                return $masterPid;
            })->run();
        } catch (Throwable $exception) {
            $io->error(sprintf('Cannot stop the server through its API server: %s', $exception->getMessage()));

            return 1;
        }

        $wait = $this->shutdownTimeout + self::MARGIN_SECONDS;
        $giveUpAt = microtime(true) + $wait;
        while (is_dir('/proc/' . $masterPid) && microtime(true) < $giveUpAt) {
            usleep(100_000);
        }
        if (is_dir('/proc/' . $masterPid)) {
            $io->error(sprintf('The server (master PID %d) is still running %d seconds after the stop request.', $masterPid, $wait));

            return 1;
        }

        $io->success('Swoole server shutdown successfully');

        return 0;
    }
}
