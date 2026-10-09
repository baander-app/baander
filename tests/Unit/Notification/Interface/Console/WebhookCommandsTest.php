<?php

declare(strict_types=1);

namespace App\Tests\Unit\Notification\Interface\Console;

use App\Notification\Application\DTO\CreateWebhookCommand;
use App\Notification\Application\DTO\DeleteWebhookCommand;
use App\Notification\Application\DTO\ListWebhooksQuery;
use App\Notification\Application\DTO\RotateWebhookSecretCommand;
use App\Notification\Application\DTO\UpdateWebhookCommand;
use App\Notification\Application\DTO\WebhookView;
use App\Notification\Application\Handler\CreateWebhookHandler;
use App\Notification\Application\Handler\DeleteWebhookHandler;
use App\Notification\Application\Handler\ListWebhooksHandler;
use App\Notification\Application\Handler\RotateWebhookSecretHandler;
use App\Notification\Application\Handler\UpdateWebhookHandler;
use App\Notification\Application\Service\WebhookInput;
use App\Notification\Infrastructure\Webhook\WebhookDestinationPolicy;
use App\Notification\Infrastructure\Webhook\WebhookSecretCodec;
use App\Notification\Interface\Console\WebhookCreateCommand;
use App\Notification\Interface\Console\WebhookDeleteCommand;
use App\Notification\Interface\Console\WebhookListCommand;
use App\Notification\Interface\Console\WebhookRotateSecretCommand;
use App\Notification\Interface\Console\WebhookUpdateCommand;
use App\Notification\Interface\Resource\WebhookResource;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Interface\Console\AdminCommandSupport;
use App\Tests\Unit\Notification\Application\Handler\InMemoryWebhookRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;

final class WebhookCommandsTest extends TestCase
{
    private InMemoryWebhookRepository $webhooks;
    private WebhookSecretCodec $codec;
    private AdminCommandSupport $support;

    protected function setUp(): void
    {
        $this->webhooks = new InMemoryWebhookRepository();
        $this->codec = new WebhookSecretCodec('test-app-secret');
        $input = new WebhookInput(new WebhookDestinationPolicy());
        $this->support = new AdminCommandSupport(new MessageBus([new HandleMessageMiddleware(new HandlersLocator([
            ListWebhooksQuery::class => [new ListWebhooksHandler($this->webhooks)],
            CreateWebhookCommand::class => [new CreateWebhookHandler($this->webhooks, $input, $this->codec)],
            UpdateWebhookCommand::class => [new UpdateWebhookHandler($this->webhooks, $input)],
            DeleteWebhookCommand::class => [new DeleteWebhookHandler($this->webhooks)],
            RotateWebhookSecretCommand::class => [new RotateWebhookSecretHandler($this->webhooks, $this->codec)],
        ]))]));
    }

    public function testListShowsEveryWebhookWithoutItsSecretAndJsonPrintsTheResources(): void
    {
        $webhook = $this->seed(['security', 'media_changes']);
        $list = new WebhookListCommand($this->support);

        $table = new CommandTester($list);
        self::assertSame(Command::SUCCESS, $table->execute([]));
        self::assertMatchesRegularExpression('/' . $webhook->id->toString() . '\s+https:\/\/1\.1\.1\.1\/hook\s+security, media_changes/', $table->getDisplay());
        self::assertStringNotContainsString('original-secret', $table->getDisplay());

        $json = new CommandTester($list);
        self::assertSame(Command::SUCCESS, $json->execute(['--json' => true]));
        self::assertSame(WebhookResource::collection([$webhook]), json_decode($json->getDisplay(), true, 512, JSON_THROW_ON_ERROR));
    }

    public function testListSaysWhenThereAreNoWebhooks(): void
    {
        $tester = new CommandTester(new WebhookListCommand($this->support));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('No webhooks are configured.', $tester->getDisplay());
    }

    public function testCreatePrintsTheSecretOnceAndStoresItEncrypted(): void
    {
        $tester = new CommandTester(new WebhookCreateCommand($this->support));

        self::assertSame(Command::SUCCESS, $tester->execute(['url' => 'https://1.1.1.1/hook', '--category' => ['security']]), $tester->getDisplay());

        [$webhook] = $this->webhooks->findAll();
        self::assertSame(['security'], $webhook->categoryFilter);
        $secret = $this->codec->decrypt((string) $this->webhooks->encryptedSecret($webhook->id));
        self::assertSame(1, substr_count($tester->getDisplay(), $secret));
    }

    public function testCreateJsonPrintsTheApiPayloadWithTheSecret(): void
    {
        $tester = new CommandTester(new WebhookCreateCommand($this->support));

        self::assertSame(Command::SUCCESS, $tester->execute(['url' => 'https://1.1.1.1/hook', '--json' => true]));

        $printed = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        [$webhook] = $this->webhooks->findAll();
        self::assertSame(['id', 'url', 'category_filter', 'secret', 'signing_version', 'created_at', 'updated_at'], array_keys($printed));
        self::assertNull($printed['category_filter']);
        self::assertSame($this->codec->decrypt((string) $this->webhooks->encryptedSecret($webhook->id)), $printed['secret']);
    }

    public function testCreateRejectsADisallowedDestinationAndAnUnknownCategory(): void
    {
        $command = new WebhookCreateCommand($this->support);

        $lan = new CommandTester($command);
        self::assertSame(Command::INVALID, $lan->execute(['url' => 'http://192.168.1.2/hook']));
        self::assertStringContainsString('Webhook destination is not allowed or cannot be resolved.', $lan->getDisplay());
        self::assertSame(Command::INVALID, (new CommandTester($command))->execute(['url' => 'https://1.1.1.1/hook', '--category' => ['unknown']]));
        self::assertSame([], $this->webhooks->findAll());
    }

    public function testUpdateWithAllCategoriesClearsTheFilterAndRefusesItTogetherWithCategories(): void
    {
        $webhook = $this->seed(['security']);
        $command = new WebhookUpdateCommand($this->support);

        $both = new CommandTester($command);
        self::assertSame(Command::INVALID, $both->execute(['id' => $webhook->id->toString(), '--all-categories' => true, '--category' => ['security']]));
        self::assertSame(['security'], $this->webhooks->find($webhook->id)?->categoryFilter);

        $clear = new CommandTester($command);
        self::assertSame(Command::SUCCESS, $clear->execute(['id' => $webhook->id->toString(), '--all-categories' => true, '--json' => true]));
        $printed = json_decode($clear->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertNull($printed['category_filter']);
        self::assertSame('https://1.1.1.1/hook', $printed['url']);
        self::assertNull($this->webhooks->find($webhook->id)?->categoryFilter);
    }

    public function testUpdateChangesTheUrlAndCategories(): void
    {
        $webhook = $this->seed(null);
        $tester = new CommandTester(new WebhookUpdateCommand($this->support));

        self::assertSame(Command::SUCCESS, $tester->execute(['id' => $webhook->id->toString(), '--url' => 'https://8.8.8.8/hook', '--category' => ['security', 'admin_operations']]));

        $stored = $this->webhooks->find($webhook->id);
        self::assertSame('https://8.8.8.8/hook', $stored?->url);
        self::assertSame(['security', 'admin_operations'], $stored->categoryFilter);
    }

    public function testDeleteNeedsForceWithoutATerminalAndAnUnknownWebhookFails(): void
    {
        $webhook = $this->seed(null);
        $command = new WebhookDeleteCommand($this->support);

        self::assertSame(Command::INVALID, (new CommandTester($command))->execute(['id' => $webhook->id->toString()], ['interactive' => false]));
        self::assertNotNull($this->webhooks->find($webhook->id));

        $deleted = new CommandTester($command);
        self::assertSame(Command::SUCCESS, $deleted->execute(['id' => $webhook->id->toString(), '--force' => true, '--json' => true], ['interactive' => false]));
        self::assertSame('', $deleted->getDisplay());
        self::assertNull($this->webhooks->find($webhook->id));

        $unknown = new CommandTester($command);
        self::assertSame(Command::FAILURE, $unknown->execute(['id' => $webhook->id->toString(), '--force' => true], ['interactive' => false]));
        self::assertStringContainsString('Webhook not found.', $unknown->getDisplay());
        self::assertSame(Command::INVALID, (new CommandTester($command))->execute(['id' => 'not-a-uuid', '--force' => true], ['interactive' => false]));
    }

    public function testRotateWithoutForceAndWithoutATerminalKeepsTheOldSecret(): void
    {
        $webhook = $this->seed(null);
        $before = $this->webhooks->encryptedSecret($webhook->id);

        $tester = new CommandTester(new WebhookRotateSecretCommand($this->support));

        self::assertSame(Command::INVALID, $tester->execute(['id' => $webhook->id->toString()], ['interactive' => false]));
        self::assertSame($before, $this->webhooks->encryptedSecret($webhook->id));
    }

    public function testRotateAsksOnATerminalAndReportsTheWebhooksOwnSigningVersion(): void
    {
        $webhook = $this->seed(null, signingVersion: 1);
        $command = new WebhookRotateSecretCommand($this->support);

        $declined = new CommandTester($command);
        $declined->setInputs(['no']);
        self::assertSame(Command::FAILURE, $declined->execute(['id' => $webhook->id->toString()]));
        self::assertSame('original-secret', $this->codec->decrypt((string) $this->webhooks->encryptedSecret($webhook->id)));

        $confirmed = new CommandTester($command);
        $confirmed->setInputs(['yes']);
        self::assertSame(Command::SUCCESS, $confirmed->execute(['id' => $webhook->id->toString()]), $confirmed->getDisplay());
        $secret = $this->codec->decrypt((string) $this->webhooks->encryptedSecret($webhook->id));
        self::assertNotSame('original-secret', $secret);
        self::assertSame(1, substr_count($confirmed->getDisplay(), $secret));
        self::assertMatchesRegularExpression('/signing version 1\b/i', $confirmed->getDisplay());

        $json = new CommandTester($command);
        self::assertSame(Command::SUCCESS, $json->execute(['id' => $webhook->id->toString(), '--force' => true, '--json' => true], ['interactive' => false]));
        $printed = json_decode($json->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['id' => $webhook->id->toString(), 'secret' => $this->codec->decrypt((string) $this->webhooks->encryptedSecret($webhook->id)), 'signing_version' => 1], $printed);
    }

    /** @param list<string>|null $categoryFilter */
    private function seed(?array $categoryFilter, int $signingVersion = 2): WebhookView
    {
        $now = new \DateTimeImmutable('2026-10-01T12:00:00+00:00');
        $webhook = new WebhookView(Uuid::generate(), 'https://1.1.1.1/hook', $categoryFilter, $signingVersion, $now, $now);
        $this->webhooks->seed($webhook, $this->codec->encrypt('original-secret'));

        return $webhook;
    }
}
