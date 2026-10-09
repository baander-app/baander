<?php

declare(strict_types=1);

namespace App\Tests\Unit\Notification\Application\Handler;

use App\Notification\Application\DTO\WebhookView;
use App\Notification\Application\Port\WebhookRepositoryInterface;
use App\Shared\Domain\Model\Uuid;

/**
 * Stores webhooks in memory, each with its own signing version, so a test can hold one
 * that signs with a version other than the current default.
 */
final class InMemoryWebhookRepository implements WebhookRepositoryInterface
{
    /** @var array<string, WebhookView> */
    private array $webhooks = [];

    /** @var array<string, string> */
    private array $encryptedSecrets = [];

    public int $writes = 0;

    public function __construct(private readonly int $signingVersion = 2)
    {
    }

    public function seed(WebhookView $webhook, string $encryptedSecret): void
    {
        $this->webhooks[$webhook->id->toString()] = $webhook;
        $this->encryptedSecrets[$webhook->id->toString()] = $encryptedSecret;
    }

    public function encryptedSecret(Uuid $id): ?string
    {
        return $this->encryptedSecrets[$id->toString()] ?? null;
    }

    public function findAll(): array
    {
        return array_values($this->webhooks);
    }

    public function find(Uuid $id): ?WebhookView
    {
        return $this->webhooks[$id->toString()] ?? null;
    }

    public function add(Uuid $id, string $url, ?array $categoryFilter, string $encryptedSecret): WebhookView
    {
        ++$this->writes;
        $now = new \DateTimeImmutable();
        $webhook = new WebhookView($id, $url, $categoryFilter, $this->signingVersion, $now, $now);
        $this->seed($webhook, $encryptedSecret);

        return $webhook;
    }

    public function update(Uuid $id, string $url, ?array $categoryFilter): ?WebhookView
    {
        $current = $this->find($id);
        if ($current === null) {
            return null;
        }
        ++$this->writes;

        return $this->webhooks[$id->toString()] = new WebhookView($id, $url, $categoryFilter, $current->signingVersion, $current->createdAt, new \DateTimeImmutable());
    }

    public function replaceSecret(Uuid $id, string $encryptedSecret): ?WebhookView
    {
        $current = $this->find($id);
        if ($current === null) {
            return null;
        }
        ++$this->writes;
        $this->encryptedSecrets[$id->toString()] = $encryptedSecret;

        return $this->webhooks[$id->toString()] = new WebhookView($id, $current->url, $current->categoryFilter, $current->signingVersion, $current->createdAt, new \DateTimeImmutable());
    }

    public function delete(Uuid $id): bool
    {
        if ($this->find($id) === null) {
            return false;
        }
        ++$this->writes;
        unset($this->webhooks[$id->toString()], $this->encryptedSecrets[$id->toString()]);

        return true;
    }
}
