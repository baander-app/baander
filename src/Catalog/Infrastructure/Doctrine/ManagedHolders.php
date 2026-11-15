<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\AssociationMapping;
use Doctrine\ORM\Mapping\JoinColumnMapping;
use Doctrine\ORM\Mapping\ToOneOwningSideMapping;

/**
 * Prepares the unit of work for a statement that deletes an entity's row behind the ORM's back.
 *
 * Every loaded entity the unit of work manages that holds the target in a to-one association is
 * either deleted by the database with the target (its foreign key cascades), or keeps a reference
 * the delete clears or moves. The first kind leaves the unit of work; the second is handed back so
 * that the caller reads it again after the delete. The target leaves the unit of work last, once
 * nothing managed holds it, or the next flush that writes anything would refuse it as new. An
 * entity still to be loaded reads its row as the delete left it.
 */
final class ManagedHolders
{
    /**
     * Releases what the database deletes with the target, then the target.
     *
     * @return list<object> the holders whose reference the delete changes, to read again after it
     */
    public static function release(EntityManagerInterface $manager, object $target): array
    {
        $stale = [];
        foreach (self::of($manager, $target) as [$holder, $deletedWithTarget]) {
            if ($deletedWithTarget) {
                $manager->detach($holder);
            } else {
                $stale[] = $holder;
            }
        }
        $manager->detach($target);

        return $stale;
    }

    /**
     * Every loaded holder of the target, with whether the database deletes it with the target.
     *
     * @return list<array{object, bool}>
     */
    private static function of(EntityManagerInterface $manager, object $target): array
    {
        $unitOfWork = $manager->getUnitOfWork();
        $holders = [];
        foreach ($unitOfWork->getIdentityMap() as $class => $entities) {
            $metadata = $manager->getClassMetadata($class);
            $associations = array_filter(
                $metadata->associationMappings,
                static fn (AssociationMapping $association): bool => $association instanceof ToOneOwningSideMapping && $target instanceof $association->targetEntity,
            );
            if ($associations === []) {
                continue;
            }
            foreach ($entities as $entity) {
                // Reading an association of a lazy entity would load it.
                if ($unitOfWork->isUninitializedObject($entity)) {
                    continue;
                }
                $holding = array_find(
                    $associations,
                    static fn (AssociationMapping $association): bool => $metadata->getFieldValue($entity, $association->fieldName) === $target,
                );
                if ($holding instanceof ToOneOwningSideMapping) {
                    $holders[] = [$entity, self::deletedWithTarget($holding)];
                }
            }
        }

        return $holders;
    }

    private static function deletedWithTarget(ToOneOwningSideMapping $association): bool
    {
        return array_any(
            $association->joinColumns,
            static fn (JoinColumnMapping $column): bool => strtolower($column->onDelete ?? '') === 'cascade',
        );
    }
}
