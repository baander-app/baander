<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Target;

use MusicBrainz\Relation;
use MusicBrainz\Value\EntityType;
use MusicBrainz\Value\URL;

/**
 * An URL relation
 */
class RelationToUrl extends Relation
{
    /**
     * The related URL
     *
     * @var Url
     */
    private $url;

    /**
     * Sets the related URL.
     *
     * @param array $url Information about the related URL
     *
     * @return void
     */
    protected function setRelatedEntity(array $url): void
    {
        $this->url = new URL($url);
    }

    /**
     * Returns the entity type of the related entity.
     *
     * @return EntityType
     */
    public static function getRelatedEntityType(): EntityType
    {
        return new EntityType(EntityType::URL);
    }

    /**
     * Returns the related URL.
     *
     * @return URL
     */
    public function getUrl(): URL
    {
        return $this->url;
    }
}
