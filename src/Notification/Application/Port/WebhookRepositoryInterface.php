<?php

declare(strict_types=1);

namespace App\Notification\Application\Port;

use App\Notification\Application\DTO\WebhookView;
use App\Shared\Domain\Model\Uuid;

/**
 * Stores webhook configuration. Signing secrets go in encrypted and never come back out
 * through this port.
 */
interface WebhookRepositoryInterface
{
    /** @return list<WebhookView> oldest first */
    public function findAll(): array;

    public function find(Uuid $id): ?WebhookView;

    /** @param list<string>|null $categoryFilter */
    public function add(Uuid $id, string $url, ?array $categoryFilter, string $encryptedSecret): WebhookView;

    /**
     * @param list<string>|null $categoryFilter
     *
     * @return WebhookView|null null when no webhook has the ID
     */
    public function update(Uuid $id, string $url, ?array $categoryFilter): ?WebhookView;

    /** @return WebhookView|null null when no webhook has the ID */
    public function replaceSecret(Uuid $id, string $encryptedSecret): ?WebhookView;

    /** @return bool false when no webhook has the ID */
    public function delete(Uuid $id): bool;
}
