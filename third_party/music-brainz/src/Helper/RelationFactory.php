<?php

declare(strict_types=1);

namespace MusicBrainz\Helper;

use MusicBrainz\Definition\RelationTarget;
use MusicBrainz\Definition\RelationTypeId;
use MusicBrainz\MusicBrainz;
use MusicBrainz\Relation;
use MusicBrainz\Relation\NullType;
use MusicBrainz\Relation\Type;
use MusicBrainz\Value\Direction;
use MusicBrainz\Value\MBID;
use MusicBrainz\Value\Property\AreaTrait;

use function array_key_exists;
use function is_null;

/**
 * The relation factory
 */
class RelationFactory
{
    use AreaTrait;

    /**
     * @param array $relations
     * @return array
     */
    public static function makeRelations(array $relations): array
    {
        foreach ($relations as $relation) {
            $relation = RelationFactory::make($relation);
            if (is_null($relation)) {
                continue;
            }
            $relationList[(string) $relation::getRelatedEntityType()][] = $relation;
        }

        return $relationList ?? [];
    }

    /**
     * Makes and returns a relation by given information from the webservice.
     *
     * @param array $relation Information about the relation
     *
     * @return null|Relation
     */
    private static function make(array $relation = []): ?Relation
    {
        $relationTypeId = ArrayAccess::getString($relation, 'type-id');

        $relationType = self::getRelationType(new MBID($relationTypeId));

        if ($relationType instanceof NullType) {
            MusicBrainz::log()->alert('Unknown relation type given. See: https://musicbrainz.org/relationship/' . $relationTypeId);

            return null;
        }

        $direction = new Direction(ArrayAccess::getString($relation, 'direction') ?? '');

        $relatedEntityType = (Direction::FORWARD == $direction)
            ? $relationType::getRelatedEntityType()
            : $relationType::getBaseEntityType();

        $class = RelationTarget::getClassMap()[(string) $relatedEntityType];

        if (empty($relation[(string) $relatedEntityType])) {
            MusicBrainz::log()->alert('Cannot resolve relation. See: https://musicbrainz.org/relationship/' . $relationTypeId);

            return null;
        }

        return new $class(
            $relation[(string) $relatedEntityType],
            $relationType,
            $direction
        );
    }

    /**
     * Returns a relation type by its MusicBrainz Identifier (MBID).
     *
     * @param MBID $relationTypeId The MusicBrainz Identifier (MBID) of the relation type.
     *
     * @return Type
     */
    private static function getRelationType(MBID $relationTypeId): Type
    {
        $classMap = RelationTypeId::getClassMap();

        if (array_key_exists((string) $relationTypeId, $classMap)) {
            $relationType = RelationTypeId::getClassMap()[(string) $relationTypeId];

            return new $relationType();
        }

        return new NullType();
    }
}
