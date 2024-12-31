<?php

declare(strict_types=1);

namespace MusicBrainz\Value;

use MusicBrainz\Value;

use function in_array;

/**
 * An event type
 */
class EventType implements Value
{
    /**
     * A concert
     * An individual concert by a single artist or collaboration, often with supporting artists who perform before the
     * main act.
     *
     * @var string
     */
    public const CONCERT = 'Concert';

    /**
     * A convention or an expo
     * A convention, expo or trade fair is an event which is not typically orientated around music performances, but can
     * include them as side activities.
     *
     * @var string
     */
    public const CONVENTION = 'Convention/Expo';

    /**
     * A festival
     * An event where a number of different acts perform across the course of the day. Larger festivals may be spread
     * across multiple days.
     *
     * @var string
     */
    public const FESTIVAL = 'Festival';

    /**
     * A launch event
     * A party, reception or other event held specifically for the launch of a release.
     *
     * @var string
     */
    public const LAUNCH_EVENT = 'Launch event';

    /**
     * A masterclass or clinic
     * A masterclass or clinic is an event where an artist meets with a small to medium-sized audience and instructs
     * them individually and/or takes questions intended to improve the audience members' playing skills.
     *
     * @var string
     */
    public const MASTERCLASS = 'Masterclass/Clinic';

    /**
     * An undefined event type
     *
     * @var string
     */
    public const UNDEFINED = '';

    /**
     * A list of valid event types
     *
     * @var string[]
     */
    private const EVENT_TYPES = [
        self::CONCERT,
        self::CONVENTION,
        self::FESTIVAL,
        self::LAUNCH_EVENT,
        self::MASTERCLASS,
    ];

    /**
     * The event type code
     *
     * @var string
     */
    private $eventTypeCode;

    /**
     * Constructs an event type.
     *
     * @param string $eventTypeCode The event type code
     */
    public function __construct(string $eventTypeCode = self::UNDEFINED)
    {
        $this->eventTypeCode = in_array($eventTypeCode, self::EVENT_TYPES)
            ? $eventTypeCode
            : self::UNDEFINED;
    }

    /**
     * Returns the event type code.
     *
     * @return string
     */
    public function __toString(): string
    {
        return $this->eventTypeCode;
    }
}
