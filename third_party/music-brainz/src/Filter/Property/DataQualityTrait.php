<?php

declare(strict_types=1);

namespace MusicBrainz\Filter\Property;

use AskLucy\Expression\Clause\Term;
use MusicBrainz\Value\DataQuality;

trait DataQualityTrait
{
    use AbstractAdderTrait;

    /**
     * Returns the field name for the data quality.
     *
     * @return string
     */
    public static function dataQuality(): string
    {
        return 'quality';
    }

    /**
     * Adds the data quality.
     *
     * @param DataQuality $dataQuality The data quality
     *
     * @return Term
     */
    public function addDataQuality(DataQuality $dataQuality): Term
    {
        return $this->addTerm((string) $dataQuality, self::dataQuality());
    }
}
