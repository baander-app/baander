<?php

declare(strict_types=1);

namespace App\Tests\Unit\Notification\Infrastructure\Webhook;

use App\Notification\Infrastructure\Doctrine\Entity\WebhookEntity;
use App\Notification\Infrastructure\Webhook\HmacSigner;
use App\Notification\Infrastructure\Webhook\WebhookDestinationPolicy;
use App\Notification\Infrastructure\Webhook\WebhookSecretCodec;
use App\Notification\Interface\Controller\WebhookController;
use App\Shared\Domain\Model\Uuid;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class WebhookConfigurationTest extends TestCase
{
    /** @return iterable<string, array<array<string, mixed>>> */
    public static function invalidConfigurations(): iterable
    {
        yield 'metadata URL' => [['url' => 'http://169.254.169.254/hook']];
        yield 'unlisted LAN' => [['url' => 'http://192.168.1.2/hook']];
        yield 'embedded credentials' => [['url' => 'https://user:pass@1.1.1.1/hook']];
        foreach (['bad', ['unknown'], [42], ['key' => 'security']] as $filter) {
            yield 'category ' . json_encode($filter) => [['url' => 'https://1.1.1.1/hook', 'category_filter' => $filter]];
        }
    }

    /** @param array<string, mixed> $payload */
    #[DataProvider('invalidConfigurations')]
    public function testInvalidConfigurationIsNeverPersisted(array $payload): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('persist');
        $em->expects(self::never())->method('flush');
        $controller = new WebhookController($em, new WebhookDestinationPolicy(), new WebhookSecretCodec('test-app-secret'));
        $response = $controller->create(Request::create('/api/webhooks/', 'POST', content: json_encode($payload, JSON_THROW_ON_ERROR)));
        self::assertSame(422, $response->getStatusCode());
    }

    public function testCreatedSecretIsReturnedOnceAndStoredEncrypted(): void
    {
        $codec = new WebhookSecretCodec('test-app-secret');
        $stored = null;
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('persist')->willReturnCallback(static function (object $entity) use (&$stored): void { $stored = $entity; });
        $em->expects(self::once())->method('flush');
        $controller = new WebhookController($em, new WebhookDestinationPolicy(), $codec);
        $response = $controller->create(Request::create('/api/webhooks/', 'POST', content: '{"url":"https://1.1.1.1/hook","category_filter":["security"]}'));
        self::assertSame(201, $response->getStatusCode());
        $data = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertInstanceOf(WebhookEntity::class, $stored);
        self::assertSame(2, $stored->getSigningVersion());
        self::assertSame($data['secret'], $codec->decrypt($stored->getEncryptedSecret()));
    }

    public function testRotationReplacesTheOriginalSecret(): void
    {
        $webhook = new WebhookEntity(Uuid::generate(), (new WebhookSecretCodec('test-app-secret'))->encrypt('original-secret'));
        $repository = $this->createStub(EntityRepository::class);
        $repository->method('find')->willReturn($webhook);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('getRepository')->willReturn($repository);
        $em->expects(self::once())->method('flush');
        $codec = new WebhookSecretCodec('test-app-secret');
        $controller = new WebhookController($em, new WebhookDestinationPolicy(), $codec);
        $response = $controller->rotateSecret($webhook->getId()->toString());
        $data = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR)['data'];
        self::assertSame(2, $data['signing_version']);
        self::assertSame($data['secret'], $codec->decrypt($webhook->getEncryptedSecret()));
        self::assertNotSame('original-secret', $data['secret']);
    }
}
