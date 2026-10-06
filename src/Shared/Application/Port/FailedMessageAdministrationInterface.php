<?php

declare(strict_types=1);

namespace App\Shared\Application\Port;

use App\Shared\Application\DTO\FailedMessage;
use App\Shared\Application\DTO\FailedMessagePage;
use App\Shared\Application\FailedMessageRetryException;
use App\Shared\Application\FailureTransportUnavailableException;

/**
 * Operates on the Messenger failure transport. The CLI counterparts are Symfony's
 * messenger:failed:show, messenger:failed:retry and messenger:failed:remove.
 * Every method throws FailureTransportUnavailableException when the transport fails.
 */
interface FailedMessageAdministrationInterface
{
    public function count(): int;

    /** Newest failures first. */
    public function page(int $page, int $limit): FailedMessagePage;

    public function find(string $id): ?FailedMessage;

    /**
     * Handles the message again through messenger:failed:retry. A message that fails
     * again goes back to the failure transport under a new id, until the failure
     * transport's retry strategy (three retries) is exhausted and the worker discards it.
     *
     * @return bool false when no failed message has this id
     *
     * @throws FailedMessageRetryException when the retry command itself fails
     * @throws FailureTransportUnavailableException
     */
    public function retry(string $id): bool;

    /** @return bool false when no failed message has this id */
    public function remove(string $id): bool;

    /** @return int the number of messages removed */
    public function removeAll(): int;
}
