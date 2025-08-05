<?php

declare(strict_types=1);

namespace App\Modules\Essentia\Algorithms\Sfx;

use App\Modules\Essentia\Algorithms\BaseAlgorithm;
use App\Modules\Essentia\Exceptions\AlgorithmException;
use App\Modules\Essentia\Types\AudioVector;

/**
 * StrongPeak


Inputs:

  [vector_real] spectrum - the input spectrum (must be greater than one element and cannot contain negative values)


Outputs:

  [real] strongPeak - the Strong Peak ratio


Description:

  This algorithm computes the Strong Peak of a spectrum. The Strong Peak is
  defined as the ratio between the spectrum's maximum peak's magnitude and the
  "bandwidth" of the peak above a threshold (half its amplitude). This ratio
  reveals whether the spectrum presents a very "pronounced" maximum peak (i.e.
  the thinner and the higher the maximum of the spectrum is, the higher the
  ratio value).
  
  Note that "bandwidth" is defined as the width of the peak in the
  log10-frequency domain. This is different than as implemented in [1]. Using
  the log10-frequency domain allows this algorithm to compare strong peaks at
  lower frequencies with those from higher frequencies.
  
  An exception is thrown if the input spectrum contains less than two elements.
  
  References:
    [1] F. Gouyon and P. Herrera, "Exploration of techniques for automatic
    labeling of audio drum tracks instruments,” in MOSART: Workshop on
  Current
    Directions in Computer Music, 2001.
 * 
 * Category: Sfx
 * Mode: standard
 */
class StrongPeak extends BaseAlgorithm
{
    protected string $algorithmName = 'StrongPeak';
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
                "Failed to compute StrongPeak: " . $e->getMessage(),
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