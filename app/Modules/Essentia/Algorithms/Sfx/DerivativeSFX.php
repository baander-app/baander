<?php

declare(strict_types=1);

namespace App\Modules\Essentia\Algorithms\Sfx;

use App\Modules\Essentia\Algorithms\BaseAlgorithm;
use App\Modules\Essentia\Exceptions\AlgorithmException;
use App\Modules\Essentia\Types\AudioVector;

/**
 * DerivativeSFX


Inputs:

  [vector_real] envelope - the envelope of the signal


Outputs:

  [real] derAvAfterMax - the weighted average of the derivative after the maximum amplitude
  [real] maxDerBeforeMax - the maximum derivative before the maximum amplitude


Description:

  This algorithm computes two descriptors that are based on the derivative of a
  signal envelope.
  
  The first descriptor is calculated after the maximum value of the input
  signal occurred. It is the average of the signal's derivative weighted by its
  amplitude. This coefficient helps discriminating impulsive sounds, which have
  a steep release phase, from non-impulsive sounds. The smaller the value the
  more impulsive.
  
  The second descriptor is the maximum derivative, before the maximum value of
  the input signal occurred. This coefficient helps discriminating sounds that
  have a smooth attack phase, and therefore a smaller value than sounds with a
  fast attack.
  
  This algorithm is meant to be fed by the outputs of the Envelope algorithm.
  If used in streaming mode, RealAccumulator should be connected in between.
  An exception is thrown if the input signal is empty.
 * 
 * Category: Sfx
 * Mode: standard
 */
class DerivativeSFX extends BaseAlgorithm
{
    protected string $algorithmName = 'DerivativeSFX';
    protected string $mode = 'standard';
    protected string $category = 'Sfx';

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
                "Failed to compute DerivativeSFX: " . $e->getMessage(),
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