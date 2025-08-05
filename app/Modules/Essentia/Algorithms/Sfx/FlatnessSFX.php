<?php

declare(strict_types=1);

namespace App\Modules\Essentia\Algorithms\Sfx;

use App\Modules\Essentia\Algorithms\BaseAlgorithm;
use App\Modules\Essentia\Exceptions\AlgorithmException;
use App\Modules\Essentia\Types\AudioVector;

/**
 * FlatnessSFX


Inputs:

  [vector_real] envelope - the envelope of the signal


Outputs:

  [real] flatness - the flatness coefficient


Description:

  This algorithm calculates the flatness coefficient of a signal envelope.
  
  There are two thresholds defined: a lower one at 20% and an upper one at 95%.
  The thresholds yield two values: one value which has 20% of the total values
  underneath, and one value which has 95% of the total values underneath. The
  flatness coefficient is then calculated as the ratio of these two values.
  This algorithm is meant to be plugged after Envelope algorithm, however in
  streaming mode a RealAccumulator algorithm should be connected in between the
  two.
  In the current form the algorithm can't be calculated in streaming mode,
  since it would violate the streaming mode policy of having low memory
  consumption.
  
  An exception is thrown if the input envelope is empty.
 * 
 * Category: Sfx
 * Mode: standard
 */
class FlatnessSFX extends BaseAlgorithm
{
    protected string $algorithmName = 'FlatnessSFX';
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
                "Failed to compute FlatnessSFX: " . $e->getMessage(),
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