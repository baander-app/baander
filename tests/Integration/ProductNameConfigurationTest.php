<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Auth\Domain\Model\User;
use App\Auth\Infrastructure\Mail\AfterResponseMailer;
use App\Auth\Infrastructure\Mail\MailerPasswordResetDelivery;
use App\Auth\Infrastructure\Security\Passkey\PasskeyService;
use App\Auth\Infrastructure\Security\Totp\TotpService;
use App\Kernel;
use App\Notification\Application\DTO\SendEmailCommand;
use App\Notification\Application\Handler\SendEmailHandler;
use App\Notification\Domain\Repository\NotificationPreferenceRepositoryInterface;
use App\Notification\Domain\ValueObject\NotificationCategory;
use App\Shared\Domain\Model\Email as EmailAddress;
use App\Shared\Domain\Model\PublicId;
use App\UserPreference\Application\Port\UserSettingsContractInterface;
use OTPHP\TOTP;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Mailer\Event\SentMessageEvent;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mailer\Transport\NullTransport;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;

/** Exercises the production branding configuration with outbound delivery and persistence replaced. */
final class ProductNameConfigurationTest extends TestCase
{
    #[DataProvider('obsoleteNames')]
    public function testBrandingIsFixedRegardlessOfObsoleteEnvironmentVariables(?string $name): void
    {
        $values = [
            'APP_NAME' => $name,
            'MUSICBRAINZ_APP_NAME' => $name,
            'MAIL_FROM_NAME' => $name,
            'MAIL_FROM_ADDRESS' => 'sender@baander.app',
            'APP_DOMAIN' => 'identity.baander.app',
            'APP_URL' => 'https://identity.baander.app',
        ];
        $previous = [];
        $directory = sys_get_temp_dir() . '/baander-product-name-' . bin2hex(random_bytes(8));
        $kernel = new ProductNameConfigurationKernel($directory);

        try {
            foreach ($values as $key => $value) {
                $previous[$key] = [
                    'server' => $_SERVER[$key] ?? null,
                    'serverExists' => array_key_exists($key, $_SERVER),
                    'env' => $_ENV[$key] ?? null,
                    'envExists' => array_key_exists($key, $_ENV),
                    'process' => getenv($key),
                ];
                if ($value === null) {
                    unset($_SERVER[$key], $_ENV[$key]);
                    putenv($key);
                } else {
                    $_SERVER[$key] = $_ENV[$key] = $value;
                    putenv($key . '=' . $value);
                }
            }

            $kernel->boot();
            $container = $kernel->getContainer();
            $settings = $this->createStub(UserSettingsContractInterface::class);
            $settings->method('resolveLanguage')->willReturn('en');
            $preferences = $this->createStub(NotificationPreferenceRepositoryInterface::class);
            $preferences->method('isEnabled')->willReturn(true);
            $messages = [];
            $dispatcher = $container->get('branding.dispatcher');
            self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
            $dispatcher->addListener(SentMessageEvent::class, static function (SentMessageEvent $event) use (&$messages): void {
                $message = $event->getMessage()->getOriginalMessage();
                self::assertInstanceOf(Email::class, $message);
                $messages[] = $message;
            });
            $transport = new NullTransport($dispatcher);
            $mailer = new Mailer($transport);
            $container->set('branding.settings', $settings);
            $container->set('branding.preferences', $preferences);
            $container->set('branding.transport', $transport);
            $container->set('branding.mailer', $mailer);

            $user = User::register(new EmailAddress('alice@baander.app'), 'hashed', 'Alice');
            $delivery = $container->get('branding.password_reset');
            self::assertInstanceOf(MailerPasswordResetDelivery::class, $delivery);
            $delivery->deliver($user, 'credential-token', new \DateTimeImmutable('+30 minutes'));
            self::assertCount(1, $messages);
            self::assertSame('Reset your Bånder password', $messages[0]->getSubject());
            self::assertStringContainsString('Bånder', (string) $messages[0]->getTextBody());
            self::assertStringContainsString('Bånder', (string) $messages[0]->getHtmlBody());

            $handler = $container->get('branding.notification');
            self::assertInstanceOf(SendEmailHandler::class, $handler);
            $handler(new SendEmailCommand(
                userId: $user->getId(),
                userEmail: 'alice@baander.app',
                category: NotificationCategory::Security,
                titleKey: 'user.password_changed.title',
                titleParameters: [],
                bodyKey: 'user.password_changed.body',
                bodyParameters: [],
                createdAt: new \DateTimeImmutable(),
            ));
            self::assertCount(2, $messages);
            self::assertSame('[Bånder] Password changed', $messages[1]->getSubject());
            self::assertStringContainsString('Bånder', (string) $messages[1]->getHtmlBody());
            foreach ($messages as $message) {
                self::assertCount(1, $message->getFrom());
                self::assertSame('Bånder', $message->getFrom()[0]->getName());
                self::assertSame('sender@baander.app', $message->getFrom()[0]->getAddress());
            }

            $passkeys = $container->get('branding.passkeys');
            self::assertInstanceOf(PasskeyService::class, $passkeys);
            $registration = $passkeys->createRegistrationOptions((new PublicId())->toString(), 'alice@baander.app');
            self::assertSame('Bånder', $registration['options']['rp']['name']);
            self::assertSame('identity.baander.app', $registration['options']['rp']['id']);

            $totp = $container->get('branding.totp');
            self::assertInstanceOf(TotpService::class, $totp);
            $secret = 'JBSWY3DPEHPK3PXP';
            $uri = $totp->getProvisioningUri($secret, 'alice@baander.app');
            self::assertSame('/Bånder:alice@baander.app', rawurldecode((string) parse_url($uri, PHP_URL_PATH)));
            parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);
            self::assertSame('Bånder', $query['issuer']);
            self::assertSame($secret, $query['secret']);
            self::assertTrue($totp->verifyCode($secret, TOTP::create($secret)->now()));
            self::assertSame('Bånder', $container->getParameter('app.name'));
        } finally {
            $kernel->shutdown();
            foreach ($previous as $key => $value) {
                if ($value['serverExists']) {
                    $_SERVER[$key] = $value['server'];
                } else {
                    unset($_SERVER[$key]);
                }
                if ($value['envExists']) {
                    $_ENV[$key] = $value['env'];
                } else {
                    unset($_ENV[$key]);
                }
                putenv($value['process'] === false ? $key : $key . '=' . $value['process']);
            }
            (new Filesystem())->remove($directory);
        }
    }

    /** @return iterable<string, array{?string}> */
    public static function obsoleteNames(): iterable
    {
        yield 'absent' => [null];
        yield 'empty' => [''];
        yield 'conflicting' => ['SomethingElse'];
    }
}

final class ProductNameConfigurationKernel extends Kernel
{
    public function __construct(private readonly string $temporaryDirectory)
    {
        parent::__construct('prod', false);
    }

    public function getProjectDir(): string { return dirname(__DIR__, 2); }
    public function getCacheDir(): string { return $this->temporaryDirectory . '/cache'; }
    public function getLogDir(): string { return $this->temporaryDirectory . '/log'; }

    protected function getContainerClass(): string
    {
        return parent::getContainerClass() . '_' . md5($this->temporaryDirectory);
    }

    protected function build(ContainerBuilder $container): void
    {
        parent::build($container);
        foreach ([
            'branding.settings' => UserSettingsContractInterface::class,
            'branding.preferences' => NotificationPreferenceRepositoryInterface::class,
            'branding.transport' => TransportInterface::class,
            'branding.mailer' => MailerInterface::class,
        ] as $id => $class) {
            $container->register($id, $class)->setSynthetic(true)->setPublic(true);
        }
        $container->register('branding.cache', ArrayAdapter::class);
        $container->setAlias('branding.dispatcher', 'event_dispatcher')->setPublic(true);
        foreach ([
            'branding.password_reset' => MailerPasswordResetDelivery::class,
            'branding.notification' => SendEmailHandler::class,
            'branding.passkeys' => PasskeyService::class,
            'branding.totp' => TotpService::class,
        ] as $id => $service) {
            $container->setAlias($id, $service)->setPublic(true);
        }
        $container->addCompilerPass(new class implements CompilerPassInterface {
            public function process(ContainerBuilder $container): void
            {
                $container->getDefinition(AfterResponseMailer::class)
                    ->setArgument('$transport', new Reference('branding.transport'))
                    ->setArgument('$settings', new Reference('branding.settings'));
                $container->getDefinition(SendEmailHandler::class)
                    ->setArgument('$mailer', new Reference('branding.mailer'))
                    ->setArgument('$preferenceRepository', new Reference('branding.preferences'))
                    ->setArgument('$userSettings', new Reference('branding.settings'));
                $container->getDefinition(PasskeyService::class)
                    ->setArgument('$cache', new Reference('branding.cache'));
            }
        });
    }
}
