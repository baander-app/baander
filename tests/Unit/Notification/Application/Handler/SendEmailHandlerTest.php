<?php

declare(strict_types=1);

namespace App\Tests\Unit\Notification\Application\Handler;

use App\Notification\Application\DTO\SendEmailCommand;
use App\Notification\Application\DTO\TranslatableParameter;
use App\Notification\Application\Handler\SendEmailHandler;
use App\Notification\Domain\Repository\NotificationPreferenceRepositoryInterface;
use App\Notification\Domain\ValueObject\NotificationCategory;
use App\Shared\Domain\Model\Uuid;
use App\UserPreference\Application\Port\UserSettingsContractInterface;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Translation\Loader\ArrayLoader;
use Symfony\Component\Translation\Translator;
use Twig\Environment;

final class SendEmailHandlerTest extends TestCase
{
    private NotificationPreferenceRepositoryInterface&Stub $preferenceRepository;
    private UserSettingsContractInterface&Stub $userSettings;
    private MailerInterface&Stub $mailer;
    private Environment&Stub $twig;
    private LoggerInterface&Stub $logger;

    protected function setUp(): void
    {
        $this->preferenceRepository = $this->createStub(NotificationPreferenceRepositoryInterface::class);
        $this->userSettings = $this->createStub(UserSettingsContractInterface::class);
        $this->userSettings->method('resolveLanguage')->willReturn('en');
        $this->mailer = $this->createStub(MailerInterface::class);
        $this->twig = $this->createStub(Environment::class);
        $this->logger = $this->createStub(LoggerInterface::class);
    }

    private function createHandler(): SendEmailHandler
    {
        return new SendEmailHandler(
            $this->preferenceRepository,
            $this->userSettings,
            $this->mailer,
            $this->twig,
            self::translator(),
            $this->logger,
            'baander.app',
            'TestApp',
        );
    }

    /**
     * @param array<string, mixed> $bodyParameters
     */
    private function createCommand(
        NotificationCategory $category = NotificationCategory::Security,
        string $titleKey = 'user.passkey_registered.title',
        string $bodyKey = 'user.passkey_registered.body',
        array $bodyParameters = ['name' => 'YubiKey'],
        ?Uuid $userId = null,
    ): SendEmailCommand {
        return new SendEmailCommand(
            userId: $userId ?? Uuid::generate(),
            userEmail: 'user@baander.app',
            category: $category,
            titleKey: $titleKey,
            titleParameters: [],
            bodyKey: $bodyKey,
            bodyParameters: $bodyParameters,
            createdAt: new \DateTimeImmutable(),
            notificationPublicId: 'abc123',
        );
    }

    public function testEmailSentWhenPreferenceEnabled(): void
    {
        $this->mailer = $this->createMock(MailerInterface::class);

        $this->preferenceRepository->method('isEnabled')->willReturn(true);
        $this->twig->method('render')->willReturn('<html>test</html>');

        $this->mailer->expects($this->once())->method('send')
            ->with($this->callback(function (Email $email) {
                return $email->getTo()[0]->getAddress() === 'user@baander.app'
                    && $email->getSubject() === '[TestApp] Passkey registered';
            }));

        $this->createHandler()($this->createCommand());
    }

    public function testEmailNotSentWhenPreferenceDisabled(): void
    {
        $this->mailer = $this->createMock(MailerInterface::class);
        $this->userSettings = $this->createMock(UserSettingsContractInterface::class);

        $this->preferenceRepository->method('isEnabled')->willReturn(false);

        $this->userSettings->expects($this->never())->method('resolveLanguage');
        $this->mailer->expects($this->never())->method('send');

        $this->createHandler()($this->createCommand());
    }

    public function testSubjectTitleBodyAndHeaderAreRenderedInTheRecipientsLanguage(): void
    {
        $this->twig = $this->createMock(Environment::class);
        $mailer = $this->createMock(MailerInterface::class);
        $this->mailer = $mailer;
        $this->userSettings = $this->createMock(UserSettingsContractInterface::class);

        $userId = Uuid::generate();
        $this->preferenceRepository->method('isEnabled')->willReturn(true);
        $this->userSettings->expects($this->once())->method('resolveLanguage')
            ->with($userId->toString())
            ->willReturn('da');

        $this->twig->expects($this->once())->method('render')
            ->with('email/notification/base.html.twig', $this->callback(function (array $context): bool {
                $this->assertSame('da', $context['locale']);
                $this->assertSame('Adgangsnøgle registreret', $context['title']);
                $this->assertSame('En ny adgangsnøgle "YubiKey" blev registreret på din konto.', $context['body']);
                $this->assertSame('Sikkerhedsadvarsel fra TestApp', $context['headerTitle']);
                $this->assertSame('security', $context['category']);

                return true;
            }))->willReturn('<html>da</html>');
        $mailer->expects($this->once())->method('send')
            ->with($this->callback(fn (Email $email): bool => $email->getSubject() === '[TestApp] Adgangsnøgle registreret'));

        $this->createHandler()($this->createCommand(userId: $userId));
    }

    public function testTranslatableParameterIsTranslatedInTheRecipientsLanguage(): void
    {
        $this->twig = $this->createMock(Environment::class);
        $this->userSettings = $this->createStub(UserSettingsContractInterface::class);
        $this->userSettings->method('resolveLanguage')->willReturn('da');
        $this->preferenceRepository->method('isEnabled')->willReturn(true);

        $this->twig->expects($this->once())->method('render')
            ->with('email/notification/base.html.twig', $this->callback(function (array $context): bool {
                $this->assertSame('En ny adgangsnøgle "Ukendt" blev registreret på din konto.', $context['body']);

                return true;
            }))->willReturn('<html>da</html>');

        $this->createHandler()($this->createCommand(bodyParameters: ['name' => new TranslatableParameter('parameter.unknown_passkey_name')]));
    }

    public function testNonSecurityCategoryUsesThePlainHeader(): void
    {
        $this->twig = $this->createMock(Environment::class);
        $this->preferenceRepository->method('isEnabled')->willReturn(true);

        $command = $this->createCommand(
            category: NotificationCategory::BackgroundJobs,
            titleKey: 'library.scan_completed.title',
            bodyKey: 'library.scan_completed.body',
            bodyParameters: ['filesDiscovered' => 150, 'filesProcessed' => 120],
        );

        $this->twig->expects($this->once())->method('render')
            ->with(
                'email/notification/base.html.twig',
                $this->callback(function (array $context) {
                    return $context['category'] === 'background_jobs'
                        && $context['title'] === 'Library scan completed'
                        && $context['body'] === 'Scan completed: 150 files discovered, 120 processed.'
                        && $context['appName'] === 'TestApp'
                        && $context['appDomain'] === 'baander.app'
                        && $context['headerColor'] === '#16213e'
                        && $context['headerTitle'] === 'TestApp'
                        && $context['locale'] === 'en';
                }),
            )->willReturn('<html>bg</html>');

        $this->createHandler()($command);
    }

    public function testTwigRenderFailureIsLogged(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);

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

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Template not found');
        $this->createHandler()($this->createCommand());
    }

    public function testMailerFailureIsLogged(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);

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

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('SMTP connection failed');
        $this->createHandler()($this->createCommand());
    }

    private static function translator(): Translator
    {
        $translator = new Translator('en');
        $translator->addLoader('array', new ArrayLoader());
        $translator->addResource('array', [
            'user.passkey_registered.title' => 'Passkey registered',
            'user.passkey_registered.body' => 'A new passkey "{name}" was registered on your account.',
            'library.scan_completed.title' => 'Library scan completed',
            'library.scan_completed.body' => 'Scan completed: {filesDiscovered} files discovered, {filesProcessed} processed.',
            'parameter.unknown_passkey_name' => 'Unknown',
            'email.header.security' => '{appName} Security Alert',
            'email.header.default' => '{appName}',
        ], 'en', 'notification+intl-icu');
        $translator->addResource('array', [
            'user.passkey_registered.title' => 'Adgangsnøgle registreret',
            'user.passkey_registered.body' => 'En ny adgangsnøgle "{name}" blev registreret på din konto.',
            'parameter.unknown_passkey_name' => 'Ukendt',
            'email.header.security' => 'Sikkerhedsadvarsel fra {appName}',
            'email.header.default' => '{appName}',
        ], 'da', 'notification+intl-icu');

        return $translator;
    }
}
