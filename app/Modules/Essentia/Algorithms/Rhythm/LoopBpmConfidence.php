<?php

declare(strict_types=1);

namespace App\Modules\Essentia\Algorithms\Rhythm;

use App\Modules\Essentia\Algorithms\BaseAlgorithm;
use App\Modules\Essentia\Exceptions\AlgorithmException;
use App\Modules\Essentia\Types\AudioVector;

/**
 * LoopBpmConfidence


Inputs:

  [vector_real] signal - loop audio signal
         [real] bpmEstimate - estimated BPM for the audio signal (will be rounded to nearest integer)


Outputs:

  [real] confidence - confidence value for the BPM estimation


Parameters:

  sampleRate:
    real ∈ (0,inf) (default = 44100)
    the sampling rate of the audio signal [Hz]


Description:

  This algorithm takes an audio signal and a BPM estimate for that signal and
  predicts the reliability of the BPM estimate in a value from 0 to 1. The
  audio signal is assumed to be a musical loop with constant tempo. The
  confidence returned is based on comparing the duration of the signal with
  multiples of the BPM estimate (see [1] for more details).
  
  References:
    [1] Font, F., & Serra, X. (2016). Tempo Estimation for Music Loops and a
  Simple Confidence Measure.
    Proceedings of the International Society for Music Information Retrieval
  Conference (ISMIR).
 * 
 * Category: Rhythm
 * Mode: standard
 */
class LoopBpmConfidence extends BaseAlgorithm
{
    protected string $algorithmName = 'LoopBpmConfidence';
    protected string $mode = 'standard';
    protected string $category = 'Rhythm';

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
                "Failed to compute LoopBpmConfidence: " . $e->getMessage(),
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