<?php

declare(strict_types=1);

namespace App\Modules\Essentia\Algorithms\AudioProblems;

use App\Modules\Essentia\Algorithms\BaseAlgorithm;
use App\Modules\Essentia\Exceptions\AlgorithmException;
use App\Modules\Essentia\Types\AudioVector;

/**
 * GapsDetector


Inputs:

  [vector_real] frame - the input frame (must be non-empty)


Outputs:

  [vector_real] starts - the start indexes of the detected gaps (if any) in seconds
  [vector_real] ends - the end indexes of the detected gaps (if any) in seconds


Parameters:

  attackTime:
    real ∈ [0,inf) (default = 0.0500000007451)
    the attack time of the first order lowpass in the attack phase [ms]

  frameSize:
    integer ∈ [0,inf) (default = 2048)
    frame size used for the analysis. Should match the input frame size.
    Otherwise, an exception will be thrown

  hopSize:
    integer ∈ [0,inf) (default = 1024)
    hop size used for the analysis

  kernelSize:
    integer ∈ [1,inf) (default = 11)
    scalar giving the size of the median filter window. Must be odd

  maximumTime:
    real ∈ (0,inf) (default = 3500)
    time of the maximum gap duration [ms]

  minimumTime:
    real ∈ (0,inf) (default = 10)
    time of the minimum gap duration [ms]

  postpowerTime:
    real ∈ (0,inf) (default = 40)
    time for the postpower calculation [ms]

  prepowerThreshold:
    real ∈ (-inf,inf) (default = -30)
    prepower threshold [dB]. 

  prepowerTime:
    real ∈ (0,inf) (default = 40)
    time for the prepower calculation [ms]

  releaseTime:
    real ∈ [0,inf) (default = 0.0500000007451)
    the release time of the first order lowpass in the release phase [ms]

  sampleRate:
    real ∈ (0,inf) (default = 44100)
    sample rate used for the analysis

  silenceThreshold:
    real ∈ (-inf,inf) (default = -50)
    silence threshold [dB]


Description:

  This algorithm uses energy and time thresholds to detect gaps in the
  waveform. A median filter is used to remove spurious silent samples. The
  power of a small audio region before the detected gaps (prepower) is
  thresholded to detect intentional pauses as described in [1]. This technique
  is extended to the region after the gap.
  The algorithm was designed for a framewise use and returns the start and end
  timestamps related to the first frame processed. Call configure() or reset()
  in order to restart the count.
  
  References:
    [1] Mühlbauer, R. (2010). Automatic Audio Defect Detection.
 * 
 * Category: AudioProblems
 * Mode: standard
 */
class GapsDetector extends BaseAlgorithm
{
    protected string $algorithmName = 'GapsDetector';
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
                "Failed to compute GapsDetector: " . $e->getMessage(),
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