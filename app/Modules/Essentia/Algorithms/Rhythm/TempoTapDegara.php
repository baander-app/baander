<?php

declare(strict_types=1);

namespace App\Modules\Essentia\Algorithms\Rhythm;

use App\Modules\Essentia\Algorithms\BaseAlgorithm;
use App\Modules\Essentia\Exceptions\AlgorithmException;
use App\Modules\Essentia\Types\AudioVector;

/**
 * TempoTapDegara


Inputs:

  [vector_real] onsetDetections - the input frame-wise vector of onset detection values


Outputs:

  [vector_real] ticks - the list of resulting ticks [s]


Parameters:

  maxTempo:
    integer ∈ [60,250] (default = 208)
    fastest tempo allowed to be detected [bpm]

  minTempo:
    integer ∈ [40,180] (default = 40)
    slowest tempo allowed to be detected [bpm]

  resample:
    string ∈ {none,x2,x3,x4} (default = "none")
    use upsampling of the onset detection function (may increase accuracy)

  sampleRateODF:
    real ∈ (0,inf) (default = 86.1328125)
    the sampling rate of the onset detection function [Hz]


Description:

  This algorithm estimates beat positions given an onset detection function. 
  The detection function is partitioned into 6-second frames with a 1.5-second
  increment, and the autocorrelation is computed for each frame, and is
  weighted by a tempo preference curve [2]. Periodicity estimations are done
  frame-wisely, searching for the best match with the Viterbi algorith [3]. The
  estimated periods are then passed to the probabilistic beat tracking
  algorithm [1], which computes beat positions.
  
  Note that the input values of the onset detection functions must be
  non-negative otherwise an exception is thrown. Parameter "maxTempo" should be
  20bpm larger than "minTempo", otherwise an exception is thrown.
  
  References:
    [1] Degara, N., Rua, E. A., Pena, A., Torres-Guijarro, S., Davies, M. E., &
  Plumbley, M. D. (2012). Reliability-informed beat tracking of musical
  signals. Audio, Speech, and Language Processing, IEEE Transactions on, 20(1),
  290-301.
    [2] Davies, M. E., & Plumbley, M. D. (2007). Context-dependent beat
  tracking of musical audio. Audio, Speech, and Language Processing, IEEE
  Transactions on, 15(3), 1009-1020.
    [3] Stark, A. M., Davies, M. E., & Plumbley, M. D. (2009, September).
  Real-time beatsynchronous analysis of musical audio. In 12th International
  Conference on Digital Audio Effects (DAFx-09), Como, Italy.
 * 
 * Category: Rhythm
 * Mode: standard
 */
class TempoTapDegara extends BaseAlgorithm
{
    protected string $algorithmName = 'TempoTapDegara';
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
                "Failed to compute TempoTapDegara: " . $e->getMessage(),
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