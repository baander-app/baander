<?php

declare(strict_types=1);

namespace MusicBrainz\Value\Property;

use MusicBrainz\Helper\ArrayAccess;
use MusicBrainz\Value\Area;

use function is_null;

/**
 * Provides a getter for the area.
 */
trait AreaTrait
{
    /**
     * An area
     * For artists: The area, as the name suggests, indicates the area with which an artist is primarily identified with.
     * It is often, but not always, its birth/formation country.
     *
     * @var Area
     */
    private Area $area;

    /**
     * Returns the area.
     *
     * @return Area
     */
    public function getArea(): Area
    {
        return $this->area;
    }

    /**
     * Sets the area by extracting it from a given input array.
     *
     * @param array       $input An array returned by the webservice
     * @param null|string $key   Optional array key (default: "area")
     *                           Use first array dimension for null
     *
     * @return void
     */
    private function setAreaFromArray(array $input, ?string $key = 'area'): void
    {
        if (is_null($key)) {
            $this->area = new Area($input);

            return;
        }

        $this->area = is_null($area = ArrayAccess::getArray($input, $key))
            ? new Area()
            : new Area($area);
    }
}
