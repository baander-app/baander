<?php

declare(strict_types=1);

namespace App\Tests\Unit\Notification\Application\Handler;

use App\Notification\Application\DTO\SendEmailCommand;
use App\Notification\Application\DTO\SendWebhookCommand;
use App\Notification\Application\Handler\SendEmailHandler;
use App\Notification\Application\Handler\SendWebhookHandler;
use App\Notification\Domain\Repository\NotificationPreferenceRepositoryInterface;
use App\Notification\Domain\ValueObject\NotificationCategory;
use App\Notification\Infrastructure\Webhook\HmacSigner;
use App\Notification\Infrastructure\Webhook\WebhookDeliveryService;
use App\Shared\Domain\Model\Uuid;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Twig\Environment;

final class ChannelFailurePropagationTest extends TestCase
{
    public function testEmailTransportFailureReachesMessenger(): void
    {
        $preferences = $this->createStub(NotificationPreferenceRepositoryInterface::class);
        $preferences->method('isEnabled')->willReturn(true);
        $twig = $this->createStub(Environment::class);
        $twig->method('render')->willReturn('<p>Notification</p>');
        $failure = new TransportException('SMTP unavailable');
        $mailer = $this->createStub(MailerInterface::class);
        $mailer->method('send')->willThrowException($failure);

        $handler = new SendEmailHandler($preferences, $mailer, $twig, new NullLogger(), 'baander.app', 'Baander');

        $this->expectExceptionObject($failure);
        $handler($this->emailCommand());
    }

    public function testEmailRenderFailureReachesMessenger(): void
    {
        $preferences = $this->createStub(NotificationPreferenceRepositoryInterface::class);
        $preferences->method('isEnabled')->willReturn(true);
        $failure = new \RuntimeException('Template unavailable');
        $twig = $this->createStub(Environment::class);
        $twig->method('render')->willThrowException($failure);
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects($this->never())->method('send');

        $handler = new SendEmailHandler($preferences, $mailer, $twig, new NullLogger(), 'baander.app', 'Baander');

        $this->expectExceptionObject($failure);
        $handler($this->emailCommand());
    }

    public function testDisabledEmailPreferencesSkipDeliveryWithoutFailure(): void
    {
        $preferences = $this->createStub(NotificationPreferenceRepositoryInterface::class);
        $preferences->method('isEnabled')->willReturn(false);
        $twig = $this->createMock(Environment::class);
        $twig->expects($this->never())->method('render');
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects($this->never())->method('send');

        $handler = new SendEmailHandler($preferences, $mailer, $twig, new NullLogger(), 'baander.app', 'Baander');
        $handler($this->emailCommand());
    }

    public function testWebhookDeliveryFailureReachesMessenger(): void
    {
        $failure = new \RuntimeException('Webhook repository unavailable');
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willThrowException($failure);
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects($this->never())->method('request');
        $delivery = new WebhookDeliveryService(
            $entityManager,
            $httpClient,
            new HmacSigner(),
            new NullLogger(),
            new JsonEncoder(),
        );
        $handler = new SendWebhookHandler($delivery, new NullLogger());

        $this->expectExceptionObject($failure);
        $handler(new SendWebhookCommand(Uuid::generate(), NotificationCategory::Security, 'Title', 'Body', 'notification-1'));
    }

    private function emailCommand(): SendEmailCommand
    {
        return new SendEmailCommand(
            Uuid::generate(),
            'user@baander.app',
            NotificationCategory::Security,
            'Title',
            'Body',
            new \DateTimeImmutable(),
            'notification-1',
        );
    }
}
