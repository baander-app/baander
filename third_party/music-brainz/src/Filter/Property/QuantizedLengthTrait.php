<?php

declare(strict_types=1);

namespace MusicBrainz\Filter\Property;

use AskLucy\Expression\Clause\Term;
use MusicBrainz\Value\Length;

trait QuantizedLengthTrait
{
    use AbstractAdderTrait;

    /**
     * Returns the field name for the quantized length (length / 2000).
     *
     * @return string
     */
    public static function quantizedLength(): string
    {
        return 'qdur';
    }

    /**
     * Adds the quantized length (length / 2000).
     *
     * @param Length $quantizedLength The Quantized length (length / 2000)
     *
     * @return Term
     */
    public function addQuantizedLength(Length $quantizedLength): Term
    {
        return $this->addTerm((string) $quantizedLength, self::quantizedLength());
    }
}
