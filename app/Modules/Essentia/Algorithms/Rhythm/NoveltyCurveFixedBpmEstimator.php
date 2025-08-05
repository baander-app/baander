<?php

declare(strict_types=1);

namespace App\Modules\Essentia\Algorithms\Rhythm;

use App\Modules\Essentia\Algorithms\BaseAlgorithm;
use App\Modules\Essentia\Exceptions\AlgorithmException;
use App\Modules\Essentia\Types\AudioVector;

/**
 * NoveltyCurveFixedBpmEstimator


Inputs:

  [vector_real] novelty - the novelty curve of the audio signal


Outputs:

  [vector_real] bpms - the bpm candidates sorted by magnitude
  [vector_real] amplitudes - the magnitude of each bpm candidate


Parameters:

  hopSize:
    integer (default = 512)
    the hopSize used to computeh the novelty curve from the original signal

  maxBpm:
    real ∈ (0,inf) (default = 560)
    the maximum bpm to look for

  minBpm:
    real ∈ (0,inf) (default = 30)
    the minimum bpm to look for

  sampleRate:
    real ∈ [1,inf) (default = 44100)
    the sampling rate original audio signal [Hz]

  tolerance:
    real ∈ (0,100] (default = 3)
    tolerance (in percentage) for considering bpms to be equal


Description:

  This algorithm outputs a histogram of the most probable bpms assuming the
  signal has constant tempo given the novelty curve. This algorithm is based on
  the autocorrelation of the novelty curve (see NoveltyCurve algorithm) and
  should only be used for signals that have a constant tempo or as a first
  tempo estimator to be used in conjunction with other algorithms such as
  BpmHistogram.It is a simplified version of the algorithm described in [1] as,
  in order to predict the best BPM candidate,  it computes autocorrelation of
  the entire novelty curve instead of analyzing it on frames and histogramming
  the peaks over frames.
  
  References:
    [1] E. Aylon and N. Wack, "Beat detection using plp," in Music Information
    Retrieval Evaluation Exchange (MIREX’10), 2010.
 * 
 * Category: Rhythm
 * Mode: standard
 */
class NoveltyCurveFixedBpmEstimator extends BaseAlgorithm
{
    protected string $algorithmName = 'NoveltyCurveFixedBpmEstimator';
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
                "Failed to compute NoveltyCurveFixedBpmEstimator: " . $e->getMessage(),
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