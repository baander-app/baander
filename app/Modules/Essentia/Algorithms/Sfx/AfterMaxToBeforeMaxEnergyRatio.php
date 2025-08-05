<?php

declare(strict_types=1);

namespace App\Modules\Essentia\Algorithms\Sfx;

use App\Modules\Essentia\Algorithms\BaseAlgorithm;
use App\Modules\Essentia\Exceptions\AlgorithmException;
use App\Modules\Essentia\Types\AudioVector;

/**
 * AfterMaxToBeforeMaxEnergyRatio


Inputs:

  [vector_real] pitch - the array of pitch values [Hz]


Outputs:

  [real] afterMaxToBeforeMaxEnergyRatio - the ratio between the pitch energy after the pitch maximum to the pitch energy before the pitch maximum


Description:

  This algorithm computes the ratio between the pitch energy after the pitch
  maximum and the pitch energy before the pitch maximum. Sounds having an
  monotonically ascending pitch or one unique pitch will show a value of (0,1],
  while sounds having a monotonically descending pitch will show a value of
  [1,inf). In case there is no energy before the max pitch, the algorithm will
  return the energy after the maximum pitch.
  
  The algorithm throws exception when input is either empty or contains only
  zeros.
 * 
 * Category: Sfx
 * Mode: standard
 */
class AfterMaxToBeforeMaxEnergyRatio extends BaseAlgorithm
{
    protected string $algorithmName = 'AfterMaxToBeforeMaxEnergyRatio';
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
                "Failed to compute AfterMaxToBeforeMaxEnergyRatio: " . $e->getMessage(),
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