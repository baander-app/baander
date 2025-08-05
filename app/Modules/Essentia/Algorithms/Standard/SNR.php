<?php

declare(strict_types=1);

namespace App\Modules\Essentia\Algorithms\Standard;

use App\Modules\Essentia\Algorithms\BaseAlgorithm;
use App\Modules\Essentia\Exceptions\AlgorithmException;
use App\Modules\Essentia\Types\AudioVector;

/**
 * SNR


Inputs:

  [vector_real] frame - the input audio frame


Outputs:

         [real] instantSNR - SNR value for the the current frame
         [real] averagedSNR - averaged SNR through an Exponential Moving Average filter
  [vector_real] spectralSNR - instant SNR for each frequency bin


Parameters:

  MAAlpha:
    real ∈ [0,1] (default = 0.949999988079)
    Alpha coefficient for the EMA SNR estimation [2]

  MMSEAlpha:
    real ∈ [0,1] (default = 0.980000019073)
    Alpha coefficient for the MMSE estimation [1].

  NoiseAlpha:
    real ∈ [0,1] (default = 0.899999976158)
    Alpha coefficient for the EMA noise estimation [2]

  frameSize:
    integer ∈ (1,inf) (default = 512)
    the size of the input frame

  noiseThreshold:
    real ∈ (-inf,0] (default = -40)
    Threshold to detect frames without signal

  sampleRate:
    real ∈ (0,inf) (default = 44100)
    the sampling rate of the audio signal [Hz]

  useBroadbadNoiseCorrection:
    bool ∈ {true,false} (default = true)
    flag to apply the -10 * log10(BW) broadband noise correction factor


Description:

  This algorithm computes the SNR of the input audio in a frame-wise manner.
  
  The algorithm assumes that:
  
  - The noise is gaussian.
  - There is a region of noise (without signal) at the beginning of the stream
  in order to estimate the PSD of the noise [1].
  
  Once the noise PSD is estimated, the algorithm relies on the Ephraim-Malah
  [2] recursion to estimate the SNR for each frequency bin.
  
  The algorithm also returns an overall (a single value for the whole spectrum)
  SNR estimation and an averaged overall SNR estimation using Exponential
  Moving Average filtering.
  
  This algorithm throws a warning if less than 15 frames are used to estimate
  the noise PSD.
  
  References:
  
  1. Vaseghi, S. V. (2008). Advanced digital signal processing and noise
  reduction. John Wiley & Sons. Page 336.
  
  2. Ephraim, Y., & Malah, D. (1984). Speech enhancement using a minimum-mean
  square error short-time spectral amplitude estimator. IEEE Transactions on
  acoustics, speech, and signal processing, 32(6), 1109-1121.
 * 
 * Category: Standard
 * Mode: standard
 */
class SNR extends BaseAlgorithm
{
    protected string $algorithmName = 'SNR';
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
                "Failed to compute SNR: " . $e->getMessage(),
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