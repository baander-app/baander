<?php

declare(strict_types=1);

namespace App\Modules\Essentia\Algorithms\Spectral;

use App\Modules\Essentia\Algorithms\BaseAlgorithm;
use App\Modules\Essentia\Exceptions\AlgorithmException;
use App\Modules\Essentia\Types\AudioVector;

/**
 * BarkBands


Inputs:

  [vector_real] spectrum - the input spectrum


Outputs:

  [vector_real] bands - the energy of the bark bands


Parameters:

  numberBands:
    integer ∈ [1,28] (default = 27)
    the number of desired barkbands

  sampleRate:
    real ∈ [0,inf) (default = 44100)
    the sampling rate of the audio signal [Hz]


Description:

  This algorithm computes energy in Bark bands of a spectrum. The band
  frequencies are: [0.0, 50.0, 100.0, 150.0, 200.0, 300.0, 400.0, 510.0, 630.0,
  770.0, 920.0, 1080.0, 1270.0, 1480.0, 1720.0, 2000.0, 2320.0, 2700.0, 3150.0,
  3700.0, 4400.0, 5300.0, 6400.0, 7700.0, 9500.0, 12000.0, 15500.0, 20500.0,
  27000.0]. The first two Bark bands [0,100] and [100,200] have been split in
  half for better resolution (because of an observed better performance in beat
  detection). For each bark band the power-spectrum (mag-squared) is summed.
  
  This algorithm uses FrequencyBands and thus inherits its input requirements
  and exceptions.
  
  References:
    [1] The Bark Frequency Scale,
    http://ccrma.stanford.edu/~jos/bbt/Bark_Frequency_Scale.html
 * 
 * Category: Spectral
 * Mode: standard
 */
class BarkBands extends BaseAlgorithm
{
    protected string $algorithmName = 'BarkBands';
    protected string $mode = 'standard';
    protected string $category = 'Spectral';

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
                "Failed to compute BarkBands: " . $e->getMessage(),
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