<?php

declare(strict_types=1);

namespace MusicBrainz\Value;

/**
 * A list of CD stubs
 */
class CdStubListList extends ValueList
{
    /**
     * Constructs a list of CD stubs.
     *
     * @param array $cdStubs Information about the CD stubs
     */
    public function __construct(array $cdStubs = [])
    {
        parent::__construct(
            array_map(
                function ($cdStub) {
                    return new CdStub($cdStub);
                },
                $cdStubs
            )
        );
    }

    /**
     * Returns the class name of the list elements.
     *
     * @return string
     */
    public static function getType(): string
    {
        return CdStubListList::class;
    }
}
