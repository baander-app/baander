<?php

declare(strict_types=1);

namespace App\Modules\Essentia\Algorithms\Spectral;

use App\Modules\Essentia\Algorithms\BaseAlgorithm;
use App\Modules\Essentia\Exceptions\AlgorithmException;
use App\Modules\Essentia\Types\AudioVector;

/**
 * ConstantQ


Inputs:

  [vector_real] frame - the windowed input audio frame


Outputs:

  [vector_complex] constantq - the Constant Q transform


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

  This algorithm computes Constant Q Transform using the FFT for fast
  calculation. It transforms a windowed audio frame into the log frequency
  domain.
  
  References:
    [1] Constant Q transform - Wikipedia, the free encyclopedia,
    https://en.wikipedia.org/wiki/Constant_Q_transform
    [2] Brown, J. C., & Puckette, M. S. (1992). An efficient algorithm for the
    calculation of a constant Q transform. The Journal of the Acoustical
  Society
    of America, 92(5), 2698-2701.
    [3] Schörkhuber, C., & Klapuri, A. (2010). Constant-Q transform toolbox
  for
    music processing. In 7th Sound and Music Computing Conference, Barcelona,
    Spain (pp. 3-64).
 * 
 * Category: Spectral
 * Mode: standard
 */
class ConstantQ extends BaseAlgorithm
{
    protected string $algorithmName = 'ConstantQ';
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
                "Failed to compute ConstantQ: " . $e->getMessage(),
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