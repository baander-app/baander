<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Doctrine\EventListener;

use App\Shared\Domain\Model\PublicId;
use App\Shared\Infrastructure\Doctrine\EventListener\GeneratePublicIdListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PrePersistEventArgs;
use PHPUnit\Framework\TestCase;
use stdClass;

final class GeneratePublicIdListenerTest extends TestCase
{
    private GeneratePublicIdListener $listener;

    protected function setUp(): void
    {
        $this->listener = new GeneratePublicIdListener();
    }

    public function testDoesNothingWhenEntityHasNoPublicIdProperty(): void
    {
        $entity = new stdClass();
        $args = $this->createEventArgs($entity);

        $this->listener->prePersist($args);

        $this->assertFalse(property_exists($entity, 'publicId'));
    }

    public function testDoesNotOverwriteAlreadySetPublicId(): void
    {
        $entity = new class {
            private ?PublicId $publicId = null;

            public function __construct()
            {
                $this->publicId = PublicId::fromString('0123456789abcdefghijk');
            }

            public function getPublicId(): ?PublicId
            {
                return $this->publicId;
            }
        };

        $args = $this->createEventArgs($entity);

        $this->listener->prePersist($args);

        $this->assertSame('0123456789abcdefghijk', $entity->getPublicId()?->toString());
    }

    public function testSetsPublicIdOnUninitializedReadonlyProperty(): void
    {
        $entity = new EntityWithReadonlyPublicId();

        $args = $this->createEventArgs($entity);

        $this->listener->prePersist($args);

        $this->assertInstanceOf(PublicId::class, $entity->getPublicId());
    }

    public function testSetsPublicIdOnUninitializedNonReadonlyProperty(): void
    {
        $entity = new EntityWithMutablePublicId();

        $args = $this->createEventArgs($entity);

        $this->listener->prePersist($args);

        $this->assertInstanceOf(PublicId::class, $entity->getPublicId());
    }

    private function createEventArgs(object $entity): PrePersistEventArgs
    {
        $em = $this->createMock(EntityManagerInterface::class);

        return new PrePersistEventArgs($entity, $em);
    }
}

class EntityWithReadonlyPublicId
{
    private ?PublicId $publicId = null;

    public function getPublicId(): ?PublicId
    {
        return $this->publicId;
    }
}

class EntityWithMutablePublicId
{
    private ?PublicId $publicId = null;

    public function getPublicId(): ?PublicId
    {
        return $this->publicId;
    }
}
