<?php

declare(strict_types=1);

namespace App\Modules\Essentia\Algorithms\HighLevel;

use App\Modules\Essentia\Algorithms\BaseAlgorithm;
use App\Modules\Essentia\Exceptions\AlgorithmException;
use App\Modules\Essentia\Types\AudioVector;

/**
 * Danceability


Inputs:

  [vector_real] signal - the input signal


Outputs:

         [real] danceability - the danceability value. Normal values range from 0 to ~3. The higher, the more danceable.
  [vector_real] dfa - the DFA exponent vector for considered segment length (tau) values


Parameters:

  maxTau:
    real ∈ (0,inf) (default = 8800)
    maximum segment length to consider [ms]

  minTau:
    real ∈ (0,inf) (default = 310)
    minimum segment length to consider [ms]

  sampleRate:
    real ∈ (0,inf) (default = 44100)
    the sampling rate of the audio signal [Hz]

  tauMultiplier:
    real ∈ (1,inf) (default = 1.10000002384)
    multiplier to increment from min to max tau


Description:

  This algorithm estimates danceability of a given audio signal. The algorithm
  is derived from Detrended Fluctuation Analysis (DFA) described in [1]. The
  parameters minTau and maxTau are used to define the range of time over which
  DFA will be performed. The output of this algorithm is the danceability of
  the audio signal. These values usually range from 0 to 3 (higher values
  meaning more danceable).
  
  Exception is thrown when minTau is greater than maxTau.
  
  References:
    [1] Streich, S. and Herrera, P., Detrended Fluctuation Analysis of Music
    Signals: Danceability Estimation and further Semantic Characterization,
    Proceedings of the AES 118th Convention, Barcelona, Spain, 2005
 * 
 * Category: HighLevel
 * Mode: standard
 */
class Danceability extends BaseAlgorithm
{
    protected string $algorithmName = 'Danceability';
    protected string $mode = 'standard';
    protected string $category = 'HighLevel';

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
                "Failed to compute Danceability: " . $e->getMessage(),
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