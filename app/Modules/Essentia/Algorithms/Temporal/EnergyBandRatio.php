<?php

declare(strict_types=1);

namespace App\Modules\Essentia\Algorithms\Temporal;

use App\Modules\Essentia\Algorithms\BaseAlgorithm;
use App\Modules\Essentia\Exceptions\AlgorithmException;
use App\Modules\Essentia\Types\AudioVector;

/**
 * EnergyBandRatio


Inputs:

  [vector_real] spectrum - the input audio spectrum


Outputs:

  [real] energyBandRatio - the energy ratio of the specified band over the total energy


Parameters:

  sampleRate:
    real ∈ (0,inf) (default = 44100)
    the sampling rate of the audio signal [Hz]

  startFrequency:
    real ∈ [0,inf) (default = 0)
    the frequency from which to start summing the energy [Hz]

  stopFrequency:
    real ∈ [0,inf) (default = 100)
    the frequency up to which to sum the energy [Hz]


Description:

  This algorithm computes the ratio of the spectral energy in the range
  [startFrequency, stopFrequency] over the total energy.
  
  An exception is thrown when startFrequency is larger than stopFrequency
  or the input spectrum is empty.
  
  References:
    [1] Energy (signal processing) - Wikipedia, the free encyclopedia,
    http://en.wikipedia.org/wiki/Energy_%28signal_processing%29
 * 
 * Category: Temporal
 * Mode: standard
 */
class EnergyBandRatio extends BaseAlgorithm
{
    protected string $algorithmName = 'EnergyBandRatio';
    protected string $mode = 'standard';
    protected string $category = 'Temporal';

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
                "Failed to compute EnergyBandRatio: " . $e->getMessage(),
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