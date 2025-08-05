<?php

declare(strict_types=1);

namespace App\Modules\Essentia\Algorithms\Rhythm;

use App\Modules\Essentia\Algorithms\BaseAlgorithm;
use App\Modules\Essentia\Exceptions\AlgorithmException;
use App\Modules\Essentia\Types\AudioVector;

/**
 * TempoTapTicks


Inputs:

  [vector_real] periods - tempo period candidates for the current frame, in frames
  [vector_real] phases - tempo ticks phase candidates for the current frame, in frames


Outputs:

  [vector_real] ticks - the list of resulting ticks [s]
  [vector_real] matchingPeriods - list of matching periods [s]


Parameters:

  frameHop:
    integer ∈ (0,inf) (default = 512)
    number of feature frames separating two evaluations

  hopSize:
    integer ∈ (0,inf) (default = 256)
    number of audio samples per features

  sampleRate:
    real ∈ (0,inf) (default = 44100)
    sampling rate of the audio signal [Hz]


Description:

  This algorithm builds the list of ticks from the period and phase candidates
  given by the TempoTap algorithm.
  
  Quality: outdated (use TempoTapDegara instead)
  
  References:
    [1] F. Gouyon, "A computational approach to rhythm description: Audio
    features for the computation of rhythm periodicity functions and their use
    in tempo induction and music content processing," UPF, Barcelona, Spain,
    2005.
 * 
 * Category: Rhythm
 * Mode: standard
 */
class TempoTapTicks extends BaseAlgorithm
{
    protected string $algorithmName = 'TempoTapTicks';
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
                "Failed to compute TempoTapTicks: " . $e->getMessage(),
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