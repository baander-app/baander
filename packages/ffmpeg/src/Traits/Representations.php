<?php


namespace Baander\Ffmpeg\Traits;

use Baander\Ffmpeg\AutoReps;
use Baander\Ffmpeg\Exception\InvalidArgumentException;
use Baander\Ffmpeg\RepresentationInterface;
use Baander\Ffmpeg\RepsCollection;

trait Representations
{
    /** @var RepsCollection */
    protected $reps;

    /**
     * add representations using an array
     * @param array $reps
     * @return $this
     */
    public function addRepresentations(array $reps)
    {
        array_walk($reps, [$this, 'addRepresentation']);
        return $this;
    }

    /**
     * @param array|null $sides
     * @param array|null $k_bitrate
     * @param bool $acceding_order
     * @return $this
     */
    public function autoGenerateRepresentations(array $sides = null, array $k_bitrate = null, bool $acceding_order = true)
    {
        if (!$this->format) {
            throw new InvalidArgumentException('First you must set the format of the video');
        }

        $reps = new AutoReps($this->getMedia(), $this->getFormat(), $sides, $k_bitrate);
        $reps->sort($acceding_order);

        foreach ($reps as $rep) {
            $this->addRepresentation($rep);
        }

        return $this;
    }

    /**
     * add a representation
     * @param RepresentationInterface $rep
     * @return $this
     */
    public function addRepresentation(RepresentationInterface $rep)
    {
        $this->reps->add($rep);
        return $this;
    }

    /**
     * @param array|null $sides
     * @param array|null $k_bitrate
     * @param bool $acceding_order
     * @return $this
     */
    public function addOriginalRepresentation(array $sides = null, array $k_bitrate = null, bool $acceding_order = true)
    {
        if (!$this->format) {
            throw new InvalidArgumentException('First you must set the format of the video');
        }
        $reps = new AutoReps($this->getMedia(), $this->getFormat(), $sides, $k_bitrate);
        $reps->sort($acceding_order);
        $this->addRepresentation($reps->getOriginalRep());


        return $this;
    }

    /**
     * @return RepsCollection
     */
    public function getRepresentations(): RepsCollection
    {
        return $this->reps;
    }
}