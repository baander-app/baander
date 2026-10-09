<?php

declare(strict_types=1);

namespace App\Tests\Unit\Notification\Application\Handler;

use App\Notification\Application\DTO\CreateWebhookCommand;
use App\Notification\Application\DTO\DeleteWebhookCommand;
use App\Notification\Application\DTO\IssuedWebhookSecret;
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
use App\Shared\Application\Exception\InvalidInputException;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WebhookHandlersTest extends TestCase
{
    private InMemoryWebhookRepository $webhooks;
    private WebhookSecretCodec $codec;
    private WebhookInput $input;

    protected function setUp(): void
    {
        $this->webhooks = new InMemoryWebhookRepository();
        $this->codec = new WebhookSecretCodec('test-app-secret');
        $this->input = new WebhookInput(new WebhookDestinationPolicy());
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidConfigurations(): iterable
    {
        yield 'missing URL' => [['url' => null]];
        yield 'blank URL' => [['url' => ' ']];
        yield 'not a URL' => [['url' => 'not-a-url']];
        yield 'metadata URL' => [['url' => 'http://169.254.169.254/hook']];
        yield 'unlisted LAN' => [['url' => 'http://192.168.1.2/hook']];
        yield 'embedded credentials' => [['url' => 'https://user:pass@1.1.1.1/hook']];
        foreach (['bad', ['unknown'], [42], ['key' => 'security']] as $filter) {
            yield 'category ' . json_encode($filter) => [['url' => 'https://1.1.1.1/hook', 'category_filter' => $filter]];
        }
    }

    /** @param array<string, mixed> $payload */
    #[DataProvider('invalidConfigurations')]
    public function testAnInvalidConfigurationIsInvalidInputAndIsNeverStored(array $payload): void
    {
        try {
            $this->create(new CreateWebhookCommand($payload['url'], $payload['category_filter'] ?? null));
            self::fail('The configuration must be rejected.');
        } catch (InvalidInputException) {
        }

        self::assertSame(0, $this->webhooks->writes);
    }

    public function testCreateStoresTheSecretEncryptedAndReturnsItInPlainOnce(): void
    {
        $issued = $this->create(new CreateWebhookCommand('https://1.1.1.1/hook', ['security']));

        self::assertSame('https://1.1.1.1/hook', $issued->webhook->url);
        self::assertSame(['security'], $issued->webhook->categoryFilter);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $issued->secret);
        $stored = $this->webhooks->encryptedSecret($issued->webhook->id);
        self::assertNotNull($stored);
        self::assertNotSame($issued->secret, $stored);
        self::assertSame($issued->secret, $this->codec->decrypt($stored));
        self::assertSame([$issued->webhook], (new ListWebhooksHandler($this->webhooks))(new ListWebhooksQuery()));
    }

    public function testRotationReportsTheWebhooksOwnSigningVersionAndReplacesTheSecret(): void
    {
        $webhook = $this->seed(signingVersion: 1);

        $rotated = (new RotateWebhookSecretHandler($this->webhooks, $this->codec))(new RotateWebhookSecretCommand($webhook->id->toString()));

        self::assertSame(1, $rotated->webhook->signingVersion);
        self::assertSame($webhook->id->toString(), $rotated->webhook->id->toString());
        self::assertNotSame('original-secret', $rotated->secret);
        self::assertSame($rotated->secret, $this->codec->decrypt((string) $this->webhooks->encryptedSecret($webhook->id)));
    }

    public function testUpdateChangesOnlyTheGivenFieldsAndANullFilterClearsIt(): void
    {
        $webhook = $this->seed();
        $update = new UpdateWebhookHandler($this->webhooks, $this->input);

        $renamed = $update(new UpdateWebhookCommand($webhook->id->toString(), changesUrl: true, url: 'https://8.8.8.8/hook'));
        self::assertSame('https://8.8.8.8/hook', $renamed->url);
        self::assertSame(['security'], $renamed->categoryFilter);

        $cleared = $update(new UpdateWebhookCommand($webhook->id->toString(), changesCategoryFilter: true, categoryFilter: null));
        self::assertSame('https://8.8.8.8/hook', $cleared->url);
        self::assertNull($cleared->categoryFilter);
    }

    public function testAnInvalidUpdateChangesNothing(): void
    {
        $webhook = $this->seed();
        $update = new UpdateWebhookHandler($this->webhooks, $this->input);

        foreach ([
            new UpdateWebhookCommand($webhook->id->toString(), changesUrl: true, url: 'http://192.168.1.2/hook', changesCategoryFilter: true, categoryFilter: null),
            new UpdateWebhookCommand($webhook->id->toString(), changesUrl: true, url: '', changesCategoryFilter: true, categoryFilter: null),
            new UpdateWebhookCommand($webhook->id->toString(), changesUrl: true, url: 'https://8.8.8.8/hook', changesCategoryFilter: true, categoryFilter: ['key' => 'security']),
        ] as $command) {
            try {
                $update($command);
                self::fail('The update must be rejected.');
            } catch (InvalidInputException) {
            }
        }

        self::assertSame(0, $this->webhooks->writes);
        self::assertEquals($webhook, $this->webhooks->find($webhook->id));
    }

    public function testAnUnknownWebhookIsNotFoundAndAMalformedIdIsInvalidInput(): void
    {
        $unknown = Uuid::generate()->toString();
        $actions = [
            function (string $id): void {
                (new UpdateWebhookHandler($this->webhooks, $this->input))(new UpdateWebhookCommand($id, changesUrl: true, url: 'https://1.1.1.1/hook'));
            },
            function (string $id): void {
                (new DeleteWebhookHandler($this->webhooks))(new DeleteWebhookCommand($id));
            },
            function (string $id): void {
                (new RotateWebhookSecretHandler($this->webhooks, $this->codec))(new RotateWebhookSecretCommand($id));
            },
        ];

        foreach ($actions as $action) {
            foreach ([$unknown => NotFoundException::class, 'not-a-uuid' => InvalidInputException::class] as $id => $outcome) {
                try {
                    $action($id);
                    self::fail(sprintf('"%s" must be rejected.', $id));
                } catch (NotFoundException|InvalidInputException $exception) {
                    self::assertInstanceOf($outcome, $exception);
                }
            }
        }

        self::assertSame(0, $this->webhooks->writes);
    }

    public function testDeleteRemovesTheWebhook(): void
    {
        $webhook = $this->seed();

        (new DeleteWebhookHandler($this->webhooks))(new DeleteWebhookCommand($webhook->id->toString()));

        self::assertNull($this->webhooks->find($webhook->id));
    }

    private function create(CreateWebhookCommand $command): IssuedWebhookSecret
    {
        return (new CreateWebhookHandler($this->webhooks, $this->input, $this->codec))($command);
    }

    private function seed(int $signingVersion = 2): WebhookView
    {
        $now = new \DateTimeImmutable('2026-10-01T12:00:00+00:00');
        $webhook = new WebhookView(Uuid::generate(), 'https://1.1.1.1/hook', ['security'], $signingVersion, $now, $now);
        $this->webhooks->seed($webhook, $this->codec->encrypt('original-secret'));

        return $webhook;
    }
}
