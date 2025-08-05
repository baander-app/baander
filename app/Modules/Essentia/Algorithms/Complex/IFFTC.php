<?php

declare(strict_types=1);

namespace App\Modules\Essentia\Algorithms\Complex;

use App\Modules\Essentia\Algorithms\BaseAlgorithm;
use App\Modules\Essentia\Exceptions\AlgorithmException;
use App\Modules\Essentia\Types\AudioVector;

/**
 * IFFTC


Inputs:

  [vector_complex] fft - the input frame


Outputs:

  [vector_complex] frame - the complex IFFT of the input frame


Parameters:

  normalize:
    bool ∈ {true,false} (default = true)
    whether to normalize the output by the FFT length.

  size:
    integer ∈ [1,inf) (default = 1024)
    the expected size of the input frame. This is purely optional and only
    targeted at optimizing the creation time of the FFT object


Description:

  This algorithm calculates the inverse short-term Fourier transform (STFT) of
  an array of complex values using the FFT algorithm. The resulting frame has a
  size equal to the input fft frame size. The inverse Fourier transform is not
  defined for frames which size is less than 2 samples. Otherwise an exception
  is thrown.
  
  An exception is thrown if the input's size is not larger than 1.
  
  References:
    [1] Fast Fourier transform - Wikipedia, the free encyclopedia,
    http://en.wikipedia.org/wiki/Fft
  
    [2] Fast Fourier Transform -- from Wolfram MathWorld,
    http://mathworld.wolfram.com/FastFourierTransform.html
 * 
 * Category: Complex
 * Mode: standard
 */
class IFFTC extends BaseAlgorithm
{
    protected string $algorithmName = 'IFFTC';
    protected string $mode = 'standard';
    protected string $category = 'Complex';

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
                "Failed to compute IFFTC: " . $e->getMessage(),
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