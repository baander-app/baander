<?php

declare(strict_types=1);

namespace App\Modules\Essentia\Algorithms\Stats;

use App\Modules\Essentia\Algorithms\BaseAlgorithm;
use App\Modules\Essentia\Exceptions\AlgorithmException;
use App\Modules\Essentia\Types\AudioVector;

/**
 * WarpedAutoCorrelation


Inputs:

  [vector_real] array - the array to be analyzed


Outputs:

  [vector_real] warpedAutoCorrelation - the warped auto-correlation vector


Parameters:

  maxLag:
    integer ∈ (0,inf) (default = 1)
    the maximum lag for which the auto-correlation is computed (inclusive)
    (must be smaller than signal size) 

  sampleRate:
    real ∈ (0,inf) (default = 44100)
    the audio sampling rate [Hz]


Description:

  This algorithm computes the warped auto-correlation of an audio signal. The
  implementation is an adapted version of K. Schmidt's implementation of the
  matlab algorithm from the 'warped toolbox' by Aki Harma and Matti Karjalainen
  found [2]. For a detailed explanation of the algorithm, see [1].
  This algorithm is only defined for positive lambda =
  1.0674*sqrt(2.0*atan(0.00006583*sampleRate)/PI) - 0.1916, thus it will throw
  an exception when the supplied sampling rate does not pass the requirements.
  If maxLag is larger than the size of the input array, an exception is thrown.
  
  References:
    [1] A. Härmä, M. Karjalainen, L. Savioja, V. Välimäki, U. K. Laine, and
    J. Huopaniemi, "Frequency-Warped Signal Processing for Audio Applications,"
    JAES, vol. 48, no. 11, pp. 1011–1031, 2000.
  
    [2] WarpTB - Matlab Toolbox for Warped DSP
    http://www.acoustics.hut.fi/software/warp
 * 
 * Category: Stats
 * Mode: standard
 */
class WarpedAutoCorrelation extends BaseAlgorithm
{
    protected string $algorithmName = 'WarpedAutoCorrelation';
    protected string $mode = 'standard';
    protected string $category = 'Stats';

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
                "Failed to compute WarpedAutoCorrelation: " . $e->getMessage(),
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