<?php

declare(strict_types=1);

namespace App\Modules\Essentia\Algorithms\Temporal;

use App\Modules\Essentia\Algorithms\BaseAlgorithm;
use App\Modules\Essentia\Exceptions\AlgorithmException;
use App\Modules\Essentia\Types\AudioVector;

/**
 * Larm


Inputs:

  [vector_real] signal - the audio input signal


Outputs:

  [real] larm - the LARM loudness estimate [dB]


Parameters:

  attackTime:
    real ∈ [0,inf) (default = 10)
    the attack time of the first order lowpass in the attack phase [ms]

  power:
    real ∈ (-inf,inf) (default = 1.5)
    the power used for averaging

  releaseTime:
    real ∈ [0,inf) (default = 1500)
    the release time of the first order lowpass in the release phase [ms]

  sampleRate:
    real ∈ (0,inf) (default = 44100)
    the sampling rate of the audio signal [Hz]


Description:

  This algorithm estimates the long-term loudness of an audio signal. The LARM
  model is based on the asymmetrical low-pass filtering of the Peak Program
  Meter (PPM), combined with Revised Low-frequency B-weighting (RLB) and power
  mean calculations. LARM has shown to be a reliable and objective loudness
  estimate of music and speech.
  
  It accepts a power parameter to define the exponential for computing the
  power mean. Note that if the parameter's value is 2, this algorithm would be
  equivalent to RMS and if 1, this algorithm would be the mean of the absolute
  value.
  
  References:
   [1] E. Skovenborg and S. H. Nielsen, "Evaluation of different loudness
   models with music and speech material,” in The 117th AES Convention, 2004.
 * 
 * Category: Temporal
 * Mode: standard
 */
class Larm extends BaseAlgorithm
{
    protected string $algorithmName = 'Larm';
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
                "Failed to compute Larm: " . $e->getMessage(),
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