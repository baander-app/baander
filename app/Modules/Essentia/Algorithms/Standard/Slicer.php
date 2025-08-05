<?php

declare(strict_types=1);

namespace App\Modules\Essentia\Algorithms\Standard;

use App\Modules\Essentia\Algorithms\BaseAlgorithm;
use App\Modules\Essentia\Exceptions\AlgorithmException;
use App\Modules\Essentia\Types\AudioVector;

/**
 * Slicer


Inputs:

  [vector_real] audio - the input audio signal


Outputs:

  [vector_vector_real] frame - the frames of the sliced input signal


Parameters:

  endTimes:
    vector_real (default = [])
    the list of end times for the slices you want to extract

  sampleRate:
    real ∈ (0,inf) (default = 44100)
    the sampling rate of the audio signal [Hz]

  startTimes:
    vector_real (default = [])
    the list of start times for the slices you want to extract

  timeUnits:
    string ∈ {samples,seconds} (default = "seconds")
    the units of time of the start and end times


Description:

  This algorithm splits an audio signal into segments given their start and end
  times.
  
  The parameters, "startTimes" and "endTimes" must be coherent. If these
  parameters differ in size, an exception is thrown. If a particular startTime
  is larger than its corresponding endTime, an exception is thrown.
 * 
 * Category: Standard
 * Mode: standard
 */
class Slicer extends BaseAlgorithm
{
    protected string $algorithmName = 'Slicer';
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
                "Failed to compute Slicer: " . $e->getMessage(),
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