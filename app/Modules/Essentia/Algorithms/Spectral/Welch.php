<?php

declare(strict_types=1);

namespace App\Modules\Essentia\Algorithms\Spectral;

use App\Modules\Essentia\Algorithms\BaseAlgorithm;
use App\Modules\Essentia\Exceptions\AlgorithmException;
use App\Modules\Essentia\Types\AudioVector;

/**
 * Welch


Inputs:

  [vector_real] frame - the input stereo audio signal


Outputs:

  [vector_real] psd - Power Spectral Density [dB] or [dB/Hz]


Parameters:

  averagingFrames:
    integer ∈ (0,inf) (default = 10)
    amount of frames to average

  fftSize:
    integer ∈ (0,inf) (default = 1024)
    size of the FFT. Zero padding is added if this is larger the input frame
    size.

  frameSize:
    integer ∈ (0,inf) (default = 512)
    the expected size of the input audio signal (this is an optional parameter
    to optimize memory allocation)

  sampleRate:
    real ∈ (0,inf) (default = 44100)
    the sampling rate of the audio signal [Hz]

  scaling:
    string ∈ {density,power} (default = "density")
    'density' normalizes the result to the bandwidth while 'power' outputs the
    unnormalized power spectrum

  windowType:
    string ∈ {hamming,hann,triangular,square,blackmanharris62,blackmanharris70,blackmanharris74,blackmanharris92} (default = "hann")
    the window type


Description:

   This algorithm estimates the Power Spectral Density of the input signal
  using the Welch's method [1].
   The input should be fed with the overlapped audio frames. The algorithm
  stores internally therequired past frames to compute each output. Call
  reset() to clear the buffers. This implentation is based on Scipy [2]
  
  References:
    [1] The Welch's method - Wikipedia, the free encyclopedia,
  https://en.wikipedia.org/wiki/Welch%27s_method
    [2]
  https://docs.scipy.org/doc/scipy-0.14.0/reference/generated/scipy.signal.welch
  .html
 * 
 * Category: Spectral
 * Mode: standard
 */
class Welch extends BaseAlgorithm
{
    protected string $algorithmName = 'Welch';
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
                "Failed to compute Welch: " . $e->getMessage(),
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