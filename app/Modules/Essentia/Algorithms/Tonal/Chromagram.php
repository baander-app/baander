<?php

declare(strict_types=1);

namespace App\Modules\Essentia\Algorithms\Tonal;

use App\Modules\Essentia\Algorithms\BaseAlgorithm;
use App\Modules\Essentia\Exceptions\AlgorithmException;
use App\Modules\Essentia\Types\AudioVector;

/**
 * Chromagram


Inputs:

  [vector_real] frame - the input audio frame


Outputs:

  [vector_real] chromagram - the magnitude constant-Q chromagram


Parameters:

  binsPerOctave:
    integer ∈ [1,inf) (default = 12)
    number of bins per octave

  minFrequency:
    real ∈ [1,inf) (default = 32.7000007629)
    minimum frequency [Hz]

  minimumKernelSize:
    integer ∈ [2,inf) (default = 4)
    minimum size allowed for frequency kernels

  normalizeType:
    string ∈ {none,unit_sum,unit_max} (default = "unit_max")
    normalize type

  numberBins:
    integer ∈ [1,inf) (default = 84)
    number of frequency bins, starting at minFrequency

  sampleRate:
    real ∈ [0,inf) (default = 44100)
    FFT sampling rate [Hz]

  scale:
    real ∈ [0,inf) (default = 1)
    filters scale. Larger values use longer windows

  threshold:
    real ∈ [0,1) (default = 0.00999999977648)
    bins whose magnitude is below this quantile are discarded

  windowType:
    string ∈ {hamming,hann,hannnsgcq,triangular,square,blackmanharris62,blackmanharris70,blackmanharris74,blackmanharris92} (default = "hann")
    the window type

  zeroPhase:
    bool ∈ {true,false} (default = true)
    a boolean value that enables zero-phase windowing. Input audio frames
    should be windowed with the same phase mode


Description:

  This algorithm computes the Constant-Q chromagram using FFT. See ConstantQ
  algorithm for more details.
 * 
 * Category: Tonal
 * Mode: standard
 */
class Chromagram extends BaseAlgorithm
{
    protected string $algorithmName = 'Chromagram';
    protected string $mode = 'standard';
    protected string $category = 'Tonal';

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
                "Failed to compute Chromagram: " . $e->getMessage(),
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