<?php

declare(strict_types=1);

namespace App\Modules\Essentia\Algorithms\Sfx;

use App\Modules\Essentia\Algorithms\BaseAlgorithm;
use App\Modules\Essentia\Exceptions\AlgorithmException;
use App\Modules\Essentia\Types\AudioVector;

/**
 * TCToTotal


Inputs:

  [vector_real] envelope - the envelope of the signal (its length must be greater than 1


Outputs:

  [real] TCToTotal - the temporal centroid to total length ratio


Description:

  This algorithm calculates the ratio of the temporal centroid to the total
  length of a signal envelope. This ratio shows how the sound is 'balanced'.
  Its value is close to 0 if most of the energy lies at the beginning of the
  sound (e.g. decrescendo or impulsive sounds), close to 0.5 if the sound is
  symetric (e.g. 'delta unvarying' sounds), and close to 1 if most of the
  energy lies at the end of the sound (e.g. crescendo sounds).
  
  Please note that the TCToTotal ratio will return 0.5 for a zero signal (a
  signal consisting of only zeros) as 0.5 is the middle point of the signal.
  TCToTotal is not defined for a signal of less than 2 elements.An exception is
  thrown if the given envelope's size is not larger than 1.
  
  This algorithm is intended to be plugged after the Envelope algorithm
 * 
 * Category: Sfx
 * Mode: standard
 */
class TCToTotal extends BaseAlgorithm
{
    protected string $algorithmName = 'TCToTotal';
    protected string $mode = 'standard';
    protected string $category = 'Sfx';

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
                "Failed to compute TCToTotal: " . $e->getMessage(),
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