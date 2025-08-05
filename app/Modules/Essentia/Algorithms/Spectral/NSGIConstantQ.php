<?php

declare(strict_types=1);

namespace App\Modules\Essentia\Algorithms\Spectral;

use App\Modules\Essentia\Algorithms\BaseAlgorithm;
use App\Modules\Essentia\Exceptions\AlgorithmException;
use App\Modules\Essentia\Types\AudioVector;

/**
 * NSGIConstantQ


Inputs:

  [vector_vector_complex] constantq - the constant Q transform of the input frame
         [vector_complex] constantqdc - the DC band transform of the input frame
         [vector_complex] constantqnf - the Nyquist band transform of the input frame


Outputs:

  [vector_real] frame - the input frame (vector)


Parameters:

  binsPerOctave:
    integer ∈ [1,inf) (default = 48)
    the number of bins per octave

  gamma:
    integer ∈ [0,inf) (default = 0)
    The bandwidth of each filter is given by Bk = 1/Q * fk + gamma

  inputSize:
    integer ∈ (0,inf) (default = 4096)
    the size of the input

  maxFrequency:
    real ∈ (0,inf) (default = 7040)
    the maximum frequency

  minFrequency:
    real ∈ (0,inf) (default = 27.5)
    the minimum frequency

  minimumWindow:
    integer ∈ [2,inf) (default = 4)
    minimum size allowed for the windows

  normalize:
    string ∈ {sine,impulse,none} (default = "none")
    coefficient normalization

  phaseMode:
    string ∈ {local,global} (default = "global")
    'local' to use zero-centered filters. 'global' to use a phase mapping
    function as described in [1]

  rasterize:
    string ∈ {none,full,piecewise} (default = "full")
    hop sizes for each frequency channel. With 'none' each frequency channel is
    distinct. 'full' sets the hop sizes of all the channels to the smallest.
    'piecewise' rounds down the hop size to a power of two

  sampleRate:
    real ∈ [0,inf) (default = 44100)
    the desired sampling rate [Hz]

  window:
    string ∈ {hamming,hann,hannnsgcq,triangular,square,blackmanharris62,blackmanharris70,blackmanharris74,blackmanharris92} (default = "hannnsgcq")
    the type of window for the frequency filters. It is not recommended to
    change the default window.

  windowSizeFactor:
    integer ∈ [1,inf) (default = 1)
    window sizes are rounded to multiples of this


Description:

  This algorithm computes an inverse constant Q transform using non stationary
  Gabor frames and returns a complex time-frequency representation of the input
  vector.
  The implementation is inspired by the toolbox described in [1].
  References:
    [1] Schörkhuber, C., Klapuri, A., Holighaus, N., & Dörfler, M. (n.d.). A
  Matlab Toolbox for Efficient Perfect Reconstruction Time-Frequency Transforms
  with Log-Frequency Resolution.
 * 
 * Category: Spectral
 * Mode: standard
 */
class NSGIConstantQ extends BaseAlgorithm
{
    protected string $algorithmName = 'NSGIConstantQ';
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
                "Failed to compute NSGIConstantQ: " . $e->getMessage(),
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