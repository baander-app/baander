<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messenger;

use App\Shared\Application\DTO\FailedMessage;
use App\Shared\Application\DTO\FailedMessagePage;
use App\Shared\Application\FailedMessageRetryException;
use App\Shared\Application\FailureTransportUnavailableException;
use App\Shared\Application\Port\FailedMessageAdministrationInterface;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DBALException;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\MessageDecodingFailedException;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\Stamp\ErrorDetailsStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Stamp\SentToFailureTransportStamp;
use Symfony\Component\Messenger\Transport\Receiver\ListableReceiverInterface;
use Symfony\Component\Process\Process;

/**
 * Administers the Doctrine failure transport through its listable receiver, the
 * same API the messenger:failed:* commands use. Paging reads ids from the
 * transport's table because the receiver can only list a prefix, in no order.
 */
final readonly class FailureTransportAdministration implements FailedMessageAdministrationInterface
{
    /** Doctrine transport ids are positive bigints; 18 digits stay below 2^63. */
    private const string ID_PATTERN = '/^[1-9][0-9]{0,17}$/';

    private const int REMOVE_BATCH = 100;

    private const int RETRY_TIMEOUT_SECONDS = 60;

    public function __construct(
        private ListableReceiverInterface $receiver,
        private Connection $connection,
        private string $tableName,
        private string $queueName,
        private string $projectDir,
    ) {
    }

    public function count(): int
    {
        try {
            return (int) $this->connection->fetchOne(
                sprintf('SELECT COUNT(*) FROM %s WHERE queue_name = ?', $this->table()),
                [$this->queueName],
            );
        } catch (DBALException $exception) {
            throw $this->unavailable($exception);
        }
    }

    public function page(int $page, int $limit): FailedMessagePage
    {
        $messages = [];
        foreach ($this->ids($limit, ($page - 1) * $limit) as $id) {
            $envelope = $this->envelope($id);
            if ($envelope !== null) {
                $messages[] = $this->describe($id, $envelope);
            }
        }

        return new FailedMessagePage($messages, $this->count());
    }

    public function find(string $id): ?FailedMessage
    {
        $envelope = $this->envelope($id);

        return $envelope === null ? null : $this->describe($id, $envelope);
    }

    public function retry(string $id): bool
    {
        if ($this->envelope($id) === null) {
            return false;
        }

        // The command runs the message through a Messenger worker, whose events reset
        // services. A child process keeps that away from the HTTP server's container.
        // An HTTP request has no terminal: --force answers the per-message prompt.
        $process = new Process(
            [PHP_BINARY, 'bin/console', 'messenger:failed:retry', $id, '--force', '--no-interaction'],
            $this->projectDir,
            timeout: self::RETRY_TIMEOUT_SECONDS,
        );
        $process->run();
        if (!$process->isSuccessful()) {
            throw new FailedMessageRetryException(trim($process->getErrorOutput()));
        }

        return true;
    }

    public function remove(string $id): bool
    {
        $envelope = $this->envelope($id);
        if ($envelope === null) {
            return false;
        }
        $this->reject($envelope);

        return true;
    }

    public function removeAll(): int
    {
        $removed = 0;
        // Each id leaves the table: rejected here, rejected by the receiver when it
        // cannot decode the row, or already removed by a concurrent operation.
        while (($ids = $this->ids(self::REMOVE_BATCH, 0)) !== []) {
            foreach ($ids as $id) {
                try {
                    $envelope = $this->receiver->find($id);
                } catch (MessageDecodingFailedException) {
                    ++$removed;
                    continue;
                } catch (TransportException $exception) {
                    throw $this->unavailable($exception);
                }
                if ($envelope !== null) {
                    $this->reject($envelope);
                    ++$removed;
                }
            }
        }

        return $removed;
    }

    /** @return list<string> */
    private function ids(int $limit, int $offset): array
    {
        try {
            /** @var list<int|string> $ids */
            $ids = $this->connection->fetchFirstColumn(
                sprintf('SELECT id FROM %s WHERE queue_name = ? ORDER BY id DESC LIMIT ? OFFSET ?', $this->table()),
                [$this->queueName, $limit, $offset],
                [ParameterType::STRING, ParameterType::INTEGER, ParameterType::INTEGER],
            );
        } catch (DBALException $exception) {
            throw $this->unavailable($exception);
        }

        return array_map(strval(...), $ids);
    }

    private function envelope(string $id): ?Envelope
    {
        if (preg_match(self::ID_PATTERN, $id) !== 1) {
            return null;
        }

        try {
            return $this->receiver->find($id);
        } catch (MessageDecodingFailedException) {
            // The Doctrine receiver deletes a row it cannot decode before throwing.
            return null;
        } catch (TransportException $exception) {
            throw $this->unavailable($exception);
        }
    }

    private function reject(Envelope $envelope): void
    {
        try {
            $this->receiver->reject($envelope);
        } catch (TransportException $exception) {
            throw $this->unavailable($exception);
        }
    }

    private function unavailable(\Throwable $exception): FailureTransportUnavailableException
    {
        return new FailureTransportUnavailableException($exception->getMessage(), 0, $exception);
    }

    private function describe(string $id, Envelope $envelope): FailedMessage
    {
        $error = $envelope->last(ErrorDetailsStamp::class);
        $redelivery = $envelope->last(RedeliveryStamp::class);

        return new FailedMessage(
            id: $id,
            messageClass: $envelope->getMessage()::class,
            originalTransport: $envelope->last(SentToFailureTransportStamp::class)?->getOriginalReceiverName(),
            errorClass: $error?->getExceptionClass(),
            errorMessage: $error?->getExceptionMessage(),
            failedAt: $redelivery === null ? null : \DateTimeImmutable::createFromInterface($redelivery->getRedeliveredAt()),
            retryCount: RedeliveryStamp::getRetryCountFromEnvelope($envelope),
        );
    }

    private function table(): string
    {
        return $this->connection->quoteSingleIdentifier($this->tableName);
    }
}
