<?php

declare(strict_types=1);

namespace App\Modules\Essentia\Algorithms\AudioProblems;

use App\Modules\Essentia\Algorithms\BaseAlgorithm;
use App\Modules\Essentia\Exceptions\AlgorithmException;
use App\Modules\Essentia\Types\AudioVector;

/**
 * HumDetector


Inputs:

  [vector_real] signal - the input audio signal


Outputs:

  [matrix_real] r - the quantile ratios matrix
  [vector_real] frequencies - humming tones frequencies
  [vector_real] saliences - humming tones saliences
  [vector_real] starts - humming tones starts
  [vector_real] ends - humming tones ends


Parameters:

  Q0:
    real ∈ (0,1) (default = 0.10000000149)
    low quantile

  Q1:
    real ∈ (0,1) (default = 0.550000011921)
    high quatile

  detectionThreshold:
    real ∈ (0,inf) (default = 5)
    the detection threshold for the peaks of the r matrix

  frameSize:
    real ∈ (0,inf) (default = 0.40000000596)
    the frame size with which the loudness is computed [s]

  hopSize:
    real ∈ (0,inf) (default = 0.20000000298)
    the hop size with which the loudness is computed [s]

  maximumFrequency:
    real ∈ (0,inf) (default = 400)
    maximum frequency to consider [Hz]

  minimumDuration:
    real ∈ (0,inf) (default = 2)
    minimun duration of the humming tones [s]

  minimumFrequency:
    real ∈ (0,inf) (default = 22.5)
    minimum frequency to consider [Hz]

  numberHarmonics:
    integer ∈ (0,inf) (default = 1)
    number of considered harmonics

  sampleRate:
    real ∈ (0,inf) (default = 44100)
    the sampling rate of the audio signal [Hz]

  timeContinuity:
    real ∈ (0,inf) (default = 10)
    time continuity cue (the maximum allowed gap duration for a pitch contour)
    [s]

  timeWindow:
    real ∈ (0,inf) (default = 10)
    analysis time to use for the hum estimation [s]


Description:

  This algorithm detects low frequency tonal noises in the audio signal. First,
  the steadiness of the Power Spectral Density (PSD) of the signal is computed
  by measuring the quantile ratios as described in [1]. After this, the
  PitchContours algorithm is used to keep track of the humming tones [2].
  
  References:
    [1] Brandt, M., & Bitzer, J. (2014). Automatic Detection of Hum in Audio
    Signals. Journal of the Audio Engineering Society, 62(9), 584-595.
  
    [2] J. Salamon and E. Gómez, Melody extraction from polyphonic music
  signals
    using pitch contour characteristics, IEEE Transactions on Audio, Speech,
    and Language Processing, vol. 20, no. 6, pp. 1759–1770, 2012.
 * 
 * Category: AudioProblems
 * Mode: standard
 */
class HumDetector extends BaseAlgorithm
{
    protected string $algorithmName = 'HumDetector';
    protected string $mode = 'standard';
    protected string $category = 'AudioProblems';

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
                "Failed to compute HumDetector: " . $e->getMessage(),
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