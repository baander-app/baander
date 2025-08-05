<?php

declare(strict_types=1);

namespace App\Modules\Essentia\Algorithms\Tonal;

use App\Modules\Essentia\Algorithms\BaseAlgorithm;
use App\Modules\Essentia\Exceptions\AlgorithmException;
use App\Modules\Essentia\Types\AudioVector;

/**
 * TuningFrequency


Inputs:

  [vector_real] frequencies - the frequencies of the spectral peaks [Hz]
  [vector_real] magnitudes - the magnitudes of the spectral peaks


Outputs:

  [real] tuningFrequency - the tuning frequency [Hz]
  [real] tuningCents - the deviation from 440 Hz (between -35 to 65 cents)


Parameters:

  resolution:
    real ∈ (0,inf) (default = 1)
    resolution in cents (logarithmic scale, 100 cents = 1 semitone) for tuning
    frequency determination


Description:

  This algorithm estimates the tuning frequency give a sequence/set of spectral
  peaks. The result is the tuning frequency in Hz, and its distance from 440Hz
  in cents. This version is slightly adapted from the original algorithm [1],
  but gives the same results.
  
  Input vectors should have the same size, otherwise an exception is thrown.
  This algorithm should be given the outputs of the spectral peaks algorithm.
  
  Application: Western vs non-western music classification, key estimation,
  HPCP computation, tonal similarity.
  References:
    [1] E. Gómez, "Key estimation from polyphonic audio," in Music Information
    Retrieval Evaluation Exchange (MIREX’05), 2005.
 * 
 * Category: Tonal
 * Mode: standard
 */
class TuningFrequency extends BaseAlgorithm
{
    protected string $algorithmName = 'TuningFrequency';
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
                "Failed to compute TuningFrequency: " . $e->getMessage(),
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