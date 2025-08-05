<?php

declare(strict_types=1);

namespace App\Modules\Essentia\Algorithms\Standard;

use App\Modules\Essentia\Algorithms\BaseAlgorithm;
use App\Modules\Essentia\Exceptions\AlgorithmException;
use App\Modules\Essentia\Types\AudioVector;

/**
 * OverlapAdd


Inputs:

  [vector_real] signal - the windowed input audio frame


Outputs:

  [vector_real] signal - the output overlap-add audio signal frame


Parameters:

  frameSize:
    integer ∈ (0,inf) (default = 2048)
    the frame size for computing the overlap-add process

  gain:
    real ∈ (0.,inf) (default = 1)
    the normalization gain that scales the output signal. Useful for IFFT
    output

  hopSize:
    integer ∈ (0,inf) (default = 128)
    the hop size with which the overlap-add function is computed


Description:

  This algorithm returns the output of an overlap-add process for a sequence of
  frames of an audio signal. It considers that the input audio frames are
  windowed audio signals. Giving the size of the frame and the hop size,
  overlapping and adding consecutive frames will produce a continuous signal. A
  normalization gain can be passed as a parameter.
  
  Empty input signals will raise an exception.
  
  References:
    [1] Overlap–add method - Wikipedia, the free encyclopedia,
    http://en.wikipedia.org/wiki/Overlap-add_method
 * 
 * Category: Standard
 * Mode: standard
 */
class OverlapAdd extends BaseAlgorithm
{
    protected string $algorithmName = 'OverlapAdd';
    protected string $mode = 'standard';
    protected string $category = 'Standard';

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
                "Failed to compute OverlapAdd: " . $e->getMessage(),
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