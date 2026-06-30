<?php

declare(strict_types=1);

namespace App\Tests\Unit\Notification\Application\Handler;

use App\Notification\Application\DTO\SendEmailCommand;
use App\Notification\Application\Handler\SendEmailHandler;
use App\Notification\Domain\Repository\NotificationPreferenceRepositoryInterface;
use App\Notification\Domain\ValueObject\NotificationCategory;
use App\Notification\Domain\ValueObject\NotificationChannel;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Twig\Environment;

final class SendEmailHandlerTest extends TestCase
{
    private NotificationPreferenceRepositoryInterface&MockObject $preferenceRepository;
    private MailerInterface&MockObject $mailer;
    private Environment&MockObject $twig;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        $this->preferenceRepository = $this->createMock(NotificationPreferenceRepositoryInterface::class);
        $this->mailer = $this->createMock(MailerInterface::class);
        $this->twig = $this->createMock(Environment::class);
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    private function createHandler(): SendEmailHandler
    {
        return new SendEmailHandler(
            $this->preferenceRepository,
            $this->mailer,
            $this->twig,
            $this->logger,
            'example.com',
            'TestApp',
        );
    }

    private function createCommand(
        NotificationCategory $category = NotificationCategory::Security,
        string $title = 'Password Changed',
        string $body = 'Your password was updated.',
    ): SendEmailCommand {
        return new SendEmailCommand(
            userId: Uuid::generate(),
            userEmail: 'user@example.com',
            category: $category,
            title: $title,
            body: $body,
            createdAt: new \DateTimeImmutable(),
            notificationPublicId: 'abc123',
        );
    }

    public function testEmailSentWhenPreferenceEnabled(): void
    {
        $command = $this->createCommand();

        $this->preferenceRepository->method('isEnabled')
            ->willReturn(true);

        $this->twig->method('render')->willReturn('<html>test</html>');

        $this->mailer->expects($this->once())->method('send')
            ->with($this->callback(function (Email $email) {
                $to = $email->getTo();
                return $to[0]->getAddress() === 'user@example.com'
                    && str_contains($email->getSubject(), 'Password Changed')
                    && str_contains($email->getSubject(), 'TestApp');
            }));

        $this->createHandler()($command);
    }

    public function testEmailNotSentWhenPreferenceDisabled(): void
    {
        $command = $this->createCommand();

        $this->preferenceRepository->method('isEnabled')
            ->willReturn(false);

        $this->mailer->expects($this->never())->method('send');

        $this->createHandler()($command);
    }

    public function testEmailRendersCorrectTemplatePerCategory(): void
    {
        $command = $this->createCommand(
            category: NotificationCategory::BackgroundJobs,
            title: 'Library Scan Completed',
            body: '150 files discovered.',
        );

        $this->preferenceRepository->method('isEnabled')->willReturn(true);

        $this->twig->expects($this->once())->method('render')
            ->with(
                'email/notification/base.html.twig',
                $this->callback(function (array $context) {
                    return $context['category'] === 'background_jobs'
                        && $context['title'] === 'Library Scan Completed'
                        && $context['appName'] === 'TestApp'
                        && $context['appDomain'] === 'example.com'
                        && $context['headerColor'] === '#16213e'
                        && $context['headerTitle'] === 'TestApp';
                }),
            )->willReturn('<html>bg</html>');

        $this->createHandler()($command);
    }

    public function testTwigRenderFailureIsLogged(): void
    {
        $command = $this->createCommand();

        $this->preferenceRepository->method('isEnabled')->willReturn(true);
        $this->twig->method('render')->willThrowException(new \RuntimeException('Template not found'));

        $this->logger->expects($this->once())->method('error')
            ->with(
                'Failed to send notification email.',
                $this->callback(function (array $context) {
                    return $context['channel'] === 'notification.email'
                        && isset($context['exception']);
                }),
            );

        $this->createHandler()($command);
    }

    public function testMailerFailureIsLogged(): void
    {
        $command = $this->createCommand();

        $this->preferenceRepository->method('isEnabled')->willReturn(true);
        $this->twig->method('render')->willReturn('<html>test</html>');
        $this->mailer->method('send')->willThrowException(new \RuntimeException('SMTP connection failed'));

        $this->logger->expects($this->once())->method('error')
            ->with(
                'Failed to send notification email.',
                $this->callback(function (array $context) {
                    return $context['channel'] === 'notification.email'
                        && str_contains($context['exception'], 'SMTP');
                }),
            );

        $this->createHandler()($command);
    }
}
