<?php

declare(strict_types=1);

namespace App\Modules\Essentia\Algorithms\Spectral;

use App\Modules\Essentia\Algorithms\BaseAlgorithm;
use App\Modules\Essentia\Exceptions\AlgorithmException;
use App\Modules\Essentia\Types\AudioVector;

/**
 * Flux


Inputs:

  [vector_real] spectrum - the input spectrum


Outputs:

  [real] flux - the spectral flux of the input spectrum


Parameters:

  halfRectify:
    bool ∈ {true,false} (default = false)
    half-rectify the differences in each spectrum bin

  norm:
    string ∈ {L1,L2} (default = "L2")
    the norm to use for difference computation


Description:

  This algorithm computes the spectral flux of a spectrum. Flux is defined as
  the L2-norm [1] or L1-norm [2] of the difference between two consecutive
  frames of the magnitude spectrum. The frames have to be of the same size in
  order to yield a meaningful result. The default L2-norm is used more
  commonly.
  
  An exception is thrown if the size of the input spectrum does not equal the
  previous input spectrum's size.
  
  References:
    [1] Tzanetakis, G., Cook, P., "Multifeature Audio Segmentation for
    Browsing and Annotation", Proceedings of the 1999 IEEE Workshop on
    Applications of Signal Processing to Audio and Acoustics, New Paltz,
    NY, USA, 1999, W99 1-4
  
    [2] S. Dixon, "Onset detection revisited", in International Conference on
    Digital Audio Effects (DAFx'06), 2006, vol. 120, pp. 133-137.
  
    [3] http://en.wikipedia.org/wiki/Spectral_flux
 * 
 * Category: Spectral
 * Mode: standard
 */
class Flux extends BaseAlgorithm
{
    protected string $algorithmName = 'Flux';
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
                "Failed to compute Flux: " . $e->getMessage(),
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