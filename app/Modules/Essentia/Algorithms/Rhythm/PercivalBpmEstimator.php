<?php

declare(strict_types=1);

namespace App\Modules\Essentia\Algorithms\Rhythm;

use App\Modules\Essentia\Algorithms\BaseAlgorithm;
use App\Modules\Essentia\Exceptions\AlgorithmException;
use App\Modules\Essentia\Types\AudioVector;

/**
 * PercivalBpmEstimator


Inputs:

  [vector_real] signal - input signal


Outputs:

  [real] bpm - the tempo estimation [bpm]


Parameters:

  frameSize:
    integer ∈ (0,inf) (default = 1024)
    frame size for the analysis of the input signal

  frameSizeOSS:
    integer ∈ (0,inf) (default = 2048)
    frame size for the analysis of the Onset Strength Signal

  hopSize:
    integer ∈ (0,inf) (default = 128)
    hop size for the analysis of the input signal

  hopSizeOSS:
    integer ∈ (0,inf) (default = 128)
    hop size for the analysis of the Onset Strength Signal

  maxBPM:
    integer ∈ (0,inf) (default = 210)
    maximum BPM to detect

  minBPM:
    integer ∈ (0,inf) (default = 50)
    minimum BPM to detect

  sampleRate:
    integer ∈ (0,inf) (default = 44100)
    the sampling rate of the audio signal [Hz]


Description:

  This algorithm estimates the tempo in beats per minute (BPM) from an input
  signal as described in [1].
  
  References:
    [1] Percival, G., & Tzanetakis, G. (2014). Streamlined tempo estimation
  based on autocorrelation and cross-correlation with pulses.
    IEEE/ACM Transactions on Audio, Speech, and Language Processing, 22(12),
  1765–1776.
 * 
 * Category: Rhythm
 * Mode: standard
 */
class PercivalBpmEstimator extends BaseAlgorithm
{
    protected string $algorithmName = 'PercivalBpmEstimator';
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
                "Failed to compute PercivalBpmEstimator: " . $e->getMessage(),
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