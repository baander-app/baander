<?php

declare(strict_types=1);

namespace App\Modules\Essentia\Algorithms\Standard;

use App\Modules\Essentia\Algorithms\BaseAlgorithm;
use App\Modules\Essentia\Exceptions\AlgorithmException;
use App\Modules\Essentia\Types\AudioVector;

/**
 * IDCT


Inputs:

  [vector_real] dct - the discrete cosine transform


Outputs:

  [vector_real] idct - the inverse cosine transform of the input array


Parameters:

  dctType:
    integer ∈ [2,3] (default = 2)
    the DCT type

  inputSize:
    integer ∈ [1,inf) (default = 10)
    the size of the input array

  liftering:
    integer ∈ [0,inf) (default = 0)
    the liftering coefficient. Use '0' to bypass it

  outputSize:
    integer ∈ [1,inf) (default = 10)
    the number of output coefficients


Description:

  This algorithm computes the Inverse Discrete Cosine Transform of an array.
  It can be configured to perform the inverse DCT-II form, with the 1/sqrt(2)
  scaling factor for the first coefficient or the inverse DCT-III form based on
  the HTK implementation.
  
  IDCT can be used to compute smoothed Mel Bands. In order to do this:
    - compute MFCC
    - smoothedMelBands = 10^(IDCT(MFCC)/20)
  Note: The second step assumes that 'logType' = 'dbamp' was used to compute
  MFCCs, otherwise that formula should be changed in order to be consistent.
  
  Note: The 'inputSize' parameter is only used as an optimization when the
  algorithm is configured. The IDCT will automatically adjust to the size of
  any input.
  
  References:
    [1] Discrete cosine transform - Wikipedia, the free encyclopedia,
    http://en.wikipedia.org/wiki/Discrete_cosine_transform 
    [2] HTK book, chapter 5.6 ,
    http://speech.ee.ntu.edu.tw/homework/DSP_HW2-1/htkbook.pdf
 * 
 * Category: Standard
 * Mode: standard
 */
class IDCT extends BaseAlgorithm
{
    protected string $algorithmName = 'IDCT';
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
                "Failed to compute IDCT: " . $e->getMessage(),
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