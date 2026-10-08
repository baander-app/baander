<?php

declare(strict_types=1);

namespace App\Notification\Application\Handler;

use App\Notification\Application\DTO\SendEmailCommand;
use App\Notification\Application\DTO\TranslatableParameter;
use App\Notification\Domain\Repository\NotificationPreferenceRepositoryInterface;
use App\Notification\Domain\ValueObject\NotificationChannel;
use App\UserPreference\Application\Port\UserSettingsContractInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * Writes the email in the recipient's language as it stands at send time,
 * passing that locale explicitly instead of changing the shared translator's.
 */
final class SendEmailHandler
{
    private const string DOMAIN = 'notification';

    public function __construct(
        private readonly NotificationPreferenceRepositoryInterface $preferenceRepository,
        private readonly UserSettingsContractInterface $userSettings,
        private readonly MailerInterface $mailer,
        private readonly Environment $twig,
        private readonly TranslatorInterface $translator,
        private readonly LoggerInterface $logger,
        private readonly string $appDomain,
        private readonly string $appName,
    ) {
    }

    #[AsMessageHandler(fromTransport: 'swoole_task')]
    #[AsMessageHandler(fromTransport: 'async')]
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
            $locale = $this->userSettings->resolveLanguage($command->userId->toString());
            $title = $this->translate($command->titleKey, $command->titleParameters, $locale);

            $htmlBody = $this->twig->render('email/notification/base.html.twig', [
                'locale' => $locale,
                'title' => $title,
                'body' => $this->translate($command->bodyKey, $command->bodyParameters, $locale),
                'category' => $command->category->value,
                'createdAt' => $command->createdAt->format(\DateTimeInterface::ATOM),
                'appName' => $this->appName,
                'appDomain' => $this->appDomain,
                'notificationPublicId' => $command->notificationPublicId,
                'headerColor' => $command->category->headerColor(),
                'headerTitle' => $this->translate($command->category->headerTitle(), ['appName' => $this->appName], $locale),
            ]);

            $email = (new Email())
                ->to(new Address($command->userEmail))
                ->subject(sprintf('[%s] %s', $this->appName, $title))
                ->html($htmlBody);

            $this->mailer->send($email);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to send notification email.', [
                'channel' => 'notification.email',
                'userId' => $command->userId->toString(),
                'category' => $command->category->value,
                'exception' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function translate(string $key, array $parameters, string $locale): string
    {
        return $this->translator->trans(
            $key,
            TranslatableParameter::resolveAll($parameters, $this->translator, $locale),
            self::DOMAIN,
            $locale,
        );
    }
}
