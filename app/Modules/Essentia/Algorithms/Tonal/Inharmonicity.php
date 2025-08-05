<?php

declare(strict_types=1);

namespace App\Modules\Essentia\Algorithms\Tonal;

use App\Modules\Essentia\Algorithms\BaseAlgorithm;
use App\Modules\Essentia\Exceptions\AlgorithmException;
use App\Modules\Essentia\Types\AudioVector;

/**
 * Inharmonicity


Inputs:

  [vector_real] frequencies - the frequencies of the harmonic peaks [Hz] (in ascending order)
  [vector_real] magnitudes - the magnitudes of the harmonic peaks (in frequency ascending order


Outputs:

  [real] inharmonicity - the inharmonicity of the audio signal


Description:

  This algorithm calculates the inharmonicity of a signal given its spectral
  peaks. The inharmonicity value is computed as an energy weighted divergence
  of the spectral components from their closest multiple of the fundamental
  frequency. The fundamental frequency is taken as the first spectral peak from
  the input. The inharmonicity value ranges from 0 (purely harmonic signal) to
  1 (inharmonic signal).
  
  Inharmonicity was designed to be fed by the output from the HarmonicPeaks
  algorithm. Note that DC components should be removed from the signal before
  obtaining its peaks. An exception is thrown if a peak is given at 0Hz.
  
  An exception is thrown if frequency vector is not sorted in ascendently, if
  it contains duplicates or if any input vector is empty.
  
  References:
    [1] G. Peeters, "A large set of audio features for sound description
    (similarity and classification) in the CUIDADO project," CUIDADO I.S.T.
    Project Report, 2004.
  
    [2] Inharmonicity - Wikipedia, the free encyclopedia,
    http://en.wikipedia.org/wiki/Inharmonicity
 * 
 * Category: Tonal
 * Mode: standard
 */
class Inharmonicity extends BaseAlgorithm
{
    protected string $algorithmName = 'Inharmonicity';
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
                "Failed to compute Inharmonicity: " . $e->getMessage(),
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