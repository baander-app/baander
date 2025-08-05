<?php

declare(strict_types=1);

namespace App\Modules\Essentia\Algorithms\Tonal;

use App\Modules\Essentia\Algorithms\BaseAlgorithm;
use App\Modules\Essentia\Exceptions\AlgorithmException;
use App\Modules\Essentia\Types\AudioVector;

/**
 * Tristimulus


Inputs:

  [vector_real] frequencies - the frequencies of the harmonic peaks ordered by frequency
  [vector_real] magnitudes - the magnitudes of the harmonic peaks ordered by frequency


Outputs:

  [vector_real] tristimulus - a three-element vector that measures the mixture of harmonics of the given spectrum


Description:

  This algorithm calculates the tristimulus of a signal given its harmonic
  peaks. The tristimulus has been introduced as a timbre equivalent to the
  color attributes in the vision. Tristimulus measures the mixture of harmonics
  in a given sound, grouped into three sections. The first tristimulus measures
  the relative weight of the first harmonic; the second tristimulus measures
  the relative weight of the second, third, and fourth harmonics taken
  together; and the third tristimulus measures the relative weight of all the
  remaining harmonics.
  
  Tristimulus is intended to be fed by the output of the HarmonicPeaks
  algorithm. The algorithm throws an exception when the input frequencies are
  not in ascending order and/or if the input vectors are of different sizes.
  
  References:
    [1] Tristimulus (audio) - Wikipedia, the free encyclopedia
    http://en.wikipedia.org/wiki/Tristimulus_%28audio%29
  
    [2] G. Peeters, "A large set of audio features for sound description
    (similarity and classification) in the CUIDADO project," CUIDADO I.S.T.
    Project Report, 2004
 * 
 * Category: Tonal
 * Mode: standard
 */
class Tristimulus extends BaseAlgorithm
{
    protected string $algorithmName = 'Tristimulus';
    protected string $mode = 'standard';
    protected string $category = 'Tonal';

    public function compute($input): array
    {
        try {
            // Input validation based on algorithm type
            $this->validateAlgorithmInput($input);
            
            // Convert input to appropriate format
            $processedInput = $this->prepareInput($input);
            
            // Execute the algorithm
            $result = $this->executeAlgorithm($processedInput);
            
            return $this->processOutput($result);
            
        } catch (\Exception $e) {
            throw new AlgorithmException(
                "Failed to compute Tristimulus: " . $e->getMessage(),
                0,
                $e
            );
        }
    }

    private function validateAlgorithmInput($input): void
    {
        // Category-specific input validation
        switch ($this->category) {
            case 'Spectral':
            case 'Temporal':
                $this->validateInput($input, 'array');
                break;
            case 'Io':
                if (!is_string($input) && !($input instanceof AudioVector)) {
                    throw new AlgorithmException('IO algorithms expect string path or AudioVector');
                }
                break;
            default:
                // Generic validation
                if (!is_array($input) && !($input instanceof AudioVector) && !is_numeric($input)) {
                    throw new AlgorithmException('Unsupported input type for algorithm');
                }
        }
    }

    private function prepareInput($input)
    {
        if ($input instanceof AudioVector) {
            return $input->toCArray($this->essentia->getFFI());
        }
        
        return $input;
    }

    private function executeAlgorithm($input)
    {
        // This would contain the actual FFI calls to Essentia
        // Implementation depends on the specific algorithm
        
        // Placeholder for algorithm execution
        return [];
    }

    private function processOutput($result): array
    {
        // Process and format the output from Essentia
        return is_array($result) ? $result : [$result];
    }
}