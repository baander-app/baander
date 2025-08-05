<?php

declare(strict_types=1);

namespace App\Modules\Essentia\Algorithms\Spectral;

use App\Modules\Essentia\Algorithms\BaseAlgorithm;
use App\Modules\Essentia\Exceptions\AlgorithmException;
use App\Modules\Essentia\Types\AudioVector;

/**
 * SpectralComplexity


Inputs:

  [vector_real] spectrum - the input spectrum


Outputs:

  [real] spectralComplexity - the spectral complexity of the input spectrum


Parameters:

  magnitudeThreshold:
    real ∈ [0,inf) (default = 0.00499999988824)
    the minimum spectral-peak magnitude that contributes to spectral complexity

  sampleRate:
    real ∈ (0,inf) (default = 44100)
    the audio sampling rate [Hz]


Description:

  This algorithm computes the spectral complexity of a spectrum. The spectral
  complexity is based on the number of peaks in the input spectrum.
  
  It is recommended that the input "spectrum" be computed by the Spectrum
  algorithm. The input "spectrum" is passed to the SpectralPeaks algorithm and
  thus inherits its input requirements and exceptions.
  References:
    [1] C. Laurier, O. Meyers, J. Serrà, M. Blech, P. Herrera, and X. Serra,
    "Indexing music by mood: design and integration of an automatic
    content-based annotator," Multimedia Tools and Applications, vol. 48,
    no. 1, pp. 161–184, 2009.
 * 
 * Category: Spectral
 * Mode: standard
 */
class SpectralComplexity extends BaseAlgorithm
{
    protected string $algorithmName = 'SpectralComplexity';
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
                "Failed to compute SpectralComplexity: " . $e->getMessage(),
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