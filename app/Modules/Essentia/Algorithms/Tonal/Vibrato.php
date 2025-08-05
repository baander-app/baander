<?php

declare(strict_types=1);

namespace App\Modules\Essentia\Algorithms\Tonal;

use App\Modules\Essentia\Algorithms\BaseAlgorithm;
use App\Modules\Essentia\Exceptions\AlgorithmException;
use App\Modules\Essentia\Types\AudioVector;

/**
 * Vibrato


Inputs:

  [vector_real] pitch - the pitch trajectory [Hz].


Outputs:

  [vector_real] vibratoFrequency - estimated vibrato frequency (or speed) [Hz]; zero if no vibrato was detected.
  [vector_real] vibratoExtend - estimated vibrato extent (or depth) [cents]; zero if no vibrato was detected.


Parameters:

  maxExtend:
    real ∈ (0,inf) (default = 250)
    maximum considered vibrato extent [cents]

  maxFrequency:
    real ∈ (0,inf) (default = 8)
    maximum considered vibrato frequency [Hz]

  minExtend:
    real ∈ (0,inf) (default = 50)
    minimum considered vibrato extent [cents]

  minFrequency:
    real ∈ (0,inf) (default = 4)
    minimum considered vibrato frequency [Hz]

  sampleRate:
    real ∈ (0,inf) (default = 344.53125)
    sample rate of the input pitch contour


Description:

  This algorithm detects the presence of vibrato and estimates its parameters
  given a pitch contour [Hz]. The result is the vibrato frequency in Hz and the
  extent (peak to peak) in cents. If no vibrato is detected in a frame, the
  output of both values is zero.
  
  This algorithm should be given the outputs of a pitch estimator, i.e.
  PredominantMelody, PitchYinFFT or PitchMelodia and the corresponding sample
  rate with which it was computed.
  
  The algorithm is an extended version of the vocal vibrato detection in
  PerdominantMelody.
  References:
    [1] J. Salamon and E. Gómez, "Melody extraction from polyphonic music
    signals using pitch contour characteristics," IEEE Transactions on Audio,
    Speech, and Language Processing, vol. 20, no. 6, pp. 1759–1770, 2012.
 * 
 * Category: Tonal
 * Mode: standard
 */
class Vibrato extends BaseAlgorithm
{
    protected string $algorithmName = 'Vibrato';
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
                "Failed to compute Vibrato: " . $e->getMessage(),
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