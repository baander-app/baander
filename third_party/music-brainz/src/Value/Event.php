<?php

declare(strict_types=1);

namespace MusicBrainz\Value;

use MusicBrainz\Relation\RelationList\Property\EventRelationListTrait;
use MusicBrainz\Value;

/**
 * An event
 * An event refers to an organised event which people can attend, and is relevant to MusicBrainz. Generally this means
 * live performances, like concerts and festivals.
 *
 * @link https://musicbrainz.org/doc/Event
 */
class Event implements Value
{
    use EventRelationListTrait;
    use Property\CancelledTrait;
    use Property\DisambiguationTrait;
    use Property\EventNameTrait;
    use Property\EventTypeTrait;
    use Property\LifeSpanTrait;
    use Property\MBIDTrait;
    use Property\TimeTrait;

    /**
     * Constructs an event.
     *
     * @param array $event Information about an event
     */
    public function __construct(array $event = [])
    {
        $this->setCancelledFromArray($event);
        $this->setDisambiguationFromArray($event);
        $this->setEventNameFromArray($event);
        $this->setEventTypeFromArray($event);
        $this->setLifeSpanFromArray($event);
        $this->setMbidFromArray($event);
        $this->setRelationsFromArray($event);
        $this->setTimeFromArray($event);
    }

    /**
     * Returns the event name.
     *
     * @return string
     */
    public function __toString(): string
    {
        return (string) $this->getEventName();
    }
}
