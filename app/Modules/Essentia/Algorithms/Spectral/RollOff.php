<?php

declare(strict_types=1);

namespace App\Modules\Essentia\Algorithms\Spectral;

use App\Modules\Essentia\Algorithms\BaseAlgorithm;
use App\Modules\Essentia\Exceptions\AlgorithmException;
use App\Modules\Essentia\Types\AudioVector;

/**
 * RollOff


Inputs:

  [vector_real] spectrum - the input audio spectrum (must have more than one elements)


Outputs:

  [real] rollOff - the roll-off frequency [Hz]


Parameters:

  cutoff:
    real ∈ (0,1) (default = 0.850000023842)
    the ratio of total energy to attain before yielding the roll-off frequency

  sampleRate:
    real ∈ (0,inf) (default = 44100)
    the sampling rate of the audio signal (used to normalize rollOff) [Hz]


Description:

  This algorithm computes the roll-off frequency of a spectrum. The roll-off
  frequency is defined as the frequency under which some percentage (cutoff) of
  the total energy of the spectrum is contained. The roll-off frequency can be
  used to distinguish between harmonic (below roll-off) and noisy sounds (above
  roll-off).
  
  An exception is thrown if the input audio spectrum is smaller than 2.
  References:
    [1] G. Peeters, "A large set of audio features for sound description
    (similarity and classification) in the CUIDADO project," CUIDADO I.S.T.
    Project Report, 2004
 * 
 * Category: Spectral
 * Mode: standard
 */
class RollOff extends BaseAlgorithm
{
    protected string $algorithmName = 'RollOff';
    protected string $mode = 'standard';
    protected string $category = 'Spectral';

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
                "Failed to compute RollOff: " . $e->getMessage(),
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