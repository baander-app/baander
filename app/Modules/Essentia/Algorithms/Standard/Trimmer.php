<?php

declare(strict_types=1);

namespace App\Modules\Essentia\Algorithms\Standard;

use App\Modules\Essentia\Algorithms\BaseAlgorithm;
use App\Modules\Essentia\Exceptions\AlgorithmException;
use App\Modules\Essentia\Types\AudioVector;

/**
 * Trimmer


Inputs:

  [vector_real] signal - the input signal


Outputs:

  [vector_real] signal - the trimmed signal


Parameters:

  checkRange:
    bool ∈ {true,false} (default = false)
    check whether the specified time range for a slice fits the size of input
    signal (throw exception if not)

  endTime:
    real ∈ [0,inf) (default = 1000000)
    the end time of the slice you want to extract [s]

  sampleRate:
    real ∈ (0,inf) (default = 44100)
    the sampling rate of the input audio signal [Hz]

  startTime:
    real ∈ [0,inf) (default = 0)
    the start time of the slice you want to extract [s]


Description:

  This algorithm extracts a segment of an audio signal given its start and end
  times.
  Giving "startTime" greater than "endTime" will raise an exception.
 * 
 * Category: Standard
 * Mode: standard
 */
class Trimmer extends BaseAlgorithm
{
    protected string $algorithmName = 'Trimmer';
    protected string $mode = 'standard';
    protected string $category = 'Standard';

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
                "Failed to compute Trimmer: " . $e->getMessage(),
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