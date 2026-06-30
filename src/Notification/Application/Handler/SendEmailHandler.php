<?php

declare(strict_types=1);

namespace App\Notification\Application\Handler;

use App\Notification\Application\DTO\SendEmailCommand;
use App\Notification\Domain\Repository\NotificationPreferenceRepositoryInterface;
use App\Notification\Domain\ValueObject\NotificationChannel;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Twig\Environment;

final class SendEmailHandler
{
    public function __construct(
        private readonly NotificationPreferenceRepositoryInterface $preferenceRepository,
        private readonly MailerInterface $mailer,
        private readonly Environment $twig,
        private readonly LoggerInterface $logger,
        private readonly string $appDomain,
        private readonly string $appName,
    ) {
    }

    #[AsMessageHandler(fromTransport: 'swoole_task')]
    public function __invoke(SendEmailCommand $command): void
    {
        if (!$this->preferenceRepository->isEnabled(
            $command->userId,
            $command->category,
            NotificationChannel::Email,
        )) {
            return;
        }

        try {
            $htmlBody = $this->twig->render('email/notification/base.html.twig', [
                'title' => $command->title,
                'body' => $command->body,
                'category' => $command->category->value,
                'createdAt' => $command->createdAt->format(\DateTimeInterface::ATOM),
                'appName' => $this->appName,
                'appDomain' => $this->appDomain,
                'notificationPublicId' => $command->notificationPublicId,
                'headerColor' => $command->category->headerColor(),
                'headerTitle' => $command->category->headerTitle($this->appName),
            ]);

            $email = (new Email())
                ->to(new Address($command->userEmail))
                ->subject(sprintf('[%s] %s', $this->appName, $command->title))
                ->html($htmlBody);

            $this->mailer->send($email);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to send notification email.', [
                'channel' => 'notification.email',
                'userId' => $command->userId->toString(),
                'category' => $command->category->value,
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
