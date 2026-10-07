<?php

declare(strict_types=1);

namespace App\Tests\Functional\Notification;

use App\Auth\Domain\Event\Passkey\PasskeyRegistered;
use App\Auth\Domain\Event\PasswordChanged;
use App\Auth\Domain\Model\User;
use App\Library\Application\Query\LibraryMembershipQueryPort;
use App\Library\Domain\Event\LibraryScanCompleted;
use App\Notification\Application\DTO\CreateNotificationCommand;
use App\Notification\Application\DTO\SendEmailCommand;
use App\Notification\Application\Handler\CreateNotificationHandler;
use App\Notification\Application\Handler\SendEmailHandler;
use App\Shared\Application\Port\SystemSettingStoreInterface;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Infrastructure\Messaging\JsonMessageCodec;
use App\Tests\Functional\TestCase;
use App\UserPreference\Application\Port\UserSettingsContractInterface;
use Doctrine\DBAL\Connection;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Translation\TranslatorBagInterface;

/**
 * Notification emails are queued for the delivery relay with translation keys
 * and rendered in each recipient's language when the worker sends them. The test mailer DSN is
 * null://, so Symfony's message logger records each email.
 */
final class NotificationEmailLanguageTest extends TestCase
{
    public function testADanishRecipientGetsNoEnglishTextInSubjectBodyHeaderOrFooter(): void
    {
        $alice = $this->verifiedUser('alice@baander.app');
        $this->userSettings()->set($alice->getId()->toString(), 'language', 'da');

        $this->createNotification(PasskeyRegistered::class, 'user.passkey_registered', $this->passkeyPayload($alice, 'YubiKey'));
        $this->sendQueuedEmails();

        $email = $this->onlyEmailTo('alice@baander.app');
        $this->assertSame(sprintf('[%s] Adgangsnøgle registreret', $this->appName()), $email->getSubject());

        $html = $this->htmlText($email);
        $this->assertStringContainsString('<html lang="da">', $html);
        $this->assertStringContainsString('Sikkerhedsadvarsel fra ' . $this->appName(), $html);
        $this->assertStringContainsString('Adgangsnøgle registreret', $html);
        $this->assertStringContainsString('En ny adgangsnøgle "YubiKey" blev registreret på din konto.', $html);
        $this->assertStringContainsString('Se i ' . $this->appName(), $html);
        $this->assertStringContainsString('Du modtager denne e-mail, fordi du har slået sikkerhedsnotifikationer til i ' . $this->appName() . '.', $html);
        $this->assertNoEnglishText($html, [
            'user.passkey_registered.title',
            'user.passkey_registered.body',
            'email.header.security',
            'email.view',
            'email.footer.security',
        ]);
    }

    public function testAServerDefaultChangedWhileTheEmailIsQueuedDecidesItsLanguage(): void
    {
        $bob = $this->verifiedUser('bob@baander.app');
        $this->systemSettings()->save(['i18n.default_language' => 'en']);

        $this->createNotification(PasswordChanged::class, 'user.password_changed', [
            'user_id' => $bob->getId()->toString(),
            'email' => 'bob@baander.app',
            'occurred_at' => '2026-10-07T12:00:00+00:00',
        ]);
        $queued = $this->queuedEmails();
        $this->systemSettings()->save(['i18n.default_language' => 'da']);
        $this->send($queued);

        $email = $this->onlyEmailTo('bob@baander.app');
        $this->assertSame(sprintf('[%s] Adgangskode ændret', $this->appName()), $email->getSubject());
        $this->assertStringContainsString('Din kontoadgangskode blev ændret.', $this->htmlText($email));
    }

    public function testAFallbackParameterIsTranslatedInTheEmailAndStaysEnglishInApp(): void
    {
        $alice = $this->verifiedUser('alice@baander.app');
        $this->userSettings()->set($alice->getId()->toString(), 'language', 'da');

        $this->createNotification(PasskeyRegistered::class, 'user.passkey_registered', $this->passkeyPayload($alice, null));
        $this->sendQueuedEmails();

        $html = $this->htmlText($this->onlyEmailTo('alice@baander.app'));
        $this->assertStringContainsString('En ny adgangsnøgle "Ukendt" blev registreret på din konto.', $html);
        $this->assertStringNotContainsString('Unknown', $html);

        $stored = $this->storedNotifications($alice);
        $this->assertCount(1, $stored);
        $this->assertSame('Passkey registered', $stored[0]['title']);
        $this->assertSame('A new passkey "Unknown" was registered on your account.', $stored[0]['body']);
        // jsonb keeps its own key order.
        $this->assertEquals(['title' => [], 'body' => ['name' => 'Unknown']], json_decode($stored[0]['parameters'], true, flags: JSON_THROW_ON_ERROR));
    }

    public function testRecipientsOfOneNotificationEachGetTheirOwnLanguage(): void
    {
        $alice = $this->verifiedUser('alice@baander.app');
        $carol = $this->verifiedUser('carol@baander.app');
        $this->userSettings()->set($alice->getId()->toString(), 'language', 'da');
        $this->userSettings()->set($carol->getId()->toString(), 'language', 'th');
        $members = $this->createStub(LibraryMembershipQueryPort::class);
        $members->method('findUserIdsForLibrary')->willReturn([$alice->getId()->toString(), $carol->getId()->toString()]);
        self::getContainer()->set(LibraryMembershipQueryPort::class, $members);

        $this->createNotification(LibraryScanCompleted::class, 'library.scan_completed', [
            'library_id' => Uuid::generate()->toString(),
            'files_discovered' => 150,
            'files_processed' => 120,
            'occurred_at' => '2026-10-07T12:00:00+00:00',
        ]);
        $this->sendQueuedEmails();

        $danish = $this->onlyEmailTo('alice@baander.app');
        $this->assertSame(sprintf('[%s] Biblioteksscannering fuldført', $this->appName()), $danish->getSubject());
        $this->assertStringContainsString('Scannering fuldført: 150 filer fundet, 120 behandlet.', $this->htmlText($danish));
        $this->assertStringContainsString('notifikationer om baggrundsjob', $this->htmlText($danish));

        $thai = $this->onlyEmailTo('carol@baander.app');
        $this->assertSame(sprintf('[%s] สแกนไลบรารีเสร็จสิ้น', $this->appName()), $thai->getSubject());
        $this->assertStringContainsString('<html lang="th">', $this->htmlText($thai));
        $this->assertStringContainsString('สแกนเสร็จสิ้น: พบไฟล์ 150 ไฟล์, ประมวลผลแล้ว 120 ไฟล์', $this->htmlText($thai));

        foreach ([$alice, $carol] as $user) {
            $stored = $this->storedNotifications($user);
            $this->assertCount(1, $stored);
            $this->assertSame('Library scan completed', $stored[0]['title']);
            $this->assertSame('Scan completed: 150 files discovered, 120 processed.', $stored[0]['body']);
        }
    }

    private function verifiedUser(string $email): User
    {
        $user = $this->createTestUser($email);
        $user->verifyEmail();
        $this->userRepository->save($user);

        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    private function passkeyPayload(User $user, ?string $name): array
    {
        return [
            'user_id' => $user->getId()->toString(),
            'passkey_id' => Uuid::generate()->toString(),
            'credential_id' => 'credential-' . bin2hex(random_bytes(4)),
            'name' => $name,
            'occurred_at' => '2026-10-07T12:00:00+00:00',
        ];
    }

    /**
     * @param class-string $eventClass
     * @param array<string, mixed> $payload
     */
    private function createNotification(string $eventClass, string $eventName, array $payload): void
    {
        $handler = self::getContainer()->get(CreateNotificationHandler::class);
        self::assertInstanceOf(CreateNotificationHandler::class, $handler);

        $handler(new CreateNotificationCommand(eventClass: $eventClass, payload: $payload, eventName: $eventName));
    }

    /**
     * The email deliveries the notification queued, as stored for the relay.
     *
     * @return list<string>
     */
    private function queuedEmails(): array
    {
        /** @var list<string> $wire */
        $wire = $this->connection()->fetchFirstColumn(
            "SELECT payload FROM domain_event_outbox_delivery WHERE channel = 'email' AND relayed_at IS NULL ORDER BY id",
        );
        $this->assertNotSame([], $wire, 'The notification queued at least one email.');

        return $wire;
    }

    /**
     * @param list<string> $wire
     */
    private function send(array $wire): void
    {
        $handler = self::getContainer()->get(SendEmailHandler::class);
        self::assertInstanceOf(SendEmailHandler::class, $handler);

        foreach ($wire as $encoded) {
            $command = $this->codec()->decode($encoded)->message;
            self::assertInstanceOf(SendEmailCommand::class, $command);
            $handler($command);
        }
    }

    private function sendQueuedEmails(): void
    {
        $this->send($this->queuedEmails());
    }

    private function onlyEmailTo(string $address): Email
    {
        $emails = [];
        foreach ($this->getMailerEvents() as $event) {
            self::assertInstanceOf(MessageEvent::class, $event);
            $message = $event->getMessage();
            if ($event->isQueued() || !$message instanceof Email) {
                continue;
            }
            if (in_array($address, array_map(static fn (Address $a): string => $a->getAddress(), $message->getTo()), true)) {
                $emails[] = $message;
            }
        }
        $this->assertCount(1, $emails, sprintf('Exactly one email was sent to %s.', $address));

        return $emails[0];
    }

    private function htmlText(Email $email): string
    {
        return html_entity_decode((string) $email->getHtmlBody(), ENT_QUOTES | ENT_HTML5);
    }

    /**
     * Fails when a word-bearing fragment of any of the English messages appears.
     *
     * @param list<string> $keys
     */
    private function assertNoEnglishText(string $html, array $keys): void
    {
        $translator = self::getContainer()->get('translator');
        self::assertInstanceOf(TranslatorBagInterface::class, $translator);
        $english = $translator->getCatalogue('en');

        foreach ($keys as $key) {
            $this->assertTrue($english->has($key, 'notification'), sprintf('English has %s.', $key));
            $fragments = preg_split('/\{[^}]*\}/', $english->get($key, 'notification')) ?: [];
            foreach ($fragments as $fragment) {
                $fragment = trim($fragment, " \t\n\".!:,");
                if (preg_match('/[A-Za-z]{3,}/', $fragment) === 1) {
                    $this->assertStringNotContainsString($fragment, $html, sprintf('English text of %s is left in the email.', $key));
                }
            }
        }
    }

    /**
     * @return list<array{title: string, body: string, parameters: string}>
     */
    private function storedNotifications(User $user): array
    {
        /** @var list<array{title: string, body: string, parameters: string}> */
        return $this->connection()->fetchAllAssociative(
            'SELECT title, body, parameters FROM notifications WHERE user_id = ?',
            [$user->getId()->toString()],
        );
    }

    private function connection(): Connection
    {
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }

    private function codec(): JsonMessageCodec
    {
        $codec = self::getContainer()->get(JsonMessageCodec::class);
        self::assertInstanceOf(JsonMessageCodec::class, $codec);

        return $codec;
    }

    private function userSettings(): UserSettingsContractInterface
    {
        $contract = self::getContainer()->get('test.user_settings_contract');
        self::assertInstanceOf(UserSettingsContractInterface::class, $contract);

        return $contract;
    }

    private function systemSettings(): SystemSettingStoreInterface
    {
        $store = self::getContainer()->get(SystemSettingStoreInterface::class);
        self::assertInstanceOf(SystemSettingStoreInterface::class, $store);

        return $store;
    }

    private function appName(): string
    {
        $name = self::getContainer()->getParameter('app.name');
        self::assertIsString($name);

        return $name;
    }
}
