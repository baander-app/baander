<?php

declare(strict_types=1);

namespace App\Modules\Essentia\Algorithms\Tonal;

use App\Modules\Essentia\Algorithms\BaseAlgorithm;
use App\Modules\Essentia\Exceptions\AlgorithmException;
use App\Modules\Essentia\Types\AudioVector;

/**
 * PitchYinProbabilistic


Inputs:

  [vector_real] signal - the input mono audio signal


Outputs:

  [vector_real] pitch - the output pitch estimations
  [vector_real] voicedProbabilities - the voiced probabilities


Parameters:

  frameSize:
    integer ∈ (0,inf) (default = 2048)
    the frame size of FFT

  hopSize:
    integer ∈ [1,inf) (default = 256)
    the hop size with which the pitch is computed

  lowRMSThreshold:
    real ∈ (0,1] (default = 0.10000000149)
    the low RMS amplitude threshold

  outputUnvoiced:
    string ∈ {zero,abs,negative} (default = "negative")
    whether output unvoiced frame, zero: output non-voiced pitch as 0.; abs:
    output non-voiced pitch as absolute values; negative: output non-voiced
    pitch as negative values

  preciseTime:
    bool ∈ {true,false} (default = false)
    use non-standard precise YIN timing (slow).

  sampleRate:
    real ∈ (0,inf) (default = 44100)
    the sampling rate of the audio signal [Hz]


Description:

  This algorithm computes the pitch track of a mono audio signal using
  probabilistic Yin algorithm.
  
  - The input mono audio signal is preprocessed with a FrameCutter to segment
  into frameSize chunks with a overlap hopSize.
  - The pitch frequencies, probabilities and RMS values of the chunks are then
  calculated by PitchYinProbabilities algorithm. The results of all chunks are
  aggregated into a Essentia pool.
  - The pitch frequencies and probabilities are finally sent to
  PitchYinProbabilitiesHMM algorithm to get a smoothed pitch track and a voiced
  probability.
  
  References:
    [1] M. Mauch and S. Dixon, "pYIN: A Fundamental Frequency Estimator
    Using Probabilistic Threshold Distributions," in Proceedings of the
    IEEE International Conference on Acoustics, Speech, and Signal Processing
    (ICASSP 2014)Project Report, 2004
 * 
 * Category: Tonal
 * Mode: standard
 */
class PitchYinProbabilistic extends BaseAlgorithm
{
    protected string $algorithmName = 'PitchYinProbabilistic';
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
                "Failed to compute PitchYinProbabilistic: " . $e->getMessage(),
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