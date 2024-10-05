<?php

declare(strict_types=1);

namespace MusicBrainz\Value;

use function array_key_exists;

/**
 * A list of values
 *
 * @link http://php.net/manual/en/class.iterator.php
 */
abstract class Page extends ValueList
{
    use Property\OffsetTrait;
    use Property\CountTrait;
    use Property\CreationTimeTrait;

    /**
     * Constructs a list of values.
     *
     * @param array  $array   Array of values
     * @param int    $offset  The offset
     * @param int    $count   The total number of elements
     * @param string $created Creation time
     */
    final public function __construct(array $array = [], int $offset = 0, int $count = 0, string $created = '')
    {
        $array = array_map(
            function ($collection) {
                $className = static::getType();
                return new $className($collection);
            },
            $array
        );

        parent::__construct($array);

        $this->setOffset($offset);
        $this->setCount($count);
        $this->setCreationTime($created);
    }

    /**
     * Abstract factory method
     *
     * @param array  $result         Result from the API
     * @param string $collectionType Type of the list elements
     *
     * @return static
     */
    public static function make(array $result, string $collectionType): self
    {
        return new static(...self::getConstructorArguments($result, $collectionType));
    }

    /**
     * Extracts the constructor arguments from the API result.
     *
     * @param array  $result         The API result
     * @param string $collectionType The type of the list elements
     *
     * @return array
     */
    private static function getConstructorArguments(array $result, string $collectionType): array
    {
        return [
            $result[(substr($collectionType, -1) === 's') ? $collectionType : $collectionType . 's'], // Pluralize
            array_key_exists('offset', $result) ? $result['offset'] : $result[$collectionType . '-offset'], // Browse responses have type prefix, search result don't
            array_key_exists('count', $result) ? $result['count'] : $result[$collectionType . '-count'], // Browse responses have type prefix, search result don't
            array_key_exists('created', $result) ? $result['created'] : '',
        ];
    }
}
