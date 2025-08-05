<?php

declare(strict_types=1);

namespace App\Modules\Essentia\Algorithms\Rhythm;

use App\Modules\Essentia\Algorithms\BaseAlgorithm;
use App\Modules\Essentia\Exceptions\AlgorithmException;
use App\Modules\Essentia\Types\AudioVector;

/**
 * TempoScaleBands


Inputs:

  [vector_real] bands - the audio power spectrum divided into bands


Outputs:

  [vector_real] scaledBands - the output bands after scaling
         [real] cumulativeBands - cumulative sum of the output bands before scaling


Parameters:

  bandsGain:
    vector_real (default = [2, 3, 2, 1, 1.20000004768, 2, 3, 2.5])
    gain for each bands

  frameTime:
    real ∈ (0,inf) (default = 512)
    the frame rate in samples


Description:

  This algorithm computes features for tempo tracking to be used with the
  TempoTap algorithm. See standard_rhythmextractor_tempotap in examples folder.
  
  An exception is thrown if less than 1 band is given. An exception is also
  thrown if the there are not an equal number of bands given as band-gains
  given.
  
  Quality: outdated (the associated TempoTap algorithm is outdated, however it
  can be potentially used as an onset detection function for other tempo
  estimation algorithms although no evaluation has been done)
  
  References:
    [1] Algorithm by Fabien Gouyon and Simon Dixon. There is no reference at
    the time of this writing.
 * 
 * Category: Rhythm
 * Mode: standard
 */
class TempoScaleBands extends BaseAlgorithm
{
    protected string $algorithmName = 'TempoScaleBands';
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
                "Failed to compute TempoScaleBands: " . $e->getMessage(),
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