<?php

declare(strict_types=1);

namespace App\Modules\Essentia\Algorithms\HighLevel;

use App\Modules\Essentia\Algorithms\BaseAlgorithm;
use App\Modules\Essentia\Exceptions\AlgorithmException;
use App\Modules\Essentia\Types\AudioVector;

/**
 * GaiaTransform


Inputs:

  [pool] pool - aggregated pool of extracted values


Outputs:

  [pool] pool - pool resulting from the transformation of the gaia point


Parameters:

  history:
    string
    gaia2 history filename


Description:

  Applies a given Gaia2 transformation history to a given pool. It first
  converts the pool into a gaia2::Point suitable for the history, applies the
  history, and converts back the resulting point into an essentia Pool. In
  particular, it allows classification.
  
  Note that in order to enable this algorithm it is necessary to install Gaia2
  library before building Essentia.
  
  References:
    [1] Gaia - A library for similarity in high-dimensional spaces,
    http://github.com/MTG/gaia
 * 
 * Category: HighLevel
 * Mode: standard
 */
class GaiaTransform extends BaseAlgorithm
{
    protected string $algorithmName = 'GaiaTransform';
    protected string $mode = 'standard';
    protected string $category = 'HighLevel';

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
                "Failed to compute GaiaTransform: " . $e->getMessage(),
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