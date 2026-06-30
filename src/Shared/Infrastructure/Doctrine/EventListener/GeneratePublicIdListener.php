<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Doctrine\EventListener;

use App\Shared\Domain\Model\PublicId;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Mapping as ORM;
use ReflectionClass;
use ReflectionProperty;

#[ORM\HasLifecycleCallbacks]
final readonly class GeneratePublicIdListener
{
    private const PROPERTY = 'publicId';

    public function prePersist(PrePersistEventArgs $args): void
    {
        $entity = $args->getObject();

        $reflection = new ReflectionClass($entity);

        if (!$reflection->hasProperty(self::PROPERTY)) {
            return;
        }

        $property = $reflection->getProperty(self::PROPERTY);

        if ($property->isInitialized($entity) && $property->getValue($entity) !== null) {
            return;
        }

        if ($property->isReadOnly()) {
            $this->setReadOnlyProperty($entity, $property, new PublicId());

            return;
        }

        $property->setAccessible(true);
        $property->setValue($entity, new PublicId());
    }

    private function setReadOnlyProperty(object $entity, ReflectionProperty $property, PublicId $id): void
    {
        $property->setAccessible(true);
        $property->setValue($entity, $id);
    }
}
