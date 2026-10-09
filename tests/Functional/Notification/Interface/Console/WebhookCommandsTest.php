<?php

declare(strict_types=1);

namespace App\Tests\Functional\Notification\Interface\Console;

use App\Notification\Application\Port\WebhookSecretPortInterface;
use App\Notification\Infrastructure\Doctrine\Entity\WebhookEntity;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Functional\Notification\WebhookDnsStub;
use App\Tests\Functional\TestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The app:webhook:* commands and WebhookController reach the same use cases, leave the same
 * stored state and report the same outcomes.
 */
final class WebhookCommandsTest extends TestCase
{
    use WebhookDnsStub;

    protected function setUp(): void
    {
        parent::setUp();

        $this->stubWebhookDns();
    }

    public function testCreatingThroughTheCliStoresWhatTheApiStoresAndBothPrintTheSecretOnce(): void
    {
        $admin = $this->createAdminUser();
        $viaApi = $this->assertJsonResponse($this->authenticatedRequest('POST', '/api/webhooks/', $admin, [
            'url' => 'https://old.baander.app/hook',
            'category_filter' => ['security', 'media_changes'],
        ]), 201, 'data')['data'];

        $create = $this->command('app:webhook:create');
        self::assertSame(Command::SUCCESS, $create->execute([
            'url' => 'https://new.baander.app/hook',
            '--category' => ['security', 'media_changes'],
            '--json' => true,
        ]), $create->getDisplay());
        $viaCli = json_decode($create->getDisplay(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(array_keys($viaApi), array_keys($viaCli));
        self::assertSame($viaApi['category_filter'], $viaCli['category_filter']);
        self::assertSame($viaApi['signing_version'], $viaCli['signing_version']);
        foreach ([$viaApi, $viaCli] as $printed) {
            $stored = $this->storedWebhook($printed['id']);
            self::assertSame($printed['url'], $stored->getUrl());
            self::assertSame(['security', 'media_changes'], $stored->getCategoryFilter());
            self::assertSame($printed['secret'], $this->secrets()->decrypt($stored->getEncryptedSecret()));
        }

        $listedViaApi = $this->assertJsonResponse($this->authenticatedRequest('GET', '/api/webhooks/', $admin), 200, 'data')['data'];
        $list = $this->command('app:webhook:list');
        self::assertSame(Command::SUCCESS, $list->execute(['--json' => true]));
        self::assertSame($listedViaApi, json_decode($list->getDisplay(), true, 512, JSON_THROW_ON_ERROR));
        $table = $this->command('app:webhook:list');
        self::assertSame(Command::SUCCESS, $table->execute([]));
        foreach ([$viaApi, $viaCli] as $printed) {
            self::assertStringNotContainsString($printed['secret'], json_encode($listedViaApi, JSON_THROW_ON_ERROR));
            self::assertStringNotContainsString($printed['secret'], $table->getDisplay());
            self::assertStringContainsString($printed['id'], $table->getDisplay());
        }
    }

    public function testAUrlThatResolvesToAPrivateAddressIsInvalidInputOnBothPaths(): void
    {
        $admin = $this->createAdminUser();

        $error = $this->assertJsonResponse($this->authenticatedRequest('POST', '/api/webhooks/', $admin, ['url' => 'https://private.baander.app/hook']), 422);
        $create = $this->command('app:webhook:create');
        self::assertSame(Command::INVALID, $create->execute(['url' => 'https://private.baander.app/hook']));
        self::assertStringContainsString($error['error']['message'], $create->getDisplay());

        self::assertSame([], $this->entityManager->getRepository(WebhookEntity::class)->findAll());
    }

    public function testRotatingWithoutForceAndWithoutATerminalKeepsTheOldSecretAndWithForceReportsTheStoredSigningVersion(): void
    {
        $admin = $this->createAdminUser();
        $created = $this->assertJsonResponse($this->authenticatedRequest('POST', '/api/webhooks/', $admin, ['url' => 'https://baander.app/hook']), 201, 'data')['data'];
        $original = $this->storedWebhook($created['id'])->getEncryptedSecret();

        $refused = $this->command('app:webhook:rotate-secret');
        self::assertSame(Command::INVALID, $refused->execute(['id' => $created['id']], ['interactive' => false]));
        self::assertSame($original, $this->storedWebhook($created['id'])->getEncryptedSecret());

        $rotate = $this->command('app:webhook:rotate-secret');
        self::assertSame(Command::SUCCESS, $rotate->execute(['id' => $created['id'], '--force' => true, '--json' => true], ['interactive' => false]), $rotate->getDisplay());
        $viaCli = json_decode($rotate->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        $stored = $this->storedWebhook($created['id']);
        self::assertSame(['id' => $created['id'], 'secret' => $this->secrets()->decrypt($stored->getEncryptedSecret()), 'signing_version' => $stored->getSigningVersion()], $viaCli);

        $viaApi = $this->assertJsonResponse($this->authenticatedRequest('POST', '/api/webhooks/' . $created['id'] . '/rotate-secret', $admin), 200, 'data')['data'];
        self::assertSame(array_keys($viaCli), array_keys($viaApi));
        self::assertSame($viaCli['signing_version'], $viaApi['signing_version']);
        self::assertSame($viaApi['secret'], $this->secrets()->decrypt($this->storedWebhook($created['id'])->getEncryptedSecret()));
    }

    public function testDeletingAnUnknownWebhookAnswers404AndExits1AndAMalformedIdIsInvalidInput(): void
    {
        $admin = $this->createAdminUser();
        $unknown = Uuid::generate()->toString();

        $this->assertJsonResponse($this->authenticatedRequest('DELETE', '/api/webhooks/' . $unknown, $admin), 404);
        $delete = $this->command('app:webhook:delete');
        self::assertSame(Command::FAILURE, $delete->execute(['id' => $unknown, '--force' => true], ['interactive' => false]));
        self::assertStringContainsString('Webhook not found.', $delete->getDisplay());

        $this->assertJsonResponse($this->authenticatedRequest('DELETE', '/api/webhooks/not-a-uuid', $admin), 422);
        self::assertSame(Command::INVALID, $this->command('app:webhook:delete')->execute(['id' => 'not-a-uuid', '--force' => true], ['interactive' => false]));

        $created = $this->assertJsonResponse($this->authenticatedRequest('POST', '/api/webhooks/', $admin, ['url' => 'https://baander.app/hook']), 201, 'data')['data'];
        $deleted = $this->command('app:webhook:delete');
        self::assertSame(Command::SUCCESS, $deleted->execute(['id' => $created['id'], '--force' => true, '--json' => true], ['interactive' => false]));
        self::assertSame('', $deleted->getDisplay());
        $this->entityManager->clear();
        self::assertNull($this->entityManager->find(WebhookEntity::class, Uuid::fromString($created['id'])));
    }

    public function testClearingTheCategoryFilterStoresNullOnBothPaths(): void
    {
        $admin = $this->createAdminUser();
        $viaApi = $this->createFiltered($admin);
        $viaCli = $this->createFiltered($admin);

        $updatedViaApi = $this->assertJsonResponse($this->authenticatedRequest('PUT', '/api/webhooks/' . $viaApi, $admin, ['category_filter' => null]), 200, 'data')['data'];
        $update = $this->command('app:webhook:update');
        self::assertSame(Command::SUCCESS, $update->execute(['id' => $viaCli, '--all-categories' => true, '--json' => true]), $update->getDisplay());
        $updatedViaCli = json_decode($update->getDisplay(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(array_keys($updatedViaApi), array_keys($updatedViaCli));
        self::assertNull($updatedViaApi['category_filter']);
        self::assertNull($updatedViaCli['category_filter']);
        self::assertNull($this->storedWebhook($viaApi)->getCategoryFilter());
        self::assertNull($this->storedWebhook($viaCli)->getCategoryFilter());
        self::assertSame('https://baander.app/hook', $this->storedWebhook($viaCli)->getUrl());
    }

    private function createFiltered(\App\Auth\Domain\Model\User $admin): string
    {
        return $this->assertJsonResponse($this->authenticatedRequest('POST', '/api/webhooks/', $admin, [
            'url' => 'https://baander.app/hook',
            'category_filter' => ['security'],
        ]), 201, 'data')['data']['id'];
    }

    private function storedWebhook(string $id): WebhookEntity
    {
        $this->entityManager->clear();
        $webhook = $this->entityManager->find(WebhookEntity::class, Uuid::fromString($id));
        self::assertInstanceOf(WebhookEntity::class, $webhook);

        return $webhook;
    }

    private function secrets(): WebhookSecretPortInterface
    {
        $secrets = static::getContainer()->get(WebhookSecretPortInterface::class);
        self::assertInstanceOf(WebhookSecretPortInterface::class, $secrets);

        return $secrets;
    }

    private function command(string $name): CommandTester
    {
        return new CommandTester((new Application($this->client->getKernel()))->find($name));
    }
}
