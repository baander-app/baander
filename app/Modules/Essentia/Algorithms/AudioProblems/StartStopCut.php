<?php

declare(strict_types=1);

namespace App\Modules\Essentia\Algorithms\AudioProblems;

use App\Modules\Essentia\Algorithms\BaseAlgorithm;
use App\Modules\Essentia\Exceptions\AlgorithmException;
use App\Modules\Essentia\Types\AudioVector;

/**
 * StartStopCut


Inputs:

  [vector_real] audio - the input audio 


Outputs:

  [integer] startCut - 1 if there is a cut at the begining of the audio
  [integer] stopCut - 1 if there is a cut at the end of the audio


Parameters:

  frameSize:
    integer ∈ (0,inf) (default = 256)
    the frame size for the internal power analysis

  hopSize:
    integer ∈ (0,inf) (default = 256)
    the hop size for the internal power analysis

  maximumStartTime:
    real ∈ [0,inf) (default = 10)
    if the first non-silent frame occurs before maximumStartTime startCut is
    activated [ms]

  maximumStopTime:
    real ∈ [0,inf) (default = 10)
    if the last non-silent frame occurs after maximumStopTime to the end
    stopCut is activated [ms]

  sampleRate:
    real ∈ (0,inf) (default = 44100)
    the sample rate

  threshold:
    integer ∈ (-inf,0] (default = -60)
    the threshold below which average energy is defined as silence [dB]


Description:

  This algorithm outputs if there is a cut at the beginning or at the end of
  the audio by locating the first and last non-silent frames and comparing
  their positions to the actual beginning and end of the audio. The input audio
  is considered to be cut at the beginning (or the end) and the corresponding
  flag is activated if the first (last) non-silent frame occurs before (after)
  the configurable time threshold.
  
  Notes: This algorithm is designed to operate on the entire (file) audio. In
  the streaming mode, use it in combination with RealAccumulator.
  The encoding/decoding process of lossy formats can introduce some padding at
  the beginning/end of the file. E.g. an MP3 file encoded and decoded with LAME
  using the default parameters will introduce a delay of 1104 samples
  [http://lame.sourceforge.net/tech-FAQ.txt]. In this case, the
  maximumStartTime can be increased by 1104 ÷ 44100 × 1000 = 25 ms to prevent
  misdetections.
 * 
 * Category: AudioProblems
 * Mode: standard
 */
class StartStopCut extends BaseAlgorithm
{
    protected string $algorithmName = 'StartStopCut';
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
                "Failed to compute StartStopCut: " . $e->getMessage(),
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