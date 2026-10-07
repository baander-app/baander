<?php

declare(strict_types=1);

namespace App\Tests\Unit\Notification\Infrastructure\Messaging;

use App\Notification\Application\DTO\SendEmailCommand;
use App\Notification\Application\DTO\TranslatableParameter;
use App\Notification\Domain\ValueObject\NotificationCategory;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Fixtures\Messaging\MessageCodecFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NotificationMessagePayloadCodecTest extends TestCase
{
    private const string USER_ID = '00000000-0000-4000-8000-000000000001';

    public function testEmailCarriesTranslationKeysAndParametersOnTheWire(): void
    {
        $command = $this->command(['name' => new TranslatableParameter('parameter.unknown_passkey_name'), 'count' => 3]);

        $wire = json_decode(MessageCodecFactory::create()->encode($command), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('notification.send_email', $wire['type']);
        $this->assertSame([
            'user_id' => self::USER_ID,
            'user_email' => 'user@baander.app',
            'category' => 'security',
            'title_key' => 'user.passkey_registered.title',
            'title_parameters' => [],
            'body_key' => 'user.passkey_registered.body',
            'body_parameters' => ['name' => ['translation_key' => 'parameter.unknown_passkey_name'], 'count' => 3],
            'created_at' => '2026-10-07T12:00:00.000+00:00',
            'notification_id' => 'notification-1',
        ], $wire['payload']);
    }

    public function testEmailRoundTripsWithTranslatableParameters(): void
    {
        $codec = MessageCodecFactory::create();
        $command = $this->command(['name' => new TranslatableParameter('parameter.unknown_passkey_name'), 'ratio' => 1.5, 'label' => 'YubiKey']);

        $decoded = $codec->decode($codec->encode($command))->message;

        $this->assertEquals($command, $decoded);
    }

    public function testEmailInTheTranslatedTextShapeIsRejected(): void
    {
        $old = json_encode([
            'format' => 'baander.message',
            'version' => 1,
            'type' => 'notification.send_email',
            'payload' => [
                'user_id' => self::USER_ID,
                'user_email' => 'user@baander.app',
                'category' => 'security',
                'title' => 'Passkey registered',
                'body' => 'A new passkey "YubiKey" was registered on your account.',
                'created_at' => '2026-10-07T12:00:00.000+00:00',
                'notification_id' => 'notification-1',
            ],
            'metadata' => (object) [],
        ], JSON_THROW_ON_ERROR);

        $this->expectException(\InvalidArgumentException::class);
        MessageCodecFactory::create()->decode($old);
    }

    /** @return iterable<string, array{mixed}> */
    public static function malformedParameters(): iterable
    {
        yield 'list instead of object' => [['YubiKey']];
        yield 'nested object' => [['name' => ['first' => 'Yubi']]];
        yield 'translation key with extra field' => [['name' => ['translation_key' => 'parameter.unknown_passkey_name', 'locale' => 'da']]];
        yield 'non-string translation key' => [['name' => ['translation_key' => 7]]];
        yield 'boolean' => [['name' => true]];
        yield 'null' => [['name' => null]];
        yield 'string' => ['name'];
    }

    #[DataProvider('malformedParameters')]
    public function testMalformedParametersAreRejected(mixed $parameters): void
    {
        $codec = MessageCodecFactory::create();
        $wire = json_decode($codec->encode($this->command([])), true, flags: JSON_THROW_ON_ERROR);
        $wire['payload']['body_parameters'] = $parameters;

        $this->expectException(\InvalidArgumentException::class);
        $codec->decode(json_encode($wire, JSON_THROW_ON_ERROR));
    }

    /**
     * @param array<string, mixed> $bodyParameters
     */
    private function command(array $bodyParameters): SendEmailCommand
    {
        return new SendEmailCommand(
            userId: Uuid::fromString(self::USER_ID),
            userEmail: 'user@baander.app',
            category: NotificationCategory::Security,
            titleKey: 'user.passkey_registered.title',
            titleParameters: [],
            bodyKey: 'user.passkey_registered.body',
            bodyParameters: $bodyParameters,
            createdAt: new \DateTimeImmutable('2026-10-07T12:00:00.000+00:00'),
            notificationPublicId: 'notification-1',
        );
    }
}
