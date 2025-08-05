<?php

declare(strict_types=1);

namespace App\Modules\Essentia\Algorithms\Tonal;

use App\Modules\Essentia\Algorithms\BaseAlgorithm;
use App\Modules\Essentia\Exceptions\AlgorithmException;
use App\Modules\Essentia\Types\AudioVector;

/**
 * HarmonicMask


Inputs:

  [vector_complex] fft - the input frame
            [real] pitch - an estimate of the fundamental frequency of the signal [Hz]


Outputs:

  [vector_complex] fft - the output frame


Parameters:

  attenuation:
    real ∈ [-inf,inf) (default = -200)
    attenuation in dB's of the muted pitched component. If value is positive
    the pitched component is attenuated (muted), if the value is negative the
    pitched component is soloed (i.e. background component is attenuated).

  binWidth:
    integer ∈ [0,inf) (default = 4)
    number of bins per harmonic partials applied to the mask. This will depend
    on the internal FFT size

  sampleRate:
    real ∈ (0,inf) (default = 44100)
    the audio sampling rate [Hz]


Description:

  This algorithm applies a spectral mask to remove a pitched source component
  from the signal. It computes first an harmonic mask corresponding to the
  input pitch and applies the mask to the input FFT to remove that pitch. The
  bin width determines how many spectral bins are masked per harmonic partial. 
  An attenuation value in dB determines the amount of suppression of the
  pitched component w.r.t the background for the case of muting. A negative
  attenuation value allows soloing the pitched component. 
  
  References:
 * 
 * Category: Tonal
 * Mode: standard
 */
class HarmonicMask extends BaseAlgorithm
{
    protected string $algorithmName = 'HarmonicMask';
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
                "Failed to compute HarmonicMask: " . $e->getMessage(),
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