<?php

declare(strict_types=1);

namespace App\Modules\Essentia\Algorithms\Complex;

use App\Modules\Essentia\Algorithms\BaseAlgorithm;
use App\Modules\Essentia\Exceptions\AlgorithmException;
use App\Modules\Essentia\Types\AudioVector;

/**
 * FFTC


Inputs:

  [vector_complex] frame - the input frame (complex)


Outputs:

  [vector_complex] fft - the FFT of the input frame


Parameters:

  negativeFrequencies:
    bool ∈ {true,false} (default = false)
    returns the full spectrum or just the positive frequencies

  size:
    integer ∈ [1,inf) (default = 1024)
    the expected size of the input frame. This is purely optional and only
    targeted at optimizing the creation time of the FFT object


Description:

  This algorithm computes the complex short-term Fourier transform (STFT) of a
  complex array using the FFT algorithm. If the `negativeFrequencies` flag is
  set on, the resulting fft has a size of (s/2)+1, where s is the size of the
  input frame. Otherwise, output matches the input size.
  At the moment FFT can only be computed on frames which size is even and non
  zero, otherwise an exception is thrown.
  
  References:
    [1] Fast Fourier transform - Wikipedia, the free encyclopedia,
    http://en.wikipedia.org/wiki/Fft
  
    [2] Fast Fourier Transform -- from Wolfram MathWorld,
    http://mathworld.wolfram.com/FastFourierTransform.html
 * 
 * Category: Complex
 * Mode: standard
 */
class FFTC extends BaseAlgorithm
{
    protected string $algorithmName = 'FFTC';
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
                "Failed to compute FFTC: " . $e->getMessage(),
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