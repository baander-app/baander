<?php

declare(strict_types=1);

namespace App\Modules\Essentia\Algorithms\Rhythm;

use App\Modules\Essentia\Algorithms\BaseAlgorithm;
use App\Modules\Essentia\Exceptions\AlgorithmException;
use App\Modules\Essentia\Types\AudioVector;

/**
 * TempoTapMaxAgreement


Inputs:

  [vector_vector_real] tickCandidates - the tick candidates estimated using different beat trackers (or features) [s]


Outputs:

  [vector_real] ticks - the list of resulting ticks [s]
         [real] confidence - confidence with which the ticks were detected [0, 5.32]


Description:

  This algorithm outputs beat positions and confidence of their estimation
  based on the maximum mutual agreement between beat candidates estimated by
  different beat trackers (or using different features).
  
  Note that the input tick times should be in ascending order and that they
  cannot contain negative values otherwise an exception will be thrown.
  
  References:
    [1] J. R. Zapata, A. Holzapfel, M. E. Davies, J. L. Oliveira, and
    F. Gouyon, "Assigning a confidence threshold on automatic beat annotation
    in large datasets," in International Society for Music Information
    Retrieval Conference (ISMIR’12), 2012.
  
    [2] A. Holzapfel, M. E. Davies, J. R. Zapata, J. L. Oliveira, and
    F. Gouyon, "Selective sampling for beat tracking evaluation," IEEE
    Transactions on Audio, Speech, and Language Processing, vol. 13, no. 9,
    pp. 2539-2548, 2012.
 * 
 * Category: Rhythm
 * Mode: standard
 */
class TempoTapMaxAgreement extends BaseAlgorithm
{
    protected string $algorithmName = 'TempoTapMaxAgreement';
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
                "Failed to compute TempoTapMaxAgreement: " . $e->getMessage(),
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