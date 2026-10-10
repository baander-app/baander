<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Console;

use App\Shared\Interface\Console\AdminCommandSupport;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * SIGINT and SIGTERM interrupt a running delete by throwing into it, so the delete handler's
 * finally block releases the library claim a delete with files holds instead of leaving it to
 * lapse with its lease. Outside a delete, a signal ends the command as it would without this.
 * For commands that implement SignalableCommandInterface.
 */
trait InterruptibleDelete
{
    private bool $deleting = false;

    /** @return list<int> */
    public function getSubscribedSignals(): array
    {
        return [\SIGINT, \SIGTERM];
    }

    public function handleSignal(int $signal, int|false $previousExitCode = 0): int|false
    {
        if ($this->deleting) {
            throw new CatalogDeleteInterrupted($signal);
        }

        return 128 + $signal;
    }

    /**
     * Dispatches the delete $message so that a signal interrupts it.
     *
     * @return int|object the handler's result, or the exit code of a failed or interrupted delete
     */
    private function dispatchDelete(AdminCommandSupport $support, SymfonyStyle $io, object $message): int|object
    {
        $this->deleting = true;
        try {
            $result = $support->dispatch($message);
            assert(is_object($result));

            return $result;
        } catch (CatalogDeleteInterrupted $interrupted) {
            $io->getErrorStyle()->error($interrupted->getMessage());

            return 128 + $interrupted->signal;
        } catch (Throwable $exception) {
            return AdminCommandSupport::fail($io, $exception);
        } finally {
            $this->deleting = false;
        }
    }
}
