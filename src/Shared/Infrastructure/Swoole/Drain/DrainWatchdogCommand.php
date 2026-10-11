<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Swoole\Drain;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\SignalableCommandInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The drain watchdog process (see DrainWatchdog). The web server's master starts it when a drain
 * begins and ends it with SIGTERM once the server has stopped. If the deadline passes first, it
 * SIGKILLs the processes and process groups it was given, which ends the master's wait for the
 * manager, and exits with DrainWatchdog::EXIT_FIRED.
 *
 * A process is killed only while it is the same process: its start time must still match the
 * one recorded when the watchdog started, so a reused PID is left alone.
 */
#[AsCommand(
    name: 'app:server:drain:watch',
    description: 'Kill the web server processes still running at the drain deadline (started by app:serve)',
    hidden: true,
)]
final class DrainWatchdogCommand extends Command implements SignalableCommandInterface
{
    private const int POLL_MICROSECONDS = 100_000;

    private bool $stopping = false;

    protected function configure(): void
    {
        $this->addOption('deadline-ns', null, InputOption::VALUE_REQUIRED, 'The deadline, as an hrtime(true) instant in nanoseconds')
            ->addOption('pid', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'A process to kill at the deadline')
            ->addOption('pgid', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'A process group to kill at the deadline');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $errors = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $deadlineNs = filter_var($input->getOption('deadline-ns'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $pids = $this->identifiers($input->getOption('pid'));
        $processGroups = $this->identifiers($input->getOption('pgid'));
        if ($deadlineNs === false || $pids === null || $processGroups === null) {
            $errors->writeln('The drain watchdog needs --deadline-ns, and process ids and groups above 1.');

            return Command::INVALID;
        }

        // A Swoole master blocks the signals it reads through signalfd, and a child inherits the
        // mask: without this, the master's SIGTERM would never reach the watchdog.
        pcntl_sigprocmask(SIG_UNBLOCK, [SIGTERM, SIGINT]);

        $startTimes = [];
        foreach ($pids as $pid) {
            $startTimes[$pid] = self::startTime($pid);
        }

        while (!$this->stopping && ($remaining = $deadlineNs - hrtime(true)) > 0) {
            usleep(min(self::POLL_MICROSECONDS, intdiv($remaining, 1000) + 1));
        }
        if ($this->stopping) {
            return Command::SUCCESS;
        }

        $killed = [];
        foreach ($startTimes as $pid => $startTime) {
            if ($startTime !== null && self::startTime($pid) === $startTime && posix_kill($pid, SIGKILL)) {
                $killed[] = $pid;
            }
        }
        $killedGroups = [];
        foreach ($processGroups as $processGroup) {
            if (posix_kill(-$processGroup, SIGKILL)) {
                $killedGroups[] = $processGroup;
            }
        }
        $errors->writeln(sprintf(
            'The web server did not stop by its drain deadline: killed processes [%s] and process groups [%s].',
            implode(', ', $killed),
            implode(', ', $killedGroups),
        ));

        return DrainWatchdog::EXIT_FIRED;
    }

    /** @return list<int> */
    public function getSubscribedSignals(): array
    {
        return [SIGTERM, SIGINT];
    }

    public function handleSignal(int $signal, int|false $previousExitCode = 0): int|false
    {
        $this->stopping = true;

        return false;
    }

    /**
     * Ids above 1: never 0 (the caller's own group), 1 (init, or every process as a group), or negative.
     *
     * @return list<int>|null
     */
    private function identifiers(mixed $values): ?array
    {
        $identifiers = [];
        foreach (is_array($values) ? $values : [] as $value) {
            $identifier = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 2]]);
            if ($identifier === false) {
                return null;
            }
            $identifiers[] = $identifier;
        }

        return $identifiers;
    }

    /** The process's start time in clock ticks since boot (field 22 of /proc/<pid>/stat), or null when it is gone. */
    private static function startTime(int $pid): ?string
    {
        $stat = @file_get_contents('/proc/' . $pid . '/stat');
        if ($stat === false) {
            return null;
        }
        $fields = explode(' ', substr($stat, (int) strrpos($stat, ')') + 2));

        return $fields[19] ?? null;
    }
}
