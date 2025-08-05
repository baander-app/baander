<?php

declare(strict_types=1);

namespace App\Modules\Essentia\Algorithms\Temporal;

use App\Modules\Essentia\Algorithms\BaseAlgorithm;
use App\Modules\Essentia\Exceptions\AlgorithmException;
use App\Modules\Essentia\Types\AudioVector;

/**
 * EnergyBand


Inputs:

  [vector_real] spectrum - the input frequency spectrum


Outputs:

  [real] energyBand - the energy in the frequency band


Parameters:

  sampleRate:
    real ∈ (0,inf) (default = 44100)
    the audio sampling rate [Hz]

  startCutoffFrequency:
    real ∈ [0,inf) (default = 0)
    the start frequency from which to sum the energy [Hz]

  stopCutoffFrequency:
    real ∈ (0,inf) (default = 100)
    the stop frequency to which to sum the energy [Hz]


Description:

  This algorithm computes energy in a given frequency band of a spectrum
  including both start and stop cutoff frequencies.
  Note that exceptions will be thrown when input spectrum is empty and if
  startCutoffFrequency is greater than stopCutoffFrequency.
  
  References:
    [1] Energy (signal processing) - Wikipedia, the free encyclopedia,
    http://en.wikipedia.org/wiki/Energy_(signal_processing)
 * 
 * Category: Temporal
 * Mode: standard
 */
class EnergyBand extends BaseAlgorithm
{
    protected string $algorithmName = 'EnergyBand';
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
                "Failed to compute EnergyBand: " . $e->getMessage(),
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